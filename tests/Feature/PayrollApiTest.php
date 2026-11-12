<?php

namespace Nit\AgraniHr\Tests\Feature;

use Nit\AgraniHr\Models\Payroll;
use Nit\AgraniHr\Services\PayrollService;
use Nit\AgraniHr\Tests\Fixtures\RecordingHrAccounting;
use Nit\AgraniHr\Tests\TestCase;
use Nit\Master\Models\Branch;

class PayrollApiTest extends TestCase
{
    private Branch $dhaka;

    private RecordingHrAccounting $books;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dhaka = $this->branch('Dhaka');
        $this->books = $this->accounting();
    }

    private function staffed(): void
    {
        $employee = $this->hire($this->dhaka);
        $this->salary($employee, '2026-01-01', '20000');
    }

    public function test_salaries_periods_and_payroll_from_draft_to_paid(): void
    {
        $employee = $this->hire($this->dhaka);
        $clerk = $this->bearer($this->inBranches($this->userWith(['SalaryComponent::*', 'SalaryStructure::*', 'PayrollPeriod::*', 'Payroll::hr_payroll_view', 'Payroll::hr_payroll_process']), $this->dhaka));
        $manager = $this->bearer($this->inBranches($this->userWith(['Payroll::*']), $this->dhaka));

        $house = $this->postJson('/api/nit/hr/salary-components', ['code' => 'house_rent', 'name' => 'House rent', 'kind' => 'earning', 'calculation' => 'percent_of_basic', 'posting_key' => 'salary_expense'], $clerk)->assertCreated()->json('data.id');
        $basic = $this->salaryComponent('basic')->id;
        $this->postJson("/api/nit/hr/employees/{$employee->uuid}/salaries", ['effective_from' => '2026-01-01', 'lines' => [['salary_component_id' => $basic, 'amount' => '20000'], ['salary_component_id' => $house, 'percent' => '50']]], $clerk)
            ->assertCreated()->assertJsonPath('data.lines.1.monthly', '10000.00');
        $period = $this->postJson('/api/nit/hr/payroll-periods', ['code' => '2026-07', 'name' => 'July 2026', 'start_date' => '2026-07-01', 'end_date' => '2026-07-31'], $clerk)->assertCreated()->json('data.id');
        $this->postJson('/api/nit/hr/payroll-periods', ['code' => 'X', 'name' => 'Overlap', 'start_date' => '2026-07-15', 'end_date' => '2026-08-14'], $clerk)->assertUnprocessable()->assertJsonPath('reason', 'period_overlap');

        $uuid = $this->postJson('/api/nit/hr/payrolls', ['payroll_period_id' => $period, 'branch_id' => $this->dhaka->id], $clerk)
            ->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.net_total', '30000.00')->json('data.id');
        $this->postJson("/api/nit/hr/payrolls/{$uuid}/approve", [], $clerk)->assertUnauthorized();

        $this->postJson("/api/nit/hr/payrolls/{$uuid}/approve", [], $manager)->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.accounting_reference', 'JE-1');
        $this->postJson("/api/nit/hr/payrolls/{$uuid}/approve", [], $manager)->assertUnprocessable()->assertJsonPath('reason', 'invalid_status');
        $this->postJson("/api/nit/hr/payrolls/{$uuid}/pay", ['payment_date' => '2026-08-01', 'settlement_account' => 'BANK'], $manager)
            ->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.payment.accounting_reference', 'JE-2');

        $payslip = $this->getJson("/api/nit/hr/payrolls/{$uuid}", $clerk)->assertOk()->assertJsonCount(1, 'data.payslips')->json('data.payslips.0');
        $this->assertSame(['30000.00', '0.00', '30000.00'], [$payslip['gross'], $payslip['total_deductions'], $payslip['net']]);
        $this->getJson("/api/nit/hr/payrolls/{$uuid}/payslips/{$payslip['id']}", $clerk)->assertOk()->assertJsonPath('data.earnings.0.code', 'basic');
        $this->get("/api/nit/hr/payrolls/{$uuid}/payslips/{$payslip['id']}?format=pdf", $clerk)->assertOk();

        $this->postJson("/api/nit/hr/payrolls/{$uuid}/reverse", [], $manager)->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->postJson("/api/nit/hr/payrolls/{$uuid}/reverse", ['reason' => 'Wrong salary', 'date' => '2026-08-03'], $manager)->assertOk()->assertJsonPath('data.status', 'reversed');
        $this->assertCount(2, $this->books->reversals);
    }

    public function test_payrolls_are_seen_and_made_only_for_the_users_branches(): void
    {
        $this->staffed();
        $ctg = $this->branch('Chattogram', 'CTG');
        $period = $this->period();
        $dhakaUser = $this->bearer($this->inBranches($this->userWith(['Payroll::*']), $this->dhaka));
        $ctgUser = $this->bearer($this->inBranches($this->userWith(['Payroll::*']), $ctg));

        $uuid = $this->postJson('/api/nit/hr/payrolls', ['payroll_period_id' => $period->id, 'branch_id' => $this->dhaka->id], $dhakaUser)->assertCreated()->json('data.id');

        $this->getJson('/api/nit/hr/payrolls', $ctgUser)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/nit/hr/payrolls/{$uuid}", $ctgUser)->assertNotFound();
        $this->postJson("/api/nit/hr/payrolls/{$uuid}/approve", [], $ctgUser)->assertNotFound();
        $this->postJson('/api/nit/hr/payrolls', ['payroll_period_id' => $period->id, 'branch_id' => $this->dhaka->id], $ctgUser)->assertUnprocessable()->assertJsonValidationErrors(['branch_id']);
        $this->assertSame(1, Payroll::query()->withoutGlobalScopes()->count());
    }

    public function test_each_payroll_step_needs_its_ability(): void
    {
        $this->staffed();
        $period = $this->period();
        $viewer = $this->bearer($this->inBranches($this->userWith(['Payroll::hr_payroll_view']), $this->dhaka));
        $payroll = app(PayrollService::class);
        $this->actingAs($this->superAdmin());
        $draft = $payroll->process($payroll->create($period, $this->dhaka->id));
        auth()->logout();

        $this->getJson("/api/nit/hr/payrolls/{$draft->uuid}", $viewer)->assertOk();
        foreach (['process', 'approve', 'pay', 'reverse-payment', 'reverse'] as $step) {
            $this->postJson("/api/nit/hr/payrolls/{$draft->uuid}/{$step}", [], $viewer)->assertUnauthorized();
        }
        $this->deleteJson("/api/nit/hr/payrolls/{$draft->uuid}", [], $viewer)->assertUnauthorized();
        $this->postJson('/api/nit/hr/payroll-periods', [], $viewer)->assertUnauthorized();
        $this->getJson('/api/nit/hr/salary-components', $viewer)->assertUnauthorized();
    }

    public function test_the_basic_component_is_protected(): void
    {
        $headers = $this->bearer($this->userWith(['SalaryComponent::*']));
        $basic = $this->salaryComponent('basic');

        $this->putJson("/api/nit/hr/salary-components/{$basic->id}", ['code' => 'basic', 'name' => 'Basic pay', 'kind' => 'earning', 'calculation' => 'fixed', 'posting_key' => 'salary_expense'], $headers)->assertOk();
        $this->putJson("/api/nit/hr/salary-components/{$basic->id}", ['code' => 'basic', 'name' => 'Basic', 'kind' => 'deduction', 'calculation' => 'fixed', 'posting_key' => 'salary_expense'], $headers)->assertUnprocessable()->assertJsonPath('reason', 'system_component');
        $this->deleteJson("/api/nit/hr/salary-components/{$basic->id}", [], $headers)->assertUnprocessable()->assertJsonPath('reason', 'in_use');
        $this->postJson('/api/nit/hr/salary-components', ['code' => 'tax', 'name' => 'Tax', 'kind' => 'deduction', 'calculation' => 'fixed', 'posting_key' => 'settlement'], $headers)->assertUnprocessable()->assertJsonValidationErrors(['posting_key']);
    }
}
