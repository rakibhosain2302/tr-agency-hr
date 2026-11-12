<?php

namespace Nit\AgraniHr\Contracts;

use Nit\AgraniHr\Models\Employee;

/**
 * Makes an employee code when none is given (called inside the hiring transaction). A host application may
 * bind its own, e.g. to its document-number service; the default is DatabaseEmployeeCodeGenerator.
 */
interface EmployeeCodeGenerator
{
    public function next(Employee $employee): string;
}
