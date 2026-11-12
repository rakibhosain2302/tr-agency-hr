# nit/agrani-hr

Human resources for the Agrani ERP, as an independent Laravel package. It depends only on `nit/master` and Laravel; accounting goes through a contract the host application implements. Namespace `Nit\AgraniHr`; tables are prefixed `hr_`.

## Install

```bash
composer require nit/agrani-hr:@dev
php artisan migrate
php artisan hr:install     # system employee statuses and the basic salary component
```

Link app users to employees with the `HasEmployee` trait on the user model.

## What it does

| Area | Services |
|---|---|
| Employees: departments, designations, employment types, statuses, branch assignments and transfers, documents (private disk), history | `EmployeeService`, `EmployeeDocumentService` |
| Attendance with corrections (request → approve), late minutes, overtime | `AttendanceService` |
| Leave types, applications (approve / reject), a ledger-based balance | `LeaveService` |
| Salary components (earning / deduction; fixed or % of basic), salary structures by effective date | `SalaryStructureService` |
| Payroll per period and branch: draft (processed, i.e. calculated, while draft) → approved → paid; payment and payroll reversal; payslips (PDF) | `PayrollService`, `PayrollCalculator` |
| Employee advances: request → approve → disburse → recovered by payroll / settled | `EmployeeAdvanceService` |
| Reports: employees, attendance, leave, payroll, advances (json / csv / pdf) | `Reports\HrReportService`, `HrReportExporter` |

Payroll calculation: prorating by calendar days for joining, leaving or salary changes; unpaid leave and absence at a day's rate; overtime at basic ÷ 208 × 2 per hour; open advances recovered last. Every line is rounded half up to 2 decimals, and the totals are sums of the lines. The settings are in `config/hr.php` › `payroll`.

## Accounting contract

`Nit\AgraniHr\Contracts\HrAccounting`:

- `connected()`
- `post(Posting $posting): ?string`
- `reverse(string $reference, string $date, ?string $narration)`
- `settlementAccountExists(string $code, int $branchId)`

Each posting line has a **posting key** (`salary_expense`, `salary_payable`, `employee_advance`, `provident_fund`, `income_tax`, `settlement`). The host maps the keys to ledger accounts. In the Agrani app, `App\Services\Accounts\AccountsHrAccounting` posts through agrani-accounts' `JournalService::record`, idempotent by source key. With no binding, HR works without posting.

Events: `EmployeeHired`, `EmployeeTerminated`, `EmployeeTransferred`, `LeaveApplicationDecided`, `PayrollApproved`, `PayrollPaid`, `PayrollReversed`, `EmployeeAdvanceDisbursed`.

## API

`/api/nit/hr/...`: 91 endpoints, Bearer token, throttle and `authorization`, enabled by `HR_API`. Employees are addressed by uuid. Reports are at `/reports/{employees|attendance|leave|payroll|advances}?from&to&branch_id&status&department_id&payroll_period_id&format=json|csv|pdf`.

## Tests

88 tests on MariaDB:

```bash
HR_TEST_DB_DATABASE=nit_hr_testing HR_TEST_DB_USERNAME=… HR_TEST_DB_PASSWORD=… vendor/bin/phpunit
```
