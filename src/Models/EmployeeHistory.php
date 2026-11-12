<?php

namespace Nit\AgraniHr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One event in an employee's working life: joined, transferred, a change of department, designation,
 * employment type, status or manager, terminated, rehired, user linked / unlinked. Written by EmployeeService;
 * never changed afterwards.
 *
 * @property int $id
 * @property int $employee_id
 * @property string $event
 * @property string|null $field
 * @property string|null $old_value
 * @property string|null $new_value
 * @property Carbon $effective_date
 */
class EmployeeHistory extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'hr_employee_histories';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['effective_date' => 'date', 'employee_id' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
