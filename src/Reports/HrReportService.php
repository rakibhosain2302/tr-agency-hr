<?php

namespace Nit\AgraniHr\Reports;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Nit\AgraniHr\Support\Money;

/**
 * HR reports, built with aggregate queries on the HR tables (one query per section). Each is filtered by the
 * branches the user may see.
 */
final class HrReportService
{
    public const REPORTS = ['employees', 'attendance', 'leave', 'payroll', 'advances'];

    /** Head count by branch, department and status, and the employee list. */
    public function employees(HrReportFilters $f): HrReport
    {
        $report = new HrReport('employees', __('Employees'), $f->toArray());
        $base = fn () => $this->branches(DB::table('hr_employees as e')->whereNull('e.deleted_at'), $f, 'e.branch_id')
            ->leftJoin('hr_employee_statuses as s', 's.id', '=', 'e.employee_status_id')
            ->leftJoin('hr_departments as d', 'd.id', '=', 'e.department_id')
            ->when($f->status, fn ($q, $status) => $q->where('s.code', $status))
            ->when($f->departmentId, fn ($q, $id) => $q->where('e.department_id', $id));

        $byDepartment = $base()->groupBy('d.name', 's.name')->orderBy('d.name')->selectRaw("COALESCE(d.name, '—') AS department, s.name AS status, COUNT(*) AS employees")->get()->map(fn ($r) => ['department' => $r->department, 'status' => $r->status, 'employees' => (int) $r->employees])->all();
        $report->section(__('By department'), ['department' => __('Department'), 'status' => __('Status'), 'employees' => __('Employees')], $byDepartment, ['department' => __('Total'), 'status' => '', 'employees' => array_sum(array_column($byDepartment, 'employees'))]);

        $joined = $base()->whereBetween('e.joining_date', [$f->from, $f->to])->count();
        $left = $base()->whereNotNull('e.termination_date')->whereBetween('e.termination_date', [$f->from, $f->to])->count();
        $list = $base()->leftJoin('hr_designations as g', 'g.id', '=', 'e.designation_id')->orderBy('e.employee_code')->limit(5000)
            ->selectRaw("e.employee_code AS code, TRIM(CONCAT(e.first_name, ' ', COALESCE(e.last_name, ''))) AS name, COALESCE(d.name, '') AS department, COALESCE(g.name, '') AS designation, e.branch_id, e.joining_date, e.termination_date, s.name AS status")
            ->get()->map(fn ($r) => (array) $r)->all();
        $report->section(__('Employees'), ['code' => __('Code'), 'name' => __('Name'), 'department' => __('Department'), 'designation' => __('Designation'), 'branch_id' => __('Branch'), 'joining_date' => __('Joined'), 'termination_date' => __('Left'), 'status' => __('Status')], $list);

        return $report->summarize(['employees' => count($list), 'joined_in_period' => $joined, 'left_in_period' => $left]);
    }

    /** Days present, absent, late and overtime per employee. */
    public function attendance(HrReportFilters $f): HrReport
    {
        $report = new HrReport('attendance', __('Attendance'), $f->toArray());
        $rows = $this->branches(DB::table('hr_attendances as a'), $f, 'a.branch_id')
            ->join('hr_employees as e', 'e.id', '=', 'a.employee_id')
            ->whereBetween('a.work_date', [$f->from, $f->to])
            ->when($f->status, fn ($q, $status) => $q->where('a.status', $status))
            ->when($f->departmentId, fn ($q, $id) => $q->where('e.department_id', $id))
            ->groupBy('e.id', 'e.employee_code', 'e.first_name', 'e.last_name')->orderBy('e.employee_code')
            ->selectRaw("e.employee_code AS code, TRIM(CONCAT(e.first_name, ' ', COALESCE(e.last_name, ''))) AS name,
                SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) AS present,
                SUM(CASE WHEN a.status = 'half_day' THEN 1 ELSE 0 END) AS half_days,
                SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) AS absent,
                SUM(CASE WHEN a.status = 'leave' THEN 1 ELSE 0 END) AS on_leave,
                SUM(CASE WHEN a.late_minutes > 0 THEN 1 ELSE 0 END) AS late_days,
                SUM(a.late_minutes) AS late_minutes, SUM(a.worked_minutes) AS worked_minutes, SUM(a.overtime_minutes) AS overtime_minutes")
            ->get()->map(fn ($r) => array_map(fn ($v) => is_numeric($v) ? (int) $v : $v, (array) $r) + ['code' => $r->code, 'name' => $r->name])->all();
        $sum = fn (string $c) => array_sum(array_map(fn ($r) => (int) $r[$c], $rows));
        $columns = ['code' => __('Code'), 'name' => __('Name'), 'present' => __('Present'), 'half_days' => __('Half days'), 'absent' => __('Absent'), 'on_leave' => __('Leave'), 'late_days' => __('Late days'), 'late_minutes' => __('Late min'), 'worked_minutes' => __('Worked min'), 'overtime_minutes' => __('Overtime min')];
        $report->section(__('Attendance by employee'), $columns, $rows, ['code' => __('Total'), 'name' => ''] + array_combine(array_slice(array_keys($columns), 2), array_map($sum, array_slice(array_keys($columns), 2))));

        return $report->summarize(['employees' => count($rows), 'absent_days' => $sum('absent'), 'overtime_hours' => round($sum('overtime_minutes') / 60, 2)]);
    }

    /** Leave days by type and status, and the applications. */
    public function leave(HrReportFilters $f): HrReport
    {
        $report = new HrReport('leave', __('Leave'), $f->toArray());
        $base = fn () => $this->branches(DB::table('hr_leave_applications as l'), $f, 'l.branch_id')
            ->join('hr_leave_types as t', 't.id', '=', 'l.leave_type_id')->join('hr_employees as e', 'e.id', '=', 'l.employee_id')
            ->whereDate('l.start_date', '<=', $f->to)->whereDate('l.end_date', '>=', $f->from)
            ->when($f->status, fn ($q, $status) => $q->where('l.status', $status))
            ->when($f->departmentId, fn ($q, $id) => $q->where('e.department_id', $id));

        $byType = $base()->groupBy('t.name', 'l.status')->orderBy('t.name')->selectRaw('t.name AS leave_type, l.status, COUNT(*) AS applications, SUM(l.days) AS days')->get()
            ->map(fn ($r) => ['leave_type' => $r->leave_type, 'status' => $r->status, 'applications' => (int) $r->applications, 'days' => Money::round($r->days, 1)])->all();
        $report->section(__('By leave type'), ['leave_type' => __('Leave type'), 'status' => __('Status'), 'applications' => __('Applications'), 'days' => __('Days')], $byType);

        $list = $base()->orderBy('l.start_date')->limit(5000)->selectRaw("e.employee_code AS code, TRIM(CONCAT(e.first_name, ' ', COALESCE(e.last_name, ''))) AS name, t.name AS leave_type, l.start_date, l.end_date, l.days, l.status")
            ->get()->map(fn ($r) => (array) $r)->all();
        $report->section(__('Applications'), ['code' => __('Code'), 'name' => __('Name'), 'leave_type' => __('Type'), 'start_date' => __('From'), 'end_date' => __('To'), 'days' => __('Days'), 'status' => __('Status')], $list);

        $approved = array_filter($byType, fn ($r) => $r['status'] === 'approved');

        return $report->summarize(['applications' => count($list), 'approved_days' => Money::round(array_sum(array_map(fn ($r) => (float) $r['days'], $approved)), 1)]);
    }

    /** Payrolls of the period (or of the dates) with their totals, and the earnings and deductions by component. */
    public function payroll(HrReportFilters $f): HrReport
    {
        $report = new HrReport('payroll', __('Payroll'), $f->toArray());
        $base = fn () => $this->branches(DB::table('hr_payrolls as p'), $f, 'p.branch_id')
            ->join('hr_payroll_periods as pp', 'pp.id', '=', 'p.payroll_period_id')
            ->when($f->payrollPeriodId, fn ($q, $id) => $q->where('p.payroll_period_id', $id), fn ($q) => $q->whereDate('pp.start_date', '<=', $f->to)->whereDate('pp.end_date', '>=', $f->from))
            ->when($f->status, fn ($q, $status) => $q->where('p.status', $status), fn ($q) => $q->where('p.status', '<>', 'reversed'));

        $payrolls = $base()->orderBy('pp.start_date')->orderBy('p.payroll_no')
            ->selectRaw('p.payroll_no, pp.name AS period, p.branch_id, p.status, p.employee_count AS employees, p.gross_total AS gross, p.deduction_total AS deductions, p.net_total AS net')
            ->get()->map(fn ($r) => ['employees' => (int) $r->employees] + (array) $r)->all();
        $total = fn (string $c) => Money::add(...array_column($payrolls, $c));
        $report->section(__('Payrolls'), ['payroll_no' => __('Payroll'), 'period' => __('Period'), 'branch_id' => __('Branch'), 'status' => __('Status'), 'employees' => __('Employees'), 'gross' => __('Gross'), 'deductions' => __('Deductions'), 'net' => __('Net')], $payrolls,
            ['payroll_no' => __('Total'), 'period' => '', 'branch_id' => '', 'status' => '', 'employees' => array_sum(array_column($payrolls, 'employees')), 'gross' => $total('gross'), 'deductions' => $total('deductions'), 'net' => $total('net')]);

        $components = $base()->join('hr_payroll_items as i', 'i.payroll_id', '=', 'p.id')->join('hr_payroll_item_lines as l', 'l.payroll_item_id', '=', 'i.id')
            ->groupBy('l.kind', 'l.code', 'l.name')->orderBy('l.kind', 'desc')->orderBy('l.code')
            ->selectRaw('l.kind, l.code, l.name, SUM(l.amount) AS amount')->get()
            ->map(fn ($r) => ['kind' => $r->kind, 'code' => $r->code, 'name' => $r->name, 'amount' => Money::round($r->amount)])->all();
        $report->section(__('By component'), ['kind' => __('Kind'), 'code' => __('Code'), 'name' => __('Component'), 'amount' => __('Amount')], $components);

        return $report->summarize(['payrolls' => count($payrolls), 'gross' => $total('gross'), 'deductions' => $total('deductions'), 'net' => $total('net')]);
    }

    /** Advances by status with what was recovered and what is still owed. */
    public function advances(HrReportFilters $f): HrReport
    {
        $report = new HrReport('advances', __('Employee advances'), $f->toArray());
        $base = fn () => $this->branches(DB::table('hr_employee_advances as a'), $f, 'a.branch_id')
            ->join('hr_employees as e', 'e.id', '=', 'a.employee_id')
            ->whereBetween('a.request_date', [$f->from, $f->to])
            ->when($f->status, fn ($q, $status) => $q->where('a.status', $status));

        $byStatus = $base()->groupBy('a.status')->orderBy('a.status')->selectRaw('a.status, COUNT(*) AS advances, SUM(a.amount) AS amount, SUM(a.recovered_amount) AS recovered')->get()
            ->map(fn ($r) => ['status' => $r->status, 'advances' => (int) $r->advances, 'amount' => Money::round($r->amount), 'recovered' => Money::round($r->recovered), 'outstanding' => in_array($r->status, ['disbursed', 'settled'], true) ? Money::sub($r->amount, $r->recovered) : '0.00'])->all();
        $report->section(__('By status'), ['status' => __('Status'), 'advances' => __('Advances'), 'amount' => __('Amount'), 'recovered' => __('Recovered'), 'outstanding' => __('Outstanding')], $byStatus);

        $list = $base()->orderBy('a.request_date')->limit(5000)->selectRaw("a.advance_no, e.employee_code AS code, TRIM(CONCAT(e.first_name, ' ', COALESCE(e.last_name, ''))) AS name, a.request_date, a.amount, a.recovered_amount AS recovered, a.status")
            ->get()->map(fn ($r) => (array) $r)->all();
        $report->section(__('Advances'), ['advance_no' => __('Advance'), 'code' => __('Code'), 'name' => __('Name'), 'request_date' => __('Requested'), 'amount' => __('Amount'), 'recovered' => __('Recovered'), 'status' => __('Status')], $list);

        return $report->summarize(['advances' => count($list), 'outstanding' => Money::add(...array_column($byStatus, 'outstanding'))]);
    }

    private function branches(Builder $query, HrReportFilters $f, string $column): Builder
    {
        return $f->branchIds === null ? $query : $query->whereIn($column, $f->branchIds ?: [0]);
    }
}
