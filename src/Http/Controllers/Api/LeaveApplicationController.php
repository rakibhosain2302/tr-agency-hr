<?php

namespace Nit\AgraniHr\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Nit\AgraniHr\Http\Controllers\Concerns\ResolvesEmployees;
use Nit\AgraniHr\Models\LeaveApplication;
use Nit\AgraniHr\Models\LeaveType;
use Nit\AgraniHr\Services\LeaveService;
use Nit\Master\Attributes\Permission;

/**
 * Leave applications of the branches the user sees. Without employee_id (or without hr_leave_view) a user
 * applies for their own employee record.
 */
class LeaveApplicationController extends Controller
{
    use ResolvesEmployees;

    public function __construct(private LeaveService $leave) {}

    #[Permission('hr_leave_view', 'View leave applications')]
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'employee_id' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in([LeaveApplication::PENDING, LeaveApplication::APPROVED, LeaveApplication::REJECTED, LeaveApplication::CANCELLED])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        return response()->json(['data' => LeaveApplication::query()->with(['employee:id,uuid,employee_code,first_name,last_name', 'leaveType'])
            ->when($request->input('employee_id'), fn ($q, $uuid) => $q->whereHas('employee', fn ($e) => $e->where('uuid', $uuid)))
            ->when($request->input('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->input('from'), fn ($q, $date) => $q->whereDate('end_date', '>=', $date))
            ->when($request->input('to'), fn ($q, $date) => $q->whereDate('start_date', '<=', $date))
            ->orderByDesc('start_date')->limit(500)->get()->map(fn (LeaveApplication $a) => self::present($a))->all()]);
    }

    #[Permission('hr_leave_view', 'View leave applications')]
    public function show(LeaveApplication $application): JsonResponse
    {
        return response()->json(['data' => self::present($application->load(['employee', 'leaveType']))]);
    }

    #[Permission('hr_leave_apply', 'Apply for and cancel leave')]
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['nullable', 'uuid'],
            'leave_type_id' => ['required', 'integer', Rule::exists('hr_leave_types', 'id')],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'half_day' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $application = $this->leave->apply($this->employeeOrSelf($data['employee_id'] ?? null, 'hr_leave_view'), LeaveType::query()->findOrFail($data['leave_type_id']), $data['start_date'], $data['end_date'], (bool) ($data['half_day'] ?? false), $data['reason'] ?? null);

        return response()->json(['data' => self::present($application->load(['employee', 'leaveType']))], 201);
    }

    #[Permission('hr_leave_approve', 'Approve or reject leave')]
    public function approve(Request $request, LeaveApplication $application): JsonResponse
    {
        return response()->json(['data' => self::present($this->leave->approve($application, $this->note($request))->load(['employee', 'leaveType']))]);
    }

    #[Permission('hr_leave_approve', 'Approve or reject leave')]
    public function reject(Request $request, LeaveApplication $application): JsonResponse
    {
        return response()->json(['data' => self::present($this->leave->reject($application, $this->note($request))->load(['employee', 'leaveType']))]);
    }

    #[Permission('hr_leave_apply', 'Apply for and cancel leave')]
    public function cancel(Request $request, LeaveApplication $application): JsonResponse
    {
        $this->ensureOwnOr($application->employee_id, 'hr_leave_view');

        return response()->json(['data' => self::present($this->leave->cancel($application, $this->note($request))->load(['employee', 'leaveType']))]);
    }

    private function note(Request $request): ?string
    {
        return $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;
    }

    /** @return array<string, mixed> */
    public static function present(LeaveApplication $a): array
    {
        return [
            'id' => $a->uuid,
            'employee_id' => $a->employee?->uuid,
            'employee_code' => $a->employee?->employee_code,
            'leave_type' => ['id' => $a->leave_type_id, 'code' => $a->leaveType?->code, 'name' => $a->leaveType?->name, 'is_paid' => $a->leaveType?->is_paid],
            'start_date' => $a->start_date->toDateString(),
            'end_date' => $a->end_date->toDateString(),
            'half_day' => $a->half_day,
            'days' => $a->days,
            'reason' => $a->reason,
            'status' => $a->status,
            'applied_by' => $a->applied_by,
            'decided_by' => $a->decided_by,
            'decided_at' => $a->decided_at?->toIso8601String(),
            'decision_note' => $a->decision_note,
        ];
    }
}
