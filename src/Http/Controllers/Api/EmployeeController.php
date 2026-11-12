<?php

namespace Nit\AgraniHr\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Nit\AgraniHr\Http\Requests\EmployeeRequest;
use Nit\AgraniHr\Http\Resources\EmployeeResource;
use Nit\AgraniHr\Models\Employee;
use Nit\AgraniHr\Services\EmployeeService;
use Nit\Master\Attributes\Permission;
use Nit\Master\Support\Branches;
use Nit\Master\Support\Table;

/**
 * Employees of the branches the user sees (main branch or a current assignment); others answer 404.
 */
class EmployeeController extends Controller
{
    public function __construct(private EmployeeService $employees) {}

    #[Permission('hr_employee_view', 'View employees and their history')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'designation_id' => ['nullable', 'integer'],
            'employment_type_id' => ['nullable', 'integer'],
            'employee_status_id' => ['nullable', 'integer'],
            'employed' => ['nullable', 'boolean'],
        ]);

        $query = Employee::query()->with(['department', 'designation', 'employmentType', 'status'])
            ->when($request->integer('branch_id'), fn ($q, $id) => $q->where('branch_id', $id))
            ->when($request->integer('department_id'), fn ($q, $id) => $q->where('department_id', $id))
            ->when($request->integer('designation_id'), fn ($q, $id) => $q->where('designation_id', $id))
            ->when($request->integer('employment_type_id'), fn ($q, $id) => $q->where('employment_type_id', $id))
            ->when($request->integer('employee_status_id'), fn ($q, $id) => $q->where('employee_status_id', $id))
            ->when($request->has('employed'), fn ($q) => $request->boolean('employed') ? $q->employed() : $q->whereHas('status', fn ($s) => $s->where('is_employed', false)));

        return EmployeeResource::collection(Table::make($query, $request)
            ->search(['employee_code', 'first_name', 'last_name', 'phone', 'email', 'national_id'])
            ->sortable(['employee_code', 'first_name', 'joining_date'], 'employee_code')
            ->paginate(min(100, max(1, $request->integer('per_page', 25)))));
    }

    #[Permission('hr_employee_view', 'View employees and their history')]
    public function show(Employee $employee): EmployeeResource
    {
        return new EmployeeResource($employee->load(['department', 'designation', 'employmentType', 'status', 'personalInfo', 'branchAssignments']));
    }

    #[Permission('hr_employee_create', 'Hire employees')]
    public function store(EmployeeRequest $request): JsonResponse
    {
        $employee = $this->employees->hire($request->employeeData(), $request->personalData() ?? []);

        return (new EmployeeResource($employee->load(['status', 'personalInfo', 'branchAssignments'])))->response()->setStatusCode(201);
    }

    #[Permission('hr_employee_edit', 'Change employees')]
    public function update(EmployeeRequest $request, Employee $employee): EmployeeResource
    {
        $updated = $this->employees->update($employee, $request->employeeData(), $request->personalData(), $request->validated('effective_date'));

        return new EmployeeResource($updated->load(['department', 'designation', 'employmentType', 'status', 'personalInfo', 'branchAssignments']));
    }

    #[Permission('hr_employee_delete', 'Delete employees')]
    public function destroy(Employee $employee): Response
    {
        $employee->delete();

        return response()->noContent();
    }

    #[Permission('hr_employee_terminate', 'Terminate and rehire employees')]
    public function terminate(Request $request, Employee $employee): EmployeeResource
    {
        $data = $request->validate([
            'termination_date' => ['required', 'date'],
            'employee_status_id' => ['required', 'integer', Rule::exists('hr_employee_statuses', 'id')],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        return new EmployeeResource($this->employees->terminate($employee, $data['termination_date'], (int) $data['employee_status_id'], $data['reason'] ?? null)->load('status'));
    }

    #[Permission('hr_employee_terminate', 'Terminate and rehire employees')]
    public function rehire(Request $request, Employee $employee): EmployeeResource
    {
        $data = $request->validate([
            'rehire_date' => ['required', 'date'],
            'employee_status_id' => ['required', 'integer', Rule::exists('hr_employee_statuses', 'id')],
            'branch_id' => Branches::rule(),
        ]);

        return new EmployeeResource($this->employees->rehire($employee, $data['rehire_date'], (int) $data['employee_status_id'], isset($data['branch_id']) ? (int) $data['branch_id'] : null)->load(['status', 'branchAssignments']));
    }

    #[Permission('hr_employee_link_user', 'Link employees to user accounts')]
    public function linkUser(Request $request, Employee $employee): EmployeeResource
    {
        $data = $request->validate(['user_id' => ['required', 'integer']]);

        return new EmployeeResource($this->employees->linkUser($employee, (int) $data['user_id']));
    }

    #[Permission('hr_employee_link_user', 'Link employees to user accounts')]
    public function unlinkUser(Employee $employee): EmployeeResource
    {
        return new EmployeeResource($this->employees->unlinkUser($employee));
    }

    #[Permission('hr_employee_view', 'View employees and their history')]
    public function history(Employee $employee): JsonResponse
    {
        return response()->json(['data' => $employee->history()->get()->map(fn ($entry): array => [
            'id' => $entry->id,
            'event' => $entry->event,
            'field' => $entry->field,
            'old_value' => $entry->old_value,
            'new_value' => $entry->new_value,
            'effective_date' => $entry->effective_date->toDateString(),
            'notes' => $entry->notes,
            'created_by' => $entry->created_by,
            'created_at' => $entry->created_at?->toIso8601String(),
        ])->all()]);
    }
}
