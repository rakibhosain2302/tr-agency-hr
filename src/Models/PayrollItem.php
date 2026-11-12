<?php

namespace Nit\AgraniHr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One employee's payslip in a payroll.
 *
 * @property int $id
 * @property int $payroll_id
 * @property int $employee_id
 * @property string $payslip_no
 * @property string $gross
 * @property string $deductions
 * @property string $net
 */
class PayrollItem extends Model
{
    protected $table = 'hr_payroll_items';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'gross' => 'decimal:2',
            'deductions' => 'decimal:2',
            'net' => 'decimal:2',
            'payable_days' => 'decimal:2',
            'unpaid_leave_days' => 'decimal:1',
            'absent_days' => 'decimal:1',
            'period_days' => 'integer',
            'late_minutes' => 'integer',
            'overtime_minutes' => 'integer',
            'payroll_id' => 'integer',
            'employee_id' => 'integer',
        ];
    }

    public function payroll(): BelongsTo
    {
        return $this->belongsTo(Payroll::class, 'payroll_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayrollItemLine::class, 'payroll_item_id')->orderBy('sort_order')->orderBy('id');
    }
}
