<?php

namespace Nit\AgraniHr\Tests\Feature;

use Nit\AgraniHr\Models\Department;
use Nit\AgraniHr\Models\Employee;
use Nit\AgraniHr\Models\EmployeeStatus;
use Nit\AgraniHr\Tests\TestCase;

class EmployeeApiTest extends TestCase
{
    public function test_an_hr_officer_hires_reads_changes_and_deletes_employees(): void
    {
        $dhaka = $this->branch('Dhaka');
        $officer = $this->inBranches($this->userWith(['Employee::*']), $dhaka);
        $headers = $this->bearer($officer);

        $created = $this->postJson('/api/nit/hr/employees', [
            'branch_id' => $dhaka->id,
            'first_name' => 'Rahim',
            'last_name' => 'Uddin',
            'joining_date' => '2026-01-01',
            'phone' => '01711111111',
            'personal' => ['father_name' => 'Karim Uddin', 'blood_group' => 'O+'],
        ], $headers)->assertCreated()
            ->assertJsonPath('data.employee_code', 'EMP-00001')
            ->assertJsonPath('data.status.code', EmployeeStatus::ACTIVE)
            ->assertJsonPath('data.personal.father_name', 'Karim Uddin')
            ->assertJsonPath('data.branches.0.branch_id', $dhaka->id);
        $uuid = $created->json('data.id');

        $this->getJson('/api/nit/hr/employees?search=Rahim', $headers)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $uuid);
        $this->getJson("/api/nit/hr/employees/{$uuid}", $headers)->assertOk()->assertJsonPath('data.full_name', 'Rahim Uddin');

        $sales = Department::query()->create(['code' => 'SAL', 'name' => 'Sales']);
        $this->putJson("/api/nit/hr/employees/{$uuid}", [
            'branch_id' => $dhaka->id,
            'first_name' => 'Rahim',
            'joining_date' => '2026-01-01',
            'department_id' => $sales->id,
            'effective_date' => '2026-02-01',
            'personal' => ['blood_group' => 'A+'],
        ], $headers)->assertOk()->assertJsonPath('data.department.name', 'Sales')->assertJsonPath('data.personal.blood_group', 'A+');

        $this->getJson("/api/nit/hr/employees/{$uuid}/history", $headers)->assertOk()
            ->assertJsonPath('data.0.event', 'department_changed')
            ->assertJsonPath('data.0.effective_date', '2026-02-01')
            ->assertJsonPath('data.0.created_by', $officer->id);

        $this->deleteJson("/api/nit/hr/employees/{$uuid}", [], $headers)->assertNoContent();
        $this->assertSoftDeleted('hr_employees', ['uuid' => $uuid]);
        $this->getJson("/api/nit/hr/employees/{$uuid}", $headers)->assertNotFound();
    }

    public function test_hiring_is_validated(): void
    {
        $dhaka = $this->branch('Dhaka');
        $other = $this->branch('Sylhet');
        $headers = $this->bearer($this->inBranches($this->userWith(['Employee::*']), $dhaka));

        $this->postJson('/api/nit/hr/employees', [], $headers)->assertUnprocessable()->assertJsonValidationErrors(['branch_id', 'first_name', 'joining_date']);
        $this->postJson('/api/nit/hr/employees', [
            'branch_id' => $other->id,
            'first_name' => 'X',
            'joining_date' => 'not a date',
            'email' => 'no-email',
            'date_of_birth' => now()->addDay()->toDateString(),
            'department_id' => 999,
            'effective_date' => '2026-01-01',
            'personal' => ['personal_email' => 'nope'],
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors(['branch_id', 'joining_date', 'email', 'date_of_birth', 'department_id', 'effective_date', 'personal.personal_email']);

        $this->hire($dhaka, ['employee_code' => 'EMP-00007']);
        $this->postJson('/api/nit/hr/employees', ['branch_id' => $dhaka->id, 'first_name' => 'X', 'joining_date' => '2026-01-01', 'employee_code' => 'EMP-00007'], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors(['employee_code']);
    }

    public function test_service_rules_answer_422_with_a_reason(): void
    {
        $dhaka = $this->branch('Dhaka');
        $headers = $this->bearer($this->inBranches($this->userWith(['Employee::*']), $dhaka));
        $employee = $this->hire($dhaka);

        $this->postJson("/api/nit/hr/employees/{$employee->uuid}/terminate", ['termination_date' => '2025-01-01', 'employee_status_id' => $this->employeeStatus(EmployeeStatus::SEPARATED)->id], $headers)
            ->assertUnprocessable()->assertJsonPath('reason', 'invalid_date');
    }

    public function test_terminate_rehire_and_user_link_through_the_api(): void
    {
        $dhaka = $this->branch('Dhaka');
        $headers = $this->bearer($this->inBranches($this->userWith(['Employee::*']), $dhaka));
        $employee = $this->hire($dhaka);
        $account = $this->userWith([]);

        $this->postJson("/api/nit/hr/employees/{$employee->uuid}/terminate", ['termination_date' => '2026-03-31', 'employee_status_id' => $this->employeeStatus(EmployeeStatus::SEPARATED)->id, 'reason' => 'Contract ended'], $headers)
            ->assertOk()->assertJsonPath('data.termination_date', '2026-03-31')->assertJsonPath('data.status.is_employed', false);
        $this->getJson('/api/nit/hr/employees?employed=1', $headers)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/nit/hr/employees?employed=0', $headers)->assertOk()->assertJsonCount(1, 'data');

        $this->postJson("/api/nit/hr/employees/{$employee->uuid}/rehire", ['rehire_date' => '2026-05-01', 'employee_status_id' => $this->employeeStatus(EmployeeStatus::ACTIVE)->id], $headers)
            ->assertOk()->assertJsonPath('data.termination_date', null);

        $this->postJson("/api/nit/hr/employees/{$employee->uuid}/user", ['user_id' => $account->id], $headers)->assertOk()->assertJsonPath('data.user_id', $account->id);
        $this->deleteJson("/api/nit/hr/employees/{$employee->uuid}/user", [], $headers)->assertOk()->assertJsonPath('data.user_id', null);
    }

    public function test_each_action_needs_its_ability(): void
    {
        $dhaka = $this->branch('Dhaka');
        $employee = $this->hire($dhaka);
        $viewer = $this->bearer($this->inBranches($this->userWith(['Employee::hr_employee_view']), $dhaka));
        $nobody = $this->bearer($this->inBranches($this->userWith([]), $dhaka));

        $this->getJson('/api/nit/hr/employees', $nobody)->assertUnauthorized();
        $this->getJson('/api/nit/hr/employees', $viewer)->assertOk();
        $this->getJson("/api/nit/hr/employees/{$employee->uuid}/history", $viewer)->assertOk();

        $this->postJson('/api/nit/hr/employees', ['branch_id' => $dhaka->id, 'first_name' => 'X', 'joining_date' => '2026-01-01'], $viewer)->assertUnauthorized();
        $this->putJson("/api/nit/hr/employees/{$employee->uuid}", ['branch_id' => $dhaka->id, 'first_name' => 'X', 'joining_date' => '2026-01-01'], $viewer)->assertUnauthorized();
        $this->deleteJson("/api/nit/hr/employees/{$employee->uuid}", [], $viewer)->assertUnauthorized();
        $this->postJson("/api/nit/hr/employees/{$employee->uuid}/terminate", [], $viewer)->assertUnauthorized();
        $this->postJson("/api/nit/hr/employees/{$employee->uuid}/user", [], $viewer)->assertUnauthorized();
        $this->getJson("/api/nit/hr/employees/{$employee->uuid}/documents", $viewer)->assertUnauthorized();
        $this->getJson("/api/nit/hr/employees/{$employee->uuid}/branches", $viewer)->assertUnauthorized();
        $this->getJson('/api/nit/hr/departments', $viewer)->assertUnauthorized();

        $this->getJson('/api/nit/hr/employees')->assertUnauthorized();
        $this->assertSame(1, Employee::query()->count());
    }

    public function test_extra_branches_through_the_api(): void
    {
        $dhaka = $this->branch('Dhaka');
        $ctg = $this->branch('Chattogram', 'CTG');
        $employee = $this->hire($dhaka);
        $headers = $this->bearer($this->inBranches($this->userWith(['EmployeeBranchAssignment::*']), $dhaka, $ctg));

        $id = $this->postJson("/api/nit/hr/employees/{$employee->uuid}/branches", ['branch_id' => $ctg->id, 'start_date' => '2026-02-01'], $headers)
            ->assertCreated()->assertJsonPath('data.is_primary', false)->json('data.id');
        $this->getJson("/api/nit/hr/employees/{$employee->uuid}/branches", $headers)->assertOk()->assertJsonCount(2, 'data');
        $this->postJson("/api/nit/hr/employees/{$employee->uuid}/branches/{$id}/end", ['end_date' => '2026-03-01'], $headers)
            ->assertOk()->assertJsonPath('data.end_date', '2026-03-01');
        $this->postJson("/api/nit/hr/employees/{$employee->uuid}/branches", ['branch_id' => $ctg->id, 'start_date' => '2026-01-01', 'end_date' => '2025-01-01'], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors(['end_date']);
    }
}
