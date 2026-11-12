<?php

namespace Nit\AgraniHr\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Nit\AgraniHr\Contracts\EmployeeCodeGenerator;
use Nit\AgraniHr\Events\EmployeeHired;
use Nit\AgraniHr\Events\EmployeeTerminated;
use Nit\AgraniHr\Events\EmployeeTransferred;
use Nit\AgraniHr\Exceptions\HrException;
use Nit\AgraniHr\Models\Department;
use Nit\AgraniHr\Models\Designation;
use Nit\AgraniHr\Models\Employee;
use Nit\AgraniHr\Models\EmployeeBranchAssignment;
use Nit\AgraniHr\Models\EmployeeHistory;
use Nit\AgraniHr\Models\EmployeePersonalInfo;
use Nit\AgraniHr\Models\EmployeeStatus;
use Nit\AgraniHr\Models\EmploymentType;
use Nit\Master\Support\Activity;
use Nit\Master\Support\Nit;

/**
 * Employees through their working life: hire, change (each change of department, designation, employment
 * type, status or manager is kept in the history), transfer to another main branch, extra branch assignments,
 * link to a nit-master user, terminate and rehire. Each step is one database transaction.
 */
final class EmployeeService
{
    /** Fields whose changes are kept in the history, with their event and how their value is named. */
    private const TRACKED = [
        'department_id' => ['department_changed', Department::class],
        'designation_id' => ['designation_changed', Designation::class],
        'employment_type_id' => ['employment_type_changed', EmploymentType::class],
        'employee_status_id' => ['status_changed', EmployeeStatus::class],
        'reports_to_id' => ['manager_changed', Employee::class],
    ];

    public function __construct(private EmployeeCodeGenerator $codes) {}

    /**
     * @param  array<string, mixed>  $data  employee fields (+ optional user_id)
     * @param  array<string, mixed>  $personal  personal information fields
     */
    public function hire(array $data, array $personal = []): Employee
    {
        return DB::transaction(function () use ($data, $personal): Employee {
            $data['employee_status_id'] ??= EmployeeStatus::query()->where('code', EmployeeStatus::ACTIVE)->value('id');
            $status = EmployeeStatus::query()->find($data['employee_status_id']);
            if ($status === null || ! $status->is_employed) {
                throw HrException::invalid(__('A new employee needs a status that counts as employed.'), 'invalid_status');
            }

            $employee = new Employee(array_intersect_key($data, array_flip((new Employee)->getFillable())));
            $this->checkOrganisation($employee);
            if (empty($employee->employee_code)) {
                $employee->employee_code = $this->codes->next($employee);
            }
            $employee->save();

            $employee->personalInfo()->create(array_intersect_key($personal, array_flip(EmployeePersonalInfo::FIELDS)));
            $employee->branchAssignments()->create(['branch_id' => $employee->branch_id, 'start_date' => $employee->joining_date, 'is_primary' => true]);
            $this->history($employee, 'joined', null, null, (string) $employee->branch_id, $employee->joining_date);

            if (! empty($data['user_id'])) {
                $this->attachUser($employee, (int) $data['user_id']);
            }

            $this->log('hr_employee.hired', "Hired {$employee->activityLabel()}", $employee);
            EmployeeHired::dispatch($employee);

            return $employee->load('personalInfo');
        });
    }

    /**
     * Change an employee. A new main branch is a transfer (the old primary assignment ends the day before
     * $effectiveDate). Ending or restarting employment is not a change: use terminate() and rehire().
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $personal
     */
    public function update(Employee $employee, array $data, ?array $personal = null, \DateTimeInterface|string|null $effectiveDate = null): Employee
    {
        return DB::transaction(function () use ($employee, $data, $personal, $effectiveDate): Employee {
            $employee = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $effective = Carbon::parse($effectiveDate ?? now())->startOfDay();
            $before = $employee->only(array_keys(self::TRACKED));
            $oldBranch = $employee->branch_id;

            $employee->fill(array_intersect_key($data, array_flip((new Employee)->getFillable())));

            if ($employee->isDirty('employee_status_id')) {
                $status = EmployeeStatus::query()->findOrFail($employee->employee_status_id);
                if ($employee->isTerminated()) {
                    throw HrException::invalid(__('The employee has left; rehire them to change the status.'), 'terminated');
                }
                if (! $status->is_employed) {
                    throw HrException::invalid(__('To end the employment, terminate the employee.'), 'use_terminate');
                }
            }
            if ($employee->isDirty('joining_date') && $employee->history()->where('event', '<>', 'joined')->exists()) {
                throw HrException::invalid(__('The joining date cannot change once the employee has a history.'), 'joining_date_locked');
            }
            $this->checkOrganisation($employee);

            $employee->save();

            foreach (self::TRACKED as $field => [$event, $model]) {
                if ((string) ($before[$field] ?? '') !== (string) ($employee->{$field} ?? '')) {
                    $this->history($employee, $event, $field, $this->label($model, $before[$field]), $this->label($model, $employee->{$field}), $effective);
                }
            }

            if ($employee->branch_id !== $oldBranch) {
                $this->transfer($employee, $oldBranch, $effective);
            }

            if ($personal !== null) {
                $employee->personalInfo()->updateOrCreate([], array_intersect_key($personal, array_flip(EmployeePersonalInfo::FIELDS)));
            }

            return $employee->load('personalInfo');
        });
    }

    /** End the employment on $date with a status that does not count as employed; open branch assignments end too. */
    public function terminate(Employee $employee, \DateTimeInterface|string $date, int $statusId, ?string $reason = null): Employee
    {
        return DB::transaction(function () use ($employee, $date, $statusId, $reason): Employee {
            $employee = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $day = Carbon::parse($date)->startOfDay();
            $status = EmployeeStatus::query()->findOrFail($statusId);

            if ($employee->isTerminated()) {
                throw HrException::invalid(__('The employee has already left.'), 'terminated');
            }
            if ($status->is_employed) {
                throw HrException::invalid(__('Choose a status that does not count as employed.'), 'invalid_status');
            }
            if ($day->lt($employee->joining_date)) {
                throw HrException::invalid(__('An employee cannot leave before joining (:date).', ['date' => $employee->joining_date->toDateString()]), 'invalid_date');
            }

            $oldStatus = $employee->employee_status_id;
            $employee->forceFill(['termination_date' => $day->toDateString(), 'termination_reason' => $reason, 'employee_status_id' => $status->id])->save();
            $employee->branchAssignments()->current()->get()->each(fn (EmployeeBranchAssignment $assignment) => $assignment->update(['end_date' => $day->toDateString()]));

            $this->history($employee, 'terminated', 'employee_status_id', $this->label(EmployeeStatus::class, $oldStatus), $this->label(EmployeeStatus::class, $status->id), $day, $reason);
            $this->log('hr_employee.terminated', "Terminated {$employee->activityLabel()} on {$day->toDateString()}", $employee);
            EmployeeTerminated::dispatch($employee);

            return $employee;
        });
    }

    /** Employ someone who has left again, from $date, in a status that counts as employed. */
    public function rehire(Employee $employee, \DateTimeInterface|string $date, int $statusId, ?int $branchId = null): Employee
    {
        return DB::transaction(function () use ($employee, $date, $statusId, $branchId): Employee {
            $employee = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $day = Carbon::parse($date)->startOfDay();
            $status = EmployeeStatus::query()->findOrFail($statusId);

            if (! $employee->isTerminated()) {
                throw HrException::invalid(__('The employee has not left.'), 'not_terminated');
            }
            if (! $status->is_employed || $day->lte($employee->termination_date)) {
                throw HrException::invalid(__('Rehire after :date with a status that counts as employed.', ['date' => $employee->termination_date->toDateString()]), 'invalid_rehire');
            }

            $employee->forceFill(['termination_date' => null, 'termination_reason' => null, 'employee_status_id' => $status->id, 'branch_id' => $branchId ?? $employee->branch_id])->save();
            $employee->branchAssignments()->create(['branch_id' => $employee->branch_id, 'start_date' => $day->toDateString(), 'is_primary' => true]);

            $this->history($employee, 'rehired', 'employee_status_id', null, $this->label(EmployeeStatus::class, $status->id), $day);
            $this->log('hr_employee.rehired', "Rehired {$employee->activityLabel()} on {$day->toDateString()}", $employee);
            EmployeeHired::dispatch($employee);

            return $employee;
        });
    }

    /** Link the employee to a nit-master user (their sign-in account); a user belongs to one employee at most. */
    public function linkUser(Employee $employee, int $userId): Employee
    {
        return DB::transaction(function () use ($employee, $userId): Employee {
            $employee = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            if ($employee->user_id !== null) {
                throw HrException::invalid(__('The employee is already linked to a user; unlink it first.'), 'user_linked');
            }
            $this->attachUser($employee, $userId);

            return $employee;
        });
    }

    public function unlinkUser(Employee $employee): Employee
    {
        return DB::transaction(function () use ($employee): Employee {
            $employee = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            if ($employee->user_id === null) {
                throw HrException::invalid(__('The employee is not linked to a user.'), 'user_not_linked');
            }
            $old = $employee->user_id;
            $employee->forceFill(['user_id' => null])->save();
            $this->history($employee, 'user_unlinked', 'user_id', (string) $old, null, now());

            return $employee;
        });
    }

    /** An extra (not main) branch the employee also works in, from $start until $end (open-ended without one). */
    public function assignBranch(Employee $employee, int $branchId, \DateTimeInterface|string $start, \DateTimeInterface|string|null $end = null, ?string $notes = null): EmployeeBranchAssignment
    {
        return DB::transaction(function () use ($employee, $branchId, $start, $end, $notes): EmployeeBranchAssignment {
            $employee = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            if ($employee->isTerminated()) {
                throw HrException::invalid(__('The employee has left.'), 'terminated');
            }
            if ($employee->branchAssignments()->current()->where('branch_id', $branchId)->exists()) {
                throw HrException::invalid(__('The employee is already assigned to this branch.'), 'already_assigned');
            }
            (new Employee)->forceFill(['branch_id' => $branchId])->ensureBranchIsAllowed($branchId);

            $assignment = $employee->branchAssignments()->create(['branch_id' => $branchId, 'start_date' => Carbon::parse($start)->toDateString(), 'end_date' => $end ? Carbon::parse($end)->toDateString() : null, 'is_primary' => false, 'notes' => $notes]);
            $this->history($employee, 'branch_assigned', 'branch_id', null, (string) $branchId, Carbon::parse($start));

            return $assignment;
        });
    }

    public function endAssignment(EmployeeBranchAssignment $assignment, \DateTimeInterface|string $date): EmployeeBranchAssignment
    {
        return DB::transaction(function () use ($assignment, $date): EmployeeBranchAssignment {
            $assignment = EmployeeBranchAssignment::query()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            $day = Carbon::parse($date)->startOfDay();
            if ($assignment->is_primary) {
                throw HrException::invalid(__('The main branch changes by a transfer, not by ending its assignment.'), 'primary_assignment');
            }
            if ($assignment->end_date !== null && $assignment->end_date->lt(now()->startOfDay())) {
                throw HrException::invalid(__('This assignment has already ended.'), 'assignment_ended');
            }
            if ($day->lt($assignment->start_date)) {
                throw HrException::invalid(__('An assignment cannot end before it starts.'), 'invalid_date');
            }

            $assignment->update(['end_date' => $day->toDateString()]);
            $this->history($assignment->employee()->withoutGlobalScopes()->firstOrFail(), 'branch_unassigned', 'branch_id', (string) $assignment->branch_id, null, $day);

            return $assignment;
        });
    }

    private function transfer(Employee $employee, int $oldBranch, Carbon $effective): void
    {
        if ($employee->isTerminated()) {
            throw HrException::invalid(__('The employee has left.'), 'terminated');
        }

        // An extra assignment to the new main branch is replaced by the primary one.
        $employee->branchAssignments()->current()->where('branch_id', $employee->branch_id)->where('is_primary', false)->get()
            ->each(fn (EmployeeBranchAssignment $extra) => $extra->update(['end_date' => max($extra->start_date->toDateString(), $effective->copy()->subDay()->toDateString())]));

        $primary = $employee->branchAssignments()->current()->where('is_primary', true)->first();
        if ($primary !== null && $primary->start_date->gte($effective)) {
            // The old main branch never took effect: correct it.
            $primary->update(['branch_id' => $employee->branch_id]);
        } else {
            $primary?->update(['end_date' => $effective->copy()->subDay()->toDateString()]);
            $employee->branchAssignments()->create(['branch_id' => $employee->branch_id, 'start_date' => $effective->toDateString(), 'is_primary' => true]);
        }

        $this->history($employee, 'transferred', 'branch_id', (string) $oldBranch, (string) $employee->branch_id, $effective);
        $this->log('hr_employee.transferred', "Transferred {$employee->activityLabel()} from branch #{$oldBranch} to #{$employee->branch_id}", $employee, ['from_branch_id' => $oldBranch]);
        EmployeeTransferred::dispatch($employee);
    }

    /** Department / designation must agree; a manager cannot be the employee or someone below them. */
    private function checkOrganisation(Employee $employee): void
    {
        if ($employee->designation_id !== null) {
            $designation = Designation::query()->find($employee->designation_id);
            if ($designation?->department_id !== null && $designation->department_id !== $employee->department_id) {
                throw HrException::invalid(__('Designation :name belongs to another department.', ['name' => $designation->name]), 'invalid_designation');
            }
        }

        $manager = $employee->reports_to_id;
        $seen = [];
        while ($manager !== null) {
            if ($manager === $employee->id || isset($seen[$manager])) {
                throw HrException::invalid(__('An employee cannot report to themselves or to someone who reports to them.'), 'invalid_manager');
            }
            $seen[$manager] = true;
            $manager = Employee::query()->withoutGlobalScopes()->whereKey($manager)->value('reports_to_id');
            $manager = $manager !== null ? (int) $manager : null;
        }
    }

    private function attachUser(Employee $employee, int $userId): void
    {
        if (! Nit::users()->whereKey($userId)->exists()) {
            throw HrException::invalid(__('The user does not exist.'), 'invalid_user');
        }
        if (Employee::query()->withoutGlobalScopes()->withTrashed()->where('user_id', $userId)->whereKeyNot($employee->id)->exists()) {
            throw HrException::invalid(__('The user is already linked to another employee.'), 'user_taken');
        }

        $employee->forceFill(['user_id' => $userId])->save();
        $this->history($employee, 'user_linked', 'user_id', null, (string) $userId, now());
    }

    private function history(Employee $employee, string $event, ?string $field, ?string $old, ?string $new, \DateTimeInterface $date, ?string $notes = null): void
    {
        EmployeeHistory::query()->create([
            'employee_id' => $employee->id,
            'event' => $event,
            'field' => $field,
            'old_value' => $old !== null ? mb_substr($old, 0, 191) : null,
            'new_value' => $new !== null ? mb_substr($new, 0, 191) : null,
            'effective_date' => Carbon::parse($date)->toDateString(),
            'notes' => $notes !== null ? mb_substr($notes, 0, 500) : null,
            'created_by' => auth()->id(),
            'created_at' => now(),
        ]);
    }

    /** "12 Sales" for a record id (kept readable in the history even if the record changes later). */
    private function label(string $model, mixed $id): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }
        $record = $model::query()->withoutGlobalScopes()->find($id);
        $name = $record instanceof Employee ? $record->fullName() : $record?->name;

        return trim($id.' '.($name ?? ''));
    }

    /** @param  array<string, mixed>  $properties */
    private function log(string $event, string $description, Employee $employee, array $properties = []): void
    {
        if (config('hr.log_activity', true)) {
            Activity::log($event, $description, $employee, ['branch_id' => $employee->branch_id] + $properties);
        }
    }
}
