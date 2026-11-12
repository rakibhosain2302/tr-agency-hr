<?php

namespace Nit\AgraniHr\Http\Controllers\Api;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Nit\AgraniHr\Exceptions\HrException;
use Nit\AgraniHr\Models\Employee;
use Nit\AgraniHr\Models\EmployeeStatus;

/**
 * Employee statuses. The two system statuses ("active", "separated") cannot be deleted or change whether
 * they count as employed.
 */
class EmployeeStatusController extends MasterDataController
{
    /** @var array<string, array{0: string, 1: string}> */
    public static $permissions = [
        'index' => ['hr_employee_status_view', 'View employee statuses'],
        'store' => ['hr_employee_status_manage', 'Add, edit and delete employee statuses'],
        'update' => ['hr_employee_status_manage', 'Add, edit and delete employee statuses'],
        'destroy' => ['hr_employee_status_manage', 'Add, edit and delete employee statuses'],
    ];

    protected function model(): string
    {
        return EmployeeStatus::class;
    }

    protected function fields(): array
    {
        return ['code', 'name', 'is_employed', 'is_system', 'sort_order'];
    }

    protected function query(Request $request): Builder
    {
        return EmployeeStatus::query()->orderBy('sort_order')->orderBy('code');
    }

    protected function rules(?Model $record): array
    {
        return [
            'code' => ['required', 'string', 'max:30', 'regex:/^[a-z0-9_]+$/', Rule::unique('hr_employee_statuses', 'code')->ignore($record?->getKey())],
            'name' => ['required', 'string', 'max:100'],
            'is_employed' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /** @param  array<string, mixed>  $data */
    protected function prepare(array $data): array
    {
        $record = request()->route('record');
        $current = $record !== null ? EmployeeStatus::query()->findOrFail($record) : null;
        if ($current?->is_system && ($data['code'] !== $current->code || (bool) $data['is_employed'] !== $current->is_employed)) {
            throw HrException::invalid(__('A system status keeps its code and whether it counts as employed.'), 'system_status');
        }

        return $data;
    }

    protected function inUse(Model $record): bool
    {
        return $record->is_system || Employee::query()->withoutGlobalScopes()->withTrashed()->where('employee_status_id', $record->getKey())->exists();
    }
}
