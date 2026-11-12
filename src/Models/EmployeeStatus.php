<?php

namespace Nit\AgraniHr\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Nit\AgraniHr\Models\Concerns\RecordsAuthors;
use Nit\Master\Models\Concerns\LogsActivity;

/**
 * An employee status. is_employed tells whether people in it count as employed; system statuses stay.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 */
class EmployeeStatus extends Model
{
    use LogsActivity, RecordsAuthors;

    protected $table = 'hr_employee_statuses';

    protected $fillable = ['code', 'name', 'is_employed', 'is_system', 'sort_order'];

    protected function casts(): array
    {
        return ['is_employed' => 'boolean', 'is_system' => 'boolean', 'sort_order' => 'integer'];
    }

    public const ACTIVE = 'active';

    public const SEPARATED = 'separated';

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'employee_status_id');
    }

    public function scopeEmployed(Builder $query): Builder
    {
        return $query->where('is_employed', true);
    }

    public function activityName(): string
    {
        return 'hr_employee_status';
    }
}
