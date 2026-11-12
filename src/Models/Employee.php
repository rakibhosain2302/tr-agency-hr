<?php

namespace Nit\AgraniHr\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Nit\AgraniHr\Models\Concerns\BranchScoped;
use Nit\AgraniHr\Models\Concerns\RecordsAuthors;
use Nit\Master\Support\Branches;
use Nit\Master\Support\Nit;

/**
 * An employee. branch_id is the main branch; an employee may also be assigned to other branches and is then
 * visible there too. user_id links nit-master's user (sign-in account). Write through EmployeeService, which
 * keeps the history.
 *
 * @property int $id
 * @property string $uuid
 * @property string $employee_code
 * @property int|null $user_id
 * @property int $branch_id
 * @property int|null $department_id
 * @property int|null $designation_id
 * @property int|null $employment_type_id
 * @property int $employee_status_id
 * @property int|null $reports_to_id
 * @property string $first_name
 * @property string|null $last_name
 * @property Carbon $joining_date
 * @property Carbon|null $termination_date
 */
class Employee extends Model
{
    use BranchScoped, RecordsAuthors, SoftDeletes;

    protected $table = 'hr_employees';

    protected $fillable = [
        'employee_code', 'branch_id', 'department_id', 'designation_id', 'employment_type_id', 'employee_status_id', 'reports_to_id',
        'first_name', 'last_name', 'gender', 'date_of_birth', 'phone', 'email', 'national_id', 'joining_date',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'joining_date' => 'date',
            'termination_date' => 'date',
            'user_id' => 'integer',
            'branch_id' => 'integer',
            'department_id' => 'integer',
            'designation_id' => 'integer',
            'employment_type_id' => 'integer',
            'employee_status_id' => 'integer',
            'reports_to_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $employee): void {
            $employee->uuid ??= (string) Str::uuid();
        });

        // Visible in the main branch and in every branch the employee is assigned to now (replaces BelongsToBranch's scope).
        static::addGlobalScope('nit_branch', function (Builder $query): void {
            $ids = Branches::visibleIds(auth()->user());
            if ($ids === null) {
                return;
            }
            $ids = $ids ?: [0];
            $query->where(fn (Builder $visible) => $visible->whereIn($query->qualifyColumn('branch_id'), $ids)
                ->orWhereExists(fn ($assignment) => $assignment->selectRaw('1')->from('hr_employee_branch_assignments as hba')
                    ->whereColumn('hba.employee_id', $query->qualifyColumn('id'))
                    ->whereIn('hba.branch_id', $ids)
                    ->where(fn ($open) => $open->whereNull('hba.end_date')->orWhereDate('hba.end_date', '>=', now()->toDateString()))));
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function user(): BelongsTo
    {
        $relation = $this->belongsTo(Nit::userModel(), 'user_id');

        return Nit::usersHaveTrash() ? $relation->withTrashed() : $relation;
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class, 'designation_id');
    }

    public function employmentType(): BelongsTo
    {
        return $this->belongsTo(EmploymentType::class, 'employment_type_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(EmployeeStatus::class, 'employee_status_id');
    }

    public function reportsTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reports_to_id');
    }

    public function personalInfo(): HasOne
    {
        return $this->hasOne(EmployeePersonalInfo::class, 'employee_id');
    }

    public function branchAssignments(): HasMany
    {
        return $this->hasMany(EmployeeBranchAssignment::class, 'employee_id')->orderByDesc('start_date');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class, 'employee_id')->latest('id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(EmployeeHistory::class, 'employee_id')->orderByDesc('effective_date')->orderByDesc('id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class, 'employee_id');
    }

    public function leaveApplications(): HasMany
    {
        return $this->hasMany(LeaveApplication::class, 'employee_id');
    }

    public function salaryStructures(): HasMany
    {
        return $this->hasMany(SalaryStructure::class, 'employee_id')->orderByDesc('effective_from');
    }

    public function advances(): HasMany
    {
        return $this->hasMany(EmployeeAdvance::class, 'employee_id');
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(PayrollItem::class, 'employee_id');
    }

    /** Employees in a status that counts as employed. */
    public function scopeEmployed(Builder $query): Builder
    {
        return $query->whereHas('status', fn (Builder $status) => $status->where('is_employed', true));
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function isTerminated(): bool
    {
        return $this->termination_date !== null;
    }

    public function activityName(): string
    {
        return 'hr_employee';
    }

    public function activityLabel(): string
    {
        return "{$this->employee_code} {$this->fullName()}";
    }
}
