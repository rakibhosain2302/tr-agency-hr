<?php

namespace Nit\AgraniHr\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Nit\AgraniHr\Models\Concerns\RecordsAuthors;
use Nit\Master\Models\Concerns\LogsActivity;

/**
 * How someone is employed (permanent, contract … as the business defines them).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 */
class EmploymentType extends Model
{
    use LogsActivity, RecordsAuthors;

    protected $table = 'hr_employment_types';

    protected $fillable = ['code', 'name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'employment_type_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function activityName(): string
    {
        return 'hr_employment_type';
    }
}
