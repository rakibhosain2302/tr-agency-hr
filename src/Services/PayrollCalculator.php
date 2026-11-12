<?php

namespace Nit\AgraniHr\Services;

use Brick\Math\BigDecimal;
use Nit\AgraniHr\Accounting\PostingLine;
use Nit\AgraniHr\Exceptions\HrException;
use Nit\AgraniHr\Models\Attendance;
use Nit\AgraniHr\Models\Employee;
use Nit\AgraniHr\Models\EmployeeAdvance;
use Nit\AgraniHr\Models\PayrollItemLine;
use Nit\AgraniHr\Models\PayrollPeriod;
use Nit\AgraniHr\Models\SalaryComponent;
use Nit\AgraniHr\Models\SalaryStructure;
use Nit\AgraniHr\Support\Money;
use Nit\AgraniHr\Support\WorkCalendar;

/**
 * One employee's pay for a period, from their salary structures, approved leave, attendance and advances:
 *
 * - each component is its monthly amount, prorated by calendar days when the employee joined, left or got a
 *   new salary within the period (a whole period on one structure is exactly the monthly amount);
 * - unpaid leave and absence cost a day's rate each (monthly gross or basic ÷ day_basis; half days half);
 *   absence is only an "absent" attendance record on a day without approved leave;
 * - overtime is paid at basic ÷ hourly_divisor × multiplier per hour;
 * - open advances are recovered last (their instalment, at most what is owed and what is left to pay).
 *
 * Each line is rounded half up to 2 decimals; the totals are the sums of the lines.
 */
final class PayrollCalculator
{
    public function __construct(private SalaryStructureService $salaries, private LeaveService $leave) {}

    /**
     * @return array{period_days: int, payable_days: string, unpaid_leave_days: string, absent_days: string, late_minutes: int, overtime_minutes: int, gross: string, deductions: string, net: string, lines: list<array<string, mixed>>}
     *
     * @throws HrException with reason not_employed, no_salary_structure or deductions_exceed_earnings
     */
    public function calculate(Employee $employee, PayrollPeriod $period): array
    {
        $config = (array) config('hr.payroll', []);
        $periodDays = $period->days();
        $basis = ($config['day_basis'] ?? 'calendar') === 'calendar' ? $periodDays : max(1, (int) $config['day_basis']);

        $from = $period->start_date->copy()->max($employee->joining_date);
        $to = $employee->termination_date !== null ? $period->end_date->copy()->min($employee->termination_date) : $period->end_date->copy();
        if ($from->gt($to)) {
            throw HrException::invalid(__('Not employed in the period.'), 'not_employed');
        }

        $structures = SalaryStructure::query()->with('lines.component')->where('employee_id', $employee->id)->overlapping($from, $to)->orderBy('effective_from')->get();
        if ($structures->isEmpty()) {
            throw HrException::invalid(__('No salary in the period.'), 'no_salary_structure');
        }
        $monthly = $structures->mapWithKeys(fn (SalaryStructure $s) => [$s->id => $this->salaries->monthly($s)]);

        // The structure of each day, and the days of each structure.
        $dayStructure = [];
        $segmentDays = [];
        foreach (WorkCalendar::days($from, $to) as $day) {
            $structure = $structures->first(fn (SalaryStructure $s) => $s->effective_from->lte($day) && ($s->effective_to === null || $s->effective_to->gte($day)));
            if ($structure !== null) {
                $dayStructure[$day->toDateString()] = $structure->id;
                $segmentDays[$structure->id] = ($segmentDays[$structure->id] ?? 0) + 1;
            }
        }

        // Components, prorated.
        $components = [];
        foreach ($segmentDays as $structureId => $days) {
            foreach ($monthly[$structureId] as $componentId => ['component' => $component, 'monthly' => $amount]) {
                $share = $days === $periodDays ? $amount : Money::ratio($amount, $days, $periodDays);
                $components[$componentId] ??= ['component' => $component, 'amount' => BigDecimal::zero()];
                $components[$componentId]['amount'] = $components[$componentId]['amount']->plus($share);
            }
        }

        // A day's rate and an hour of overtime, by the structure of the day.
        $base = ($config['unpaid_deduction_base'] ?? 'gross') === 'basic' ? 'basic' : 'gross';
        $dayRate = fn (string $date): BigDecimal => Money::ratio($this->baseOf($monthly[$dayStructure[$date]], $base), 1, $basis);
        $overtimeHour = fn (string $date): BigDecimal => Money::ratio($this->baseOf($monthly[$dayStructure[$date]], 'basic'), $config['overtime']['multiplier'] ?? 2, $config['overtime']['hourly_divisor'] ?? 208);

        $leaveDays = $this->leave->approvedDays($employee->id, $from, $to);
        $unpaidDays = BigDecimal::zero();
        $unpaidAmount = BigDecimal::zero();
        foreach ($leaveDays as $date => ['fraction' => $fraction, 'paid' => $paid]) {
            if (! $paid && isset($dayStructure[$date])) {
                $unpaidDays = $unpaidDays->plus($fraction);
                $unpaidAmount = $unpaidAmount->plus($dayRate($date)->multipliedBy($fraction));
            }
        }

        $attendance = Attendance::query()->withoutGlobalScopes()->where('employee_id', $employee->id)
            ->whereDate('work_date', '>=', $from->toDateString())->whereDate('work_date', '<=', $to->toDateString())->get();
        $absentDays = BigDecimal::zero();
        $absentAmount = BigDecimal::zero();
        $overtimeMinutes = 0;
        $overtimeAmount = BigDecimal::zero();
        $lateMinutes = 0;
        foreach ($attendance as $record) {
            $date = $record->work_date->toDateString();
            if (! isset($dayStructure[$date])) {
                continue;
            }
            $lateMinutes += $record->late_minutes;
            if (($config['deduct_absence'] ?? true) && ! isset($leaveDays[$date]) && in_array($record->status, [Attendance::ABSENT, Attendance::HALF_DAY], true)) {
                $fraction = $record->status === Attendance::ABSENT ? '1' : '0.5';
                $absentDays = $absentDays->plus($fraction);
                $absentAmount = $absentAmount->plus($dayRate($date)->multipliedBy($fraction));
            }
            if (($config['overtime']['enabled'] ?? true) && $record->overtime_minutes > 0) {
                $overtimeMinutes += $record->overtime_minutes;
                $overtimeAmount = $overtimeAmount->plus(Money::ratio($overtimeHour($date), $record->overtime_minutes, 60));
            }
        }

        $keys = (array) ($config['posting_keys'] ?? []);
        $lines = [];
        $order = 0;
        foreach (collect($components)->sortBy(fn ($c) => [$c['component']->isEarning() ? 0 : 1, $c['component']->sort_order, $c['component']->id]) as $componentId => ['component' => $component, 'amount' => $amount]) {
            $rounded = Money::round($amount);
            if (Money::isPositive($rounded) || $component->code === SalaryComponent::BASIC) {
                $lines[] = $this->line($component->code, $component->name, $component->kind, PayrollItemLine::SOURCE_STRUCTURE, $component->posting_key, $rounded, ++$order, $componentId);
            }
        }
        foreach ([
            [$overtimeAmount, 'overtime', __('Overtime'), SalaryComponent::EARNING, PayrollItemLine::SOURCE_OVERTIME],
            [$unpaidAmount, 'unpaid_leave', __('Unpaid leave'), SalaryComponent::DEDUCTION, PayrollItemLine::SOURCE_UNPAID_LEAVE],
            [$absentAmount, 'absence', __('Absence'), SalaryComponent::DEDUCTION, PayrollItemLine::SOURCE_ABSENCE],
        ] as [$amount, $code, $name, $kind, $source]) {
            if (Money::isPositive(Money::round($amount))) {
                $lines[] = $this->line($code, $name, $kind, $source, (string) ($keys[$code] ?? 'salary_expense'), Money::round($amount), ++$order);
            }
        }

        // Earnings first, then deductions, each in their order.
        usort($lines, fn (array $a, array $b) => [$a['kind'] !== SalaryComponent::EARNING, $a['sort_order']] <=> [$b['kind'] !== SalaryComponent::EARNING, $b['sort_order']]);
        foreach ($lines as $index => &$sorted) {
            $sorted['sort_order'] = $index + 1;
        }
        unset($sorted);
        $order = count($lines);

        [$gross, $deductions] = $this->totals($lines);
        if (Money::compare($deductions, $gross) > 0) {
            throw HrException::invalid(__('The deductions (:deductions) are more than the earnings (:gross).', ['deductions' => $deductions, 'gross' => $gross]), 'deductions_exceed_earnings');
        }

        $left = Money::sub($gross, $deductions);
        $advances = EmployeeAdvance::query()->withoutGlobalScopes()->where('employee_id', $employee->id)->where('status', EmployeeAdvance::DISBURSED)
            ->whereDate('disbursement_date', '<=', $to->toDateString())->orderBy('disbursement_date')->orderBy('id')->get();
        foreach ($advances as $advance) {
            $recover = Money::min($advance->installment_amount ?? $advance->outstanding(), $advance->outstanding(), $left);
            if (Money::isPositive($recover)) {
                $lines[] = $this->line('advance', __('Advance :no', ['no' => $advance->advance_no]), SalaryComponent::DEDUCTION, PayrollItemLine::SOURCE_ADVANCE, PostingLine::EMPLOYEE_ADVANCE, $recover, ++$order, advanceId: $advance->id);
                $left = Money::sub($left, $recover);
            }
        }

        [$gross, $deductions] = $this->totals($lines);

        return [
            'period_days' => $periodDays,
            'payable_days' => Money::round(BigDecimal::of(array_sum($segmentDays))->minus($unpaidDays)->minus($absentDays)),
            'unpaid_leave_days' => Money::round($unpaidDays, 1),
            'absent_days' => Money::round($absentDays, 1),
            'late_minutes' => $lateMinutes,
            'overtime_minutes' => $overtimeMinutes,
            'gross' => $gross,
            'deductions' => $deductions,
            'net' => Money::sub($gross, $deductions),
            'lines' => $lines,
        ];
    }

    /** @param  array<int, array{component: SalaryComponent, monthly: BigDecimal}>  $monthly */
    private function baseOf(array $monthly, string $base): BigDecimal
    {
        $total = BigDecimal::zero();
        foreach ($monthly as ['component' => $component, 'monthly' => $amount]) {
            if ($base === 'basic' ? $component->code === SalaryComponent::BASIC : $component->isEarning()) {
                $total = $total->plus($amount);
            }
        }

        return $total;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array{0: string, 1: string}
     */
    private function totals(array $lines): array
    {
        $sum = fn (string $kind): string => Money::add(...array_map(fn (array $line) => $line['amount'], array_filter($lines, fn (array $line) => $line['kind'] === $kind)));

        return [$sum(SalaryComponent::EARNING), $sum(SalaryComponent::DEDUCTION)];
    }

    /** @return array<string, mixed> */
    private function line(string $code, string $name, string $kind, string $source, string $postingKey, string $amount, int $order, ?int $componentId = null, ?int $advanceId = null): array
    {
        return [
            'salary_component_id' => $componentId,
            'employee_advance_id' => $advanceId,
            'code' => $code,
            'name' => $name,
            'kind' => $kind,
            'source' => $source,
            'posting_key' => $postingKey,
            'amount' => $amount,
            'sort_order' => $order,
        ];
    }
}
