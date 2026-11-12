<?php

namespace Nit\AgraniHr\Reports;

use Illuminate\Http\JsonResponse;
use Nit\Master\Support\Pdf;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * An HR report as JSON, CSV (opens in Excel; UTF-8 with BOM) or PDF (nit-master's print page).
 */
final class HrReportExporter
{
    public const FORMATS = ['json', 'csv', 'pdf'];

    public function respond(HrReport $report, string $format): Response|JsonResponse
    {
        return match ($format) {
            'csv' => $this->csv($report),
            'pdf' => Pdf::download('hr::report', ['report' => $report], $this->filename($report, 'pdf')),
            default => response()->json(['data' => $report->toArray()]),
        };
    }

    public function csv(HrReport $report): StreamedResponse
    {
        return response()->streamDownload(function () use ($report): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [$report->title]);
            foreach ($report->filters as $key => $value) {
                fputcsv($out, [$key, is_array($value) ? implode(' ', $value) : (string) $value]);
            }
            foreach ($report->sections as $section) {
                fputcsv($out, []);
                fputcsv($out, [$section['title']]);
                fputcsv($out, array_values($section['columns']));
                foreach ([...$section['rows'], ...($section['totals'] !== null ? [$section['totals']] : [])] as $row) {
                    fputcsv($out, array_map(fn ($key) => $this->cell($row[$key] ?? ''), array_keys($section['columns'])));
                }
            }
            fputcsv($out, []);
            foreach ($report->summary as $key => $value) {
                fputcsv($out, [$key, (string) $value]);
            }
            fclose($out);
        }, $this->filename($report, 'csv'), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Text a spreadsheet would run as a formula is quoted. */
    private function cell(mixed $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@'], true) && ! is_numeric($value) ? "'".$value : $value;
    }

    private function filename(HrReport $report, string $extension): string
    {
        return "hr-{$report->key}-".($report->filters['from'] ?? '').'-'.($report->filters['to'] ?? '').".{$extension}";
    }
}
