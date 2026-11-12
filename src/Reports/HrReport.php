<?php

namespace Nit\AgraniHr\Reports;

/**
 * A report: title, the filters it was made with, sections (columns, rows, totals) and a summary. The same shape
 * as the accounting package's reports, so a host can show and export them alike.
 */
final class HrReport
{
    /** @var list<array{title: string, columns: array<string, string>, rows: list<array<string, mixed>>, totals: array<string, mixed>|null}> */
    public array $sections = [];

    /** @var array<string, mixed> */
    public array $summary = [];

    /** @param  array<string, mixed>  $filters */
    public function __construct(public string $key, public string $title, public array $filters) {}

    /**
     * @param  array<string, string>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>|null  $totals
     */
    public function section(string $title, array $columns, array $rows, ?array $totals = null): self
    {
        $this->sections[] = ['title' => $title, 'columns' => $columns, 'rows' => array_values($rows), 'totals' => $totals];

        return $this;
    }

    /** @param  array<string, mixed>  $values */
    public function summarize(array $values): self
    {
        $this->summary = $values + $this->summary;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['key' => $this->key, 'title' => $this->title, 'filters' => $this->filters, 'sections' => $this->sections, 'summary' => $this->summary];
    }
}
