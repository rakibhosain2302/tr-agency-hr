<?php

namespace Nit\AgraniHr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Nit\AgraniHr\Models\Concerns\RecordsAuthors;

/**
 * A recovery of an advance: a salary deduction (from an approved payroll) or a cash repayment. Reversed
 * settlements stay, with reversed_at, and no longer count.
 *
 * @property int $id
 * @property int $employee_advance_id
 * @property string $kind
 * @property string $amount
 * @property Carbon $settlement_date
 * @property int|null $payroll_item_id
 * @property string|null $accounting_reference
 * @property Carbon|null $reversed_at
 */
class AdvanceSettlement extends Model
{
    use RecordsAuthors;

    public const SALARY_DEDUCTION = 'salary_deduction';

    public const CASH_REPAYMENT = 'cash_repayment';

    protected $table = 'hr_advance_settlements';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'settlement_date' => 'date', 'reversed_at' => 'datetime', 'employee_advance_id' => 'integer', 'payroll_item_id' => 'integer'];
    }

    public function advance(): BelongsTo
    {
        return $this->belongsTo(EmployeeAdvance::class, 'employee_advance_id');
    }

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }
}
