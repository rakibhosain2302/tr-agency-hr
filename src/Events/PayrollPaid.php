<?php

namespace Nit\AgraniHr\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Nit\AgraniHr\Models\Payroll;

/**
 * The salaries of a payroll were paid. Dispatched after the database transaction commits.
 */
class PayrollPaid implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Payroll $payroll) {}
}
