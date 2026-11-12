<?php

namespace Nit\AgraniHr\Tests\Feature;

use Nit\AgraniHr\Models\Attendance;
use Nit\AgraniHr\Models\AttendanceCorrection;
use Nit\AgraniHr\Services\AttendanceService;
use Nit\AgraniHr\Tests\TestCase;
use Nit\Master\Models\Branch;

/**
 * The default schedule: 09:00, 480 minutes, 10 minutes' grace, a 60-minute break on days over 6 hours,
 * overtime from 30 minutes; Friday is the weekend (2026-07-10).
 */
class AttendanceTest extends TestCase
{
    private Branch $dhaka;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dhaka = $this->branch('Dhaka');
        $this->actingAs($this->superAdmin());
    }

    private function attendance(): AttendanceService
    {
        return app(AttendanceService::class);
    }

    public function test_check_in_and_out_calculate_worked_late_and_overtime_minutes(): void
    {
        $employee = $this->hire($this->dhaka);

        $in = $this->attendance()->checkIn($employee, '2026-07-13 09:25');
        $this->assertSame([Attendance::PRESENT, 25, 'self'], [$in->status, $in->late_minutes, $in->source]);
        $this->assertRefused('already_checked_in', fn () => $this->attendance()->checkIn($employee, '2026-07-13 09:30'));

        $out = $this->attendance()->checkOut($employee, '2026-07-13 19:25');
        // 600 minutes less the break = 540 worked; 60 over the schedule.
        $this->assertSame([540, 25, 60, 480], [$out->worked_minutes, $out->late_minutes, $out->overtime_minutes, $out->scheduled_minutes]);
        $this->assertRefused('not_checked_in', fn () => $this->attendance()->checkOut($employee, '2026-07-13 20:00'));
    }

    public function test_grace_short_overtime_and_a_night_shift(): void
    {
        $employee = $this->hire($this->dhaka);

        $onTime = $this->attendance()->record($employee, '2026-07-13', ['check_in_at' => '09:10', 'check_out_at' => '18:20']);
        $this->assertSame([0, 490, 0], [$onTime->late_minutes, $onTime->worked_minutes, $onTime->overtime_minutes]);

        $this->attendance()->checkIn($employee, '2026-07-14 20:00');
        $night = $this->attendance()->checkOut($employee, '2026-07-15 05:00');
        $this->assertSame(['2026-07-14', 480], [$night->work_date->toDateString(), $night->worked_minutes]);
    }

    public function test_work_on_the_weekend_is_all_overtime(): void
    {
        $employee = $this->hire($this->dhaka);

        $friday = $this->attendance()->checkIn($employee, '2026-07-10 10:00');
        $friday = $this->attendance()->checkOut($employee, '2026-07-10 14:00');

        $this->assertSame([Attendance::WEEKEND, 0, 240, 240], [$friday->status, $friday->late_minutes, $friday->worked_minutes, $friday->overtime_minutes]);
    }

    public function test_records_are_validated(): void
    {
        $employee = $this->hire($this->dhaka);

        $this->assertRefused('times_not_allowed', fn () => $this->attendance()->record($employee, '2026-07-13', ['status' => Attendance::ABSENT, 'check_in_at' => '09:00']));
        $this->assertRefused('invalid_time', fn () => $this->attendance()->record($employee, '2026-07-13', ['check_in_at' => '18:00', 'check_out_at' => '09:00']));
        $this->assertRefused('invalid_status', fn () => $this->attendance()->record($employee, '2026-07-13', ['status' => 'sick']));
        $this->assertRefused('not_employed', fn () => $this->attendance()->record($employee, '2025-12-31', ['status' => Attendance::ABSENT]));
    }

    public function test_a_correction_is_approved_by_someone_else_and_keeps_the_old_values(): void
    {
        $user = $this->inBranches($this->userWith([]), $this->dhaka);
        $employee = $this->hire($this->dhaka, ['user_id' => $user->id]);
        $this->attendance()->record($employee, '2026-07-13', ['check_in_at' => '10:30', 'check_out_at' => '18:00']);

        $this->actingAs($user);
        $correction = $this->attendance()->requestCorrection($employee, '2026-07-13', ['check_in_at' => '09:00'], 'The device was down');
        $this->assertRefused('correction_pending', fn () => $this->attendance()->requestCorrection($employee, '2026-07-13', ['check_in_at' => '09:05'], 'Again'));
        $this->assertRefused('own_request', fn () => $this->attendance()->approveCorrection($correction));

        $this->actingAs($this->inBranches($this->userWith([]), $this->dhaka));
        $approved = $this->attendance()->approveCorrection($correction, 'Checked with the guard');

        $this->assertSame(AttendanceCorrection::APPROVED, $approved->status);
        $this->assertSame('2026-07-13 10:30', $approved->old_check_in_at->format('Y-m-d H:i'));
        $record = Attendance::query()->sole();
        $this->assertSame(['09:00', 0, 'correction'], [$record->check_in_at->format('H:i'), $record->late_minutes, $record->source]);
        $this->assertRefused('already_decided', fn () => $this->attendance()->rejectCorrection($correction));
    }

    public function test_a_correction_can_add_a_missing_day_or_mark_an_absence(): void
    {
        $employee = $this->hire($this->dhaka);
        $this->attendance()->record($employee, '2026-07-14', ['check_in_at' => '09:00', 'check_out_at' => '18:00']);

        $added = $this->attendance()->approveCorrection($this->attendance()->requestCorrection($employee, '2026-07-13', ['check_in_at' => '09:00', 'check_out_at' => '17:00'], 'Forgot'));
        $absent = $this->attendance()->approveCorrection($this->attendance()->requestCorrection($employee, '2026-07-14', ['status' => Attendance::ABSENT], 'Was not there'));

        $this->assertNull($added->old_status);
        $this->assertSame(Attendance::PRESENT, $absent->old_status);
        $this->assertSame([Attendance::PRESENT, Attendance::ABSENT], Attendance::query()->orderBy('work_date')->pluck('status')->all());
        $this->assertNull(Attendance::query()->whereDate('work_date', '2026-07-14')->value('check_in_at'));
        $this->assertRefused('empty_correction', fn () => $this->attendance()->requestCorrection($employee, '2026-07-15', [], 'Nothing'));
    }
}
