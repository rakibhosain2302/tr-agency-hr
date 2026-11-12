<?php

namespace Nit\AgraniHr\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Nit\AgraniHr\Models\Concerns\RecordsAuthors;

/**
 * An employee's salary from effective_from until effective_to (open-ended when null). Write through
 * SalaryStructureService, which ends the previous structure.
 *
 * @property int $id
 * @property int $employee_id
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 */
class SalaryStructure extends Model
{
    use RecordsAuthors;

    protected $table = 'hr_salary_structures';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_to' => 'date', 'employee_id' => 'integer'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalaryStructureLine::class, 'salary_structure_id');
    }

    /** Structures in force on some day between $from and $to. */
    public function scopeOverlapping(Builder $query, \DateTimeInterface|string $from, \DateTimeInterface|string $to): Builder
    {
        return $query->whereDate('effective_from', '<=', Carbon::parse($to)->toDateString())
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', Carbon::parse($from)->toDateString()));
    }
}
