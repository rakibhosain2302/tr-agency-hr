<?php

namespace Nit\AgraniHr\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Nit\AgraniHr\Http\Controllers\Concerns\ResolvesEmployees;
use Nit\AgraniHr\Models\Attendance;
use Nit\AgraniHr\Models\Employee;
use Nit\AgraniHr\Services\AttendanceService;
use Nit\Master\Attributes\Permission;
use Nit\Master\Support\Table;

/**
 * Attendance of the branches the user sees; the user's own check-in and check-out.
 */
class AttendanceController extends Controller
{
    use ResolvesEmployees;

    public function __construct(private AttendanceService $attendance) {}

    #[Permission('hr_attendance_view', 'View attendance')]
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'employee_id' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'status' => ['nullable', Rule::in(Attendance::STATUSES)],
            'late' => ['nullable', 'boolean'],
        ]);

        $query = Attendance::query()->with('employee:id,uuid,employee_code,first_name,last_name')
            ->when($request->input('employee_id'), fn ($q, $uuid) => $q->whereHas('employee', fn ($e) => $e->where('uuid', $uuid)))
            ->when($request->input('from'), fn ($q, $date) => $q->whereDate('work_date', '>=', $date))
            ->when($request->input('to'), fn ($q, $date) => $q->whereDate('work_date', '<=', $date))
            ->when($request->input('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->boolean('late'), fn ($q) => $q->where('late_minutes', '>', 0));

        $page = Table::make($query, $request)->sortable(['work_date', 'late_minutes', 'overtime_minutes'], 'work_date')->paginate(min(200, max(1, $request->integer('per_page', 50))));

        return response()->json(['data' => collect($page->items())->map(fn (Attendance $a) => self::present($a))->all(), 'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    #[Permission('hr_attendance_manage', 'Record and change attendance of employees')]
    public function record(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'work_date' => ['required', 'date'],
            'status' => ['nullable', Rule::in(Attendance::STATUSES)],
            'check_in_at' => ['nullable', 'string', 'max:25'],
            'check_out_at' => ['nullable', 'string', 'max:25'],
            'branch_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $record = $this->attendance->record($employee, $data['work_date'], collect($data)->except('work_date')->filter(fn ($v, $k) => $request->has($k))->all());

        return response()->json(['data' => self::present($record)]);
    }

    #[Permission('hr_attendance_self', 'Check in and out')]
    public function checkIn(Request $request): JsonResponse
    {
        $data = $request->validate(['branch_id' => ['nullable', 'integer']]);

        return response()->json(['data' => self::present($this->attendance->checkIn($this->employeeOrSelf(null), now(), $data['branch_id'] ?? null))], 201);
    }

    #[Permission('hr_attendance_self', 'Check in and out')]
    public function checkOut(): JsonResponse
    {
        return response()->json(['data' => self::present($this->attendance->checkOut($this->employeeOrSelf(null), now()))]);
    }

    /** @return array<string, mixed> */
    public static function present(Attendance $attendance): array
    {
        return [
            'id' => $attendance->id,
            'employee_id' => $attendance->relationLoaded('employee') ? $attendance->employee?->uuid : null,
            'employee_code' => $attendance->relationLoaded('employee') ? $attendance->employee?->employee_code : null,
            'branch_id' => $attendance->branch_id,
            'work_date' => $attendance->work_date->toDateString(),
            'status' => $attendance->status,
            'check_in_at' => $attendance->check_in_at?->format('Y-m-d H:i'),
            'check_out_at' => $attendance->check_out_at?->format('Y-m-d H:i'),
            'scheduled_minutes' => $attendance->scheduled_minutes,
            'worked_minutes' => $attendance->worked_minutes,
            'late_minutes' => $attendance->late_minutes,
            'overtime_minutes' => $attendance->overtime_minutes,
            'source' => $attendance->source,
            'notes' => $attendance->notes,
        ];
    }
}
