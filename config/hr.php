<?php

/*
| agrani-hr (nit/agrani-hr): employees and their organisation.
| Publish with: php artisan vendor:publish --tag=hr-config
|
| The package depends only on Laravel and nit/master (users, roles, permissions, branches, files).
*/

return [

    /*
    | Employee codes made when none is given: {number} is the next number, padded.
    | Bind Nit\AgraniHr\Contracts\EmployeeCodeGenerator to use the host application's own numbering.
    */
    'employee_code' => [
        'format' => 'EMP-{number}',
        'padding' => 5,
    ],

    /*
    | Employee documents (national ID, certificates, contracts …): the row is kept in hr_employee_documents,
    | the file in nit-master's "files" table on this disk. Keep the disk private: files are served only
    | through an authorized download.
    */
    'documents' => [
        'disk' => env('HR_DOCUMENTS_DISK', 'local'),
        'directory' => 'hr-documents',
        'max_kilobytes' => 10240,
        'mimes' => ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx'],
    ],

    /*
    | The JSON API. By default inside nit-master's token API (/api/nit/hr/…, on only when NIT_API=true).
    */
    'api' => [
        'enabled' => null,       // null = follow nit-master's NIT_API
        'prefix' => null,        // null = "<nit-master api prefix>/hr"
        'middleware' => null,    // null = nit-master's API middleware + "authorization"
    ],

    /*
    | Attendance. Late = check-in after work_start + grace (counted from work_start); worked = check-out −
    | check-in, less break_minutes when the day is longer than break_after_minutes; overtime = worked beyond
    | scheduled_minutes (all worked minutes on a weekend or holiday), if at least overtime_min_minutes.
    | weekend_days: Carbon day numbers (0 = Sunday … 5 = Friday, 6 = Saturday).
    */
    'attendance' => [
        'work_start' => '09:00',
        'scheduled_minutes' => 480,
        'grace_minutes' => 10,
        'break_minutes' => 60,
        'break_after_minutes' => 360,
        'overtime_min_minutes' => 30,
        'weekend_days' => [5],
    ],

    /*
    | Leave. Days are counted without the weekend days above; balances are per calendar year.
    */
    'leave' => [
        'exclude_weekends' => true,
    ],

    /*
    | Payroll.
    | - day_basis: the days a monthly salary is divided by for a day's deduction (unpaid leave, absence):
    |   'calendar' (days of the period) or a number such as 30. A part of a period is prorated by calendar days.
    | - unpaid_deduction_base: 'gross' (all fixed earnings) or 'basic'.
    | - overtime pay per hour = basic ÷ hourly_divisor × multiplier.
    | - separate_approver: the user who processed a payroll may not approve it (super admins excepted).
    | - posting_keys: where these amounts go in the accounts (the host maps keys to ledger accounts).
    */
    'payroll' => [
        'number_prefix' => 'PR-',
        'day_basis' => 'calendar',
        'unpaid_deduction_base' => 'gross',
        'deduct_absence' => true,
        'overtime' => [
            'enabled' => true,
            'hourly_divisor' => 208,
            'multiplier' => 2,
        ],
        'separate_approver' => true,
        'posting_keys' => [
            'overtime' => 'salary_expense',
            'unpaid_leave' => 'salary_expense',
            'absence' => 'salary_expense',
        ],
    ],

    /*
    | Employee advances.
    */
    'advances' => [
        'number_prefix' => 'ADV-',
    ],

    /*
    | Employee changes are written to nit-master's activity log, with the employee's branch.
    */
    'log_activity' => true,

];
