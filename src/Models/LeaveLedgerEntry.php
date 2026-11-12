<?php

namespace Nit\AgraniHr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One movement of a leave balance (days: + entitlement, carry forward, restored; − taken; ± adjustment).
 * Written by LeaveService; never changed afterwards.
 *
 * @property int $id
 * @property int $employee_id
 * @property int $leave_type_id
 * @property int $year
 * @property string $kind
 * @property string $days
 * @property Carbon $entry_date
 */
class LeaveLedgerEntry extends Model
{
    public const ENTITLEMENT = 'entitlement';

    public const CARRY_FORWARD = 'carry_forward';

    public const ADJUSTMENT = 'adjustment';

    public const TAKEN = 'taken';

    public const RESTORED = 'restored';

    public const UPDATED_AT = null;

    protected $table = 'hr_leave_ledger';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['days' => 'decimal:1', 'entry_date' => 'date', 'year' => 'integer', 'employee_id' => 'integer', 'leave_type_id' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(LeaveApplication::class, 'leave_application_id');
    }
}
