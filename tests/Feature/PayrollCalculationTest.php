<?php

namespace Nit\AgraniHr\Tests\Feature;

use Nit\AgraniHr\Models\Attendance;
use Nit\AgraniHr\Models\Employee;
use Nit\AgraniHr\Models\LeaveType;
use Nit\AgraniHr\Services\AttendanceService;
use Nit\AgraniHr\Services\EmployeeAdvanceService;
use Nit\AgraniHr\Services\EmployeeService;
use Nit\AgraniHr\Services\LeaveService;
use Nit\AgraniHr\Services\PayrollCalculator;
use Nit\AgraniHr\Tests\TestCase;
use Nit\Master\Models\Branch;

/**
 * Salary calculation for July 2026 (31 days; Friday is the weekend). The standard salary: basic 20,000,
 * house rent 50 % of basic, medical 1,500, provident fund 10 % of basic.
 */
class PayrollCalculationTest extends TestCase
{
    private Branch $dhaka;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dhaka = $this->branch('Dhaka');
        $this->actingAs($this->superAdmin());
    }

    private function calculate(Employee $employee, string $month = '2026-07'): array
    {
        return app(PayrollCalculator::class)->calculate($employee->fresh(), $this->period($month));
    }

    /** @return array<string, string> code => amount */
    private function amounts(array $result): array
    {
        return collect($result['lines'])->mapWithKeys(fn (array $line) => [$line['code'] => $line['amount']])->all();
    }

    public function test_a_whole_month_is_exactly_the_monthly_salary(): void
    {
        $employee = $this->hire($this->dhaka);
        $this->salary($employee, '2026-01-01', '20000');

        $result = $this->calculate($employee);

        $this->assertSame(['basic' => '20000.00', 'house_rent' => '10000.00', 'medical' => '1500.00', 'provident_fund' => '2000.00'], $this->amounts($result));
        $this->assertSame(['31500.00', '2000.00', '29500.00'], [$result['gross'], $result['deductions'], $result['net']]);
        $this->assertSame('31.00', $result['payable_days']);
        $this->assertSame(31, $result['period_days']);
    }

    public function test_joining_in_the_month_prorates_by_calendar_days(): void
    {
        $employee = $this->hire($this->dhaka, ['joining_date' => '2026-07-11']);
        $this->salary($employee, '2026-07-11', '20000');

        $result = $this->calculate($employee);

        // 21 of 31 days: 20000 × 21 / 31 = 13548.387…
        $this->assertSame(['basic' => '13548.39', 'house_rent' => '6774.19', 'medical' => '1016.13', 'provident_fund' => '1354.84'], $this->amounts($result));
        $this->assertSame(['21338.71', '1354.84', '19983.87'], [$result['gross'], $result['deductions'], $result['net']]);
        $this->assertSame('21.00', $result['payable_days']);
    }

    public function test_leaving_in_the_month_pays_up_to_the_last_day(): void
    {
        $employee = $this->hire($this->dhaka);
        $this->salary($employee, '2026-01-01', '31000', '0', '0', '0');
        app(EmployeeService::class)->terminate($employee, '2026-07-10', $this->employeeStatus('separated')->id);

        $this->assertSame(['basic' => '10000.00'], $this->amounts($this->calculate($employee)));
    }

    public function test_a_raise_in_the_month_prorates_each_salary_by_its_days(): void
    {
        $employee = $this->hire($this->dhaka);
        $this->salary($employee, '2026-01-01', '20000');
        $this->salary($employee, '2026-07-16', '30000');

        $result = $this->calculate($employee);

        // 15 days at 20,000 and 16 days at 30,000.
        $this->assertSame(['basic' => '25161.29', 'house_rent' => '12580.65', 'medical' => '1500.00', 'provident_fund' => '2516.13'], $this->amounts($result));
        $this->assertSame(['39241.94', '2516.13', '36725.81'], [$result['gross'], $result['deductions'], $result['net']]);
        $this->assertSame('2026-07-15', $employee->salaryStructures()->get()->last()->effective_to->toDateString());
    }

    public function test_unpaid_leave_absence_and_overtime(): void
    {
        $employee = $this->hire($this->dhaka);
        $this->salary($employee, '2026-01-01', '20000');
        $unpaid = LeaveType::query()->create(['code' => 'unpaid', 'name' => 'Unpaid leave', 'is_paid' => false]);
        $leave = app(LeaveService::class);
        $leave->approve($leave->apply($employee, $unpaid, '2026-07-06', '2026-07-07'));
        $attendance = app(AttendanceService::class);
        $attendance->record($employee, '2026-07-08', ['status' => Attendance::ABSENT]);
        $attendance->record($employee, '2026-07-09', ['check_in_at' => '09:00', 'check_out_at' => '21:00']);

        $result = $this->calculate($employee);

        // A day = 31,500 / 31 = 1016.129…; an overtime hour = 20,000 / 208 × 2 = 192.307…; 3 hours.
        $this->assertSame(['basic' => '20000.00', 'house_rent' => '10000.00', 'medical' => '1500.00', 'overtime' => '576.92', 'provident_fund' => '2000.00', 'unpaid_leave' => '2032.26', 'absence' => '1016.13'], $this->amounts($result));
        $this->assertSame(['32076.92', '5048.39', '27028.53'], [$result['gross'], $result['deductions'], $result['net']]);
        $this->assertSame(['2.0', '1.0', 180, '28.00'], [$result['unpaid_leave_days'], $result['absent_days'], $result['overtime_minutes'], $result['payable_days']]);
    }

    public function test_a_fixed_day_basis_and_a_basic_deduction_base(): void
    {
        config(['hr.payroll.day_basis' => 30, 'hr.payroll.unpaid_deduction_base' => 'basic']);
        $employee = $this->hire($this->dhaka);
        $this->salary($employee, '2026-01-01', '20000');
        app(AttendanceService::class)->record($employee, '2026-07-08', ['status' => Attendance::ABSENT]);
        app(AttendanceService::class)->record($employee, '2026-07-13', ['status' => Attendance::HALF_DAY, 'check_in_at' => '09:00', 'check_out_at' => '13:00']);

        // 1.5 days × 20,000 / 30.
        $this->assertSame('1000.00', $this->amounts($this->calculate($employee))['absence']);
    }

    public function test_an_absence_on_a_day_of_paid_leave_costs_nothing(): void
    {
        $employee = $this->hire($this->dhaka);
        $this->salary($employee, '2026-01-01', '20000');
        $casual = LeaveType::query()->create(['code' => 'casual', 'name' => 'Casual', 'days_per_year' => 10]);
        $leave = app(LeaveService::class);
        $leave->approve($leave->apply($employee, $casual, '2026-07-08', '2026-07-08'));
        app(AttendanceService::class)->record($employee, '2026-07-08', ['status' => Attendance::ABSENT]);

        $result = $this->calculate($employee);

        $this->assertArrayNotHasKey('absence', $this->amounts($result));
        $this->assertSame('29500.00', $result['net']);
    }

    public function test_advances_are_recovered_by_instalment_and_at_most_what_is_left(): void
    {
        $employee = $this->hire($this->dhaka);
        $this->salary($employee, '2026-01-01', '10000', '0', '0', '0');
        $this->accounting();
        $advances = app(EmployeeAdvanceService::class);
        $first = $advances->request($employee, '5000', '2026-06-01', '2000');
        $advances->approve($first);
        $advances->disburse($first, '2026-06-02', 'CASH');
        $second = $advances->request($employee, '9000', '2026-06-05');
        $advances->approve($second);
        $advances->disburse($second, '2026-06-05', 'CASH');

        $result = $this->calculate($employee);

        $recoveries = collect($result['lines'])->where('source', 'advance')->map(fn ($line) => [$line['employee_advance_id'], $line['amount']])->values()->all();
        $this->assertSame([[$first->id, '2000.00'], [$second->id, '8000.00']], $recoveries);
        $this->assertSame('0.00', $result['net']);
    }

    public function test_employees_without_salary_or_with_too_many_deductions_are_skipped(): void
    {
        $employee = $this->hire($this->dhaka);
        $this->assertRefused('no_salary_structure', fn () => $this->calculate($employee));

        $this->salary($employee, '2026-01-01', '1000', '0', '0', '200');
        $this->assertRefused('deductions_exceed_earnings', fn () => $this->calculate($employee));

        $later = $this->hire($this->dhaka, ['joining_date' => '2026-08-01']);
        $this->assertRefused('not_employed', fn () => $this->calculate($later));
    }
}
