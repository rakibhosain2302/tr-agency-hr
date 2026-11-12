<?php

namespace Nit\AgraniHr\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Nit\AgraniHr\Http\Controllers\Concerns\ResolvesEmployees;
use Nit\AgraniHr\Models\Attendance;
use Nit\AgraniHr\Models\AttendanceCorrection;
use Nit\AgraniHr\Services\AttendanceService;
use Nit\Master\Attributes\Permission;

/**
 * Attendance corrections: asked for by the employee (or HR for them), decided by someone else.
 */
class AttendanceCorrectionController extends Controller
{
    use ResolvesEmployees;

    public function __construct(private AttendanceService $attendance) {}

    #[Permission('hr_attendance_view', 'View attendance')]
    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', Rule::in([AttendanceCorrection::PENDING, AttendanceCorrection::APPROVED, AttendanceCorrection::REJECTED])]]);

        return response()->json(['data' => AttendanceCorrection::query()->with('employee:id,uuid,employee_code')
            ->when($request->input('status'), fn ($q, $status) => $q->where('status', $status))
            ->latest('id')->limit(500)->get()->map(fn (AttendanceCorrection $c) => $this->present($c))->all()]);
    }

    #[Permission('hr_attendance_correction_request', 'Ask for attendance corrections')]
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['nullable', 'uuid'],
            'work_date' => ['required', 'date'],
            'status' => ['nullable', Rule::in(Attendance::STATUSES)],
            'check_in_at' => ['nullable', 'string', 'max:25'],
            'check_out_at' => ['nullable', 'string', 'max:25'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $correction = $this->attendance->requestCorrection($this->employeeOrSelf($data['employee_id'] ?? null, 'hr_attendance_view'), $data['work_date'], collect($data)->only(['status', 'check_in_at', 'check_out_at'])->all(), $data['reason']);

        return response()->json(['data' => $this->present($correction->load('employee'))], 201);
    }

    #[Permission('hr_attendance_correction_approve', 'Approve or reject attendance corrections')]
    public function approve(Request $request, int $correction): JsonResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

        return response()->json(['data' => $this->present($this->attendance->approveCorrection(AttendanceCorrection::query()->findOrFail($correction), $note)->load('employee'))]);
    }

    #[Permission('hr_attendance_correction_approve', 'Approve or reject attendance corrections')]
    public function reject(Request $request, int $correction): JsonResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

        return response()->json(['data' => $this->present($this->attendance->rejectCorrection(AttendanceCorrection::query()->findOrFail($correction), $note)->load('employee'))]);
    }

    /** @return array<string, mixed> */
    private function present(AttendanceCorrection $c): array
    {
        return [
            'id' => $c->id,
            'employee_id' => $c->employee?->uuid,
            'work_date' => $c->work_date->toDateString(),
            'requested_status' => $c->requested_status,
            'check_in_at' => $c->check_in_at?->format('Y-m-d H:i'),
            'check_out_at' => $c->check_out_at?->format('Y-m-d H:i'),
            'reason' => $c->reason,
            'status' => $c->status,
            'old' => ['status' => $c->old_status, 'check_in_at' => $c->old_check_in_at?->format('Y-m-d H:i'), 'check_out_at' => $c->old_check_out_at?->format('Y-m-d H:i')],
            'requested_by' => $c->requested_by,
            'decided_by' => $c->decided_by,
            'decided_at' => $c->decided_at?->toIso8601String(),
            'decision_note' => $c->decision_note,
        ];
    }
}
