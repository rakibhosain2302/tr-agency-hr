<?php

namespace Nit\AgraniHr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An earning or deduction on a payslip. source: structure, overtime, unpaid_leave, absence or advance.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $kind
 * @property string $source
 * @property string $posting_key
 * @property string $amount
 * @property int|null $employee_advance_id
 */
class PayrollItemLine extends Model
{
    public const SOURCE_STRUCTURE = 'structure';

    public const SOURCE_OVERTIME = 'overtime';

    public const SOURCE_UNPAID_LEAVE = 'unpaid_leave';

    public const SOURCE_ABSENCE = 'absence';

    public const SOURCE_ADVANCE = 'advance';

    protected $table = 'hr_payroll_item_lines';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'sort_order' => 'integer', 'employee_advance_id' => 'integer', 'salary_component_id' => 'integer'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(PayrollItem::class, 'payroll_item_id');
    }

    public function advance(): BelongsTo
    {
        return $this->belongsTo(EmployeeAdvance::class, 'employee_advance_id');
    }
}
