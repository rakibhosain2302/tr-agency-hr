<?php

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Nit\AgraniHr\Http\Controllers\Api\AttendanceController;
use Nit\AgraniHr\Http\Controllers\Api\AttendanceCorrectionController;
use Nit\AgraniHr\Http\Controllers\Api\DepartmentController;
use Nit\AgraniHr\Http\Controllers\Api\DesignationController;
use Nit\AgraniHr\Http\Controllers\Api\EmployeeAdvanceController;
use Nit\AgraniHr\Http\Controllers\Api\EmployeeBranchAssignmentController;
use Nit\AgraniHr\Http\Controllers\Api\EmployeeController;
use Nit\AgraniHr\Http\Controllers\Api\EmployeeDocumentController;
use Nit\AgraniHr\Http\Controllers\Api\EmployeeStatusController;
use Nit\AgraniHr\Http\Controllers\Api\EmploymentTypeController;
use Nit\AgraniHr\Http\Controllers\Api\HrReportController;
use Nit\AgraniHr\Http\Controllers\Api\LeaveApplicationController;
use Nit\AgraniHr\Http\Controllers\Api\LeaveBalanceController;
use Nit\AgraniHr\Http\Controllers\Api\LeaveTypeController;
use Nit\AgraniHr\Http\Controllers\Api\PayrollController;
use Nit\AgraniHr\Http\Controllers\Api\PayrollPeriodController;
use Nit\AgraniHr\Http\Controllers\Api\SalaryComponentController;
use Nit\AgraniHr\Http\Controllers\Api\SalaryStructureController;
use Nit\Master\Http\Middlewares\AuthenticateApiToken;
use Nit\Master\Support\Nit;

/*
| The HR JSON API. By default inside nit-master's token API: /api/nit/hr/…, on only when NIT_API=true,
| with nit-master's token, throttle and "authorization" (ability) middleware. config('hr.api') can move it
| or give it other middleware. Employees are addressed by uuid.
*/
if (config('hr.api.enabled') ?? Nit::config('api.enabled', false)) {
    Route::prefix(config('hr.api.prefix') ?? trim((string) Nit::config('api.prefix', 'api/nit'), '/').'/hr')
        ->name('hr.api.')
        ->middleware(config('hr.api.middleware') ?? [AuthenticateApiToken::class, 'throttle:60,1,nit-api', SubstituteBindings::class, 'authorization'])
        ->group(function (): void {
            foreach ([
                'departments' => DepartmentController::class,
                'designations' => DesignationController::class,
                'employment-types' => EmploymentTypeController::class,
                'employee-statuses' => EmployeeStatusController::class,
                'leave-types' => LeaveTypeController::class,
                'salary-components' => SalaryComponentController::class,
                'payroll-periods' => PayrollPeriodController::class,
            ] as $path => $controller) {
                Route::get($path, [$controller, 'index'])->name("{$path}.index");
                Route::post($path, [$controller, 'store'])->name("{$path}.store");
                Route::put("{$path}/{record}", [$controller, 'update'])->whereNumber('record')->name("{$path}.update");
                Route::delete("{$path}/{record}", [$controller, 'destroy'])->whereNumber('record')->name("{$path}.destroy");
            }

            Route::get('employees', [EmployeeController::class, 'index'])->name('employees.index');
            Route::post('employees', [EmployeeController::class, 'store'])->name('employees.store');
            Route::get('employees/{employee}', [EmployeeController::class, 'show'])->whereUuid('employee')->name('employees.show');
            Route::put('employees/{employee}', [EmployeeController::class, 'update'])->whereUuid('employee')->name('employees.update');
            Route::delete('employees/{employee}', [EmployeeController::class, 'destroy'])->whereUuid('employee')->name('employees.destroy');
            Route::post('employees/{employee}/terminate', [EmployeeController::class, 'terminate'])->whereUuid('employee')->name('employees.terminate');
            Route::post('employees/{employee}/rehire', [EmployeeController::class, 'rehire'])->whereUuid('employee')->name('employees.rehire');
            Route::post('employees/{employee}/user', [EmployeeController::class, 'linkUser'])->whereUuid('employee')->name('employees.user.link');
            Route::delete('employees/{employee}/user', [EmployeeController::class, 'unlinkUser'])->whereUuid('employee')->name('employees.user.unlink');
            Route::get('employees/{employee}/history', [EmployeeController::class, 'history'])->whereUuid('employee')->name('employees.history');

            Route::get('employees/{employee}/branches', [EmployeeBranchAssignmentController::class, 'index'])->whereUuid('employee')->name('employees.branches.index');
            Route::post('employees/{employee}/branches', [EmployeeBranchAssignmentController::class, 'store'])->whereUuid('employee')->name('employees.branches.store');
            Route::post('employees/{employee}/branches/{assignment}/end', [EmployeeBranchAssignmentController::class, 'end'])->whereUuid('employee')->whereNumber('assignment')->name('employees.branches.end');

            Route::get('employees/{employee}/documents', [EmployeeDocumentController::class, 'index'])->whereUuid('employee')->name('employees.documents.index');
            Route::post('employees/{employee}/documents', [EmployeeDocumentController::class, 'store'])->whereUuid('employee')->name('employees.documents.store');
            Route::get('employees/{employee}/documents/{document}/download', [EmployeeDocumentController::class, 'download'])->whereUuid('employee')->whereNumber('document')->name('employees.documents.download');
            Route::delete('employees/{employee}/documents/{document}', [EmployeeDocumentController::class, 'destroy'])->whereUuid('employee')->whereNumber('document')->name('employees.documents.destroy');

            // Attendance and corrections; check-in / check-out are the signed-in user's own.
            Route::get('attendance', [AttendanceController::class, 'index'])->name('attendance.index');
            Route::post('attendance/check-in', [AttendanceController::class, 'checkIn'])->name('attendance.check-in');
            Route::post('attendance/check-out', [AttendanceController::class, 'checkOut'])->name('attendance.check-out');
            Route::put('employees/{employee}/attendance', [AttendanceController::class, 'record'])->whereUuid('employee')->name('employees.attendance.record');
            Route::get('attendance-corrections', [AttendanceCorrectionController::class, 'index'])->name('attendance-corrections.index');
            Route::post('attendance-corrections', [AttendanceCorrectionController::class, 'store'])->name('attendance-corrections.store');
            Route::post('attendance-corrections/{correction}/approve', [AttendanceCorrectionController::class, 'approve'])->whereNumber('correction')->name('attendance-corrections.approve');
            Route::post('attendance-corrections/{correction}/reject', [AttendanceCorrectionController::class, 'reject'])->whereNumber('correction')->name('attendance-corrections.reject');

            // Leave.
            Route::get('leave-applications', [LeaveApplicationController::class, 'index'])->name('leave-applications.index');
            Route::post('leave-applications', [LeaveApplicationController::class, 'store'])->name('leave-applications.store');
            Route::get('leave-applications/{application}', [LeaveApplicationController::class, 'show'])->whereUuid('application')->name('leave-applications.show');
            foreach (['approve', 'reject', 'cancel'] as $action) {
                Route::post("leave-applications/{application}/{$action}", [LeaveApplicationController::class, $action])->whereUuid('application')->name("leave-applications.{$action}");
            }
            Route::get('employees/{employee}/leave-balances', [LeaveBalanceController::class, 'index'])->whereUuid('employee')->name('employees.leave-balances.index');
            Route::post('employees/{employee}/leave-balances', [LeaveBalanceController::class, 'adjust'])->whereUuid('employee')->name('employees.leave-balances.adjust');
            Route::get('employees/{employee}/leave-history', [LeaveBalanceController::class, 'history'])->whereUuid('employee')->name('employees.leave-history');

            // Salaries.
            Route::get('employees/{employee}/salaries', [SalaryStructureController::class, 'index'])->whereUuid('employee')->name('employees.salaries.index');
            Route::post('employees/{employee}/salaries', [SalaryStructureController::class, 'store'])->whereUuid('employee')->name('employees.salaries.store');
            Route::put('employees/{employee}/salaries/{structure}', [SalaryStructureController::class, 'update'])->whereUuid('employee')->whereNumber('structure')->name('employees.salaries.update');
            Route::delete('employees/{employee}/salaries/{structure}', [SalaryStructureController::class, 'destroy'])->whereUuid('employee')->whereNumber('structure')->name('employees.salaries.destroy');

            // Payroll.
            Route::get('payrolls', [PayrollController::class, 'index'])->name('payrolls.index');
            Route::post('payrolls', [PayrollController::class, 'store'])->name('payrolls.store');
            Route::get('payrolls/{payroll}', [PayrollController::class, 'show'])->whereUuid('payroll')->name('payrolls.show');
            Route::delete('payrolls/{payroll}', [PayrollController::class, 'destroy'])->whereUuid('payroll')->name('payrolls.destroy');
            foreach (['process' => 'process', 'approve' => 'approve', 'pay' => 'pay', 'reverse-payment' => 'reversePayment', 'reverse' => 'reverse'] as $path => $action) {
                Route::post("payrolls/{payroll}/{$path}", [PayrollController::class, $action])->whereUuid('payroll')->name("payrolls.{$path}");
            }
            Route::get('payrolls/{payroll}/payslips/{item}', [PayrollController::class, 'payslip'])->whereUuid('payroll')->whereNumber('item')->name('payrolls.payslip');

            // Employee advances.
            Route::get('advances', [EmployeeAdvanceController::class, 'index'])->name('advances.index');
            Route::post('advances', [EmployeeAdvanceController::class, 'store'])->name('advances.store');
            Route::get('advances/{advance}', [EmployeeAdvanceController::class, 'show'])->whereUuid('advance')->name('advances.show');
            foreach (['approve' => 'approve', 'reject' => 'reject', 'cancel' => 'cancel', 'disburse' => 'disburse', 'reverse-disbursement' => 'reverseDisbursement', 'repayments' => 'repay'] as $path => $action) {
                Route::post("advances/{advance}/{$path}", [EmployeeAdvanceController::class, $action])->whereUuid('advance')->name("advances.{$path}");
            }
            Route::post('advances/{advance}/settlements/{settlement}/reverse', [EmployeeAdvanceController::class, 'reverseSettlement'])->whereUuid('advance')->whereNumber('settlement')->name('advances.settlements.reverse');

            // Reports (?format=json|csv|pdf).
            foreach (['employees', 'attendance', 'leave', 'payroll', 'advances'] as $report) {
                Route::get("reports/{$report}", [HrReportController::class, $report])->name("reports.{$report}");
            }
        });
}
