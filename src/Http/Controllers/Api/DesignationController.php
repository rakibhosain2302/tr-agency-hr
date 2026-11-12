<?php

namespace Nit\AgraniHr\Http\Controllers\Api;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Nit\AgraniHr\Models\Designation;
use Nit\AgraniHr\Models\Employee;

class DesignationController extends MasterDataController
{
    /** @var array<string, array{0: string, 1: string}> */
    public static $permissions = [
        'index' => ['hr_designation_view', 'View designations'],
        'store' => ['hr_designation_manage', 'Add, edit and delete designations'],
        'update' => ['hr_designation_manage', 'Add, edit and delete designations'],
        'destroy' => ['hr_designation_manage', 'Add, edit and delete designations'],
    ];

    protected function model(): string
    {
        return Designation::class;
    }

    protected function fields(): array
    {
        return ['department_id', 'code', 'name', 'is_active'];
    }

    protected function query(Request $request): Builder
    {
        return Designation::query()->when($request->integer('department_id'), fn ($q, $id) => $q->where(fn ($w) => $w->whereNull('department_id')->orWhere('department_id', $id)))->orderBy('code');
    }

    protected function rules(?Model $record): array
    {
        return [
            'department_id' => ['nullable', 'integer', Rule::exists('hr_departments', 'id')->whereNull('deleted_at')],
            'code' => ['required', 'string', 'max:30', Rule::unique('hr_designations', 'code')->ignore($record?->getKey())],
            'name' => ['required', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    protected function inUse(Model $record): bool
    {
        return Employee::query()->withoutGlobalScopes()->withTrashed()->where('designation_id', $record->getKey())->exists();
    }
}
