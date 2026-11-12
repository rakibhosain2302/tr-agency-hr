<?php

namespace Nit\AgraniHr\Http\Controllers\Api;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Nit\AgraniHr\Exceptions\HrException;
use Nit\AgraniHr\Models\PayrollItemLine;
use Nit\AgraniHr\Models\SalaryComponent;
use Nit\AgraniHr\Models\SalaryStructureLine;

/**
 * Salary components. The system component "basic" keeps its code, kind and calculation.
 */
class SalaryComponentController extends MasterDataController
{
    /** @var array<string, array{0: string, 1: string}> */
    public static $permissions = [
        'index' => ['hr_salary_component_view', 'View salary components'],
        'store' => ['hr_salary_component_manage', 'Add, edit and delete salary components'],
        'update' => ['hr_salary_component_manage', 'Add, edit and delete salary components'],
        'destroy' => ['hr_salary_component_manage', 'Add, edit and delete salary components'],
    ];

    protected function model(): string
    {
        return SalaryComponent::class;
    }

    protected function fields(): array
    {
        return ['code', 'name', 'kind', 'calculation', 'default_value', 'posting_key', 'is_system', 'is_active', 'sort_order'];
    }

    protected function rules(?Model $record): array
    {
        return [
            'code' => ['required', 'string', 'max:30', 'regex:/^[a-z0-9_]+$/', Rule::unique('hr_salary_components', 'code')->ignore($record?->getKey())],
            'name' => ['required', 'string', 'max:100'],
            'kind' => ['required', Rule::in([SalaryComponent::EARNING, SalaryComponent::DEDUCTION])],
            'calculation' => ['required', Rule::in([SalaryComponent::FIXED, SalaryComponent::PERCENT_OF_BASIC])],
            'default_value' => ['nullable', 'numeric', 'min:0'],
            'posting_key' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/', Rule::notIn(['settlement'])],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }

    protected function prepare(array $data): array
    {
        $record = request()->route('record');
        $current = $record !== null ? SalaryComponent::query()->findOrFail($record) : null;
        if ($current?->is_system && ($data['code'] !== $current->code || $data['kind'] !== $current->kind || $data['calculation'] !== $current->calculation)) {
            throw HrException::invalid(__('The basic salary keeps its code, kind and calculation.'), 'system_component');
        }
        if ($current === null && $data['code'] === SalaryComponent::BASIC) {
            throw HrException::invalid(__('"basic" is the system component.'), 'system_component');
        }

        return $data;
    }

    protected function inUse(Model $record): bool
    {
        return $record->is_system || SalaryStructureLine::query()->where('salary_component_id', $record->getKey())->exists()
            || PayrollItemLine::query()->where('salary_component_id', $record->getKey())->exists();
    }
}
