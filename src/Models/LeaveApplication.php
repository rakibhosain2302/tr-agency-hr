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
 * A leave application. Write through LeaveService (it keeps the leave ledger).
 *
 * @property int $id
 * @property string $uuid
 * @property int $employee_id
 * @property int $leave_type_id
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property bool $half_day
 * @property string $days
 * @property string $status
 */
class LeaveApplication extends Model
{
    use BranchScoped, RecordsAuthors;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    protected $table = 'hr_leave_applications';

    protected $guarded = ['id'];

    /** The database defaults, so a new record has them before it is read back. */
    protected $attributes = ['status' => self::PENDING, 'half_day' => false];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'half_day' => 'boolean',
            'days' => 'decimal:1',
            'decided_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'employee_id' => 'integer',
            'leave_type_id' => 'integer',
            'applied_by' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $application): void {
            $application->uuid ??= (string) Str::uuid();
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

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    public function ledger(): HasMany
    {
        return $this->hasMany(LeaveLedgerEntry::class, 'leave_application_id');
    }

    public function activityName(): string
    {
        return 'hr_leave_application';
    }
}
