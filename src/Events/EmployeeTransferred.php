<?php

namespace Nit\AgraniHr\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Nit\AgraniHr\Models\Employee;

/**
 * An employee's main branch changed. Dispatched after the database transaction commits.
 */
class EmployeeTransferred implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Employee $employee) {}
}
