<?php

namespace Nit\AgraniHr\Models;

use Illuminate\Database\Eloquent\Model;
use Nit\AgraniHr\Models\Concerns\RecordsAuthors;
use Nit\Master\Models\Concerns\LogsActivity;

/**
 * A kind of leave. days_per_year null = no yearly limit (no balance is checked); is_paid false = the days
 * are deducted from the salary. carry_forward_max days of the unused balance move to the next year.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $days_per_year
 * @property bool $is_paid
 * @property string $carry_forward_max
 * @property bool $allow_half_day
 */
class LeaveType extends Model
{
    use LogsActivity, RecordsAuthors;

    protected $table = 'hr_leave_types';

    protected $fillable = ['code', 'name', 'days_per_year', 'is_paid', 'carry_forward_max', 'allow_half_day', 'is_active'];

    /** The database defaults, so a new record has them before it is read back. */
    protected $attributes = ['is_paid' => true, 'carry_forward_max' => '0.0', 'allow_half_day' => true, 'is_active' => true];

    protected function casts(): array
    {
        return ['days_per_year' => 'decimal:1', 'carry_forward_max' => 'decimal:1', 'is_paid' => 'boolean', 'allow_half_day' => 'boolean', 'is_active' => 'boolean'];
    }

    public function isLimited(): bool
    {
        return $this->days_per_year !== null;
    }

    public function activityName(): string
    {
        return 'hr_leave_type';
    }
}
