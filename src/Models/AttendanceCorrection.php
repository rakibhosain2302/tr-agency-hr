<?php

namespace Nit\AgraniHr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Nit\AgraniHr\Models\Concerns\BranchScoped;
use Nit\AgraniHr\Models\Concerns\RecordsAuthors;

/**
 * A request to change (or add) an attendance record. Approved, its values replace the record's and the old
 * values stay here.
 *
 * @property int $id
 * @property int $employee_id
 * @property int|null $attendance_id
 * @property Carbon $work_date
 * @property string $status
 * @property int|null $requested_by
 */
class AttendanceCorrection extends Model
{
    use BranchScoped, RecordsAuthors;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $table = 'hr_attendance_corrections';

    protected $guarded = ['id'];

    /** The database defaults, so a new record has them before it is read back. */
    protected $attributes = ['status' => self::PENDING];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'check_in_at' => 'datetime',
            'check_out_at' => 'datetime',
            'old_check_in_at' => 'datetime',
            'old_check_out_at' => 'datetime',
            'decided_at' => 'datetime',
            'employee_id' => 'integer',
            'attendance_id' => 'integer',
            'requested_by' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class, 'attendance_id');
    }
}
