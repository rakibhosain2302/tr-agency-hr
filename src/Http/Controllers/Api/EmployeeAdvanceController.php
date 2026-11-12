<?php

namespace Nit\AgraniHr\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Nit\AgraniHr\Http\Controllers\Concerns\ResolvesEmployees;
use Nit\AgraniHr\Models\AdvanceSettlement;
use Nit\AgraniHr\Models\EmployeeAdvance;
use Nit\AgraniHr\Services\EmployeeAdvanceService;
use Nit\Master\Attributes\Permission;

/**
 * Employee advances of the branches the user sees. Without employee_id (or without hr_advance_view) a request is
 * for the user's own employee record.
 */
class EmployeeAdvanceController extends Controller
{
    use ResolvesEmployees;

    public function __construct(private EmployeeAdvanceService $advances) {}

    #[Permission('hr_advance_view', 'View employee advances')]
    public function index(Request $request): JsonResponse
    {
        $request->validate(['employee_id' => ['nullable', 'uuid'], 'status' => ['nullable', Rule::in([EmployeeAdvance::PENDING, EmployeeAdvance::APPROVED, EmployeeAdvance::REJECTED, EmployeeAdvance::DISBURSED, EmployeeAdvance::SETTLED, EmployeeAdvance::CANCELLED])]]);

        return response()->json(['data' => EmployeeAdvance::query()->with('employee:id,uuid,employee_code,first_name,last_name')
            ->when($request->input('employee_id'), fn ($q, $uuid) => $q->whereHas('employee', fn ($e) => $e->where('uuid', $uuid)))
            ->when($request->input('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('id')->limit(500)->get()->map(fn (EmployeeAdvance $a) => $this->present($a))->all()]);
    }

    #[Permission('hr_advance_view', 'View employee advances')]
    public function show(EmployeeAdvance $advance): JsonResponse
    {
        return response()->json(['data' => $this->present($advance->load(['employee', 'settlements']), true)]);
    }

    #[Permission('hr_advance_request', 'Request and cancel advances')]
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['nullable', 'uuid'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999999999'],
            'installment_amount' => ['nullable', 'numeric', 'gt:0', 'decimal:0,2', 'lte:amount'],
            'request_date' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $advance = $this->advances->request($this->employeeOrSelf($data['employee_id'] ?? null, 'hr_advance_view'), $data['amount'], $data['request_date'] ?? null, $data['installment_amount'] ?? null, $data['reason'] ?? null);

        return response()->json(['data' => $this->present($advance->load('employee'))], 201);
    }

    #[Permission('hr_advance_approve', 'Approve or reject advances')]
    public function approve(Request $request, EmployeeAdvance $advance): JsonResponse
    {
        return response()->json(['data' => $this->present($this->advances->approve($advance, $this->note($request))->load('employee'))]);
    }

    #[Permission('hr_advance_approve', 'Approve or reject advances')]
    public function reject(Request $request, EmployeeAdvance $advance): JsonResponse
    {
        return response()->json(['data' => $this->present($this->advances->reject($advance, $this->note($request))->load('employee'))]);
    }

    #[Permission('hr_advance_request', 'Request and cancel advances')]
    public function cancel(Request $request, EmployeeAdvance $advance): JsonResponse
    {
        $this->ensureOwnOr($advance->employee_id, 'hr_advance_view');

        return response()->json(['data' => $this->present($this->advances->cancel($advance, $this->note($request))->load('employee'))]);
    }

    #[Permission('hr_advance_disburse', 'Pay out advances and undo payouts')]
    public function disburse(Request $request, EmployeeAdvance $advance): JsonResponse
    {
        $data = $request->validate(['disbursement_date' => ['required', 'date'], 'settlement_account' => ['required', 'string', 'max:30']]);

        return response()->json(['data' => $this->present($this->advances->disburse($advance, $data['disbursement_date'], $data['settlement_account'])->load('employee'))]);
    }

    #[Permission('hr_advance_disburse', 'Pay out advances and undo payouts')]
    public function reverseDisbursement(Request $request, EmployeeAdvance $advance): JsonResponse
    {
        $data = $request->validate(['date' => ['nullable', 'date'], 'reason' => ['required', 'string', 'max:500']]);

        return response()->json(['data' => $this->present($this->advances->reverseDisbursement($advance, $data['date'] ?? null, $data['reason'])->load('employee'))]);
    }

    #[Permission('hr_advance_settle', 'Record and reverse advance repayments')]
    public function repay(Request $request, EmployeeAdvance $advance): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'settlement_date' => ['required', 'date'],
            'settlement_account' => ['required', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $this->advances->repay($advance, $data['amount'], $data['settlement_date'], $data['settlement_account'], $data['notes'] ?? null);

        return response()->json(['data' => $this->present(EmployeeAdvance::query()->with(['employee', 'settlements'])->findOrFail($advance->id), true)], 201);
    }

    #[Permission('hr_advance_settle', 'Record and reverse advance repayments')]
    public function reverseSettlement(Request $request, EmployeeAdvance $advance, int $settlement): JsonResponse
    {
        $data = $request->validate(['date' => ['nullable', 'date'], 'reason' => ['nullable', 'string', 'max:500']]);
        $this->advances->reverseSettlement($advance->settlements()->findOrFail($settlement), $data['date'] ?? null, $data['reason'] ?? null);

        return response()->json(['data' => $this->present(EmployeeAdvance::query()->with(['employee', 'settlements'])->findOrFail($advance->id), true)]);
    }

    private function note(Request $request): ?string
    {
        return $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;
    }

    /** @return array<string, mixed> */
    private function present(EmployeeAdvance $a, bool $withSettlements = false): array
    {
        return [
            'id' => $a->uuid,
            'advance_no' => $a->advance_no,
            'employee_id' => $a->employee?->uuid,
            'employee_code' => $a->employee?->employee_code,
            'branch_id' => $a->branch_id,
            'amount' => $a->amount,
            'installment_amount' => $a->installment_amount,
            'recovered_amount' => $a->recovered_amount,
            'outstanding' => $a->outstanding(),
            'request_date' => $a->request_date->toDateString(),
            'reason' => $a->reason,
            'status' => $a->status,
            'decided_by' => $a->decided_by,
            'decision_note' => $a->decision_note,
            'disbursement_date' => $a->disbursement_date?->toDateString(),
            'settlement_account' => $a->settlement_account,
            'accounting_reference' => $a->accounting_reference,
            'reversal_accounting_reference' => $a->reversal_accounting_reference,
        ] + ($withSettlements ? ['settlements' => $a->settlements->map(fn (AdvanceSettlement $s) => [
            'id' => $s->id,
            'kind' => $s->kind,
            'amount' => $s->amount,
            'settlement_date' => $s->settlement_date->toDateString(),
            'settlement_account' => $s->settlement_account,
            'accounting_reference' => $s->accounting_reference,
            'reversed_at' => $s->reversed_at?->toIso8601String(),
            'reversal_accounting_reference' => $s->reversal_accounting_reference,
        ])->all()] : []);
    }
}
