<?php

namespace Nit\AgraniHr\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Nit\AgraniHr\Models\Employee;
use Nit\AgraniHr\Models\EmployeePersonalInfo;

/**
 * @mixin Employee
 */
class EmployeeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $named = fn (string $relation): ?array => $this->relationLoaded($relation) && $this->{$relation} ? ['id' => $this->{$relation}->id, 'name' => $this->{$relation}->name] : null;

        return [
            'id' => $this->uuid,
            'employee_code' => $this->employee_code,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->fullName(),
            'gender' => $this->gender,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'phone' => $this->phone,
            'email' => $this->email,
            'national_id' => $this->national_id,
            'branch_id' => $this->branch_id,
            'department' => $named('department') ?? ($this->department_id ? ['id' => $this->department_id] : null),
            'designation' => $named('designation') ?? ($this->designation_id ? ['id' => $this->designation_id] : null),
            'employment_type' => $named('employmentType') ?? ($this->employment_type_id ? ['id' => $this->employment_type_id] : null),
            'status' => $this->relationLoaded('status') ? ['id' => $this->status->id, 'code' => $this->status->code, 'name' => $this->status->name, 'is_employed' => $this->status->is_employed] : ['id' => $this->employee_status_id],
            'reports_to_id' => $this->reports_to_id,
            'user_id' => $this->user_id,
            'joining_date' => $this->joining_date->toDateString(),
            'termination_date' => $this->termination_date?->toDateString(),
            'termination_reason' => $this->termination_reason,
            'personal' => $this->whenLoaded('personalInfo', fn () => $this->personalInfo?->only(EmployeePersonalInfo::FIELDS)),
            'branches' => $this->whenLoaded('branchAssignments', fn () => $this->branchAssignments->map(fn ($a): array => ['id' => $a->id, 'branch_id' => $a->branch_id, 'start_date' => $a->start_date->toDateString(), 'end_date' => $a->end_date?->toDateString(), 'is_primary' => $a->is_primary])->all()),
        ];
    }
}
