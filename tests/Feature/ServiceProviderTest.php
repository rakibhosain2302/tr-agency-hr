<?php

namespace Nit\AgraniHr\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Nit\AgraniHr\Contracts\EmployeeCodeGenerator;
use Nit\AgraniHr\Contracts\HrAccounting;
use Nit\AgraniHr\Models\Employee;
use Nit\AgraniHr\Models\EmployeeStatus;
use Nit\AgraniHr\Models\SalaryComponent;
use Nit\AgraniHr\Services\EmployeeService;
use Nit\AgraniHr\Support\DatabaseEmployeeCodeGenerator;
use Nit\AgraniHr\Support\NullHrAccounting;
use Nit\AgraniHr\Tests\TestCase;
use Nit\Master\Support\RoleSync;
use Orchestra\Testbench\Attributes\DefineEnvironment;

class ServiceProviderTest extends TestCase
{
    public function test_the_package_registers_its_config_routes_and_numbering(): void
    {
        $this->assertSame('EMP-{number}', config('hr.employee_code.format'));
        $this->assertInstanceOf(DatabaseEmployeeCodeGenerator::class, app(EmployeeCodeGenerator::class));
        $this->assertTrue(Route::has('hr.api.employees.index'));
        $this->assertSame('api/nit/hr/employees', Route::getRoutes()->getByName('hr.api.employees.index')->uri());
    }

    public function test_the_abilities_are_known_to_nit_master(): void
    {
        [$keys, $unknown] = RoleSync::expand([
            'Employee::*', 'EmployeeBranchAssignment::*', 'EmployeeDocument::*',
            'Department::*', 'Designation::*', 'EmploymentType::*', 'EmployeeStatus::*',
            'Attendance::*', 'AttendanceCorrection::*', 'LeaveType::*', 'LeaveApplication::*', 'LeaveBalance::*',
            'SalaryComponent::*', 'SalaryStructure::*', 'PayrollPeriod::*', 'Payroll::*', 'EmployeeAdvance::*',
        ]);

        $this->assertSame([], $unknown);
        foreach (['hr_employee_view', 'hr_employee_create', 'hr_employee_edit', 'hr_employee_delete', 'hr_employee_terminate', 'hr_employee_link_user', 'hr_employee_branch_manage', 'hr_employee_document_manage', 'hr_department_manage', 'hr_employee_status_manage', 'hr_attendance_self', 'hr_attendance_correction_approve', 'hr_leave_approve', 'hr_leave_balance_manage', 'hr_salary_manage', 'hr_payroll_process', 'hr_payroll_approve', 'hr_payroll_pay', 'hr_payroll_reverse', 'hr_advance_approve', 'hr_advance_disburse', 'hr_advance_settle'] as $ability) {
            $this->assertContains($ability, array_map(fn ($key) => last(explode('::', (string) $key)), $keys), $ability);
        }
    }

    public function test_install_is_safe_to_run_again(): void
    {
        $this->artisan('hr:install', ['--no-migrate' => true])->assertSuccessful();
        $this->artisan('hr:install', ['--no-migrate' => true])->assertSuccessful();

        $this->assertSame(['active', 'separated'], EmployeeStatus::query()->orderBy('code')->pluck('code')->all());
        $this->assertSame(['basic'], SalaryComponent::query()->pluck('code')->all());
        $this->assertTrue(EmployeeStatus::query()->where('is_system', true)->count() === 2);
    }

    public function test_a_host_application_replaces_the_employee_numbering(): void
    {
        $this->app->bind(EmployeeCodeGenerator::class, fn () => new class implements EmployeeCodeGenerator
        {
            public function next(Employee $employee): string
            {
                return 'AGR-'.$employee->branch_id.'-1';
            }
        });
        $this->app->forgetInstance(EmployeeService::class);

        $dhaka = $this->branch('Dhaka');

        $this->assertSame("AGR-{$dhaka->id}-1", $this->hire($dhaka)->employee_code);
    }

    public function test_without_a_host_connection_hr_posts_nothing(): void
    {
        $this->assertInstanceOf(NullHrAccounting::class, app(HrAccounting::class));
        $this->assertFalse(app(HrAccounting::class)->connected());
    }

    #[DefineEnvironment('switchApiOff')]
    public function test_the_api_can_be_switched_off(): void
    {
        $this->assertFalse(Route::has('hr.api.employees.index'));
    }

    protected function switchApiOff($app): void
    {
        $app['config']->set('hr.api.enabled', false);
    }
}
