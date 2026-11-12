<?php

namespace Nit\AgraniHr\Tests\Feature;

use Nit\AgraniHr\Models\AdvanceSettlement;
use Nit\AgraniHr\Models\EmployeeAdvance;
use Nit\AgraniHr\Services\EmployeeAdvanceService;
use Nit\AgraniHr\Services\PayrollService;
use Nit\AgraniHr\Tests\Fixtures\RecordingHrAccounting;
use Nit\AgraniHr\Tests\TestCase;
use Nit\Master\Models\Branch;

class EmployeeAdvanceTest extends TestCase
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

    private function advances(): EmployeeAdvanceService
    {
        return app(EmployeeAdvanceService::class);
    }

    private function disbursed(string $amount = '6000', ?string $installment = '2000'): EmployeeAdvance
    {
        $advance = $this->advances()->request($this->hire($this->dhaka), $amount, '2026-06-01', $installment, 'Medical');
        $this->advances()->approve($advance);

        return $this->advances()->disburse($advance, '2026-06-02', 'CASH');
    }

    public function test_an_advance_is_requested_approved_and_disbursed_with_a_posting(): void
    {
        $advance = $this->disbursed();

        $this->assertSame('ADV-00001', $advance->advance_no);
        $this->assertSame(EmployeeAdvance::DISBURSED, $advance->status);
        $posting = $this->books->postings[$advance->accounting_reference];
        $this->assertSame("hr.employee_advances.{$advance->id}.disbursement", $posting->sourceKey);
        $this->assertSame('2026-06-02', $posting->date);
        $this->assertSame([
            'employee_advance' => ['debit' => '6000.00', 'credit' => '0.00'],
            'settlement' => ['debit' => '0.00', 'credit' => '6000.00'],
        ], $this->books->sides($advance->accounting_reference));

        $this->assertRefused('invalid_status', fn () => $this->advances()->disburse($advance, '2026-06-03', 'CASH'));
        $this->assertCount(1, $this->books->postings);
    }

    public function test_requests_are_validated_and_decided_once(): void
    {
        $employee = $this->hire($this->dhaka);

        $this->assertRefused('invalid_amount', fn () => $this->advances()->request($employee, '0'));
        $this->assertRefused('invalid_amount', fn () => $this->advances()->request($employee, '1000', null, '1500'));

        $advance = $this->advances()->request($employee, '1000');
        $this->assertRefused('invalid_status', fn () => $this->advances()->disburse($advance, now(), 'CASH'));
        $this->advances()->reject($advance, 'Not now');
        $this->assertRefused('invalid_status', fn () => $this->advances()->approve($advance));

        $other = $this->advances()->request($employee, '1000');
        $this->advances()->approve($other);
        $this->assertRefused('invalid_settlement_account', fn () => $this->advances()->disburse($other, now(), 'NOPE'));
        $this->assertSame(EmployeeAdvance::CANCELLED, $this->advances()->cancel($other)->status);
    }

    public function test_nobody_approves_their_own_advance(): void
    {
        $user = $this->inBranches($this->userWith([]), $this->dhaka);
        $employee = $this->hire($this->dhaka, ['user_id' => $user->id]);
        $this->actingAs($user);
        $advance = $this->advances()->request($employee, '1000');

        $this->assertRefused('own_request', fn () => $this->advances()->approve($advance));

        $this->actingAs($this->inBranches($this->userWith([]), $this->dhaka));
        $this->assertSame(EmployeeAdvance::APPROVED, $this->advances()->approve($advance)->status);
    }

    public function test_cash_repayments_are_posted_settle_the_advance_and_can_be_reversed(): void
    {
        $advance = $this->disbursed();

        $this->assertRefused('invalid_amount', fn () => $this->advances()->repay($advance, '6000.01', '2026-06-10', 'CASH'));
        $first = $this->advances()->repay($advance, '4000', '2026-06-10', 'CASH');
        $this->assertSame([
            'employee_advance' => ['debit' => '0.00', 'credit' => '4000.00'],
            'settlement' => ['debit' => '4000.00', 'credit' => '0.00'],
        ], $this->books->sides($first->accounting_reference));
        $this->assertSame('2000.00', $advance->fresh()->outstanding());

        $this->advances()->repay($advance, '2000', '2026-06-20', 'BANK');
        $this->assertSame(EmployeeAdvance::SETTLED, $advance->fresh()->status);

        $this->advances()->reverseSettlement($first, '2026-06-25');
        $advance->refresh();
        $this->assertSame(['2000.00', '4000.00', EmployeeAdvance::DISBURSED], [$advance->recovered_amount, $advance->outstanding(), $advance->status]);
        $this->assertSame('REV-'.$first->accounting_reference, $first->fresh()->reversal_accounting_reference);
        $this->assertRefused('already_reversed', fn () => $this->advances()->reverseSettlement($first));
    }

    public function test_a_disbursement_is_reversed_only_while_nothing_was_recovered(): void
    {
        $advance = $this->disbursed();
        $repayment = $this->advances()->repay($advance, '100', '2026-06-03', 'CASH');

        $this->assertRefused('has_settlements', fn () => $this->advances()->reverseDisbursement($advance));

        $this->advances()->reverseSettlement($repayment);
        $cancelled = $this->advances()->reverseDisbursement($advance, '2026-06-04', 'Paid twice');

        $this->assertSame(EmployeeAdvance::CANCELLED, $cancelled->status);
        $this->assertSame('REV-'.$advance->accounting_reference, $cancelled->reversal_accounting_reference);
    }

    public function test_salary_deductions_settle_the_advance_over_several_payrolls(): void
    {
        $advance = $this->disbursed('5000', '2000');
        $employee = $advance->employee;
        $this->salary($employee, '2026-01-01', '20000');
        $payroll = app(PayrollService::class);

        foreach (['2026-07', '2026-08', '2026-09'] as $month) {
            $payroll->approve($payroll->process($payroll->create($this->period($month), $this->dhaka->id)));
        }

        $advance->refresh();
        $this->assertSame(EmployeeAdvance::SETTLED, $advance->status);
        $this->assertSame(['2000.00', '2000.00', '1000.00'], AdvanceSettlement::query()->orderBy('id')->pluck('amount')->all());
        $this->assertSame([AdvanceSettlement::SALARY_DEDUCTION], AdvanceSettlement::query()->distinct()->pluck('kind')->all());

        $deduction = AdvanceSettlement::query()->first();
        $this->assertRefused('salary_deduction', fn () => $this->advances()->reverseSettlement($deduction));
    }
}
