<?php

namespace Nit\AgraniHr\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Nit\AgraniHr\Models\Payroll;

/**
 * An approved or paid payroll was reversed (its postings too). Dispatched after the database transaction commits.
 */
class PayrollReversed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Payroll $payroll) {}
}
