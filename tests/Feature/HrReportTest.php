<?php

namespace Nit\AgraniHr\Tests\Feature;

use Nit\AgraniHr\Models\Attendance;
use Nit\AgraniHr\Models\LeaveType;
use Nit\AgraniHr\Services\AttendanceService;
use Nit\AgraniHr\Services\EmployeeAdvanceService;
use Nit\AgraniHr\Services\LeaveService;
use Nit\AgraniHr\Services\PayrollService;
use Nit\AgraniHr\Tests\TestCase;
use Nit\Master\Models\Branch;

class HrReportTest extends TestCase
{
    private Branch $dhaka;

    private Branch $ctg;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dhaka = $this->branch('Dhaka');
        $this->ctg = $this->branch('Chattogram', 'CTG');
        $this->accounting();
        $this->actingAs($this->superAdmin());

        $rahim = $this->hire($this->dhaka, ['first_name' => 'Rahim']);
        $this->salary($rahim, '2026-01-01', '20000');
        $karim = $this->hire($this->ctg, ['first_name' => 'Karim']);
        $this->salary($karim, '2026-01-01', '10000', '0', '0', '0');

        $attendance = app(AttendanceService::class);
        $attendance->record($rahim, '2026-07-13', ['check_in_at' => '09:30', 'check_out_at' => '19:30']);
        $attendance->record($rahim, '2026-07-14', ['status' => Attendance::ABSENT]);
        $attendance->record($karim, '2026-07-13', ['check_in_at' => '09:00', 'check_out_at' => '18:00']);

        $casual = LeaveType::query()->create(['code' => 'casual', 'name' => 'Casual', 'days_per_year' => 10]);
        app(LeaveService::class)->approve(app(LeaveService::class)->apply($rahim, $casual, '2026-07-20', '2026-07-21'));

        $advances = app(EmployeeAdvanceService::class);
        $advance = $advances->request($rahim, '4000', '2026-06-01', '1000');
        $advances->approve($advance);
        $advances->disburse($advance, '2026-06-02', 'CASH');

        $payroll = app(PayrollService::class);
        foreach ([$this->dhaka, $this->ctg] as $branch) {
            $payroll->approve($payroll->process($payroll->create($this->period(), $branch->id)));
        }
        auth()->logout();
    }

    public function test_employee_attendance_leave_payroll_and_advance_reports(): void
    {
        $headers = $this->bearer($this->userWith(['HrReport::*']));
        $range = 'from=2026-01-01&to=2026-12-31';

        $this->getJson("/api/nit/hr/reports/employees?{$range}", $headers)->assertOk()->assertJsonPath('data.summary.employees', 2)->assertJsonPath('data.sections.0.totals.employees', 2);
        $attendance = $this->getJson('/api/nit/hr/reports/attendance?from=2026-07-01&to=2026-07-31', $headers)->assertOk()->json('data');
        $this->assertSame(['Rahim Uddin', 1, 1, 30, 60], [$attendance['sections'][0]['rows'][0]['name'], $attendance['sections'][0]['rows'][0]['present'], $attendance['sections'][0]['rows'][0]['absent'], $attendance['sections'][0]['rows'][0]['late_minutes'], $attendance['sections'][0]['rows'][0]['overtime_minutes']]);
        $this->assertSame(1, $attendance['summary']['absent_days']);
        $this->getJson("/api/nit/hr/reports/leave?{$range}&status=approved", $headers)->assertOk()->assertJsonPath('data.summary.approved_days', '2.0')->assertJsonPath('data.sections.0.rows.0.leave_type', 'Casual');

        // Dhaka: 31,500 + 192.31 overtime − 2,000 PF − 1,016.13 absence − 1,000 advance; Chattogram: 10,000.
        $this->getJson('/api/nit/hr/reports/payroll?from=2026-07-01&to=2026-07-31', $headers)->assertOk()
            ->assertJsonPath('data.summary.gross', '41692.31')->assertJsonPath('data.summary.net', '37676.18')->assertJsonPath('data.summary.payrolls', 2)
            ->assertJsonPath('data.sections.1.rows.0.code', 'basic')->assertJsonPath('data.sections.1.rows.0.amount', '30000.00');
        $this->getJson("/api/nit/hr/reports/advances?{$range}", $headers)->assertOk()->assertJsonPath('data.summary.outstanding', '3000.00');
    }

    public function test_reports_follow_the_branches_and_export(): void
    {
        $dhakaOnly = $this->bearer($this->inBranches($this->userWith(['HrReport::*']), $this->dhaka));
        $admin = $this->bearer($this->superAdmin());

        $this->getJson('/api/nit/hr/reports/payroll?from=2026-07-01&to=2026-07-31', $dhakaOnly)->assertJsonPath('data.summary.net', '27676.18');
        $this->getJson("/api/nit/hr/reports/payroll?from=2026-07-01&to=2026-07-31&branch_id={$this->ctg->id}", $dhakaOnly)->assertJsonPath('data.summary.payrolls', 0);
        $this->getJson("/api/nit/hr/reports/payroll?from=2026-07-01&to=2026-07-31&branch_id={$this->ctg->id}", $admin)->assertJsonPath('data.summary.net', '10000.00');

        $csv = $this->get('/api/nit/hr/reports/payroll?from=2026-07-01&to=2026-07-31&format=csv', $admin)->assertOk()->assertDownload('hr-payroll-2026-07-01-2026-07-31.csv')->streamedContent();
        $this->assertStringContainsString('Payrolls', $csv);
        $this->assertStringContainsString('41692.31', $csv);
        $this->get('/api/nit/hr/reports/employees?format=pdf', $admin)->assertOk();
        $this->getJson('/api/nit/hr/reports/leave?from=2026-08-01&to=2026-07-01', $admin)->assertUnprocessable()->assertJsonValidationErrors(['to']);
    }

    public function test_each_report_group_needs_its_ability(): void
    {
        $employees = $this->bearer($this->userWith(['HrReport::hr_report_employees']));

        $this->getJson('/api/nit/hr/reports/employees', $employees)->assertOk();
        $this->getJson('/api/nit/hr/reports/payroll', $employees)->assertUnauthorized();
        $this->getJson('/api/nit/hr/reports/attendance', $employees)->assertUnauthorized();
    }
}
