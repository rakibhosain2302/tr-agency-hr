<?php

namespace Nit\AgraniHr\Support;

use Illuminate\Database\Eloquent\Model;
use Nit\Master\Support\Activity;

/**
 * Writes HR events to nit-master's activity log (when config('hr.log_activity') is on), with the branch.
 */
final class HrLog
{
    /** @param  array<string, mixed>  $properties */
    public static function write(string $event, string $description, ?Model $subject, ?int $branchId, array $properties = []): void
    {
        if (config('hr.log_activity', true)) {
            Activity::log($event, $description, $subject, array_filter(['branch_id' => $branchId]) + $properties);
        }
    }
}
