<?php

namespace Nit\AgraniHr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Nit\AgraniHr\Models\Concerns\BranchScoped;
use Nit\AgraniHr\Models\Concerns\RecordsAuthors;
use Nit\AgraniHr\Support\Money;

/**
 * Money advanced to an employee and recovered from salary or repaid. Write through EmployeeAdvanceService.
 *
 * @property int $id
 * @property string $uuid
 * @property string $advance_no
 * @property int $employee_id
 * @property int $branch_id
 * @property string $amount
 * @property string|null $installment_amount
 * @property string $recovered_amount
 * @property string $status
 * @property int|null $requested_by
 * @property Carbon|null $disbursement_date
 * @property string|null $accounting_reference
 */
class EmployeeAdvance extends Model
{
    use BranchScoped, RecordsAuthors;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const DISBURSED = 'disbursed';

    public const SETTLED = 'settled';

    public const CANCELLED = 'cancelled';

    protected $table = 'hr_employee_advances';

    protected $guarded = ['id'];

    /** The database defaults, so a new record has them before it is read back. */
    protected $attributes = ['status' => self::PENDING, 'recovered_amount' => '0.00'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'installment_amount' => 'decimal:2',
            'recovered_amount' => 'decimal:2',
            'request_date' => 'date',
            'disbursement_date' => 'date',
            'decided_at' => 'datetime',
            'disbursed_at' => 'datetime',
            'employee_id' => 'integer',
            'branch_id' => 'integer',
            'requested_by' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $advance): void {
            $advance->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(AdvanceSettlement::class, 'employee_advance_id')->orderBy('settlement_date')->orderBy('id');
    }

    public function outstanding(): string
    {
        return Money::sub($this->amount, $this->recovered_amount);
    }

    public function activityName(): string
    {
        return 'hr_employee_advance';
    }

    public function activityLabel(): string
    {
        return $this->advance_no;
    }
}
