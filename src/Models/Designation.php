<?php

namespace Nit\AgraniHr\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Nit\AgraniHr\Models\Concerns\RecordsAuthors;
use Nit\Master\Models\Concerns\LogsActivity;

/**
 * A job title, for every department or for one (department_id).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 */
class Designation extends Model
{
    use LogsActivity, RecordsAuthors, SoftDeletes;

    protected $table = 'hr_designations';

    protected $fillable = ['department_id', 'code', 'name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'department_id' => 'integer'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'designation_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function activityName(): string
    {
        return 'hr_designation';
    }
}
