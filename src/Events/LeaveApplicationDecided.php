<?php

namespace Nit\AgraniHr\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Nit\AgraniHr\Models\LeaveApplication;

/**
 * A leave application was approved or rejected. Dispatched after the database transaction commits.
 */
class LeaveApplicationDecided implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public LeaveApplication $application) {}
}
