<?php

namespace Nit\AgraniHr\Tests\Feature;

use Nit\AgraniHr\Models\Department;
use Nit\AgraniHr\Models\EmployeeStatus;
use Nit\AgraniHr\Tests\TestCase;

class MasterDataApiTest extends TestCase
{
    public function test_departments_and_designations_are_managed_and_kept_while_in_use(): void
    {
        $dhaka = $this->branch('Dhaka');
        $headers = $this->bearer($this->inBranches($this->userWith(['Department::*', 'Designation::*']), $dhaka));

        $sales = $this->postJson('/api/nit/hr/departments', ['code' => 'SAL', 'name' => 'Sales'], $headers)->assertCreated()->json('data.id');
        $retail = $this->postJson('/api/nit/hr/departments', ['code' => 'RET', 'name' => 'Retail', 'parent_id' => $sales], $headers)->assertCreated()->json('data.id');
        $this->postJson('/api/nit/hr/departments', ['code' => 'SAL', 'name' => 'Again'], $headers)->assertUnprocessable()->assertJsonValidationErrors(['code']);
        $this->putJson("/api/nit/hr/departments/{$sales}", ['code' => 'SAL', 'name' => 'Sales', 'parent_id' => $retail], $headers)->assertUnprocessable()->assertJsonPath('reason', 'invalid_parent');

        $officer = $this->postJson('/api/nit/hr/designations', ['department_id' => $retail, 'code' => 'RO', 'name' => 'Retail officer'], $headers)->assertCreated()->json('data.id');
        $this->getJson('/api/nit/hr/designations', $headers)->assertOk()->assertJsonCount(1, 'data');

        $this->hire($dhaka, ['department_id' => $retail, 'designation_id' => $officer]);
        $this->deleteJson("/api/nit/hr/departments/{$retail}", [], $headers)->assertUnprocessable()->assertJsonPath('reason', 'in_use');
        $this->deleteJson("/api/nit/hr/designations/{$officer}", [], $headers)->assertUnprocessable()->assertJsonPath('reason', 'in_use');
        $this->putJson("/api/nit/hr/departments/{$retail}", ['code' => 'RET', 'name' => 'Retail', 'parent_id' => $sales, 'is_active' => false], $headers)->assertOk()->assertJsonPath('data.is_active', false);

        $empty = Department::query()->create(['code' => 'TMP', 'name' => 'Temporary']);
        $this->deleteJson("/api/nit/hr/departments/{$empty->id}", [], $headers)->assertNoContent();
    }

    public function test_employment_types_and_statuses_keep_the_system_statuses(): void
    {
        $headers = $this->bearer($this->userWith(['EmploymentType::*', 'EmployeeStatus::*']));
        $active = $this->employeeStatus(EmployeeStatus::ACTIVE);

        $this->postJson('/api/nit/hr/employment-types', ['code' => 'contract', 'name' => 'Contract'], $headers)->assertCreated();
        $this->getJson('/api/nit/hr/employment-types', $headers)->assertOk()->assertJsonPath('data.0.code', 'contract');

        $this->postJson('/api/nit/hr/employee-statuses', ['code' => 'probation', 'name' => 'On probation', 'is_employed' => true], $headers)->assertCreated()->assertJsonPath('data.is_system', false);
        $this->getJson('/api/nit/hr/employee-statuses', $headers)->assertOk()->assertJsonCount(3, 'data');

        $this->putJson("/api/nit/hr/employee-statuses/{$active->id}", ['code' => 'active', 'name' => 'Working', 'is_employed' => true], $headers)->assertOk()->assertJsonPath('data.name', 'Working');
        $this->putJson("/api/nit/hr/employee-statuses/{$active->id}", ['code' => 'active', 'name' => 'Working', 'is_employed' => false], $headers)->assertUnprocessable()->assertJsonPath('reason', 'system_status');
        $this->deleteJson("/api/nit/hr/employee-statuses/{$active->id}", [], $headers)->assertUnprocessable()->assertJsonPath('reason', 'in_use');
    }

    public function test_viewing_does_not_allow_managing(): void
    {
        $headers = $this->bearer($this->userWith(['Department::hr_department_view']));

        $this->getJson('/api/nit/hr/departments', $headers)->assertOk();
        $this->postJson('/api/nit/hr/departments', ['code' => 'X', 'name' => 'X'], $headers)->assertUnauthorized();
    }
}
