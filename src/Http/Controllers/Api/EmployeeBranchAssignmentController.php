<?php

namespace Nit\AgraniHr\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nit\AgraniHr\Models\Employee;
use Nit\AgraniHr\Models\EmployeeBranchAssignment;
use Nit\AgraniHr\Services\EmployeeService;
use Nit\Master\Attributes\Permission;
use Nit\Master\Support\Branches;

/**
 * The branches an employee works in. The main branch changes by a transfer (PUT /employees/{id} with a new
 * branch_id); here extra branches are added and ended.
 */
class EmployeeBranchAssignmentController extends Controller
{
    public function __construct(private EmployeeService $employees) {}

    #[Permission('hr_employee_branch_view', 'View the branches of employees')]
    public function index(Employee $employee): JsonResponse
    {
        return response()->json(['data' => $employee->branchAssignments()->get()->map(fn (EmployeeBranchAssignment $a): array => $this->present($a))->all()]);
    }

    #[Permission('hr_employee_branch_manage', 'Assign employees to more branches')]
    public function store(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => Branches::rule(required: true),
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $assignment = $this->employees->assignBranch($employee, (int) $data['branch_id'], $data['start_date'], $data['end_date'] ?? null, $data['notes'] ?? null);

        return response()->json(['data' => $this->present($assignment)], 201);
    }

    #[Permission('hr_employee_branch_manage', 'Assign employees to more branches')]
    public function end(Request $request, Employee $employee, int $assignment): JsonResponse
    {
        $data = $request->validate(['end_date' => ['required', 'date']]);
        $record = $employee->branchAssignments()->findOrFail($assignment);

        return response()->json(['data' => $this->present($this->employees->endAssignment($record, $data['end_date']))]);
    }

    /** @return array<string, mixed> */
    private function present(EmployeeBranchAssignment $assignment): array
    {
        return [
            'id' => $assignment->id,
            'branch_id' => $assignment->branch_id,
            'start_date' => $assignment->start_date->toDateString(),
            'end_date' => $assignment->end_date?->toDateString(),
            'is_primary' => $assignment->is_primary,
            'notes' => $assignment->notes,
        ];
    }
}
