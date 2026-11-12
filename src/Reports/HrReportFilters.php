<?php

namespace Nit\AgraniHr\Reports;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Nit\AgraniHr\Exceptions\HrException;
use Nit\Master\Support\Branches;

/**
 * Filters of an HR report: dates (default: the year so far), branches (only those the user sees; null = all),
 * status, department and pay period.
 */
final readonly class HrReportFilters
{
    /** @param  list<int>|null  $branchIds */
    public function __construct(
        public string $from,
        public string $to,
        public ?array $branchIds = null,
        public ?string $status = null,
        public ?int $departmentId = null,
        public ?int $payrollPeriodId = null,
    ) {}

    /** @param  array<string, mixed>  $input */
    public static function resolve(array $input, ?Authenticatable $user = null): self
    {
        $to = Carbon::parse($input['to'] ?? now())->toDateString();
        $from = Carbon::parse($input['from'] ?? Carbon::parse($to)->startOfYear())->toDateString();
        if ($from > $to) {
            throw HrException::invalid(__('The report starts after it ends.'), 'invalid_dates');
        }

        $visible = Branches::visibleIds($user);
        $asked = isset($input['branch_id']) ? [(int) $input['branch_id']] : (isset($input['branch_ids']) ? array_map('intval', (array) $input['branch_ids']) : null);
        $branches = match (true) {
            $asked === null => $visible,
            $visible === null => $asked,
            default => array_values(array_intersect($asked, $visible)) ?: [0],
        };

        return new self($from, $to, $branches, $input['status'] ?? null, isset($input['department_id']) ? (int) $input['department_id'] : null, isset($input['payroll_period_id']) ? (int) $input['payroll_period_id'] : null);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter(['from' => $this->from, 'to' => $this->to, 'branch_ids' => $this->branchIds, 'status' => $this->status, 'department_id' => $this->departmentId, 'payroll_period_id' => $this->payrollPeriodId], fn ($v) => $v !== null);
    }
}
