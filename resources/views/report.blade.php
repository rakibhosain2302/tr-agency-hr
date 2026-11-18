{{-- An HR report on nit-master's A4 print page. --}}
@extends('nit::print')

@section('title', $report->title)

@section('subtitle')
    {{ __(':from to :to', ['from' => $report->filters['from'] ?? '', 'to' => $report->filters['to'] ?? '']) }}
@endsection

@section('content')
    @foreach ($report->sections as $section)
        @php
            // An app without branches (config nit.branches off) shows no branch column.
            $columns = config('nit.branches', true) ? $section['columns'] : array_diff_key($section['columns'], array_flip(['branch_id', 'branch', 'branch_name']));
        @endphp
        <h3 style="margin: 18px 0 6px;">{{ $section['title'] }}</h3>
        <table style="width: 100%; border-collapse: collapse; font-size: 11px;">
            <thead>
                <tr>
                    @foreach ($columns as $label)
                        <th style="text-align: left; border-bottom: 1px solid #999; padding: 3px 4px;">{{ $label }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ([...$section['rows'], ...($section['totals'] !== null ? [$section['totals']] : [])] as $row)
                    <tr>
                        @foreach (array_keys($columns) as $key)
                            <td style="border-bottom: 1px solid #eee; padding: 3px 4px;">{{ $row[$key] ?? '' }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endforeach

    @if ($report->summary)
        <table style="margin-top: 16px; font-size: 11px;">
            @foreach ($report->summary as $key => $value)
                <tr><th style="text-align: left; padding: 2px 8px 2px 0;">{{ str_replace('_', ' ', $key) }}</th><td>{{ $value }}</td></tr>
            @endforeach
        </table>
    @endif
@endsection
