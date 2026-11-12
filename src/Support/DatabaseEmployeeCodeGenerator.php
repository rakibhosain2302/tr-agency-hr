<?php

namespace Nit\AgraniHr\Support;

use Illuminate\Support\Facades\DB;
use Nit\AgraniHr\Contracts\EmployeeCodeGenerator;
use Nit\AgraniHr\Models\Employee;

/**
 * "EMP-00001" …: the next number after the highest code of the same format. The employees table is locked
 * for the moment the number is taken, so two hires at once never get the same code.
 */
final class DatabaseEmployeeCodeGenerator implements EmployeeCodeGenerator
{
    public function next(Employee $employee): string
    {
        $format = (string) config('hr.employee_code.format', 'EMP-{number}');
        [$prefix, $suffix] = array_pad(explode('{number}', $format, 2), 2, '');

        $codes = DB::table('hr_employees')->lockForUpdate()
            ->where('employee_code', 'like', addcslashes($prefix, '%_\\').'%'.addcslashes($suffix, '%_\\'))
            ->pluck('employee_code');

        $highest = 0;
        foreach ($codes as $code) {
            $number = substr((string) $code, strlen($prefix), strlen((string) $code) - strlen($prefix) - strlen($suffix));
            if (ctype_digit($number)) {
                $highest = max($highest, (int) $number);
            }
        }

        return $prefix.str_pad((string) ($highest + 1), max(1, (int) config('hr.employee_code.padding', 5)), '0', STR_PAD_LEFT).$suffix;
    }
}
