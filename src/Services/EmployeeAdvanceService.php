<?php

namespace Nit\AgraniHr\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Nit\AgraniHr\Accounting\Posting;
use Nit\AgraniHr\Accounting\PostingLine;
use Nit\AgraniHr\Contracts\HrAccounting;
use Nit\AgraniHr\Events\EmployeeAdvanceDisbursed;
use Nit\AgraniHr\Exceptions\HrException;
use Nit\AgraniHr\Models\AdvanceSettlement;
use Nit\AgraniHr\Models\Employee;
use Nit\AgraniHr\Models\EmployeeAdvance;
use Nit\AgraniHr\Support\Approver;
use Nit\AgraniHr\Support\HrLog;
use Nit\AgraniHr\Support\Money;
use Nit\AgraniHr\Support\SequenceNumber;

/**
 * Employee advances: request → approve / reject → disburse (posted: employee advances against cash / bank) →
 * recovered by salary deductions (PayrollService, at payroll approval) or cash repayments (posted: cash / bank
 * against employee advances) → settled. A disbursement without recoveries and a cash repayment can be
 * reversed; their postings are reversed with them.
 */
final class EmployeeAdvanceService
{
    public function __construct(private HrAccounting $accounting) {}

    public function request(Employee $employee, string|int|float $amount, \DateTimeInterface|string|null $date = null, string|int|float|null $installment = null, ?string $reason = null): EmployeeAdvance
    {
        $amount = Money::round($amount);
        $installment = $installment !== null && $installment !== '' ? Money::round($installment) : null;
        if (! Money::isPositive($amount) || ($installment !== null && (! Money::isPositive($installment) || Money::compare($installment, $amount) > 0))) {
            throw HrException::invalid(__('The amount must be above zero and the instalment at most the amount.'), 'invalid_amount');
        }
        if ($employee->isTerminated()) {
            throw HrException::invalid(__('The employee has left.'), 'terminated');
        }

        return DB::transaction(function () use ($employee, $amount, $date, $installment, $reason): EmployeeAdvance {
            $advance = EmployeeAdvance::query()->create([
                'advance_no' => SequenceNumber::next('hr_employee_advances', 'advance_no', (string) config('hr.advances.number_prefix', 'ADV-')),
                'employee_id' => $employee->id,
                'branch_id' => $employee->branch_id,
                'amount' => $amount,
                'installment_amount' => $installment,
                'request_date' => Carbon::parse($date ?? now())->toDateString(),
                'reason' => $reason,
                'status' => EmployeeAdvance::PENDING,
                'requested_by' => auth()->id(),
            ]);
            HrLog::write('hr_advance.requested', "Advance {$advance->advance_no} of {$amount} for {$employee->activityLabel()}", $advance, $advance->branch_id);

            return $advance;
        });
    }

    public function approve(EmployeeAdvance $advance, ?string $note = null): EmployeeAdvance
    {
        return $this->decide($advance, EmployeeAdvance::APPROVED, $note);
    }

    public function reject(EmployeeAdvance $advance, ?string $note = null): EmployeeAdvance
    {
        return $this->decide($advance, EmployeeAdvance::REJECTED, $note);
    }

    /** Withdraw a request that has not been paid out. */
    public function cancel(EmployeeAdvance $advance, ?string $note = null): EmployeeAdvance
    {
        return DB::transaction(function () use ($advance, $note): EmployeeAdvance {
            $advance = $this->locked($advance, [EmployeeAdvance::PENDING, EmployeeAdvance::APPROVED]);
            $advance->forceFill(['status' => EmployeeAdvance::CANCELLED, 'decision_note' => $note ?? $advance->decision_note])->save();

            return $advance;
        });
    }

    /** Pay the approved advance out of a cash / bank account. */
    public function disburse(EmployeeAdvance $advance, \DateTimeInterface|string $date, string $settlementAccount): EmployeeAdvance
    {
        $day = Carbon::parse($date)->startOfDay();

        return DB::transaction(function () use ($advance, $day, $settlementAccount): EmployeeAdvance {
            $advance = $this->locked($advance, [EmployeeAdvance::APPROVED]);
            $this->ensureAccount($settlementAccount, $advance->branch_id);
            if ($day->lt($advance->request_date)) {
                throw HrException::invalid(__('An advance is paid on or after its request date.'), 'invalid_date');
            }

            $reference = $this->accounting->post(Posting::for($advance, 'disbursement', $advance->branch_id, $day, [
                PostingLine::debit(PostingLine::EMPLOYEE_ADVANCE, Money::round($advance->amount), $this->label($advance)),
                PostingLine::credit(PostingLine::SETTLEMENT, Money::round($advance->amount), $this->label($advance), $settlementAccount),
            ], __('Employee advance :no', ['no' => $advance->advance_no]), $advance->advance_no));

            $advance->forceFill([
                'status' => EmployeeAdvance::DISBURSED,
                'disbursement_date' => $day->toDateString(),
                'settlement_account' => $settlementAccount,
                'accounting_reference' => $reference,
                'disbursed_by' => auth()->id(),
                'disbursed_at' => now(),
            ])->save();

            HrLog::write('hr_advance.disbursed', "Disbursed advance {$advance->advance_no}", $advance, $advance->branch_id, ['accounting_reference' => $reference]);
            EmployeeAdvanceDisbursed::dispatch($advance);

            return $advance;
        });
    }

    /** Undo a disbursement nothing was recovered from: the posting is reversed and the advance cancelled. */
    public function reverseDisbursement(EmployeeAdvance $advance, \DateTimeInterface|string|null $date = null, ?string $reason = null): EmployeeAdvance
    {
        return DB::transaction(function () use ($advance, $date, $reason): EmployeeAdvance {
            $advance = $this->locked($advance, [EmployeeAdvance::DISBURSED]);
            if (Money::isPositive($advance->recovered_amount) || $advance->settlements()->whereNull('reversed_at')->exists()) {
                throw HrException::invalid(__('Part of the advance has been recovered; reverse those recoveries first.'), 'has_settlements');
            }
            if ($this->inLivePayroll($advance)) {
                throw HrException::invalid(__('A payroll recovers this advance; reverse or process that payroll again first.'), 'in_payroll');
            }

            $reversal = $advance->accounting_reference !== null
                ? $this->accounting->reverse($advance->accounting_reference, Carbon::parse($date ?? now())->toDateString(), __('Reversal of employee advance :no', ['no' => $advance->advance_no]))
                : null;
            $advance->forceFill(['status' => EmployeeAdvance::CANCELLED, 'reversal_accounting_reference' => $reversal, 'decision_note' => $reason ?? $advance->decision_note])->save();

            HrLog::write('hr_advance.reversed', "Reversed the disbursement of advance {$advance->advance_no}", $advance, $advance->branch_id, ['reason' => $reason]);

            return $advance;
        });
    }

    /** The employee pays (part of) the advance back in cash / to the bank. */
    public function repay(EmployeeAdvance $advance, string|int|float $amount, \DateTimeInterface|string $date, string $settlementAccount, ?string $notes = null): AdvanceSettlement
    {
        $amount = Money::round($amount);
        $day = Carbon::parse($date)->startOfDay();

        return DB::transaction(function () use ($advance, $amount, $day, $settlementAccount, $notes): AdvanceSettlement {
            $advance = $this->locked($advance, [EmployeeAdvance::DISBURSED]);
            $this->ensureAccount($settlementAccount, $advance->branch_id);
            if (! Money::isPositive($amount) || Money::compare($amount, $advance->outstanding()) > 0) {
                throw HrException::invalid(__('A repayment is above zero and at most the :outstanding still owed.', ['outstanding' => $advance->outstanding()]), 'invalid_amount');
            }
            if ($day->lt($advance->disbursement_date)) {
                throw HrException::invalid(__('A repayment is made after the advance was paid out.'), 'invalid_date');
            }

            $settlement = $advance->settlements()->create([
                'kind' => AdvanceSettlement::CASH_REPAYMENT,
                'amount' => $amount,
                'settlement_date' => $day->toDateString(),
                'settlement_account' => $settlementAccount,
                'notes' => $notes,
            ]);
            $reference = $this->accounting->post(Posting::for($settlement, 'repayment', $advance->branch_id, $day, [
                PostingLine::debit(PostingLine::SETTLEMENT, $amount, $this->label($advance), $settlementAccount),
                PostingLine::credit(PostingLine::EMPLOYEE_ADVANCE, $amount, $this->label($advance)),
            ], __('Repayment of employee advance :no', ['no' => $advance->advance_no]), $advance->advance_no));
            $settlement->forceFill(['accounting_reference' => $reference])->save();

            $this->recover($advance, $amount);
            HrLog::write('hr_advance.repaid', "Repayment of {$amount} on advance {$advance->advance_no}", $advance, $advance->branch_id, ['accounting_reference' => $reference]);

            return $settlement;
        });
    }

    /** Reverse a cash repayment (salary deductions are reversed with their payroll). */
    public function reverseSettlement(AdvanceSettlement $settlement, \DateTimeInterface|string|null $date = null, ?string $reason = null): AdvanceSettlement
    {
        return DB::transaction(function () use ($settlement, $date, $reason): AdvanceSettlement {
            $settlement = AdvanceSettlement::query()->whereKey($settlement->id)->lockForUpdate()->firstOrFail();
            if ($settlement->isReversed()) {
                throw HrException::invalid(__('This recovery has already been reversed.'), 'already_reversed');
            }
            if ($settlement->kind !== AdvanceSettlement::CASH_REPAYMENT) {
                throw HrException::invalid(__('A salary deduction is reversed with its payroll.'), 'salary_deduction');
            }
            $advance = $this->locked($settlement->advance()->withoutGlobalScopes()->firstOrFail(), [EmployeeAdvance::DISBURSED, EmployeeAdvance::SETTLED]);

            $reversal = $settlement->accounting_reference !== null
                ? $this->accounting->reverse($settlement->accounting_reference, Carbon::parse($date ?? now())->toDateString(), __('Reversal of repayment of :no', ['no' => $advance->advance_no]))
                : null;
            $settlement->forceFill(['reversed_at' => now(), 'reversed_by' => auth()->id(), 'reversal_accounting_reference' => $reversal, 'notes' => $reason ?? $settlement->notes])->save();
            $advance->forceFill(['recovered_amount' => Money::sub($advance->recovered_amount, $settlement->amount), 'status' => EmployeeAdvance::DISBURSED])->save();

            return $settlement;
        });
    }

    private function decide(EmployeeAdvance $advance, string $status, ?string $note): EmployeeAdvance
    {
        return DB::transaction(function () use ($advance, $status, $note): EmployeeAdvance {
            $advance = $this->locked($advance, [EmployeeAdvance::PENDING]);
            Approver::ensureNotOwn(Employee::query()->withoutGlobalScopes()->find($advance->employee_id), $advance->requested_by);

            $advance->forceFill(['status' => $status, 'decided_by' => auth()->id(), 'decided_at' => now(), 'decision_note' => $note])->save();
            HrLog::write("hr_advance.{$status}", ucfirst($status)." advance {$advance->advance_no}", $advance, $advance->branch_id);

            return $advance;
        });
    }

    private function recover(EmployeeAdvance $advance, string $amount): void
    {
        $recovered = Money::add($advance->recovered_amount, $amount);
        $advance->forceFill(['recovered_amount' => $recovered, 'status' => Money::compare($recovered, $advance->amount) >= 0 ? EmployeeAdvance::SETTLED : EmployeeAdvance::DISBURSED])->save();
    }

    private function inLivePayroll(EmployeeAdvance $advance): bool
    {
        return DB::table('hr_payroll_item_lines as l')->join('hr_payroll_items as i', 'i.id', '=', 'l.payroll_item_id')->join('hr_payrolls as p', 'p.id', '=', 'i.payroll_id')
            ->where('l.employee_advance_id', $advance->id)->whereNotNull('p.run_key')->exists();
    }

    private function ensureAccount(string $code, int $branchId): void
    {
        if (! $this->accounting->settlementAccountExists($code, $branchId)) {
            throw HrException::invalid(__('Unknown cash or bank account :code.', ['code' => $code]), 'invalid_settlement_account');
        }
    }

    /** @param  list<string>  $statuses */
    private function locked(EmployeeAdvance $advance, array $statuses): EmployeeAdvance
    {
        $advance = EmployeeAdvance::query()->withoutGlobalScopes()->whereKey($advance->id)->lockForUpdate()->firstOrFail();
        if (! in_array($advance->status, $statuses, true)) {
            throw HrException::invalid(__('Advance :no is :status.', ['no' => $advance->advance_no, 'status' => $advance->status]), 'invalid_status');
        }

        return $advance;
    }

    private function label(EmployeeAdvance $advance): string
    {
        $employee = Employee::query()->withoutGlobalScopes()->find($advance->employee_id);

        return __('Advance :no — :employee', ['no' => $advance->advance_no, 'employee' => $employee?->activityLabel()]);
    }
}
