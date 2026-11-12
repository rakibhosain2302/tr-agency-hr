<?php

namespace Nit\AgraniHr\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Nit\AgraniHr\Models\Concerns\RecordsAuthors;
use Nit\Master\Models\Branch;
use Nit\Master\Models\Concerns\LogsActivity;

/**
 * A department: a tree (parent_id), company-wide or of one branch (branch_id).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 */
class Department extends Model
{
    use LogsActivity, RecordsAuthors, SoftDeletes;

    protected $table = 'hr_departments';

    protected $fillable = ['parent_id', 'branch_id', 'code', 'name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'parent_id' => 'integer', 'branch_id' => 'integer'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function designations(): HasMany
    {
        return $this->hasMany(Designation::class, 'department_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'department_id');
    }

    /** @return list<int> this department and every one below it */
    public function descendantIds(): array
    {
        $ids = [$this->id];
        $level = [$this->id];
        while ($level !== []) {
            $level = self::query()->whereIn('parent_id', $level)->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $ids = [...$ids, ...$level];
        }

        return $ids;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function activityName(): string
    {
        return 'hr_department';
    }
}
