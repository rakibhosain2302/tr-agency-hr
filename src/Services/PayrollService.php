<?php

namespace Nit\AgraniHr\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Nit\AgraniHr\Accounting\Posting;
use Nit\AgraniHr\Accounting\PostingLine;
use Nit\AgraniHr\Contracts\HrAccounting;
use Nit\AgraniHr\Events\PayrollApproved;
use Nit\AgraniHr\Events\PayrollPaid;
use Nit\AgraniHr\Events\PayrollReversed;
use Nit\AgraniHr\Exceptions\HrException;
use Nit\AgraniHr\Models\AdvanceSettlement;
use Nit\AgraniHr\Models\Employee;
use Nit\AgraniHr\Models\EmployeeAdvance;
use Nit\AgraniHr\Models\Payroll;
use Nit\AgraniHr\Models\PayrollItem;
use Nit\AgraniHr\Models\PayrollItemLine;
use Nit\AgraniHr\Models\PayrollPeriod;
use Nit\AgraniHr\Models\SalaryComponent;
use Nit\AgraniHr\Support\Approver;
use Nit\AgraniHr\Support\HrLog;
use Nit\AgraniHr\Support\Money;
use Nit\AgraniHr\Support\SequenceNumber;

/**
 * Payroll of a branch for a period: draft (processed as often as needed) → approved (posted: salary expense
 * and deductions against salaries payable; advance recoveries settle the advances) → paid (posted: salaries
 * payable against cash / bank). A posted payroll is corrected by reversing it (its postings are reversed,
 * advance recoveries given back) and processing a new one for the period.
 *
 * Posting goes through the HrAccounting connection, inside the same database transaction: if accounting
 * refuses, nothing of the step is kept. A payroll is posted once: the step's status is checked under a row
 * lock and every posting carries a source key that the accounting side records only once.
 */
final class PayrollService
{
    public function __construct(private PayrollCalculator $calculator, private HrAccounting $accounting) {}

    public function create(PayrollPeriod $period, int $branchId, ?string $notes = null): Payroll
    {
        return DB::transaction(function () use ($period, $branchId, $notes): Payroll {
            $period = PayrollPeriod::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();
            if ($period->status !== PayrollPeriod::OPEN) {
                throw HrException::invalid(__('The period :code is closed.', ['code' => $period->code]), 'period_closed');
            }
            if (Payroll::query()->withoutGlobalScopes()->where('run_key', $this->runKey($period->id, $branchId))->exists()) {
                throw HrException::invalid(__('This branch already has a payroll for :code; reverse it to make a new one.', ['code' => $period->code]), 'payroll_exists');
            }

            $payroll = Payroll::query()->create([
                'payroll_no' => SequenceNumber::next('hr_payrolls', 'payroll_no', (string) config('hr.payroll.number_prefix', 'PR-')),
                'payroll_period_id' => $period->id,
                'branch_id' => $branchId,
                'status' => Payroll::DRAFT,
                'run_key' => $this->runKey($period->id, $branchId),
                'notes' => $notes,
            ]);

            HrLog::write('hr_payroll.created', "Payroll {$payroll->payroll_no} for {$period->code}", $payroll, $branchId);

            return $payroll;
        });
    }

    /** (Re)calculate every payslip of a draft payroll. */
    public function process(Payroll $payroll): Payroll
    {
        return DB::transaction(function () use ($payroll): Payroll {
            $payroll = $this->locked($payroll, [Payroll::DRAFT]);
            $period = $payroll->period()->firstOrFail();

            $payroll->items()->each(fn (PayrollItem $item) => $item->delete());

            $employees = Employee::query()->withoutGlobalScope('nit_branch')->withoutGlobalScope('hr_branch_assigned')
                ->where('branch_id', $payroll->branch_id)
                ->whereDate('joining_date', '<=', $period->end_date->toDateString())
                ->where(fn ($q) => $q->whereNull('termination_date')->orWhereDate('termination_date', '>=', $period->start_date->toDateString()))
                ->orderBy('employee_code')->get();

            $skipped = [];
            $sequence = 0;
            foreach ($employees as $employee) {
                if ($this->inOtherPayroll($employee->id, $period->id, $payroll->id)) {
                    $skipped[] = ['employee_id' => $employee->id, 'employee_code' => $employee->employee_code, 'reason' => 'in_other_payroll'];

                    continue;
                }
                try {
                    $result = $this->calculator->calculate($employee, $period);
                } catch (HrException $exception) {
                    $skipped[] = ['employee_id' => $employee->id, 'employee_code' => $employee->employee_code, 'reason' => $exception->reason, 'message' => $exception->getMessage()];

                    continue;
                }

                $item = $payroll->items()->create([
                    'employee_id' => $employee->id,
                    'payslip_no' => $payroll->payroll_no.'-'.str_pad((string) ++$sequence, 3, '0', STR_PAD_LEFT),
                ] + collect($result)->except('lines')->all());
                $item->lines()->createMany($result['lines']);
            }

            $items = $payroll->items()->get();
            $payroll->forceFill([
                'employee_count' => $items->count(),
                'gross_total' => Money::add(...$items->pluck('gross')->all()),
                'deduction_total' => Money::add(...$items->pluck('deductions')->all()),
                'net_total' => Money::add(...$items->pluck('net')->all()),
                'skipped' => $skipped,
                'processed_by' => auth()->id(),
                'processed_at' => now(),
            ])->save();

            return $payroll->load('items');
        });
    }

    /** Approve and post the payroll; advance recoveries become settlements. */
    public function approve(Payroll $payroll): Payroll
    {
        return DB::transaction(function () use ($payroll): Payroll {
            $payroll = $this->locked($payroll, [Payroll::DRAFT]);
            if ($payroll->processed_at === null || $payroll->employee_count === 0) {
                throw HrException::invalid(__('Process the payroll first; it has no payslips.'), 'not_processed');
            }
            if (config('hr.payroll.separate_approver', true) && ! Approver::isSuperAdmin() && $payroll->processed_by !== null && $payroll->processed_by === auth()->id()) {
                throw HrException::invalid(__('Someone other than the person who processed the payroll approves it.'), 'own_request');
            }
            $period = $payroll->period()->firstOrFail();
            $items = $payroll->items()->with(['lines', 'employee' => fn ($q) => $q->withoutGlobalScopes()])->get();

            foreach ($items as $item) {
                if ($this->inOtherPayroll($item->employee_id, $period->id, $payroll->id)) {
                    throw HrException::invalid(__(':code is in another payroll of the period; process this payroll again.', ['code' => $item->employee->employee_code]), 'reprocess');
                }
            }
            $this->settleAdvances($items, $period);

            $reference = $this->accounting->post(Posting::for($payroll, 'approval', $payroll->branch_id, $period->end_date, $this->approvalLines($items), __('Payroll :no (:period)', ['no' => $payroll->payroll_no, 'period' => $period->name]), $payroll->payroll_no));

            $payroll->forceFill(['status' => Payroll::APPROVED, 'approved_by' => auth()->id(), 'approved_at' => now(), 'accounting_reference' => $reference])->save();
            AdvanceSettlement::query()->whereIn('payroll_item_id', $items->pluck('id'))->update(['accounting_reference' => $reference]);

            HrLog::write('hr_payroll.approved', "Approved payroll {$payroll->payroll_no}", $payroll, $payroll->branch_id, ['net_total' => $payroll->net_total, 'accounting_reference' => $reference]);
            PayrollApproved::dispatch($payroll);

            return $payroll;
        });
    }

    /** Pay the net salaries from a cash / bank account. */
    public function pay(Payroll $payroll, \DateTimeInterface|string $date, string $settlementAccount, ?string $reference = null): Payroll
    {
        $day = Carbon::parse($date)->startOfDay();

        return DB::transaction(function () use ($payroll, $day, $settlementAccount, $reference): Payroll {
            $payroll = $this->locked($payroll, [Payroll::APPROVED]);
            $period = $payroll->period()->firstOrFail();
            if (! $this->accounting->settlementAccountExists($settlementAccount, $payroll->branch_id)) {
                throw HrException::invalid(__('Unknown cash or bank account :code.', ['code' => $settlementAccount]), 'invalid_settlement_account');
            }
            if ($day->lt($period->start_date)) {
                throw HrException::invalid(__('Salaries are paid after the period starts.'), 'invalid_date');
            }

            $attempt = $payroll->payment_count + 1;
            $posting = Money::isPositive($payroll->net_total) ? $this->accounting->post(Posting::for($payroll, "payment.{$attempt}", $payroll->branch_id, $day, [
                PostingLine::debit(PostingLine::SALARY_PAYABLE, Money::round($payroll->net_total), __('Salaries :no', ['no' => $payroll->payroll_no])),
                PostingLine::credit(PostingLine::SETTLEMENT, Money::round($payroll->net_total), __('Salaries :no', ['no' => $payroll->payroll_no]), $settlementAccount),
            ], __('Salary payment :no (:period)', ['no' => $payroll->payroll_no, 'period' => $period->name]), $reference ?? $payroll->payroll_no)) : null;

            $payroll->forceFill([
                'status' => Payroll::PAID,
                'payment_date' => $day->toDateString(),
                'settlement_account' => $settlementAccount,
                'payment_reference' => $reference,
                'payment_accounting_reference' => $posting,
                'payment_count' => $attempt,
                'paid_by' => auth()->id(),
                'paid_at' => now(),
            ])->save();

            HrLog::write('hr_payroll.paid', "Paid payroll {$payroll->payroll_no} from {$settlementAccount}", $payroll, $payroll->branch_id, ['accounting_reference' => $posting]);
            PayrollPaid::dispatch($payroll);

            return $payroll;
        });
    }

    /** Undo the payment (wrong account or date): the payroll is approved again and can be paid again. */
    public function reversePayment(Payroll $payroll, \DateTimeInterface|string|null $date = null, ?string $reason = null): Payroll
    {
        return DB::transaction(function () use ($payroll, $date, $reason): Payroll {
            $payroll = $this->locked($payroll, [Payroll::PAID]);
            $this->undoPayment($payroll, Carbon::parse($date ?? now())->toDateString(), $reason);
            $payroll->save();

            HrLog::write('hr_payroll.payment_reversed', "Reversed the payment of payroll {$payroll->payroll_no}", $payroll, $payroll->branch_id, ['reason' => $reason]);

            return $payroll;
        });
    }

    /**
     * Reverse an approved or paid payroll: its postings are reversed on $date, salary deductions of advances
     * are given back, and the period and branch are free for a corrected payroll.
     */
    public function reverse(Payroll $payroll, \DateTimeInterface|string|null $date = null, ?string $reason = null): Payroll
    {
        return DB::transaction(function () use ($payroll, $date, $reason): Payroll {
            $payroll = $this->locked($payroll, [Payroll::APPROVED, Payroll::PAID]);
            $day = Carbon::parse($date ?? now())->toDateString();

            if ($payroll->status === Payroll::PAID) {
                $this->undoPayment($payroll, $day, $reason);
            }
            $reversal = $payroll->accounting_reference !== null
                ? $this->accounting->reverse($payroll->accounting_reference, $day, __('Reversal of payroll :no', ['no' => $payroll->payroll_no]))
                : null;

            $itemIds = $payroll->items()->pluck('id');
            AdvanceSettlement::query()->whereIn('payroll_item_id', $itemIds)->whereNull('reversed_at')->lockForUpdate()->get()
                ->each(function (AdvanceSettlement $settlement) use ($reversal): void {
                    $advance = EmployeeAdvance::query()->withoutGlobalScopes()->whereKey($settlement->employee_advance_id)->lockForUpdate()->firstOrFail();
                    $advance->forceFill(['recovered_amount' => Money::sub($advance->recovered_amount, $settlement->amount), 'status' => EmployeeAdvance::DISBURSED])->save();
                    $settlement->forceFill(['reversed_at' => now(), 'reversed_by' => auth()->id(), 'reversal_accounting_reference' => $reversal])->save();
                });

            $payroll->forceFill([
                'status' => Payroll::REVERSED,
                'run_key' => null,
                'reversed_by' => auth()->id(),
                'reversed_at' => now(),
                'reversal_reason' => $reason,
                'reversal_accounting_reference' => $reversal,
            ])->save();

            HrLog::write('hr_payroll.reversed', "Reversed payroll {$payroll->payroll_no}", $payroll, $payroll->branch_id, ['reason' => $reason, 'accounting_reference' => $reversal]);
            PayrollReversed::dispatch($payroll);

            return $payroll;
        });
    }

    /** Delete a draft payroll. */
    public function delete(Payroll $payroll): void
    {
        DB::transaction(function () use ($payroll): void {
            $payroll = $this->locked($payroll, [Payroll::DRAFT]);
            $payroll->items()->each(fn (PayrollItem $item) => $item->delete());
            $payroll->delete();
        });
    }

    /**
     * The approval posting: each posting key's earnings (debit) less its deductions (credit), and the net
     * salaries owed (credit salaries payable).
     *
     * @param  iterable<PayrollItem>  $items
     * @return list<PostingLine>
     */
    public function approvalLines(iterable $items): array
    {
        $byKey = [];
        $net = '0.00';
        foreach ($items as $item) {
            foreach ($item->lines as $line) {
                $signed = $line->kind === SalaryComponent::EARNING ? $line->amount : '-'.$line->amount;
                $byKey[$line->posting_key] = Money::add($byKey[$line->posting_key] ?? '0', $signed);
            }
            $net = Money::add($net, $item->net);
        }

        $lines = [];
        ksort($byKey);
        foreach ($byKey as $key => $amount) {
            if (Money::compare($amount, '0') > 0) {
                $lines[] = PostingLine::debit($key, $amount);
            } elseif (Money::compare($amount, '0') < 0) {
                $lines[] = PostingLine::credit($key, Money::round(Money::of($amount)->negated()));
            }
        }
        if (Money::isPositive($net)) {
            $lines[] = PostingLine::credit(PostingLine::SALARY_PAYABLE, $net);
        }

        return $lines;
    }

    /** @param  iterable<PayrollItem>  $items */
    private function settleAdvances(iterable $items, PayrollPeriod $period): void
    {
        foreach ($items as $item) {
            foreach ($item->lines->where('source', PayrollItemLine::SOURCE_ADVANCE) as $line) {
                $advance = EmployeeAdvance::query()->withoutGlobalScopes()->whereKey($line->employee_advance_id)->lockForUpdate()->firstOrFail();
                if ($advance->status !== EmployeeAdvance::DISBURSED || Money::compare($advance->outstanding(), $line->amount) < 0) {
                    throw HrException::invalid(__('Advance :no changed since the payroll was processed; process it again.', ['no' => $advance->advance_no]), 'reprocess');
                }

                $advance->settlements()->create([
                    'kind' => AdvanceSettlement::SALARY_DEDUCTION,
                    'amount' => $line->amount,
                    'settlement_date' => $period->end_date->toDateString(),
                    'payroll_item_id' => $item->id,
                    'notes' => $item->payslip_no,
                ]);
                $recovered = Money::add($advance->recovered_amount, $line->amount);
                $advance->forceFill(['recovered_amount' => $recovered, 'status' => Money::compare($recovered, $advance->amount) >= 0 ? EmployeeAdvance::SETTLED : EmployeeAdvance::DISBURSED])->save();
            }
        }
    }

    private function undoPayment(Payroll $payroll, string $date, ?string $reason): void
    {
        if ($payroll->payment_accounting_reference !== null) {
            $this->accounting->reverse($payroll->payment_accounting_reference, $date, __('Reversal of salary payment :no', ['no' => $payroll->payroll_no]).($reason ? " — {$reason}" : ''));
        }
        $payroll->forceFill(['status' => Payroll::APPROVED, 'payment_date' => null, 'settlement_account' => null, 'payment_reference' => null, 'payment_accounting_reference' => null, 'paid_by' => null, 'paid_at' => null]);
    }

    private function inOtherPayroll(int $employeeId, int $periodId, int $payrollId): bool
    {
        return PayrollItem::query()->where('employee_id', $employeeId)->where('payroll_id', '<>', $payrollId)
            ->whereHas('payroll', fn ($q) => $q->withoutGlobalScopes()->where('payroll_period_id', $periodId)->whereNotNull('run_key'))
            ->exists();
    }

    /** @param  list<string>  $statuses */
    private function locked(Payroll $payroll, array $statuses): Payroll
    {
        $payroll = Payroll::query()->whereKey($payroll->id)->lockForUpdate()->firstOrFail();
        if (! in_array($payroll->status, $statuses, true)) {
            throw HrException::invalid(__('Payroll :no is :status.', ['no' => $payroll->payroll_no, 'status' => $payroll->status]), 'invalid_status');
        }

        return $payroll;
    }

    private function runKey(int $periodId, int $branchId): string
    {
        return "{$periodId}:{$branchId}";
    }
}
