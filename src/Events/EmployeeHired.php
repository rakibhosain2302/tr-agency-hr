<?php

namespace Nit\AgraniHr\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Nit\AgraniHr\Models\Employee;

/**
 * An employee was hired (or rehired). Dispatched after the database transaction commits.
 */
class EmployeeHired implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Employee $employee) {}
}
