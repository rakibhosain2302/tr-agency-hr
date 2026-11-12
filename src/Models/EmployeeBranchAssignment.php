<?php

namespace Nit\AgraniHr\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Nit\AgraniHr\Models\Concerns\RecordsAuthors;
use Nit\Master\Models\Branch;

/**
 * A period in which an employee works in a branch. One primary assignment is open at a time: the employee's
 * main branch (hr_employees.branch_id). end_date NULL = still assigned.
 *
 * @property int $id
 * @property int $employee_id
 * @property int $branch_id
 * @property Carbon $start_date
 * @property Carbon|null $end_date
 * @property bool $is_primary
 */
class EmployeeBranchAssignment extends Model
{
    use RecordsAuthors;

    protected $table = 'hr_employee_branch_assignments';

    protected $fillable = ['employee_id', 'branch_id', 'start_date', 'end_date', 'is_primary', 'notes'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'is_primary' => 'boolean', 'employee_id' => 'integer', 'branch_id' => 'integer'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', now()->toDateString()));
    }
}
