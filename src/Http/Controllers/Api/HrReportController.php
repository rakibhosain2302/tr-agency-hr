<?php

namespace Nit\AgraniHr\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Nit\AgraniHr\Reports\HrReportExporter;
use Nit\AgraniHr\Reports\HrReportFilters;
use Nit\AgraniHr\Reports\HrReportService;
use Symfony\Component\HttpFoundation\Response;

/**
 * HR reports: ?from, ?to, ?branch_id, ?status, ?department_id, ?payroll_period_id, ?format=json|csv|pdf.
 */
class HrReportController extends Controller
{
    /** @var array<string, array{0: string, 1: string}> */
    public static $permissions = [
        'employees' => ['hr_report_employees', 'Employee reports'],
        'attendance' => ['hr_report_attendance', 'Attendance and leave reports'],
        'leave' => ['hr_report_attendance', 'Attendance and leave reports'],
        'payroll' => ['hr_report_payroll', 'Payroll and advance reports'],
        'advances' => ['hr_report_payroll', 'Payroll and advance reports'],
    ];

    public function __construct(private HrReportService $reports, private HrReportExporter $exporter) {}

    public function employees(Request $request): Response|JsonResponse
    {
        return $this->run($request, 'employees');
    }

    public function attendance(Request $request): Response|JsonResponse
    {
        return $this->run($request, 'attendance');
    }

    public function leave(Request $request): Response|JsonResponse
    {
        return $this->run($request, 'leave');
    }

    public function payroll(Request $request): Response|JsonResponse
    {
        return $this->run($request, 'payroll');
    }

    public function advances(Request $request): Response|JsonResponse
    {
        return $this->run($request, 'advances');
    }

    private function run(Request $request, string $report): Response|JsonResponse
    {
        $input = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'branch_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'max:20'],
            'department_id' => ['nullable', 'integer'],
            'payroll_period_id' => ['nullable', 'integer'],
            'format' => ['nullable', Rule::in(HrReportExporter::FORMATS)],
        ]);

        return $this->exporter->respond($this->reports->{$report}(HrReportFilters::resolve($input, $request->user())), $input['format'] ?? 'json');
    }
}
