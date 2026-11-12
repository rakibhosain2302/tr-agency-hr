<?php

namespace Nit\AgraniHr\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Nit\AgraniHr\Models\Employee;

/**
 * An employee's employment ended. Dispatched after the database transaction commits.
 */
class EmployeeTerminated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Employee $employee) {}
}
