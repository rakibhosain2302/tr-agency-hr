<?php

namespace Nit\AgraniHr\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Nit\AgraniHr\Events\LeaveApplicationDecided;
use Nit\AgraniHr\Exceptions\HrException;
use Nit\AgraniHr\Models\Employee;
use Nit\AgraniHr\Models\LeaveApplication;
use Nit\AgraniHr\Models\LeaveLedgerEntry;
use Nit\AgraniHr\Models\LeaveType;
use Nit\AgraniHr\Support\Approver;
use Nit\AgraniHr\Support\HrLog;
use Nit\AgraniHr\Support\Money;
use Nit\AgraniHr\Support\PayrollLock;
use Nit\AgraniHr\Support\WorkCalendar;

/**
 * Leave: applications (pending → approved / rejected; cancelled by the applicant or HR) and balances kept as a
 * ledger per employee, type and calendar year. Approving writes "taken" entries, cancelling an approved leave
 * "restored" ones, so the balance always has its history. Limited types (days_per_year set) need enough
 * balance; their yearly entitlement is added the first time it is needed.
 */
final class LeaveService
{
    public function apply(Employee $employee, LeaveType $type, \DateTimeInterface|string $start, \DateTimeInterface|string $end, bool $halfDay = false, ?string $reason = null): LeaveApplication
    {
        $start = Carbon::parse($start)->startOfDay();
        $end = Carbon::parse($end)->startOfDay();

        return DB::transaction(function () use ($employee, $type, $start, $end, $halfDay, $reason): LeaveApplication {
            $employee = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            if (! $type->is_active) {
                throw HrException::invalid(__('This leave type is no longer used.'), 'inactive_type');
            }
            if ($end->lt($start)) {
                throw HrException::invalid(__('The leave cannot end before it starts.'), 'invalid_date');
            }
            if ($halfDay && (! $type->allow_half_day || ! $start->equalTo($end))) {
                throw HrException::invalid(__('A half day is one day of a type that allows half days.'), 'invalid_half_day');
            }
            if ($start->lt($employee->joining_date) || ($employee->termination_date !== null && $end->gt($employee->termination_date))) {
                throw HrException::invalid(__('The leave must fall within the employment.'), 'not_employed');
            }
            PayrollLock::ensureOpen($employee->id, $start, $end);
            if ($this->overlapping($employee->id, $start, $end)) {
                throw HrException::invalid(__('The employee already has leave on some of these days.'), 'overlap');
            }

            $byYear = $this->daysByYear($start, $end, $halfDay);
            if (Money::compare(Money::add(...array_values($byYear)), '0') <= 0) {
                throw HrException::invalid(__('These days are all weekend days.'), 'no_working_days');
            }
            $this->ensureBalance($employee, $type, $byYear, withPending: true);

            $application = LeaveApplication::query()->create([
                'employee_id' => $employee->id,
                'branch_id' => $employee->branch_id,
                'leave_type_id' => $type->id,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'half_day' => $halfDay,
                'days' => Money::round(Money::add(...array_values($byYear)), 1),
                'reason' => $reason,
                'status' => LeaveApplication::PENDING,
                'applied_by' => auth()->id(),
            ]);

            HrLog::write('hr_leave.applied', "Leave {$type->code} applied for {$employee->activityLabel()}", $application, $application->branch_id);

            return $application;
        });
    }

    public function approve(LeaveApplication $application, ?string $note = null): LeaveApplication
    {
        return DB::transaction(function () use ($application, $note): LeaveApplication {
            $application = $this->pending($application);
            $employee = Employee::query()->withoutGlobalScopes()->whereKey($application->employee_id)->lockForUpdate()->firstOrFail();
            Approver::ensureNotOwn($employee, $application->applied_by);
            PayrollLock::ensureOpen($employee->id, $application->start_date, $application->end_date);

            $type = $application->leaveType()->firstOrFail();
            $byYear = $this->daysByYear($application->start_date, $application->end_date, $application->half_day);
            $this->ensureBalance($employee, $type, $byYear, withPending: false);

            foreach ($byYear as $year => $days) {
                if (Money::isPositive($days)) {
                    $this->entry($employee->id, $type->id, $year, LeaveLedgerEntry::TAKEN, '-'.$days, $application->id, "taken:{$application->id}:{$year}", $application->start_date);
                }
            }

            $application->forceFill(['status' => LeaveApplication::APPROVED, 'decided_by' => auth()->id(), 'decided_at' => now(), 'decision_note' => $note])->save();
            HrLog::write('hr_leave.approved', "Leave of {$employee->activityLabel()} approved", $application, $application->branch_id);
            LeaveApplicationDecided::dispatch($application);

            return $application;
        });
    }

    public function reject(LeaveApplication $application, ?string $note = null): LeaveApplication
    {
        return DB::transaction(function () use ($application, $note): LeaveApplication {
            $application = $this->pending($application);
            Approver::ensureNotOwn(Employee::query()->withoutGlobalScopes()->find($application->employee_id), $application->applied_by);

            $application->forceFill(['status' => LeaveApplication::REJECTED, 'decided_by' => auth()->id(), 'decided_at' => now(), 'decision_note' => $note])->save();
            LeaveApplicationDecided::dispatch($application);

            return $application;
        });
    }

    /** Withdraw a pending application, or cancel an approved one (its days go back to the balance). */
    public function cancel(LeaveApplication $application, ?string $note = null): LeaveApplication
    {
        return DB::transaction(function () use ($application, $note): LeaveApplication {
            $application = LeaveApplication::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();
            if (! in_array($application->status, [LeaveApplication::PENDING, LeaveApplication::APPROVED], true)) {
                throw HrException::invalid(__('Only pending or approved leave can be cancelled.'), 'not_cancellable');
            }

            if ($application->status === LeaveApplication::APPROVED) {
                PayrollLock::ensureOpen($application->employee_id, $application->start_date, $application->end_date);
                foreach ($this->daysByYear($application->start_date, $application->end_date, $application->half_day) as $year => $days) {
                    if (Money::isPositive($days)) {
                        $this->entry($application->employee_id, $application->leave_type_id, $year, LeaveLedgerEntry::RESTORED, $days, $application->id, "restored:{$application->id}:{$year}", now());
                    }
                }
            }

            $application->forceFill(['status' => LeaveApplication::CANCELLED, 'cancelled_by' => auth()->id(), 'cancelled_at' => now(), 'decision_note' => $note ?? $application->decision_note])->save();

            return $application;
        });
    }

    /** Add the type's yearly entitlement (or $days) once for the year. */
    public function allocate(Employee $employee, LeaveType $type, int $year, string|int|float|null $days = null): ?LeaveLedgerEntry
    {
        $days = $days !== null ? Money::round($days, 1) : ($type->days_per_year !== null ? Money::round($type->days_per_year, 1) : null);
        if ($days === null) {
            return null;
        }

        return $this->entry($employee->id, $type->id, $year, LeaveLedgerEntry::ENTITLEMENT, $days, null, "entitlement:{$employee->id}:{$type->id}:{$year}", Carbon::create($year, 1, 1));
    }

    /** Add (positive) or remove (negative) days, with a note. */
    public function adjust(Employee $employee, LeaveType $type, int $year, string|int|float $days, string $notes): LeaveLedgerEntry
    {
        $days = Money::round($days, 1);
        if (Money::compare($days, '0') === 0) {
            throw HrException::invalid(__('An adjustment changes the balance.'), 'invalid_adjustment');
        }

        return DB::transaction(function () use ($employee, $type, $year, $days, $notes): LeaveLedgerEntry {
            Employee::query()->withoutGlobalScopes()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $this->allocate($employee, $type, $year);
            if (Money::compare(Money::add($this->balance($employee->id, $type->id, $year), $days), '0') < 0) {
                throw HrException::invalid(__('The balance cannot go below zero.'), 'insufficient_balance');
            }

            return $this->entry($employee->id, $type->id, $year, LeaveLedgerEntry::ADJUSTMENT, $days, null, null, now(), $notes);
        });
    }

    /** Move the unused days of $fromYear (at most the type's carry_forward_max) to the next year, once. */
    public function carryForward(Employee $employee, LeaveType $type, int $fromYear): ?LeaveLedgerEntry
    {
        return DB::transaction(function () use ($employee, $type, $fromYear): ?LeaveLedgerEntry {
            Employee::query()->withoutGlobalScopes()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $days = Money::min($this->balance($employee->id, $type->id, $fromYear), $type->carry_forward_max);
            if (! Money::isPositive($days)) {
                return null;
            }

            return $this->entry($employee->id, $type->id, $fromYear + 1, LeaveLedgerEntry::CARRY_FORWARD, Money::round($days, 1), null, "carry_forward:{$employee->id}:{$type->id}:".($fromYear + 1), Carbon::create($fromYear + 1, 1, 1));
        });
    }

    public function balance(int $employeeId, int $typeId, int $year): string
    {
        return Money::round(LeaveLedgerEntry::query()->where(['employee_id' => $employeeId, 'leave_type_id' => $typeId, 'year' => $year])->sum('days'), 1);
    }

    /**
     * The employee's balances in the year, per active type.
     *
     * @return list<array{leave_type_id: int, code: string, name: string, is_paid: bool, limited: bool, entitlement: string, carried_forward: string, adjustments: string, taken: string, balance: string, pending: string, available: string|null}>
     */
    public function balances(Employee $employee, int $year): array
    {
        $ledger = LeaveLedgerEntry::query()->where(['employee_id' => $employee->id, 'year' => $year])
            ->selectRaw('leave_type_id, kind, SUM(days) as days')->groupBy('leave_type_id', 'kind')->get()
            ->groupBy('leave_type_id');
        $pending = LeaveApplication::query()->withoutGlobalScopes()->where('employee_id', $employee->id)->where('status', LeaveApplication::PENDING)->get()
            ->groupBy('leave_type_id')->map(fn ($applications) => Money::round(Money::add(...$applications->map(fn (LeaveApplication $a) => $this->daysByYear($a->start_date, $a->end_date, $a->half_day)[$year] ?? '0')->all()), 1));

        return LeaveType::query()->where('is_active', true)->orderBy('code')->get()->map(function (LeaveType $type) use ($ledger, $pending): array {
            $kinds = ($ledger[$type->id] ?? collect())->mapWithKeys(fn ($row) => [$row->kind => (string) $row->days]);
            $entitlement = $type->isLimited() && ! isset($kinds[LeaveLedgerEntry::ENTITLEMENT]) ? (string) $type->days_per_year : ($kinds[LeaveLedgerEntry::ENTITLEMENT] ?? '0');
            $balance = Money::add($entitlement, $kinds[LeaveLedgerEntry::CARRY_FORWARD] ?? 0, $kinds[LeaveLedgerEntry::ADJUSTMENT] ?? 0, $kinds[LeaveLedgerEntry::TAKEN] ?? 0, $kinds[LeaveLedgerEntry::RESTORED] ?? 0);
            $waiting = $pending[$type->id] ?? '0.0';

            return [
                'leave_type_id' => $type->id,
                'code' => $type->code,
                'name' => $type->name,
                'is_paid' => $type->is_paid,
                'limited' => $type->isLimited(),
                'entitlement' => Money::round($entitlement, 1),
                'carried_forward' => Money::round($kinds[LeaveLedgerEntry::CARRY_FORWARD] ?? 0, 1),
                'adjustments' => Money::round($kinds[LeaveLedgerEntry::ADJUSTMENT] ?? 0, 1),
                'taken' => Money::round(Money::of(Money::add($kinds[LeaveLedgerEntry::TAKEN] ?? 0, $kinds[LeaveLedgerEntry::RESTORED] ?? 0))->negated(), 1),
                'balance' => Money::round($balance, 1),
                'pending' => Money::round($waiting, 1),
                'available' => $type->isLimited() ? Money::round(Money::sub($balance, $waiting), 1) : null,
            ];
        })->all();
    }

    /**
     * Working days of the leave per calendar year ("1.0", "0.5" …).
     *
     * @return array<int, string>
     */
    public function daysByYear(\DateTimeInterface|string $start, \DateTimeInterface|string $end, bool $halfDay = false): array
    {
        $years = [];
        foreach ($this->leaveDays($start, $end, $halfDay) as $date => $fraction) {
            $year = (int) substr($date, 0, 4);
            $years[$year] = Money::round(Money::add($years[$year] ?? '0', $fraction), 1);
        }

        return $years ?: [(int) Carbon::parse($start)->year => '0.0'];
    }

    /**
     * The days of approved leave of the employee between $from and $to, with the fraction and whether paid.
     *
     * @return array<string, array{fraction: string, paid: bool}>
     */
    public function approvedDays(int $employeeId, \DateTimeInterface|string $from, \DateTimeInterface|string $to): array
    {
        $from = Carbon::parse($from)->startOfDay();
        $to = Carbon::parse($to)->startOfDay();
        $days = [];

        LeaveApplication::query()->withoutGlobalScopes()->with('leaveType')->where('employee_id', $employeeId)->where('status', LeaveApplication::APPROVED)
            ->whereDate('start_date', '<=', $to->toDateString())->whereDate('end_date', '>=', $from->toDateString())
            ->get()->each(function (LeaveApplication $application) use (&$days, $from, $to): void {
                foreach ($this->leaveDays($application->start_date, $application->end_date, $application->half_day) as $date => $fraction) {
                    if ($date >= $from->toDateString() && $date <= $to->toDateString()) {
                        $days[$date] = ['fraction' => $fraction, 'paid' => $application->leaveType->is_paid];
                    }
                }
            });

        return $days;
    }

    /** @return array<string, string> date => "1.0" / "0.5" for every leave day (weekends left out) */
    private function leaveDays(\DateTimeInterface|string $start, \DateTimeInterface|string $end, bool $halfDay): array
    {
        $days = [];
        foreach (WorkCalendar::days($start, $end) as $day) {
            if (config('hr.leave.exclude_weekends', true) && WorkCalendar::isWeekend($day)) {
                continue;
            }
            $days[$day->toDateString()] = $halfDay ? '0.5' : '1.0';
        }

        return $days;
    }

    /** @param  array<int, string>  $byYear */
    private function ensureBalance(Employee $employee, LeaveType $type, array $byYear, bool $withPending): void
    {
        if (! $type->isLimited()) {
            return;
        }

        foreach ($byYear as $year => $days) {
            $this->allocate($employee, $type, $year);
            $available = $this->balance($employee->id, $type->id, $year);
            if ($withPending) {
                $pending = LeaveApplication::query()->withoutGlobalScopes()->where(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'status' => LeaveApplication::PENDING])->get()
                    ->map(fn (LeaveApplication $a) => $this->daysByYear($a->start_date, $a->end_date, $a->half_day)[$year] ?? '0')->all();
                $available = Money::sub($available, Money::add(...$pending));
            }
            if (Money::compare($available, $days) < 0) {
                throw HrException::invalid(__(':type: :available day(s) left in :year, :days asked.', ['type' => $type->name, 'available' => Money::round($available, 1), 'year' => $year, 'days' => $days]), 'insufficient_balance');
            }
        }
    }

    private function overlapping(int $employeeId, Carbon $start, Carbon $end): bool
    {
        return LeaveApplication::query()->withoutGlobalScopes()->where('employee_id', $employeeId)
            ->whereIn('status', [LeaveApplication::PENDING, LeaveApplication::APPROVED])
            ->whereDate('start_date', '<=', $end->toDateString())->whereDate('end_date', '>=', $start->toDateString())
            ->exists();
    }

    private function pending(LeaveApplication $application): LeaveApplication
    {
        $application = LeaveApplication::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();
        if ($application->status !== LeaveApplication::PENDING) {
            throw HrException::invalid(__('This application has already been decided.'), 'already_decided');
        }

        return $application;
    }

    private function entry(int $employeeId, int $typeId, int $year, string $kind, string $days, ?int $applicationId, ?string $uniqueKey, \DateTimeInterface|string $date, ?string $notes = null): LeaveLedgerEntry
    {
        if ($uniqueKey !== null && ($existing = LeaveLedgerEntry::query()->where('unique_key', $uniqueKey)->first())) {
            return $existing;
        }

        return LeaveLedgerEntry::query()->create([
            'employee_id' => $employeeId,
            'leave_type_id' => $typeId,
            'year' => $year,
            'kind' => $kind,
            'days' => $days,
            'leave_application_id' => $applicationId,
            'unique_key' => $uniqueKey,
            'entry_date' => Carbon::parse($date)->toDateString(),
            'notes' => $notes,
            'created_by' => auth()->id(),
            'created_at' => now(),
        ]);
    }
}
