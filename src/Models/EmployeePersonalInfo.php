<?php

namespace Nit\AgraniHr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nit\AgraniHr\Models\Concerns\RecordsAuthors;

/**
 * An employee's personal information (1:1): family, contact, addresses and emergency contact.
 */
class EmployeePersonalInfo extends Model
{
    use RecordsAuthors;

    public const FIELDS = [
        'father_name', 'mother_name', 'spouse_name', 'marital_status', 'blood_group', 'religion', 'nationality',
        'personal_email', 'personal_phone', 'present_address', 'permanent_address',
        'emergency_contact_name', 'emergency_contact_relation', 'emergency_contact_phone',
    ];

    protected $table = 'hr_employee_personal_infos';

    protected $fillable = self::FIELDS;

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
