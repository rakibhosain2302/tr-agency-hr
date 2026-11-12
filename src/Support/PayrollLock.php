<?php

namespace Nit\AgraniHr\Support;

use Illuminate\Support\Carbon;
use Nit\AgraniHr\Exceptions\HrException;
use Nit\AgraniHr\Models\Payroll;
use Nit\AgraniHr\Models\PayrollItem;

/**
 * Days already paid: an approved or paid payroll of the employee covers them. Attendance, leave and salary
 * of those days no longer change (reverse the payroll to correct them).
 */
final class PayrollLock
{
    public static function covers(int $employeeId, \DateTimeInterface|string $from, \DateTimeInterface|string|null $to = null): bool
    {
        $from = Carbon::parse($from)->toDateString();
        $to = Carbon::parse($to ?? $from)->toDateString();

        return PayrollItem::query()->where('employee_id', $employeeId)
            ->whereHas('payroll', fn ($payroll) => $payroll->withoutGlobalScopes()->whereIn('status', [Payroll::APPROVED, Payroll::PAID])
                ->whereHas('period', fn ($period) => $period->whereDate('start_date', '<=', $to)->whereDate('end_date', '>=', $from)))
            ->exists();
    }

    public static function ensureOpen(int $employeeId, \DateTimeInterface|string $from, \DateTimeInterface|string|null $to = null): void
    {
        if (self::covers($employeeId, $from, $to)) {
            throw HrException::invalid(__('These days are in an approved payroll; reverse the payroll to change them.'), 'payroll_locked');
        }
    }
}
