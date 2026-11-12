<?php

namespace Nit\AgraniHr\Models;

use Illuminate\Database\Eloquent\Model;
use Nit\AgraniHr\Models\Concerns\RecordsAuthors;
use Nit\Master\Models\Concerns\LogsActivity;

/**
 * A salary component: an earning (basic, house rent, medical …) or a deduction (provident fund, tax …), as a
 * fixed monthly amount or a percentage of basic. "basic" is the system component every structure has.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $kind
 * @property string $calculation
 * @property string|null $default_value
 * @property string $posting_key
 * @property bool $is_system
 */
class SalaryComponent extends Model
{
    use LogsActivity, RecordsAuthors;

    public const BASIC = 'basic';

    public const EARNING = 'earning';

    public const DEDUCTION = 'deduction';

    public const FIXED = 'fixed';

    public const PERCENT_OF_BASIC = 'percent_of_basic';

    protected $table = 'hr_salary_components';

    protected $fillable = ['code', 'name', 'kind', 'calculation', 'default_value', 'posting_key', 'is_system', 'is_active', 'sort_order'];

    /** The database defaults, so a new record has them before it is read back. */
    protected $attributes = ['calculation' => self::FIXED, 'is_system' => false, 'is_active' => true, 'sort_order' => 100];

    protected function casts(): array
    {
        return ['default_value' => 'decimal:4', 'is_system' => 'boolean', 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function isEarning(): bool
    {
        return $this->kind === self::EARNING;
    }

    public function activityName(): string
    {
        return 'hr_salary_component';
    }
}
