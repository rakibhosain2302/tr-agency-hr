<?php

namespace Nit\AgraniHr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Nit\Master\Support\Branches;

/**
 * Hire (POST) or change (PUT) an employee, with optional personal information under "personal".
 */
class EmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $employee = $this->route('employee');
        $hiring = $employee === null;

        return [
            'employee_code' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9_.\/-]+$/', Rule::unique('hr_employees', 'employee_code')->ignore($employee?->id)],
            'branch_id' => Branches::rule(required: true),
            'department_id' => ['nullable', 'integer', Rule::exists('hr_departments', 'id')->whereNull('deleted_at')],
            'designation_id' => ['nullable', 'integer', Rule::exists('hr_designations', 'id')->whereNull('deleted_at')],
            'employment_type_id' => ['nullable', 'integer', Rule::exists('hr_employment_types', 'id')],
            'employee_status_id' => ['nullable', 'integer', Rule::exists('hr_employee_statuses', 'id')],
            'reports_to_id' => ['nullable', 'integer', Rule::exists('hr_employees', 'id')->whereNull('deleted_at')],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'gender' => ['nullable', 'string', 'max:10'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'phone' => ['nullable', 'string', 'max:25'],
            'email' => ['nullable', 'email', 'max:191'],
            'national_id' => ['nullable', 'string', 'max:30'],
            'joining_date' => ['required', 'date'],
            'user_id' => $hiring ? ['nullable', 'integer'] : ['prohibited'],
            'effective_date' => $hiring ? ['prohibited'] : ['nullable', 'date'],
            'personal' => ['nullable', 'array'],
            'personal.father_name' => ['nullable', 'string', 'max:150'],
            'personal.mother_name' => ['nullable', 'string', 'max:150'],
            'personal.spouse_name' => ['nullable', 'string', 'max:150'],
            'personal.marital_status' => ['nullable', 'string', 'max:20'],
            'personal.blood_group' => ['nullable', 'string', 'max:5'],
            'personal.religion' => ['nullable', 'string', 'max:50'],
            'personal.nationality' => ['nullable', 'string', 'max:100'],
            'personal.personal_email' => ['nullable', 'email', 'max:191'],
            'personal.personal_phone' => ['nullable', 'string', 'max:25'],
            'personal.present_address' => ['nullable', 'string', 'max:500'],
            'personal.permanent_address' => ['nullable', 'string', 'max:500'],
            'personal.emergency_contact_name' => ['nullable', 'string', 'max:150'],
            'personal.emergency_contact_relation' => ['nullable', 'string', 'max:50'],
            'personal.emergency_contact_phone' => ['nullable', 'string', 'max:25'],
        ];
    }

    /** @return array<string, mixed> */
    public function employeeData(): array
    {
        return collect($this->validated())->except(['personal', 'effective_date'])->all();
    }

    /** @return array<string, mixed>|null */
    public function personalData(): ?array
    {
        return $this->has('personal') ? (array) $this->validated('personal', []) : null;
    }
}
