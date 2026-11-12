<?php

namespace Nit\AgraniHr\Http\Controllers\Api;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Nit\AgraniHr\Exceptions\HrException;
use Nit\AgraniHr\Models\Payroll;
use Nit\AgraniHr\Models\PayrollPeriod;

/**
 * Pay periods. Periods do not overlap; a period with payrolls keeps its dates (it can still be closed).
 */
class PayrollPeriodController extends MasterDataController
{
    /** @var array<string, array{0: string, 1: string}> */
    public static $permissions = [
        'index' => ['hr_payroll_period_view', 'View pay periods'],
        'store' => ['hr_payroll_period_manage', 'Add, edit, close and delete pay periods'],
        'update' => ['hr_payroll_period_manage', 'Add, edit, close and delete pay periods'],
        'destroy' => ['hr_payroll_period_manage', 'Add, edit, close and delete pay periods'],
    ];

    protected function model(): string
    {
        return PayrollPeriod::class;
    }

    protected function fields(): array
    {
        return ['code', 'name', 'start_date', 'end_date', 'status'];
    }

    protected function query(Request $request): Builder
    {
        return PayrollPeriod::query()->orderByDesc('start_date');
    }

    protected function rules(?Model $record): array
    {
        return [
            'code' => ['required', 'string', 'max:30', Rule::unique('hr_payroll_periods', 'code')->ignore($record?->getKey())],
            'name' => ['required', 'string', 'max:100'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date', 'before_or_equal:'.Carbon::parse((string) request('start_date', now()))->addDays(30)->toDateString()],
            'status' => ['nullable', Rule::in([PayrollPeriod::OPEN, PayrollPeriod::CLOSED])],
        ];
    }

    protected function prepare(array $data): array
    {
        $record = request()->route('record');
        $overlap = PayrollPeriod::query()->when($record, fn ($q) => $q->whereKeyNot($record))
            ->whereDate('start_date', '<=', $data['end_date'])->whereDate('end_date', '>=', $data['start_date'])->exists();
        if ($overlap) {
            throw HrException::invalid(__('The period overlaps another one.'), 'period_overlap');
        }
        if ($record !== null) {
            $current = PayrollPeriod::query()->findOrFail($record);
            $datesChanged = $current->start_date->toDateString() !== Carbon::parse($data['start_date'])->toDateString() || $current->end_date->toDateString() !== Carbon::parse($data['end_date'])->toDateString();
            if ($datesChanged && $this->inUse($current)) {
                throw HrException::invalid(__('A period with payrolls keeps its dates.'), 'in_use');
            }
        }

        return $data + ['status' => PayrollPeriod::OPEN];
    }

    protected function present(Model $record): array
    {
        return ['id' => $record->getKey(), 'code' => $record->code, 'name' => $record->name, 'start_date' => $record->start_date->toDateString(), 'end_date' => $record->end_date->toDateString(), 'status' => $record->status];
    }

    protected function inUse(Model $record): bool
    {
        return Payroll::query()->withoutGlobalScopes()->where('payroll_period_id', $record->getKey())->exists();
    }
}
