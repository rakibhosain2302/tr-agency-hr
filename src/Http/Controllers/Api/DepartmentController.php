<?php

namespace Nit\AgraniHr\Http\Controllers\Api;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Nit\AgraniHr\Exceptions\HrException;
use Nit\AgraniHr\Models\Department;
use Nit\AgraniHr\Models\Employee;

class DepartmentController extends MasterDataController
{
    /** @var array<string, array{0: string, 1: string}> */
    public static $permissions = [
        'index' => ['hr_department_view', 'View departments'],
        'store' => ['hr_department_manage', 'Add, edit and delete departments'],
        'update' => ['hr_department_manage', 'Add, edit and delete departments'],
        'destroy' => ['hr_department_manage', 'Add, edit and delete departments'],
    ];

    protected function model(): string
    {
        return Department::class;
    }

    protected function fields(): array
    {
        return ['parent_id', 'branch_id', 'code', 'name', 'is_active'];
    }

    protected function query(Request $request): Builder
    {
        return Department::query()->when($request->integer('branch_id'), fn ($q, $id) => $q->where(fn ($w) => $w->whereNull('branch_id')->orWhere('branch_id', $id)))->orderBy('code');
    }

    protected function rules(?Model $record): array
    {
        return [
            'parent_id' => ['nullable', 'integer', Rule::exists('hr_departments', 'id')->whereNull('deleted_at'), Rule::notIn(array_filter([$record?->getKey()]))],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')],
            'code' => ['required', 'string', 'max:30', Rule::unique('hr_departments', 'code')->ignore($record?->getKey())],
            'name' => ['required', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /** @param  array<string, mixed>  $data */
    protected function prepare(array $data): array
    {
        $record = request()->route('record');
        if ($record !== null && ! empty($data['parent_id']) && in_array((int) $data['parent_id'], Department::query()->findOrFail($record)->descendantIds(), true)) {
            throw HrException::invalid(__('A department cannot be placed below itself.'), 'invalid_parent');
        }

        return $data;
    }

    protected function inUse(Model $record): bool
    {
        return $record->children()->exists() || $record->designations()->exists() || Employee::query()->withoutGlobalScopes()->withTrashed()->where('department_id', $record->getKey())->exists();
    }
}
