<?php

namespace Nit\AgraniHr\Tests\Feature;

use Nit\AgraniHr\Models\Employee;
use Nit\AgraniHr\Services\EmployeeService;
use Nit\AgraniHr\Tests\TestCase;

class BranchIsolationTest extends TestCase
{
    public function test_users_see_only_employees_of_their_branches(): void
    {
        $dhaka = $this->branch('Dhaka');
        $ctg = $this->branch('Chattogram', 'CTG');
        $inDhaka = $this->hire($dhaka, ['first_name' => 'Dhaka']);
        $inCtg = $this->hire($ctg, ['first_name' => 'Ctg']);
        $headers = $this->bearer($this->inBranches($this->userWith(['Employee::*']), $dhaka));

        $this->getJson('/api/nit/hr/employees', $headers)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $inDhaka->uuid);
        $this->getJson("/api/nit/hr/employees/{$inCtg->uuid}", $headers)->assertNotFound();
        $this->putJson("/api/nit/hr/employees/{$inCtg->uuid}", ['branch_id' => $dhaka->id, 'first_name' => 'X', 'joining_date' => '2026-01-01'], $headers)->assertNotFound();
        $this->deleteJson("/api/nit/hr/employees/{$inCtg->uuid}", [], $headers)->assertNotFound();
        $this->postJson('/api/nit/hr/employees', ['branch_id' => $ctg->id, 'first_name' => 'X', 'joining_date' => '2026-01-01'], $headers)->assertUnprocessable()->assertJsonValidationErrors(['branch_id']);

        $this->getJson('/api/nit/hr/employees', $this->bearer($this->superAdmin()))->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_a_user_without_branches_sees_no_employees(): void
    {
        $this->hire($this->branch('Dhaka'));

        $this->getJson('/api/nit/hr/employees', $this->bearer($this->userWith(['Employee::*'])))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_an_employee_assigned_to_another_branch_is_seen_there_while_the_assignment_lasts(): void
    {
        $dhaka = $this->branch('Dhaka');
        $ctg = $this->branch('Chattogram', 'CTG');
        $employee = $this->hire($dhaka);
        $assignment = app(EmployeeService::class)->assignBranch($employee, $ctg->id, '2026-02-01');
        $ctgUser = $this->inBranches($this->userWith(['Employee::*']), $ctg);
        $headers = $this->bearer($ctgUser);

        $this->getJson("/api/nit/hr/employees/{$employee->uuid}", $headers)->assertOk();
        // Changes to the employee belong to the main branch.
        $this->putJson("/api/nit/hr/employees/{$employee->uuid}", ['branch_id' => $ctg->id, 'first_name' => 'X', 'joining_date' => '2026-01-01'], $headers)->assertForbidden();
        $this->assertSame($dhaka->id, Employee::query()->withoutGlobalScopes()->whereKey($employee->id)->value('branch_id'));

        app(EmployeeService::class)->endAssignment($assignment, now()->subDay()->toDateString());

        $this->getJson("/api/nit/hr/employees/{$employee->uuid}", $headers)->assertNotFound();
    }

    public function test_a_transfer_moves_the_employee_out_of_sight_of_the_old_branch(): void
    {
        $dhaka = $this->branch('Dhaka');
        $ctg = $this->branch('Chattogram', 'CTG');
        $employee = $this->hire($dhaka);
        $both = $this->bearer($this->inBranches($this->userWith(['Employee::*']), $dhaka, $ctg));

        $this->putJson("/api/nit/hr/employees/{$employee->uuid}", ['branch_id' => $ctg->id, 'first_name' => 'Rahim', 'joining_date' => '2026-01-01', 'effective_date' => now()->subDays(3)->toDateString()], $both)
            ->assertOk()->assertJsonPath('data.branch_id', $ctg->id);

        $this->getJson("/api/nit/hr/employees/{$employee->uuid}", $this->bearer($this->inBranches($this->userWith(['Employee::*']), $dhaka)))->assertNotFound();
        $this->getJson("/api/nit/hr/employees/{$employee->uuid}", $this->bearer($this->inBranches($this->userWith(['Employee::*']), $ctg)))->assertOk();
    }
}
