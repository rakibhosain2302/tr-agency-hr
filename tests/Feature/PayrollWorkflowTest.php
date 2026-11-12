<?php

namespace Nit\AgraniHr\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Nit\AgraniHr\Contracts\HrAccounting;
use Nit\AgraniHr\Events\PayrollApproved;
use Nit\AgraniHr\Events\PayrollReversed;
use Nit\AgraniHr\Models\AdvanceSettlement;
use Nit\AgraniHr\Models\Attendance;
use Nit\AgraniHr\Models\EmployeeAdvance;
use Nit\AgraniHr\Models\Payroll;
use Nit\AgraniHr\Models\PayrollPeriod;
use Nit\AgraniHr\Services\AttendanceService;
use Nit\AgraniHr\Services\EmployeeAdvanceService;
use Nit\AgraniHr\Services\PayrollService;
use Nit\AgraniHr\Services\SalaryStructureService;
use Nit\AgraniHr\Support\NullHrAccounting;
use Nit\AgraniHr\Tests\Fixtures\RecordingHrAccounting;
use Nit\AgraniHr\Tests\TestCase;
use Nit\Master\Models\Branch;

/**
 * Payroll through its life, against a recording accounting connection.
 */
class PayrollWorkflowTest extends TestCase
{
    private Branch $dhaka;

    private RecordingHrAccounting $books;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dhaka = $this->branch('Dhaka');
        $this->books = $this->accounting();
        $this->actingAs($this->superAdmin());
    }

    private function payroll(): PayrollService
    {
        return app(PayrollService::class);
    }

    /** Two employees (31,500 gross / 29,500 net and 15,750 / 14,750) and an advance of 3,000 for the first. */
    private function processed(): Payroll
    {
        $rahim = $this->hire($this->dhaka, ['first_name' => 'Rahim']);
        $this->salary($rahim, '2026-01-01', '20000');
        $karim = $this->hire($this->dhaka, ['first_name' => 'Karim']);
        $this->salary($karim, '2026-01-01', '10000', '50', '750');

        $advances = app(EmployeeAdvanceService::class);
        $advance = $advances->request($rahim, '3000', '2026-06-01', '1000');
        $advances->approve($advance);
        $advances->disburse($advance, '2026-06-02', 'CASH');

        return $this->payroll()->process($this->payroll()->create($this->period(), $this->dhaka->id));
    }

    public function test_processing_makes_a_payslip_per_employee_with_totals(): void
    {
        $payroll = $this->processed();

        $this->assertSame(Payroll::DRAFT, $payroll->status);
        $this->assertSame(2, $payroll->employee_count);
        $this->assertSame(['47250.00', '4000.00', '43250.00'], [$payroll->gross_total, $payroll->deduction_total, $payroll->net_total]);
        $this->assertSame(['PR-00001-001', 'PR-00001-002'], $payroll->items->pluck('payslip_no')->sort()->values()->all());
        $this->assertSame([], $payroll->skipped);
        // Nothing is posted or recovered before approval.
        $this->assertCount(1, $this->books->postings);
        $this->assertSame(0, AdvanceSettlement::query()->count());
    }

    public function test_approval_posts_expense_deductions_advance_and_payable_once(): void
    {
        Event::fake([PayrollApproved::class]);
        $payroll = $this->payroll()->approve($this->processed());

        $this->assertSame(Payroll::APPROVED, $payroll->status);
        $this->assertSame('JE-2', $payroll->accounting_reference);
        $posting = $this->books->postings['JE-2'];
        $this->assertSame("hr.payrolls.{$payroll->id}.approval", $posting->sourceKey);
        $this->assertSame('2026-07-31', $posting->date);
        $this->assertSame([
            'employee_advance' => ['debit' => '0.00', 'credit' => '1000.00'],
            'provident_fund' => ['debit' => '0.00', 'credit' => '3000.00'],
            'salary_expense' => ['debit' => '47250.00', 'credit' => '0.00'],
            'salary_payable' => ['debit' => '0.00', 'credit' => '43250.00'],
        ], $this->books->sides('JE-2'));

        $advance = EmployeeAdvance::query()->sole();
        $this->assertSame(['1000.00', '2000.00', EmployeeAdvance::DISBURSED], [$advance->recovered_amount, $advance->outstanding(), $advance->status]);
        $this->assertSame('JE-2', AdvanceSettlement::query()->sole()->accounting_reference);
        Event::assertDispatched(PayrollApproved::class);

        $calls = $this->books->calls;
        $this->assertRefused('invalid_status', fn () => $this->payroll()->approve($payroll));
        $this->assertRefused('invalid_status', fn () => $this->payroll()->process($payroll));
        $this->assertSame($calls, $this->books->calls, 'A second approval reached the books.');
        $this->assertSame(1, AdvanceSettlement::query()->count());
    }

    public function test_a_period_and_branch_has_one_live_payroll(): void
    {
        $this->processed();

        $this->assertRefused('payroll_exists', fn () => $this->payroll()->create($this->period(), $this->dhaka->id));
    }

    public function test_paying_posts_payable_against_the_cash_or_bank_account(): void
    {
        $payroll = $this->payroll()->approve($this->processed());

        $this->assertRefused('invalid_settlement_account', fn () => $this->payroll()->pay($payroll, '2026-08-01', 'NOPE'));
        $paid = $this->payroll()->pay($payroll, '2026-08-01', 'BANK', 'Bank batch 17');

        $this->assertSame(Payroll::PAID, $paid->status);
        $this->assertSame([
            'salary_payable' => ['debit' => '43250.00', 'credit' => '0.00'],
            'settlement' => ['debit' => '0.00', 'credit' => '43250.00'],
        ], $this->books->sides($paid->payment_accounting_reference));
        $this->assertSame('BANK', $this->books->postings[$paid->payment_accounting_reference]->lines[1]->settlementAccount);
        $this->assertSame("hr.payrolls.{$payroll->id}.payment.1", $this->books->postings[$paid->payment_accounting_reference]->sourceKey);
        $this->assertRefused('invalid_status', fn () => $this->payroll()->pay($paid, '2026-08-01', 'BANK'));
    }

    public function test_a_wrong_payment_is_reversed_and_paid_again(): void
    {
        $payroll = $this->payroll()->pay($this->payroll()->approve($this->processed()), '2026-08-01', 'CASH');
        $first = $payroll->payment_accounting_reference;

        $back = $this->payroll()->reversePayment($payroll, '2026-08-02', 'Should have been the bank');
        $this->assertSame(Payroll::APPROVED, $back->status);
        $this->assertSame('REV-'.$first, $this->books->reversals[$first]['reference']);

        $again = $this->payroll()->pay($back, '2026-08-02', 'BANK');
        $this->assertNotSame($first, $again->payment_accounting_reference);
        $this->assertSame("hr.payrolls.{$payroll->id}.payment.2", $this->books->postings[$again->payment_accounting_reference]->sourceKey);
    }

    public function test_reversing_a_paid_payroll_reverses_both_postings_and_gives_advances_back(): void
    {
        Event::fake([PayrollReversed::class]);
        $payroll = $this->payroll()->pay($this->payroll()->approve($this->processed()), '2026-08-01', 'CASH');

        $reversed = $this->payroll()->reverse($payroll, '2026-08-05', 'Wrong overtime');

        $this->assertSame(Payroll::REVERSED, $reversed->status);
        $this->assertNull($reversed->run_key);
        $this->assertSame(['REV-JE-2', 'REV-JE-3'], collect($this->books->reversals)->pluck('reference')->sort()->values()->all());
        $this->assertSame('REV-JE-2', $reversed->reversal_accounting_reference);
        $advance = EmployeeAdvance::query()->sole();
        $this->assertSame(['0.00', EmployeeAdvance::DISBURSED], [$advance->recovered_amount, $advance->status]);
        $this->assertNotNull(AdvanceSettlement::query()->sole()->reversed_at);
        Event::assertDispatched(PayrollReversed::class);

        // The correction: a new payroll for the same period and branch.
        $corrected = $this->payroll()->approve($this->payroll()->process($this->payroll()->create($this->period(), $this->dhaka->id)));
        $this->assertSame('43250.00', $corrected->net_total);
        $this->assertSame('1000.00', EmployeeAdvance::query()->sole()->recovered_amount);
        $this->assertRefused('invalid_status', fn () => $this->payroll()->reverse($reversed));
    }

    public function test_when_the_books_refuse_the_approval_nothing_is_kept(): void
    {
        $payroll = $this->processed();
        $this->books->refuse = true;

        try {
            $this->payroll()->approve($payroll);
            $this->fail('The approval went through.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('The books are closed.', $exception->getMessage());
        }

        $this->assertSame(Payroll::DRAFT, $payroll->fresh()->status);
        $this->assertSame(0, AdvanceSettlement::query()->count());
        $this->assertSame('0.00', EmployeeAdvance::query()->sole()->recovered_amount);
    }

    public function test_someone_else_approves_a_payroll(): void
    {
        $processor = $this->inBranches($this->userWith([]), $this->dhaka);
        $this->actingAs($processor);
        $employee = $this->hire($this->dhaka);
        $this->salary($employee, '2026-01-01', '20000');
        $payroll = $this->payroll()->process($this->payroll()->create($this->period(), $this->dhaka->id));

        $this->assertRefused('own_request', fn () => $this->payroll()->approve($payroll));

        $this->actingAs($this->inBranches($this->userWith([]), $this->dhaka));
        $this->assertSame(Payroll::APPROVED, $this->payroll()->approve($payroll)->status);
    }

    public function test_an_advance_repaid_after_processing_makes_the_payroll_be_processed_again(): void
    {
        $payroll = $this->processed();
        $advances = app(EmployeeAdvanceService::class);
        $advances->repay(EmployeeAdvance::query()->sole(), '2500', '2026-07-20', 'CASH');

        $this->assertRefused('reprocess', fn () => $this->payroll()->approve($payroll));

        $approved = $this->payroll()->approve($this->payroll()->process($payroll));
        $this->assertSame(EmployeeAdvance::SETTLED, EmployeeAdvance::query()->sole()->status);
        $this->assertSame('43750.00', $approved->net_total);
    }

    public function test_paid_days_are_locked_until_the_payroll_is_reversed(): void
    {
        $payroll = $this->payroll()->approve($this->processed());
        $employee = $payroll->items()->first()->employee;
        $attendance = app(AttendanceService::class);

        $this->assertRefused('payroll_locked', fn () => $attendance->record($employee, '2026-07-08', ['status' => Attendance::ABSENT]));
        $this->assertRefused('payroll_locked', fn () => app(SalaryStructureService::class)->create($employee, '2026-07-20', [['salary_component_id' => $this->salaryComponent('basic')->id, 'amount' => '99999']]));

        $this->payroll()->reverse($payroll);
        $this->assertSame(Attendance::ABSENT, $attendance->record($employee, '2026-07-08', ['status' => Attendance::ABSENT])->status);
    }

    public function test_employees_without_salary_are_listed_as_skipped_and_closed_periods_take_no_payroll(): void
    {
        $this->hire($this->dhaka, ['first_name' => 'No salary']);
        $paid = $this->hire($this->dhaka);
        $this->salary($paid, '2026-01-01', '20000');

        $payroll = $this->payroll()->process($this->payroll()->create($this->period(), $this->dhaka->id));
        $this->assertSame(1, $payroll->employee_count);
        $this->assertSame(['no_salary_structure'], array_column($payroll->skipped, 'reason'));

        $this->period('2026-08')->update(['status' => PayrollPeriod::CLOSED]);
        $this->assertRefused('period_closed', fn () => $this->payroll()->create($this->period('2026-08'), $this->dhaka->id));
    }

    public function test_an_unprocessed_or_empty_payroll_cannot_be_approved_and_drafts_can_be_deleted(): void
    {
        $payroll = $this->payroll()->create($this->period(), $this->dhaka->id);
        $this->assertRefused('not_processed', fn () => $this->payroll()->approve($payroll));

        $this->payroll()->delete($payroll);
        $this->assertSame(0, Payroll::query()->count());
        $this->payroll()->create($this->period(), $this->dhaka->id);
    }

    public function test_without_an_accounting_connection_payroll_still_works_and_records_no_reference(): void
    {
        $this->app->forgetInstance(HrAccounting::class);
        $this->app->bind(HrAccounting::class, NullHrAccounting::class);
        $this->app->forgetInstance(PayrollService::class);
        $employee = $this->hire($this->dhaka);
        $this->salary($employee, '2026-01-01', '20000');

        $payroll = $this->payroll()->approve($this->payroll()->process($this->payroll()->create($this->period(), $this->dhaka->id)));

        $this->assertSame(Payroll::APPROVED, $payroll->status);
        $this->assertNull($payroll->accounting_reference);
    }
}
