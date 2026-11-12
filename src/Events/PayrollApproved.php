<?php

namespace Nit\AgraniHr\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Nit\AgraniHr\Models\Payroll;

/**
 * A payroll was approved and posted to accounting. Dispatched after the database transaction commits.
 */
class PayrollApproved implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Payroll $payroll) {}
}
