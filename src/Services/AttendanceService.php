<?php

namespace Nit\AgraniHr\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Nit\AgraniHr\Exceptions\HrException;
use Nit\AgraniHr\Models\Attendance;
use Nit\AgraniHr\Models\AttendanceCorrection;
use Nit\AgraniHr\Models\Employee;
use Nit\AgraniHr\Support\Approver;
use Nit\AgraniHr\Support\HrLog;
use Nit\AgraniHr\Support\PayrollLock;
use Nit\AgraniHr\Support\WorkCalendar;

/**
 * Attendance: check-in / check-out, records kept by HR, and corrections (requested, then approved by someone
 * else). The worked, late and overtime minutes are calculated from config('hr.attendance') every time a
 * record changes. Days in an approved payroll are locked.
 */
final class AttendanceService
{
    /** Check in now (or at $at); the work day is the day of the check-in. */
    public function checkIn(Employee $employee, \DateTimeInterface|string|null $at = null, ?int $branchId = null): Attendance
    {
        $at = Carbon::parse($at ?? now())->second(0);

        return DB::transaction(function () use ($employee, $at, $branchId): Attendance {
            $this->ensureEmployed($employee, $at);
            PayrollLock::ensureOpen($employee->id, $at);

            $attendance = Attendance::query()->where('employee_id', $employee->id)->whereDate('work_date', $at->toDateString())->lockForUpdate()->first();
            if ($attendance?->check_in_at !== null) {
                throw HrException::invalid(__('Already checked in on :date.', ['date' => $at->toDateString()]), 'already_checked_in');
            }

            $attendance ??= new Attendance(['employee_id' => $employee->id, 'work_date' => $at->toDateString()]);
            $attendance->fill([
                'branch_id' => $this->branchFor($employee, $branchId),
                'status' => $this->defaultStatus($at),
                'check_in_at' => $at,
                'source' => 'self',
            ]);
            $this->calculate($attendance);
            $attendance->save();

            return $attendance;
        });
    }

    /** Check out of the open day: today's, or yesterday's for a shift that passes midnight. */
    public function checkOut(Employee $employee, \DateTimeInterface|string|null $at = null): Attendance
    {
        $at = Carbon::parse($at ?? now())->second(0);

        return DB::transaction(function () use ($employee, $at): Attendance {
            $attendance = Attendance::query()->where('employee_id', $employee->id)
                ->whereIn('work_date', [$at->toDateString(), $at->copy()->subDay()->toDateString()])
                ->whereNotNull('check_in_at')->whereNull('check_out_at')
                ->orderByDesc('work_date')->lockForUpdate()->first()
                ?? throw HrException::invalid(__('There is no check-in to check out of.'), 'not_checked_in');

            PayrollLock::ensureOpen($employee->id, $attendance->work_date);
            if ($at->lt($attendance->check_in_at)) {
                throw HrException::invalid(__('Check-out cannot be before check-in.'), 'invalid_time');
            }

            $attendance->check_out_at = $at;
            $this->calculate($attendance);
            $attendance->save();

            return $attendance;
        });
    }

    /**
     * Write the day's record (HR): status and times, replacing what was there.
     *
     * @param  array{status?: string, check_in_at?: ?string, check_out_at?: ?string, notes?: ?string, branch_id?: ?int}  $data
     */
    public function record(Employee $employee, \DateTimeInterface|string $date, array $data, string $source = 'manual'): Attendance
    {
        $day = Carbon::parse($date)->startOfDay();

        return DB::transaction(function () use ($employee, $day, $data, $source): Attendance {
            $this->ensureEmployed($employee, $day);
            PayrollLock::ensureOpen($employee->id, $day);

            $attendance = Attendance::query()->where('employee_id', $employee->id)->whereDate('work_date', $day->toDateString())->lockForUpdate()->first()
                ?? new Attendance(['employee_id' => $employee->id, 'work_date' => $day->toDateString()]);

            $attendance->fill([
                'branch_id' => $attendance->exists && empty($data['branch_id']) ? $attendance->branch_id : $this->branchFor($employee, $data['branch_id'] ?? null),
                'status' => $data['status'] ?? ($attendance->status ?? $this->defaultStatus($day)),
                'check_in_at' => array_key_exists('check_in_at', $data) ? $this->onDay($day, $data['check_in_at']) : $attendance->check_in_at,
                'check_out_at' => array_key_exists('check_out_at', $data) ? $this->onDay($day, $data['check_out_at']) : $attendance->check_out_at,
                'notes' => $data['notes'] ?? $attendance->notes,
                'source' => $source,
            ]);
            $this->calculate($attendance);
            $attendance->save();

            return $attendance;
        });
    }

    /**
     * Ask for the day's record to be changed (or added).
     *
     * @param  array{status?: ?string, check_in_at?: ?string, check_out_at?: ?string}  $data
     */
    public function requestCorrection(Employee $employee, \DateTimeInterface|string $date, array $data, string $reason): AttendanceCorrection
    {
        $day = Carbon::parse($date)->startOfDay();
        if ($day->isFuture()) {
            throw HrException::invalid(__('A future day cannot be corrected.'), 'invalid_date');
        }
        $this->ensureEmployed($employee, $day);
        PayrollLock::ensureOpen($employee->id, $day);
        if (empty($data['status']) && empty($data['check_in_at']) && empty($data['check_out_at'])) {
            throw HrException::invalid(__('Say what should change: the status or a time.'), 'empty_correction');
        }

        $in = $this->onDay($day, $data['check_in_at'] ?? null);
        $out = $this->onDay($day, $data['check_out_at'] ?? null);
        if ($in !== null && $out !== null && $out->lt($in)) {
            throw HrException::invalid(__('Check-out cannot be before check-in.'), 'invalid_time');
        }
        if (AttendanceCorrection::query()->where('employee_id', $employee->id)->whereDate('work_date', $day->toDateString())->where('status', AttendanceCorrection::PENDING)->exists()) {
            throw HrException::invalid(__('A correction of this day is already waiting.'), 'correction_pending');
        }

        $attendance = Attendance::query()->withoutGlobalScopes()->where('employee_id', $employee->id)->whereDate('work_date', $day->toDateString())->first();

        return AttendanceCorrection::query()->create([
            'employee_id' => $employee->id,
            'branch_id' => $attendance?->branch_id ?? $employee->branch_id,
            'attendance_id' => $attendance?->id,
            'work_date' => $day->toDateString(),
            'requested_status' => $data['status'] ?? null,
            'check_in_at' => $in,
            'check_out_at' => $out,
            'reason' => $reason,
            'status' => AttendanceCorrection::PENDING,
            'requested_by' => auth()->id(),
        ]);
    }

    public function approveCorrection(AttendanceCorrection $correction, ?string $note = null): AttendanceCorrection
    {
        return DB::transaction(function () use ($correction, $note): AttendanceCorrection {
            $correction = $this->pendingCorrection($correction);
            $employee = Employee::query()->withoutGlobalScopes()->findOrFail($correction->employee_id);
            Approver::ensureNotOwn($employee, $correction->requested_by);

            $old = Attendance::query()->withoutGlobalScopes()->where('employee_id', $employee->id)->whereDate('work_date', $correction->work_date->toDateString())->first();
            $changes = array_filter([
                'status' => $correction->requested_status,
                'check_in_at' => $correction->check_in_at?->format('Y-m-d H:i'),
                'check_out_at' => $correction->check_out_at?->format('Y-m-d H:i'),
            ], fn ($value) => $value !== null);
            if (in_array($correction->requested_status, [Attendance::ABSENT, Attendance::LEAVE, Attendance::HOLIDAY], true)) {
                $changes += ['check_in_at' => null, 'check_out_at' => null];
            }

            $attendance = $this->record($employee, $correction->work_date, $changes + ['branch_id' => $old?->branch_id ?? $correction->branch_id], 'correction');

            $correction->forceFill([
                'attendance_id' => $attendance->id,
                'old_status' => $old?->status,
                'old_check_in_at' => $old?->check_in_at,
                'old_check_out_at' => $old?->check_out_at,
                'status' => AttendanceCorrection::APPROVED,
                'decided_by' => auth()->id(),
                'decided_at' => now(),
                'decision_note' => $note,
            ])->save();

            HrLog::write('hr_attendance.corrected', "Corrected attendance of {$employee->activityLabel()} on {$correction->work_date->toDateString()}", $attendance, $attendance->branch_id, ['correction_id' => $correction->id]);

            return $correction;
        });
    }

    public function rejectCorrection(AttendanceCorrection $correction, ?string $note = null): AttendanceCorrection
    {
        return DB::transaction(function () use ($correction, $note): AttendanceCorrection {
            $correction = $this->pendingCorrection($correction);
            Approver::ensureNotOwn(Employee::query()->withoutGlobalScopes()->find($correction->employee_id), $correction->requested_by);

            $correction->forceFill(['status' => AttendanceCorrection::REJECTED, 'decided_by' => auth()->id(), 'decided_at' => now(), 'decision_note' => $note])->save();

            return $correction;
        });
    }

    /** Fill the scheduled, worked, late and overtime minutes from the status and the times. */
    public function calculate(Attendance $attendance): void
    {
        $config = (array) config('hr.attendance', []);
        $day = Carbon::parse($attendance->work_date)->startOfDay();
        $status = $attendance->status;

        if (! in_array($status, Attendance::STATUSES, true)) {
            throw HrException::invalid(__('Unknown attendance status :status.', ['status' => $status]), 'invalid_status');
        }
        $in = $attendance->check_in_at;
        $out = $attendance->check_out_at;
        if (in_array($status, [Attendance::ABSENT, Attendance::LEAVE], true) && ($in !== null || $out !== null)) {
            throw HrException::invalid(__('An absence or leave day has no check-in or check-out.'), 'times_not_allowed');
        }
        if ($out !== null && $in === null) {
            throw HrException::invalid(__('A check-out needs a check-in.'), 'invalid_time');
        }
        if ($in !== null && $out !== null && $out->lt($in)) {
            throw HrException::invalid(__('Check-out cannot be before check-in.'), 'invalid_time');
        }

        $offDay = in_array($status, [Attendance::WEEKEND, Attendance::HOLIDAY], true);
        $scheduled = match (true) {
            $offDay, $status === Attendance::ABSENT, $status === Attendance::LEAVE => 0,
            $status === Attendance::HALF_DAY => intdiv((int) ($config['scheduled_minutes'] ?? 480), 2),
            default => (int) ($config['scheduled_minutes'] ?? 480),
        };
        $start = $scheduled > 0 ? $day->copy()->setTimeFromTimeString((string) ($config['work_start'] ?? '09:00')) : null;

        $worked = 0;
        if ($in !== null && $out !== null) {
            $span = (int) $in->diffInMinutes($out);
            $worked = $span > (int) ($config['break_after_minutes'] ?? 360) ? max(0, $span - (int) ($config['break_minutes'] ?? 0)) : $span;
        }

        $late = 0;
        if ($start !== null && $in !== null && $in->gt($start)) {
            $minutes = (int) $start->diffInMinutes($in);
            $late = $minutes > (int) ($config['grace_minutes'] ?? 0) ? $minutes : 0;
        }

        $overtime = $offDay ? $worked : max(0, $worked - $scheduled);
        if ($status === Attendance::HALF_DAY || $overtime < (int) ($config['overtime_min_minutes'] ?? 0)) {
            $overtime = 0;
        }

        $attendance->forceFill([
            'scheduled_start' => $start?->format('H:i:s'),
            'scheduled_minutes' => $scheduled,
            'worked_minutes' => $worked,
            'late_minutes' => $late,
            'overtime_minutes' => $overtime,
        ]);
    }

    private function pendingCorrection(AttendanceCorrection $correction): AttendanceCorrection
    {
        $correction = AttendanceCorrection::query()->whereKey($correction->id)->lockForUpdate()->firstOrFail();
        if ($correction->status !== AttendanceCorrection::PENDING) {
            throw HrException::invalid(__('This correction has already been decided.'), 'already_decided');
        }

        return $correction;
    }

    private function defaultStatus(\DateTimeInterface $day): string
    {
        return WorkCalendar::isWeekend($day) ? Attendance::WEEKEND : Attendance::PRESENT;
    }

    /** The employee's main branch, or another branch they are assigned to now. */
    private function branchFor(Employee $employee, ?int $branchId): int
    {
        if ($branchId === null || $branchId === $employee->branch_id) {
            return $employee->branch_id;
        }
        if (! $employee->branchAssignments()->current()->where('branch_id', $branchId)->exists()) {
            throw HrException::invalid(__('The employee does not work in this branch.'), 'invalid_branch');
        }

        return $branchId;
    }

    private function ensureEmployed(Employee $employee, \DateTimeInterface $day): void
    {
        $day = Carbon::parse($day)->startOfDay();
        if ($day->lt($employee->joining_date) || ($employee->termination_date !== null && $day->gt($employee->termination_date))) {
            throw HrException::invalid(__('The employee is not employed on :date.', ['date' => $day->toDateString()]), 'not_employed');
        }
    }

    /** "09:05" (a time on the work day) or a full date-time (e.g. a check-out after midnight). */
    private function onDay(Carbon $day, ?string $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $value) ? $day->copy()->setTimeFromTimeString($value) : Carbon::parse($value)->second(0);
    }
}
