<?php

namespace Nit\AgraniHr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One component of a structure: amount (monthly) for fixed components, percent (of basic) for percentage ones.
 *
 * @property int $id
 * @property int $salary_component_id
 * @property string|null $amount
 * @property string|null $percent
 */
class SalaryStructureLine extends Model
{
    protected $table = 'hr_salary_structure_lines';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'percent' => 'decimal:4', 'salary_component_id' => 'integer'];
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(SalaryComponent::class, 'salary_component_id');
    }
}
