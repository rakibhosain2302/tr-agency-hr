<?php

namespace Nit\AgraniHr\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Nit\AgraniHr\Models\EmployeeAdvance;

/**
 * An employee advance was paid out. Dispatched after the database transaction commits.
 */
class EmployeeAdvanceDisbursed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public EmployeeAdvance $advance) {}
}
