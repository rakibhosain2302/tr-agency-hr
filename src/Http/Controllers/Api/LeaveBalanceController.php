<?php

namespace Nit\AgraniHr\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Nit\AgraniHr\Models\Employee;
use Nit\AgraniHr\Models\LeaveLedgerEntry;
use Nit\AgraniHr\Models\LeaveType;
use Nit\AgraniHr\Services\LeaveService;
use Nit\Master\Attributes\Permission;

/**
 * An employee's leave balances and their history (the leave ledger), with entitlements, adjustments and
 * carry forward.
 */
class LeaveBalanceController extends Controller
{
    public function __construct(private LeaveService $leave) {}

    #[Permission('hr_leave_balance_view', 'View leave balances and history')]
    public function index(Request $request, Employee $employee): JsonResponse
    {
        $year = (int) ($request->validate(['year' => ['nullable', 'integer', 'min:2000', 'max:2100']])['year'] ?? now()->year);

        return response()->json(['data' => $this->leave->balances($employee, $year), 'meta' => ['year' => $year]]);
    }

    #[Permission('hr_leave_balance_view', 'View leave balances and history')]
    public function history(Request $request, Employee $employee): JsonResponse
    {
        $request->validate(['year' => ['nullable', 'integer'], 'leave_type_id' => ['nullable', 'integer']]);

        return response()->json(['data' => LeaveLedgerEntry::query()->with(['leaveType:id,code,name', 'application:id,uuid'])->where('employee_id', $employee->id)
            ->when($request->integer('year'), fn ($q, $year) => $q->where('year', $year))
            ->when($request->integer('leave_type_id'), fn ($q, $id) => $q->where('leave_type_id', $id))
            ->orderBy('entry_date')->orderBy('id')->get()->map(fn (LeaveLedgerEntry $e) => [
                'id' => $e->id,
                'leave_type' => $e->leaveType?->code,
                'year' => $e->year,
                'kind' => $e->kind,
                'days' => $e->days,
                'leave_application_id' => $e->application?->uuid,
                'entry_date' => $e->entry_date->toDateString(),
                'notes' => $e->notes,
                'created_by' => $e->created_by,
            ])->all()]);
    }

    #[Permission('hr_leave_balance_manage', 'Change leave balances')]
    public function adjust(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'leave_type_id' => ['required', 'integer', Rule::exists('hr_leave_types', 'id')],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'action' => ['required', Rule::in(['allocate', 'adjust', 'carry_forward'])],
            'days' => [Rule::requiredIf($request->input('action') === 'adjust'), 'nullable', 'numeric', 'multiple_of:0.5', 'between:-366,366'],
            'notes' => [Rule::requiredIf($request->input('action') === 'adjust'), 'nullable', 'string', 'max:255'],
        ]);
        $type = LeaveType::query()->findOrFail($data['leave_type_id']);

        match ($data['action']) {
            'allocate' => $this->leave->allocate($employee, $type, (int) $data['year'], $data['days'] ?? null),
            'adjust' => $this->leave->adjust($employee, $type, (int) $data['year'], $data['days'], $data['notes']),
            'carry_forward' => $this->leave->carryForward($employee, $type, (int) $data['year']),
        };
        $year = $data['action'] === 'carry_forward' ? (int) $data['year'] + 1 : (int) $data['year'];

        return response()->json(['data' => collect($this->leave->balances($employee, $year))->firstWhere('leave_type_id', $type->id), 'meta' => ['year' => $year]]);
    }
}
