<?php

namespace Nit\AgraniHr\Http\Controllers\Api;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Nit\AgraniHr\Models\Employee;
use Nit\AgraniHr\Models\EmploymentType;

class EmploymentTypeController extends MasterDataController
{
    /** @var array<string, array{0: string, 1: string}> */
    public static $permissions = [
        'index' => ['hr_employment_type_view', 'View employment types'],
        'store' => ['hr_employment_type_manage', 'Add, edit and delete employment types'],
        'update' => ['hr_employment_type_manage', 'Add, edit and delete employment types'],
        'destroy' => ['hr_employment_type_manage', 'Add, edit and delete employment types'],
    ];

    protected function model(): string
    {
        return EmploymentType::class;
    }

    protected function fields(): array
    {
        return ['code', 'name', 'is_active'];
    }

    protected function rules(?Model $record): array
    {
        return [
            'code' => ['required', 'string', 'max:30', 'regex:/^[a-z0-9_]+$/', Rule::unique('hr_employment_types', 'code')->ignore($record?->getKey())],
            'name' => ['required', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    protected function inUse(Model $record): bool
    {
        return Employee::query()->withoutGlobalScopes()->withTrashed()->where('employment_type_id', $record->getKey())->exists();
    }
}
