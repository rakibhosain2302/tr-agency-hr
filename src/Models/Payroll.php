<?php

namespace Nit\AgraniHr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Nit\AgraniHr\Models\Concerns\BranchScoped;
use Nit\AgraniHr\Models\Concerns\RecordsAuthors;

/**
 * The payroll of one branch for one period. Write through PayrollService.
 *
 * @property int $id
 * @property string $uuid
 * @property string $payroll_no
 * @property int $payroll_period_id
 * @property int $branch_id
 * @property string $status
 * @property string $gross_total
 * @property string $deduction_total
 * @property string $net_total
 * @property int|null $processed_by
 * @property string|null $accounting_reference
 * @property string|null $payment_accounting_reference
 * @property int $payment_count
 * @property Carbon|null $approved_at
 */
class Payroll extends Model
{
    use BranchScoped, RecordsAuthors;

    public const DRAFT = 'draft';

    public const APPROVED = 'approved';

    public const PAID = 'paid';

    public const REVERSED = 'reversed';

    protected $table = 'hr_payrolls';

    protected $guarded = ['id'];

    /** The database defaults, so a new record has them before it is read back. */
    protected $attributes = ['status' => self::DRAFT, 'employee_count' => 0, 'gross_total' => '0.00', 'deduction_total' => '0.00', 'net_total' => '0.00', 'payment_count' => 0];

    protected function casts(): array
    {
        return [
            'gross_total' => 'decimal:2',
            'deduction_total' => 'decimal:2',
            'net_total' => 'decimal:2',
            'skipped' => 'array',
            'processed_at' => 'datetime',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
            'reversed_at' => 'datetime',
            'payment_date' => 'date',
            'payroll_period_id' => 'integer',
            'branch_id' => 'integer',
            'employee_count' => 'integer',
            'payment_count' => 'integer',
            'processed_by' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $payroll): void {
            $payroll->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayrollItem::class, 'payroll_id');
    }

    /** Approved or paid: posted to accounting and counting for the employees. */
    public function isPosted(): bool
    {
        return in_array($this->status, [self::APPROVED, self::PAID], true);
    }

    public function activityName(): string
    {
        return 'hr_payroll';
    }

    public function activityLabel(): string
    {
        return $this->payroll_no;
    }
}
