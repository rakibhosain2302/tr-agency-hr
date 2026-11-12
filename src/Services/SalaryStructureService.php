<?php

namespace Nit\AgraniHr\Services;

use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Nit\AgraniHr\Exceptions\HrException;
use Nit\AgraniHr\Models\Employee;
use Nit\AgraniHr\Models\SalaryComponent;
use Nit\AgraniHr\Models\SalaryStructure;
use Nit\AgraniHr\Models\SalaryStructureLine;
use Nit\AgraniHr\Support\HrLog;
use Nit\AgraniHr\Support\Money;
use Nit\AgraniHr\Support\PayrollLock;

/**
 * Salary structures. A new structure starts after the current one, which then ends the day before; days
 * already in an approved payroll cannot get another salary (reverse that payroll first).
 */
final class SalaryStructureService
{
    /**
     * @param  list<array{salary_component_id: int, amount?: string|int|float|null, percent?: string|int|float|null}>  $lines
     */
    public function create(Employee $employee, \DateTimeInterface|string $effectiveFrom, array $lines, ?string $notes = null): SalaryStructure
    {
        $from = Carbon::parse($effectiveFrom)->startOfDay();

        return DB::transaction(function () use ($employee, $from, $lines, $notes): SalaryStructure {
            $employee = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            if ($from->lt($employee->joining_date)) {
                throw HrException::invalid(__('A salary cannot start before the joining date (:date).', ['date' => $employee->joining_date->toDateString()]), 'invalid_date');
            }
            $latest = SalaryStructure::query()->where('employee_id', $employee->id)->orderByDesc('effective_from')->first();
            if ($latest !== null && $from->lte($latest->effective_from)) {
                throw HrException::invalid(__('A new salary starts after the current one (:date); change that one instead.', ['date' => $latest->effective_from->toDateString()]), 'invalid_date');
            }
            PayrollLock::ensureOpen($employee->id, $from, $latest?->effective_to ?? $from->copy()->addYears(50));

            $prepared = $this->prepare($lines);
            $latest?->forceFill(['effective_to' => $from->copy()->subDay()->toDateString()])->save();

            $structure = SalaryStructure::query()->create(['employee_id' => $employee->id, 'effective_from' => $from->toDateString(), 'notes' => $notes]);
            $structure->lines()->createMany($prepared);

            HrLog::write('hr_salary.created', "Salary of {$employee->activityLabel()} from {$from->toDateString()}", $structure, $employee->branch_id);

            return $structure->load('lines.component');
        });
    }

    /**
     * Correct the components of a structure none of whose days are in an approved payroll.
     *
     * @param  list<array{salary_component_id: int, amount?: string|int|float|null, percent?: string|int|float|null}>  $lines
     */
    public function update(SalaryStructure $structure, array $lines, ?string $notes = null): SalaryStructure
    {
        return DB::transaction(function () use ($structure, $lines, $notes): SalaryStructure {
            $structure = SalaryStructure::query()->whereKey($structure->id)->lockForUpdate()->firstOrFail();
            PayrollLock::ensureOpen($structure->employee_id, $structure->effective_from, $structure->effective_to ?? $structure->effective_from->copy()->addYears(50));

            $prepared = $this->prepare($lines);
            $structure->lines()->delete();
            $structure->lines()->createMany($prepared);
            $structure->update(['notes' => $notes ?? $structure->notes]);

            return $structure->load('lines.component');
        });
    }

    /** Delete the latest structure (unused by approved payrolls); the one before runs on again. */
    public function delete(SalaryStructure $structure): void
    {
        DB::transaction(function () use ($structure): void {
            $structure = SalaryStructure::query()->whereKey($structure->id)->lockForUpdate()->firstOrFail();
            $later = SalaryStructure::query()->where('employee_id', $structure->employee_id)->whereDate('effective_from', '>', $structure->effective_from->toDateString())->exists();
            if ($later) {
                throw HrException::invalid(__('Only the latest salary can be deleted.'), 'not_latest');
            }
            PayrollLock::ensureOpen($structure->employee_id, $structure->effective_from, $structure->effective_to ?? $structure->effective_from->copy()->addYears(50));

            SalaryStructure::query()->where('employee_id', $structure->employee_id)->whereDate('effective_to', $structure->effective_from->copy()->subDay()->toDateString())
                ->update(['effective_to' => $structure->effective_to?->toDateString()]);
            $structure->delete();
        });
    }

    /** The structure in force on $date. */
    public function on(int $employeeId, \DateTimeInterface|string $date): ?SalaryStructure
    {
        $day = Carbon::parse($date)->toDateString();

        return SalaryStructure::query()->with('lines.component')->where('employee_id', $employeeId)
            ->whereDate('effective_from', '<=', $day)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day))
            ->first();
    }

    /**
     * Monthly amount of every component of the structure (percentages resolved against basic).
     *
     * @return array<int, array{component: SalaryComponent, monthly: BigDecimal}>
     */
    public function monthly(SalaryStructure $structure): array
    {
        $structure->loadMissing('lines.component');
        $basic = $structure->lines->first(fn (SalaryStructureLine $line) => $line->component->code === SalaryComponent::BASIC);
        $basicAmount = Money::of($basic?->amount);

        $amounts = [];
        foreach ($structure->lines->sortBy(fn (SalaryStructureLine $line) => [$line->component->sort_order, $line->component->id]) as $line) {
            $monthly = $line->component->calculation === SalaryComponent::PERCENT_OF_BASIC
                ? Money::ratio($basicAmount, $line->percent ?? '0', 100)
                : Money::of($line->amount);
            $amounts[$line->salary_component_id] = ['component' => $line->component, 'monthly' => $monthly];
        }

        return $amounts;
    }

    /**
     * @param  list<array{salary_component_id: int, amount?: string|int|float|null, percent?: string|int|float|null}>  $lines
     * @return list<array{salary_component_id: int, amount: ?string, percent: ?string}>
     */
    private function prepare(array $lines): array
    {
        $components = SalaryComponent::query()->whereIn('id', array_column($lines, 'salary_component_id'))->get()->keyBy('id');
        $prepared = [];
        $hasBasic = false;

        foreach ($lines as $line) {
            $component = $components[(int) $line['salary_component_id']] ?? throw HrException::invalid(__('Unknown salary component.'), 'invalid_component');
            if (isset($prepared[$component->id])) {
                throw HrException::invalid(__(':name is given twice.', ['name' => $component->name]), 'duplicate_component');
            }
            if (! $component->is_active) {
                throw HrException::invalid(__(':name is no longer used.', ['name' => $component->name]), 'inactive_component');
            }

            $percent = $component->calculation === SalaryComponent::PERCENT_OF_BASIC;
            $value = $line[$percent ? 'percent' : 'amount'] ?? $component->default_value;
            if ($value === null || $value === '' || Money::compare($value, '0') < 0) {
                throw HrException::invalid(__(':name needs a :what.', ['name' => $component->name, 'what' => $percent ? __('percentage') : __('monthly amount')]), 'invalid_amount');
            }
            if ($component->code === SalaryComponent::BASIC) {
                $hasBasic = Money::isPositive($value);
            }

            $prepared[$component->id] = [
                'salary_component_id' => $component->id,
                'amount' => $percent ? null : Money::round($value),
                'percent' => $percent ? Money::round($value, 4) : null,
            ];
        }

        if (! $hasBasic) {
            throw HrException::invalid(__('A salary needs a basic amount above zero.'), 'basic_required');
        }

        return array_values($prepared);
    }
}
