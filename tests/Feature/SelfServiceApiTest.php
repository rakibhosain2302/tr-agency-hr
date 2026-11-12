<?php

namespace Nit\AgraniHr\Tests\Feature;

use Illuminate\Support\Carbon;
use Nit\AgraniHr\Models\LeaveType;
use Nit\AgraniHr\Services\LeaveService;
use Nit\AgraniHr\Tests\TestCase;
use Nit\Master\Models\Branch;

/**
 * An employee with a user account checks in and out, applies for leave, asks for an advance and a
 * correction; HR decides.
 */
class SelfServiceApiTest extends TestCase
{
    private Branch $dhaka;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dhaka = $this->branch('Dhaka');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_an_employee_works_through_their_own_requests_and_hr_decides(): void
    {
        $this->accounting();
        $staffUser = $this->inBranches($this->userWith(['Attendance::hr_attendance_self', 'AttendanceCorrection::hr_attendance_correction_request', 'LeaveApplication::hr_leave_apply', 'EmployeeAdvance::hr_advance_request']), $this->dhaka);
        $employee = $this->hire($this->dhaka, ['user_id' => $staffUser->id]);
        $staff = $this->bearer($staffUser);
        $hr = $this->bearer($this->inBranches($this->userWith(['Attendance::*', 'AttendanceCorrection::*', 'LeaveApplication::*', 'LeaveBalance::*', 'EmployeeAdvance::*']), $this->dhaka));

        Carbon::setTestNow('2026-07-13 09:20:00');
        $this->postJson('/api/nit/hr/attendance/check-in', [], $staff)->assertCreated()->assertJsonPath('data.late_minutes', 20);
        Carbon::setTestNow('2026-07-13 18:30:00');
        $this->postJson('/api/nit/hr/attendance/check-out', [], $staff)->assertOk()->assertJsonPath('data.worked_minutes', 490);

        $correction = $this->postJson('/api/nit/hr/attendance-corrections', ['work_date' => '2026-07-13', 'check_in_at' => '09:00', 'reason' => 'Stuck at the gate'], $staff)->assertCreated()->json('data.id');
        $this->postJson("/api/nit/hr/attendance-corrections/{$correction}/approve", [], $staff)->assertUnauthorized();
        $this->postJson("/api/nit/hr/attendance-corrections/{$correction}/approve", ['note' => 'OK'], $hr)->assertOk()->assertJsonPath('data.status', 'approved');
        $this->getJson("/api/nit/hr/attendance?employee_id={$employee->uuid}", $hr)->assertOk()->assertJsonPath('data.0.late_minutes', 0);

        $casual = LeaveType::query()->create(['code' => 'casual', 'name' => 'Casual', 'days_per_year' => 10]);
        $leave = $this->postJson('/api/nit/hr/leave-applications', ['leave_type_id' => $casual->id, 'start_date' => '2026-07-20', 'end_date' => '2026-07-21'], $staff)
            ->assertCreated()->assertJsonPath('data.days', '2.0')->assertJsonPath('data.employee_id', $employee->uuid)->json('data.id');
        $this->postJson("/api/nit/hr/leave-applications/{$leave}/approve", [], $hr)->assertOk()->assertJsonPath('data.status', 'approved');
        $this->getJson("/api/nit/hr/employees/{$employee->uuid}/leave-balances?year=2026", $hr)->assertOk()->assertJsonPath('data.0.balance', '8.0');
        $this->getJson("/api/nit/hr/employees/{$employee->uuid}/leave-history", $hr)->assertOk()->assertJsonCount(2, 'data');
        $this->postJson("/api/nit/hr/employees/{$employee->uuid}/leave-balances", ['leave_type_id' => $casual->id, 'year' => 2026, 'action' => 'adjust', 'days' => '1.5', 'notes' => 'Worked a holiday'], $hr)
            ->assertOk()->assertJsonPath('data.balance', '9.5');

        $advance = $this->postJson('/api/nit/hr/advances', ['amount' => '5000', 'installment_amount' => '1000', 'reason' => 'Rent'], $staff)->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->postJson("/api/nit/hr/advances/{$advance}/approve", [], $hr)->assertOk();
        $this->postJson("/api/nit/hr/advances/{$advance}/disburse", ['disbursement_date' => '2026-07-14', 'settlement_account' => 'CASH'], $hr)->assertOk()->assertJsonPath('data.status', 'disbursed');
        $this->postJson("/api/nit/hr/advances/{$advance}/repayments", ['amount' => '1500', 'settlement_date' => '2026-07-20', 'settlement_account' => 'CASH'], $hr)
            ->assertCreated()->assertJsonPath('data.outstanding', '3500.00')->assertJsonCount(1, 'data.settlements');
    }

    public function test_self_service_abilities_act_only_for_the_users_own_record(): void
    {
        $user = $this->inBranches($this->userWith(['LeaveApplication::hr_leave_apply', 'EmployeeAdvance::hr_advance_request', 'AttendanceCorrection::hr_attendance_correction_request']), $this->dhaka);
        $this->hire($this->dhaka, ['user_id' => $user->id]);
        $colleague = $this->hire($this->dhaka, ['first_name' => 'Colleague']);
        $casual = LeaveType::query()->create(['code' => 'casual', 'name' => 'Casual', 'days_per_year' => 10]);
        $headers = $this->bearer($user);

        $this->postJson('/api/nit/hr/leave-applications', ['employee_id' => $colleague->uuid, 'leave_type_id' => $casual->id, 'start_date' => '2026-07-20', 'end_date' => '2026-07-20'], $headers)->assertForbidden();
        $this->postJson('/api/nit/hr/advances', ['employee_id' => $colleague->uuid, 'amount' => '100'], $headers)->assertForbidden();
        $this->postJson('/api/nit/hr/attendance-corrections', ['employee_id' => $colleague->uuid, 'work_date' => '2026-07-13', 'status' => 'present', 'reason' => 'x'], $headers)->assertForbidden();

        $this->actingAs($this->superAdmin());
        $theirs = app(LeaveService::class)->apply($colleague, $casual, '2026-07-20', '2026-07-20');
        auth()->logout();
        $this->postJson("/api/nit/hr/leave-applications/{$theirs->uuid}/cancel", [], $headers)->assertForbidden();
    }

    public function test_a_user_without_an_employee_record_is_told_so(): void
    {
        $headers = $this->bearer($this->inBranches($this->userWith(['Attendance::hr_attendance_self']), $this->dhaka));

        $this->postJson('/api/nit/hr/attendance/check-in', [], $headers)->assertUnprocessable()->assertJsonPath('reason', 'no_employee');
    }

    public function test_hr_records_attendance_only_for_employees_of_its_branches(): void
    {
        $ctg = $this->branch('Chattogram', 'CTG');
        $mine = $this->hire($this->dhaka);
        $theirs = $this->hire($ctg);
        $headers = $this->bearer($this->inBranches($this->userWith(['Attendance::*']), $this->dhaka));

        $this->putJson("/api/nit/hr/employees/{$mine->uuid}/attendance", ['work_date' => '2026-07-13', 'check_in_at' => '09:00', 'check_out_at' => '19:00'], $headers)
            ->assertOk()->assertJsonPath('data.overtime_minutes', 60);
        $this->putJson("/api/nit/hr/employees/{$theirs->uuid}/attendance", ['work_date' => '2026-07-13', 'status' => 'absent'], $headers)->assertNotFound();
        $this->putJson("/api/nit/hr/employees/{$mine->uuid}/attendance", ['work_date' => '2026-07-13', 'status' => 'sick'], $headers)->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $this->getJson('/api/nit/hr/attendance', $headers)->assertOk()->assertJsonCount(1, 'data');
    }
}
