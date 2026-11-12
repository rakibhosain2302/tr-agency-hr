<?php

namespace Nit\AgraniHr\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Nit\AgraniHr\Models\Employee;
use Nit\AgraniHr\Models\SalaryStructure;
use Nit\AgraniHr\Services\SalaryStructureService;
use Nit\AgraniHr\Support\Money;
use Nit\Master\Attributes\Permission;

/**
 * An employee's salaries by effective date.
 */
class SalaryStructureController extends Controller
{
    public function __construct(private SalaryStructureService $salaries) {}

    #[Permission('hr_salary_view', 'View salaries')]
    public function index(Employee $employee): JsonResponse
    {
        return response()->json(['data' => $employee->salaryStructures()->with('lines.component')->get()->map(fn (SalaryStructure $s) => $this->present($s))->all()]);
    }

    #[Permission('hr_salary_manage', 'Set and change salaries')]
    public function store(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate($this->rules() + ['effective_from' => ['required', 'date']]);

        return response()->json(['data' => $this->present($this->salaries->create($employee, $data['effective_from'], $data['lines'], $data['notes'] ?? null))], 201);
    }

    #[Permission('hr_salary_manage', 'Set and change salaries')]
    public function update(Request $request, Employee $employee, int $structure): JsonResponse
    {
        $data = $request->validate($this->rules());

        return response()->json(['data' => $this->present($this->salaries->update($employee->salaryStructures()->findOrFail($structure), $data['lines'], $data['notes'] ?? null))]);
    }

    #[Permission('hr_salary_manage', 'Set and change salaries')]
    public function destroy(Employee $employee, int $structure): Response
    {
        $this->salaries->delete($employee->salaryStructures()->findOrFail($structure));

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.salary_component_id' => ['required', 'integer', 'distinct', Rule::exists('hr_salary_components', 'id')],
            'lines.*.amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999', 'decimal:0,2'],
            'lines.*.percent' => ['nullable', 'numeric', 'min:0', 'max:1000', 'decimal:0,4'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, mixed> */
    private function present(SalaryStructure $structure): array
    {
        $monthly = $this->salaries->monthly($structure);

        return [
            'id' => $structure->id,
            'effective_from' => $structure->effective_from->toDateString(),
            'effective_to' => $structure->effective_to?->toDateString(),
            'notes' => $structure->notes,
            'lines' => $structure->lines->map(fn ($line) => [
                'salary_component_id' => $line->salary_component_id,
                'code' => $line->component->code,
                'name' => $line->component->name,
                'kind' => $line->component->kind,
                'amount' => $line->amount,
                'percent' => $line->percent,
                'monthly' => Money::round($monthly[$line->salary_component_id]['monthly']),
            ])->values()->all(),
        ];
    }
}
