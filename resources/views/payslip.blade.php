{{-- A printable payslip; $payslip is PayrollController's payslip data. --}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Payslip') }} {{ $payslip['payslip_no'] }}</title>
    <style>
        body { font-family: "Noto Sans Bengali", "Noto Sans", Arial, sans-serif; font-size: 12px; color: #111; margin: 24px; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        .muted { color: #555; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; }
        td.amount, th.amount { text-align: right; font-variant-numeric: tabular-nums; }
        .columns { display: flex; gap: 16px; }
        .columns > table { flex: 1; }
        .total td { font-weight: bold; }
    </style>
</head>
<body>
    <h1>{{ __('Payslip') }} {{ $payslip['payslip_no'] }}</h1>
    <div class="muted">{{ $payslip['payroll']['period']['name'] ?? '' }} ({{ $payslip['payroll']['period']['start_date'] ?? '' }} – {{ $payslip['payroll']['period']['end_date'] ?? '' }}) · {{ $payslip['payroll']['payroll_no'] }}</div>

    <table>
        <tr><th>{{ __('Employee') }}</th><td>{{ $payslip['employee']['employee_code'] }} {{ $payslip['employee']['name'] }}</td><th>{{ __('Designation') }}</th><td>{{ $payslip['employee']['designation'] ?? '—' }}</td></tr>
        <tr><th>{{ __('Department') }}</th><td>{{ $payslip['employee']['department'] ?? '—' }}</td><th>{{ __('Payable days') }}</th><td>{{ $payslip['payable_days'] }} / {{ $payslip['period_days'] }}</td></tr>
        <tr><th>{{ __('Unpaid leave days') }}</th><td>{{ $payslip['unpaid_leave_days'] }}</td><th>{{ __('Absent days') }}</th><td>{{ $payslip['absent_days'] }}</td></tr>
        <tr><th>{{ __('Overtime (minutes)') }}</th><td>{{ $payslip['overtime_minutes'] }}</td><th>{{ __('Late (minutes)') }}</th><td>{{ $payslip['late_minutes'] }}</td></tr>
    </table>

    <div class="columns">
        <table>
            <tr><th>{{ __('Earnings') }}</th><th class="amount">{{ __('Amount') }}</th></tr>
            @foreach ($payslip['earnings'] as $line)
                <tr><td>{{ $line['name'] }}</td><td class="amount">{{ number_format((float) $line['amount'], 2) }}</td></tr>
            @endforeach
            <tr class="total"><td>{{ __('Gross') }}</td><td class="amount">{{ number_format((float) $payslip['gross'], 2) }}</td></tr>
        </table>
        <table>
            <tr><th>{{ __('Deductions') }}</th><th class="amount">{{ __('Amount') }}</th></tr>
            @foreach ($payslip['deductions'] as $line)
                <tr><td>{{ $line['name'] }}</td><td class="amount">{{ number_format((float) $line['amount'], 2) }}</td></tr>
            @endforeach
            <tr class="total"><td>{{ __('Total deductions') }}</td><td class="amount">{{ number_format((float) $payslip['total_deductions'], 2) }}</td></tr>
        </table>
    </div>

    <table>
        <tr class="total"><td>{{ __('Net pay') }}</td><td class="amount">{{ number_format((float) $payslip['net'], 2) }}</td></tr>
    </table>
</body>
</html>
