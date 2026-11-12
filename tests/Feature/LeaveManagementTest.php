<?php

namespace Nit\AgraniHr\Tests\Feature;

use Nit\AgraniHr\Models\LeaveApplication;
use Nit\AgraniHr\Models\LeaveLedgerEntry;
use Nit\AgraniHr\Models\LeaveType;
use Nit\AgraniHr\Services\LeaveService;
use Nit\AgraniHr\Tests\TestCase;
use Nit\Master\Models\Branch;

/**
 * Leave in 2026; Friday is the weekend (2026-07-10 is a Friday).
 */
class LeaveManagementTest extends TestCase
{
    private Branch $dhaka;

    private LeaveType $casual;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dhaka = $this->branch('Dhaka');
        $this->casual = LeaveType::query()->create(['code' => 'casual', 'name' => 'Casual leave', 'days_per_year' => 10, 'carry_forward_max' => 3]);
        $this->actingAs($this->superAdmin());
    }

    private function leave(): LeaveService
    {
        return app(LeaveService::class);
    }

    public function test_an_application_counts_working_days_and_approval_takes_them_from_the_balance(): void
    {
        $employee = $this->hire($this->dhaka);

        // Thursday 9 to Sunday 12 July: Friday is not counted.
        $application = $this->leave()->apply($employee, $this->casual, '2026-07-09', '2026-07-12', reason: 'Family');
        $this->assertSame(['3.0', LeaveApplication::PENDING], [$application->days, $application->status]);

        $balance = collect($this->leave()->balances($employee, 2026))->firstWhere('code', 'casual');
        $this->assertSame(['10.0', '10.0', '3.0', '7.0'], [$balance['entitlement'], $balance['balance'], $balance['pending'], $balance['available']]);

        $this->leave()->approve($application, 'Enjoy');

        $balance = collect($this->leave()->balances($employee, 2026))->firstWhere('code', 'casual');
        $this->assertSame(['3.0', '7.0', '0.0', '7.0'], [$balance['taken'], $balance['balance'], $balance['pending'], $balance['available']]);
        $this->assertSame([LeaveLedgerEntry::ENTITLEMENT, LeaveLedgerEntry::TAKEN], LeaveLedgerEntry::query()->orderBy('id')->pluck('kind')->all());
        $this->assertRefused('already_decided', fn () => $this->leave()->approve($application));
    }

    public function test_the_balance_limits_applications_including_pending_ones(): void
    {
        $employee = $this->hire($this->dhaka);
        $this->leave()->apply($employee, $this->casual, '2026-07-01', '2026-07-08');

        $this->assertRefused('insufficient_balance', fn () => $this->leave()->apply($employee, $this->casual, '2026-08-03', '2026-08-06'));
        $this->assertRefused('overlap', fn () => $this->leave()->apply($employee, $this->casual, '2026-07-08', '2026-07-08'));
    }

    public function test_half_days_weekends_and_dates_are_checked(): void
    {
        $employee = $this->hire($this->dhaka);

        $half = $this->leave()->apply($employee, $this->casual, '2026-07-13', '2026-07-13', halfDay: true);
        $this->assertSame('0.5', $half->days);
        $this->assertRefused('invalid_half_day', fn () => $this->leave()->apply($employee, $this->casual, '2026-07-14', '2026-07-15', halfDay: true));
        $this->assertRefused('no_working_days', fn () => $this->leave()->apply($employee, $this->casual, '2026-07-10', '2026-07-10'));
        $this->assertRefused('invalid_date', fn () => $this->leave()->apply($employee, $this->casual, '2026-07-20', '2026-07-19'));
        $this->assertRefused('not_employed', fn () => $this->leave()->apply($employee, $this->casual, '2025-12-30', '2026-01-02'));
    }

    public function test_cancelling_approved_leave_restores_the_days(): void
    {
        $employee = $this->hire($this->dhaka);
        $application = $this->leave()->approve($this->leave()->apply($employee, $this->casual, '2026-07-13', '2026-07-14'));

        $this->leave()->cancel($application, 'Plans changed');

        $this->assertSame('10.0', $this->leave()->balance($employee->id, $this->casual->id, 2026));
        $this->assertSame([LeaveLedgerEntry::ENTITLEMENT, LeaveLedgerEntry::TAKEN, LeaveLedgerEntry::RESTORED], LeaveLedgerEntry::query()->orderBy('id')->pluck('kind')->all());
        $this->assertRefused('not_cancellable', fn () => $this->leave()->cancel($application));
    }

    public function test_leave_across_the_new_year_uses_each_years_balance(): void
    {
        $employee = $this->hire($this->dhaka);

        // Wed 30 Dec 2026 – Sun 3 Jan 2027: 30, 31 Dec; 2, 3 Jan (1 Jan is a Friday).
        $application = $this->leave()->approve($this->leave()->apply($employee, $this->casual, '2026-12-30', '2027-01-03'));

        $this->assertSame('4.0', $application->days);
        $this->assertSame('8.0', $this->leave()->balance($employee->id, $this->casual->id, 2026));
        $this->assertSame('8.0', $this->leave()->balance($employee->id, $this->casual->id, 2027));
    }

    public function test_carry_forward_adjustments_and_unlimited_types(): void
    {
        $employee = $this->hire($this->dhaka);
        $this->leave()->approve($this->leave()->apply($employee, $this->casual, '2026-07-13', '2026-07-14'));

        $carried = $this->leave()->carryForward($employee, $this->casual, 2026);
        $this->assertSame('3.0', $carried->days);
        $this->assertSame($carried->id, $this->leave()->carryForward($employee, $this->casual, 2026)->id, 'Carried forward twice.');
        $this->leave()->allocate($employee, $this->casual, 2027);
        $this->assertSame('13.0', $this->leave()->balance($employee->id, $this->casual->id, 2027));

        $this->leave()->adjust($employee, $this->casual, 2027, '-1.5', 'Late return');
        $this->assertSame('11.5', $this->leave()->balance($employee->id, $this->casual->id, 2027));
        $this->assertRefused('insufficient_balance', fn () => $this->leave()->adjust($employee, $this->casual, 2027, '-20', 'Too much'));

        $unpaid = LeaveType::query()->create(['code' => 'unpaid', 'name' => 'Unpaid', 'is_paid' => false]);
        $long = $this->leave()->approve($this->leave()->apply($employee, $unpaid, '2026-08-01', '2026-08-31'));
        $this->assertSame('27.0', $long->days);
        $this->assertNull(collect($this->leave()->balances($employee, 2026))->firstWhere('code', 'unpaid')['available']);
    }

    public function test_nobody_decides_their_own_leave(): void
    {
        $user = $this->inBranches($this->userWith([]), $this->dhaka);
        $employee = $this->hire($this->dhaka, ['user_id' => $user->id]);
        $this->actingAs($user);
        $application = $this->leave()->apply($employee, $this->casual, '2026-07-13', '2026-07-13');

        $this->assertRefused('own_request', fn () => $this->leave()->approve($application));
        $this->assertRefused('own_request', fn () => $this->leave()->reject($application));
        $this->assertSame(LeaveApplication::CANCELLED, $this->leave()->cancel($application)->status);
    }
}
