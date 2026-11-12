<?php

namespace Nit\AgraniHr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Nit\AgraniHr\Models\Concerns\RecordsAuthors;

/**
 * A pay period (usually a calendar month). Closed periods take no new payroll.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property string $status
 */
class PayrollPeriod extends Model
{
    use RecordsAuthors;

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    protected $table = 'hr_payroll_periods';

    protected $fillable = ['code', 'name', 'start_date', 'end_date', 'status'];

    /** The database defaults, so a new record has them before it is read back. */
    protected $attributes = ['status' => self::OPEN];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date'];
    }

    public function payrolls(): HasMany
    {
        return $this->hasMany(Payroll::class, 'payroll_period_id');
    }

    public function days(): int
    {
        return (int) $this->start_date->diffInDays($this->end_date) + 1;
    }
}
