<?php

namespace Nit\AgraniHr\Http\Controllers\Api;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Nit\AgraniHr\Models\LeaveApplication;
use Nit\AgraniHr\Models\LeaveLedgerEntry;
use Nit\AgraniHr\Models\LeaveType;

class LeaveTypeController extends MasterDataController
{
    /** @var array<string, array{0: string, 1: string}> */
    public static $permissions = [
        'index' => ['hr_leave_type_view', 'View leave types'],
        'store' => ['hr_leave_type_manage', 'Add, edit and delete leave types'],
        'update' => ['hr_leave_type_manage', 'Add, edit and delete leave types'],
        'destroy' => ['hr_leave_type_manage', 'Add, edit and delete leave types'],
    ];

    protected function model(): string
    {
        return LeaveType::class;
    }

    protected function fields(): array
    {
        return ['code', 'name', 'days_per_year', 'is_paid', 'carry_forward_max', 'allow_half_day', 'is_active'];
    }

    protected function rules(?Model $record): array
    {
        return [
            'code' => ['required', 'string', 'max:30', 'regex:/^[a-z0-9_]+$/', Rule::unique('hr_leave_types', 'code')->ignore($record?->getKey())],
            'name' => ['required', 'string', 'max:100'],
            'days_per_year' => ['nullable', 'numeric', 'min:0', 'max:366', 'multiple_of:0.5'],
            'is_paid' => ['required', 'boolean'],
            'carry_forward_max' => ['nullable', 'numeric', 'min:0', 'max:366', 'multiple_of:0.5'],
            'allow_half_day' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    protected function prepare(array $data): array
    {
        return $data + ['days_per_year' => null, 'carry_forward_max' => 0];
    }

    protected function inUse(Model $record): bool
    {
        return LeaveApplication::query()->withoutGlobalScopes()->where('leave_type_id', $record->getKey())->exists()
            || LeaveLedgerEntry::query()->where('leave_type_id', $record->getKey())->exists();
    }
}
