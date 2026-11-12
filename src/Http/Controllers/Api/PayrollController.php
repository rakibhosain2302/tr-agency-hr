<?php

namespace Nit\AgraniHr\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Nit\AgraniHr\Models\Payroll;
use Nit\AgraniHr\Models\PayrollItem;
use Nit\AgraniHr\Models\PayrollPeriod;
use Nit\AgraniHr\Services\PayrollService;
use Nit\Master\Attributes\Permission;
use Nit\Master\Support\Branches;
use Nit\Master\Support\Pdf;

/**
 * Payrolls of the branches the user sees and their payslips.
 */
class PayrollController extends Controller
{
    public function __construct(private PayrollService $payrolls) {}

    #[Permission('hr_payroll_view', 'View payrolls and payslips')]
    public function index(Request $request): JsonResponse
    {
        $request->validate(['payroll_period_id' => ['nullable', 'integer'], 'status' => ['nullable', Rule::in([Payroll::DRAFT, Payroll::APPROVED, Payroll::PAID, Payroll::REVERSED])]]);

        return response()->json(['data' => Payroll::query()->with('period')
            ->when($request->integer('payroll_period_id'), fn ($q, $id) => $q->where('payroll_period_id', $id))
            ->when($request->input('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('id')->limit(200)->get()->map(fn (Payroll $p) => $this->present($p))->all()]);
    }

    #[Permission('hr_payroll_view', 'View payrolls and payslips')]
    public function show(Payroll $payroll): JsonResponse
    {
        $payroll->load(['period', 'items.employee' => fn ($q) => $q->withoutGlobalScopes(), 'items.lines']);

        return response()->json(['data' => $this->present($payroll) + ['payslips' => $payroll->items->map(fn (PayrollItem $item) => $this->payslipData($item))->all()]]);
    }

    #[Permission('hr_payroll_process', 'Create, process and delete draft payrolls')]
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'payroll_period_id' => ['required', 'integer', Rule::exists('hr_payroll_periods', 'id')],
            'branch_id' => Branches::rule(required: true),
            'notes' => ['nullable', 'string', 'max:500'],
            'process' => ['nullable', 'boolean'],
        ]);

        $payroll = $this->payrolls->create(PayrollPeriod::query()->findOrFail($data['payroll_period_id']), (int) $data['branch_id'], $data['notes'] ?? null);
        if ($data['process'] ?? true) {
            $payroll = $this->payrolls->process($payroll);
        }

        return response()->json(['data' => $this->present($payroll->load('period'))], 201);
    }

    #[Permission('hr_payroll_process', 'Create, process and delete draft payrolls')]
    public function process(Payroll $payroll): JsonResponse
    {
        return response()->json(['data' => $this->present($this->payrolls->process($payroll)->load('period'))]);
    }

    #[Permission('hr_payroll_process', 'Create, process and delete draft payrolls')]
    public function destroy(Payroll $payroll): Response
    {
        $this->payrolls->delete($payroll);

        return response()->noContent();
    }

    #[Permission('hr_payroll_approve', 'Approve payrolls (posts them to accounting)')]
    public function approve(Payroll $payroll): JsonResponse
    {
        return response()->json(['data' => $this->present($this->payrolls->approve($payroll)->load('period'))]);
    }

    #[Permission('hr_payroll_pay', 'Pay salaries and undo payments')]
    public function pay(Request $request, Payroll $payroll): JsonResponse
    {
        $data = $request->validate([
            'payment_date' => ['required', 'date'],
            'settlement_account' => ['required', 'string', 'max:30'],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json(['data' => $this->present($this->payrolls->pay($payroll, $data['payment_date'], $data['settlement_account'], $data['reference'] ?? null)->load('period'))]);
    }

    #[Permission('hr_payroll_pay', 'Pay salaries and undo payments')]
    public function reversePayment(Request $request, Payroll $payroll): JsonResponse
    {
        $data = $this->reversal($request);

        return response()->json(['data' => $this->present($this->payrolls->reversePayment($payroll, $data['date'] ?? null, $data['reason'])->load('period'))]);
    }

    #[Permission('hr_payroll_reverse', 'Reverse approved payrolls')]
    public function reverse(Request $request, Payroll $payroll): JsonResponse
    {
        $data = $this->reversal($request);

        return response()->json(['data' => $this->present($this->payrolls->reverse($payroll, $data['date'] ?? null, $data['reason'])->load('period'))]);
    }

    /** One payslip: JSON, or ?format=pdf for a printable payslip. */
    #[Permission('hr_payroll_view', 'View payrolls and payslips')]
    public function payslip(Request $request, Payroll $payroll, int $item): JsonResponse|\Symfony\Component\HttpFoundation\Response
    {
        $payslip = $payroll->items()->with(['lines', 'employee' => fn ($q) => $q->withoutGlobalScopes()->with(['department', 'designation'])])->findOrFail($item);
        $data = $this->payslipData($payslip) + ['payroll' => $this->present($payroll->load('period'))];

        if ($request->input('format') === 'pdf') {
            return Pdf::download('hr::payslip', ['payslip' => $data], "payslip-{$payslip->payslip_no}.pdf");
        }

        return response()->json(['data' => $data]);
    }

    /** @return array{date?: string, reason: string} */
    private function reversal(Request $request): array
    {
        return $request->validate(['date' => ['nullable', 'date'], 'reason' => ['required', 'string', 'max:500']]);
    }

    /** @return array<string, mixed> */
    private function present(Payroll $p): array
    {
        return [
            'id' => $p->uuid,
            'payroll_no' => $p->payroll_no,
            'period' => $p->period ? ['id' => $p->period->id, 'code' => $p->period->code, 'name' => $p->period->name, 'start_date' => $p->period->start_date->toDateString(), 'end_date' => $p->period->end_date->toDateString()] : null,
            'branch_id' => $p->branch_id,
            'status' => $p->status,
            'employee_count' => $p->employee_count,
            'gross_total' => $p->gross_total,
            'deduction_total' => $p->deduction_total,
            'net_total' => $p->net_total,
            'skipped' => $p->skipped ?? [],
            'processed_by' => $p->processed_by,
            'processed_at' => $p->processed_at?->toIso8601String(),
            'approved_by' => $p->approved_by,
            'approved_at' => $p->approved_at?->toIso8601String(),
            'accounting_reference' => $p->accounting_reference,
            'payment' => $p->status === Payroll::PAID ? ['date' => $p->payment_date?->toDateString(), 'settlement_account' => $p->settlement_account, 'reference' => $p->payment_reference, 'accounting_reference' => $p->payment_accounting_reference] : null,
            'reversed_at' => $p->reversed_at?->toIso8601String(),
            'reversal_reason' => $p->reversal_reason,
            'reversal_accounting_reference' => $p->reversal_accounting_reference,
        ];
    }

    /** @return array<string, mixed> */
    private function payslipData(PayrollItem $item): array
    {
        $line = fn ($l) => ['code' => $l->code, 'name' => $l->name, 'source' => $l->source, 'amount' => $l->amount];

        return [
            'id' => $item->id,
            'payslip_no' => $item->payslip_no,
            'employee' => ['id' => $item->employee?->uuid, 'employee_code' => $item->employee?->employee_code, 'name' => $item->employee?->fullName(), 'department' => $item->employee?->department?->name, 'designation' => $item->employee?->designation?->name],
            'period_days' => $item->period_days,
            'payable_days' => $item->payable_days,
            'unpaid_leave_days' => $item->unpaid_leave_days,
            'absent_days' => $item->absent_days,
            'late_minutes' => $item->late_minutes,
            'overtime_minutes' => $item->overtime_minutes,
            'earnings' => $item->lines->where('kind', 'earning')->map($line)->values()->all(),
            'deductions' => $item->lines->where('kind', 'deduction')->map($line)->values()->all(),
            'gross' => $item->gross,
            'total_deductions' => $item->deductions,
            'net' => $item->net,
        ];
    }
}
