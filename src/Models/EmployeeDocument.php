<?php

namespace Nit\AgraniHr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Nit\AgraniHr\Models\Concerns\RecordsAuthors;
use Nit\Master\Models\File;

/**
 * An employee document (national ID, certificate, contract …). The file is a nit-master "files" row on the
 * HR documents disk, served only through an authorized download.
 *
 * @property int $id
 * @property int $employee_id
 * @property string $document_type
 * @property Carbon|null $expiry_date
 */
class EmployeeDocument extends Model
{
    use RecordsAuthors, SoftDeletes;

    protected $table = 'hr_employee_documents';

    protected $fillable = ['employee_id', 'document_type', 'title', 'document_number', 'issue_date', 'expiry_date', 'notes'];

    protected function casts(): array
    {
        return ['issue_date' => 'date', 'expiry_date' => 'date', 'employee_id' => 'integer'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function file(): MorphOne
    {
        return $this->morphOne(File::class, 'fileable');
    }

    public function isExpired(): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->isPast();
    }
}
