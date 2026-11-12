<?php

namespace Nit\AgraniHr\Support;

use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;

/**
 * Weekend days from config('hr.attendance.weekend_days').
 */
final class WorkCalendar
{
    public static function isWeekend(\DateTimeInterface|string $date): bool
    {
        return in_array(Carbon::parse($date)->dayOfWeek, array_map('intval', (array) config('hr.attendance.weekend_days', [])), true);
    }

    /** @return list<Carbon> every day from $from to $to */
    public static function days(\DateTimeInterface|string $from, \DateTimeInterface|string $to): array
    {
        return array_values(iterator_to_array(CarbonPeriod::create(Carbon::parse($from)->startOfDay(), Carbon::parse($to)->startOfDay())));
    }
}
