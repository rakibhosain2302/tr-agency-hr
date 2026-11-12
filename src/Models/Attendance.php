<?php

namespace Nit\AgraniHr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Nit\AgraniHr\Models\Concerns\BranchScoped;
use Nit\AgraniHr\Models\Concerns\RecordsAuthors;

/**
 * An employee's attendance on one day. The minutes are calculated by AttendanceService; write through it.
 *
 * @property int $id
 * @property int $employee_id
 * @property int $branch_id
 * @property Carbon $work_date
 * @property string $status
 * @property Carbon|null $check_in_at
 * @property Carbon|null $check_out_at
 * @property int $worked_minutes
 * @property int $late_minutes
 * @property int $overtime_minutes
 */
class Attendance extends Model
{
    use BranchScoped, RecordsAuthors;

    public const PRESENT = 'present';

    public const ABSENT = 'absent';

    public const HALF_DAY = 'half_day';

    public const LEAVE = 'leave';

    public const HOLIDAY = 'holiday';

    public const WEEKEND = 'weekend';

    public const STATUSES = [self::PRESENT, self::ABSENT, self::HALF_DAY, self::LEAVE, self::HOLIDAY, self::WEEKEND];

    protected $table = 'hr_attendances';

    protected $guarded = ['id'];

    /** The database defaults, so a new record has them before it is read back. */
    protected $attributes = ['status' => self::PRESENT, 'source' => 'manual', 'scheduled_minutes' => 0, 'worked_minutes' => 0, 'late_minutes' => 0, 'overtime_minutes' => 0];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'check_in_at' => 'datetime',
            'check_out_at' => 'datetime',
            'employee_id' => 'integer',
            'branch_id' => 'integer',
            'scheduled_minutes' => 'integer',
            'worked_minutes' => 'integer',
            'late_minutes' => 'integer',
            'overtime_minutes' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function activityName(): string
    {
        return 'hr_attendance';
    }
}
