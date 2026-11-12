<?php

namespace Nit\AgraniHr\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Nit\AgraniHr\Events\EmployeeHired;
use Nit\AgraniHr\Events\EmployeeTerminated;
use Nit\AgraniHr\Events\EmployeeTransferred;
use Nit\AgraniHr\Exceptions\HrException;
use Nit\AgraniHr\Models\Department;
use Nit\AgraniHr\Models\Designation;
use Nit\AgraniHr\Models\Employee;
use Nit\AgraniHr\Models\EmployeeStatus;
use Nit\AgraniHr\Models\EmploymentType;
use Nit\AgraniHr\Services\EmployeeService;
use Nit\AgraniHr\Tests\TestCase;

class EmployeeLifecycleTest extends TestCase
{
    private function service(): EmployeeService
    {
        return app(EmployeeService::class);
    }

    public function test_hiring_numbers_the_employee_and_records_the_start(): void
    {
        Event::fake([EmployeeHired::class]);
        $dhaka = $this->branch('Dhaka');

        $first = $this->hire($dhaka, [], ['father_name' => 'Karim Uddin', 'blood_group' => 'B+']);
        $second = $this->hire($dhaka, ['first_name' => 'Salma']);

        $this->assertSame('EMP-00001', $first->employee_code);
        $this->assertSame('EMP-00002', $second->employee_code);
        $this->assertSame(EmployeeStatus::ACTIVE, $first->status->code);
        $this->assertSame('Karim Uddin', $first->personalInfo->father_name);
        $this->assertSame([[$dhaka->id, '2026-01-01', null, true]], $first->branchAssignments->map(fn ($a) => [$a->branch_id, $a->start_date->toDateString(), $a->end_date, $a->is_primary])->all());
        $this->assertSame(['joined'], $first->history()->pluck('event')->all());
        Event::assertDispatchedTimes(EmployeeHired::class, 2);
    }

    public function test_a_given_employee_code_is_kept_and_numbering_continues_after_it(): void
    {
        $dhaka = $this->branch('Dhaka');

        $this->hire($dhaka, ['employee_code' => 'EMP-00041']);

        $this->assertSame('EMP-00042', $this->hire($dhaka)->employee_code);
    }

    public function test_a_new_employee_needs_an_employed_status(): void
    {
        $this->assertRefused('invalid_status', fn () => $this->hire($this->branch('Dhaka'), ['employee_status_id' => $this->employeeStatus(EmployeeStatus::SEPARATED)->id]));
    }

    public function test_changes_to_the_organisation_are_kept_in_the_history(): void
    {
        $employee = $this->hire($this->branch('Dhaka'));
        $sales = Department::query()->create(['code' => 'SAL', 'name' => 'Sales']);
        $officer = Designation::query()->create(['department_id' => $sales->id, 'code' => 'SO', 'name' => 'Sales officer']);
        $permanent = EmploymentType::query()->create(['code' => 'PERM', 'name' => 'Permanent']);

        $this->service()->update($employee, ['department_id' => $sales->id, 'designation_id' => $officer->id, 'employment_type_id' => $permanent->id, 'phone' => '01700000000'], null, '2026-03-01');

        $history = $employee->history()->where('event', '<>', 'joined')->get()->keyBy('event');
        $this->assertSame(['department_changed', 'designation_changed', 'employment_type_changed'], $history->keys()->sort()->values()->all());
        $this->assertSame("{$sales->id} Sales", $history['department_changed']->new_value);
        $this->assertNull($history['department_changed']->old_value);
        $this->assertSame('2026-03-01', $history['designation_changed']->effective_date->toDateString());
        $this->assertSame('01700000000', $employee->fresh()->phone);
    }

    public function test_a_designation_of_another_department_is_refused(): void
    {
        $employee = $this->hire($this->branch('Dhaka'));
        $sales = Department::query()->create(['code' => 'SAL', 'name' => 'Sales']);
        $accounts = Department::query()->create(['code' => 'ACC', 'name' => 'Accounts']);
        $officer = Designation::query()->create(['department_id' => $sales->id, 'code' => 'SO', 'name' => 'Sales officer']);

        $this->assertRefused('invalid_designation', fn () => $this->service()->update($employee, ['department_id' => $accounts->id, 'designation_id' => $officer->id]));
    }

    public function test_reporting_lines_cannot_loop(): void
    {
        $dhaka = $this->branch('Dhaka');
        $boss = $this->hire($dhaka);
        $staff = $this->hire($dhaka, ['reports_to_id' => $boss->id]);

        $this->assertRefused('invalid_manager', fn () => $this->service()->update($boss, ['reports_to_id' => $staff->id]));
    }

    public function test_a_transfer_moves_the_main_branch_and_keeps_the_old_assignment(): void
    {
        Event::fake([EmployeeTransferred::class]);
        $dhaka = $this->branch('Dhaka');
        $ctg = $this->branch('Chattogram', 'CTG');
        $employee = $this->hire($dhaka);

        $this->service()->update($employee, ['branch_id' => $ctg->id], null, '2026-06-01');

        $assignments = $employee->branchAssignments()->reorder('start_date')->get()->map(fn ($a) => [$a->branch_id, $a->start_date->toDateString(), $a->end_date?->toDateString(), $a->is_primary])->all();
        $this->assertSame([[$dhaka->id, '2026-01-01', '2026-05-31', true], [$ctg->id, '2026-06-01', null, true]], $assignments);
        $this->assertSame($ctg->id, $employee->fresh()->branch_id);
        $transfer = $employee->history()->where('event', 'transferred')->sole();
        $this->assertSame([(string) $dhaka->id, (string) $ctg->id], [$transfer->old_value, $transfer->new_value]);
        Event::assertDispatched(EmployeeTransferred::class);
    }

    public function test_a_transfer_effective_from_the_joining_day_corrects_the_main_branch(): void
    {
        $dhaka = $this->branch('Dhaka');
        $ctg = $this->branch('Chattogram', 'CTG');
        $employee = $this->hire($dhaka);

        $this->service()->update($employee, ['branch_id' => $ctg->id], null, '2026-01-01');

        $this->assertSame([[$ctg->id, null, true]], $employee->branchAssignments()->get()->map(fn ($a) => [$a->branch_id, $a->end_date, $a->is_primary])->all());
    }

    public function test_an_employee_works_in_more_branches_and_the_extra_ones_end(): void
    {
        $dhaka = $this->branch('Dhaka');
        $ctg = $this->branch('Chattogram', 'CTG');
        $employee = $this->hire($dhaka);

        $extra = $this->service()->assignBranch($employee, $ctg->id, '2026-02-01', null, 'Covers Chattogram');

        $this->assertFalse($extra->is_primary);
        $this->assertSame([$dhaka->id, $ctg->id], $employee->branchAssignments()->current()->reorder('id')->pluck('branch_id')->all());

        try {
            $this->service()->assignBranch($employee, $ctg->id, '2026-03-01');
            $this->fail('A second current assignment to the same branch was accepted.');
        } catch (HrException $e) {
            $this->assertSame('already_assigned', $e->reason);
        }

        $this->service()->endAssignment($extra, now()->subDay()->toDateString());
        $this->assertSame([$dhaka->id], $employee->branchAssignments()->current()->pluck('branch_id')->all());

        $primary = $employee->branchAssignments()->where('is_primary', true)->sole();
        $this->assertRefused('primary_assignment', fn () => $this->service()->endAssignment($primary, now()->toDateString()));
    }

    public function test_termination_ends_the_employment_and_rehire_starts_it_again(): void
    {
        Event::fake([EmployeeTerminated::class, EmployeeHired::class]);
        $dhaka = $this->branch('Dhaka');
        $ctg = $this->branch('Chattogram', 'CTG');
        $employee = $this->hire($dhaka);
        $this->service()->assignBranch($employee, $ctg->id, '2026-02-01');
        $separated = $this->employeeStatus(EmployeeStatus::SEPARATED);

        $left = $this->service()->terminate($employee, '2026-04-30', $separated->id, 'Resigned');

        $this->assertTrue($left->isTerminated());
        $this->assertSame('Resigned', $left->termination_reason);
        $this->assertSame(0, $employee->branchAssignments()->whereNull('end_date')->count());
        $this->assertSame(0, Employee::query()->employed()->count());
        Event::assertDispatched(EmployeeTerminated::class);

        foreach ([
            fn () => $this->service()->terminate($left, '2026-05-01', $separated->id),
            fn () => $this->service()->update($left, ['employee_status_id' => $this->employeeStatus(EmployeeStatus::ACTIVE)->id]),
            fn () => $this->service()->rehire($left, '2026-04-30', $this->employeeStatus(EmployeeStatus::ACTIVE)->id),
        ] as $refused) {
            try {
                $refused();
                $this->fail('A change to a terminated employee was accepted.');
            } catch (HrException) {
                $this->addToAssertionCount(1);
            }
        }

        $back = $this->service()->rehire($left, '2026-08-01', $this->employeeStatus(EmployeeStatus::ACTIVE)->id, $ctg->id);

        $this->assertFalse($back->isTerminated());
        $this->assertSame($ctg->id, $back->branch_id);
        $this->assertSame([[$ctg->id, '2026-08-01']], $back->branchAssignments()->current()->get()->map(fn ($a) => [$a->branch_id, $a->start_date->toDateString()])->all());
        $this->assertSame(['joined', 'branch_assigned', 'terminated', 'rehired'], $back->history()->reorder('id')->pluck('event')->all());
    }

    public function test_an_employee_cannot_leave_before_joining_or_in_an_employed_status(): void
    {
        $employee = $this->hire($this->branch('Dhaka'));

        foreach ([['2025-12-31', EmployeeStatus::SEPARATED, 'invalid_date'], ['2026-05-01', EmployeeStatus::ACTIVE, 'invalid_status']] as [$date, $status, $reason]) {
            try {
                $this->service()->terminate($employee, $date, $this->employeeStatus($status)->id);
                $this->fail("Termination on {$date} in {$status} was accepted.");
            } catch (HrException $e) {
                $this->assertSame($reason, $e->reason);
            }
        }
    }
}
