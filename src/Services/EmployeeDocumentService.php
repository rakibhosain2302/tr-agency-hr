<?php

namespace Nit\AgraniHr\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Nit\AgraniHr\Exceptions\HrException;
use Nit\AgraniHr\Models\Employee;
use Nit\AgraniHr\Models\EmployeeDocument;
use Nit\Master\Models\File;
use Nit\Master\Support\Activity;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Employee documents: the details in hr_employee_documents, the file in nit-master's "files" table on the HR
 * documents disk (private). nit-master's own Fileable needs a public disk, so it is not used for these.
 */
final class EmployeeDocumentService
{
    /**
     * @param  array{document_type: string, title?: ?string, document_number?: ?string, issue_date?: ?string, expiry_date?: ?string, notes?: ?string}  $details
     */
    public function store(Employee $employee, array $details, ?UploadedFile $file = null): EmployeeDocument
    {
        if (! preg_match('/^[a-z0-9_]{1,40}$/', (string) ($details['document_type'] ?? ''))) {
            throw HrException::invalid(__('A document type is 1–40 lowercase letters, digits or underscores.'), 'invalid_document');
        }
        if (! empty($details['issue_date']) && ! empty($details['expiry_date']) && Carbon::parse($details['expiry_date'])->lt(Carbon::parse($details['issue_date']))) {
            throw HrException::invalid(__('A document cannot expire before it is issued.'), 'invalid_document');
        }

        $stored = null;
        try {
            return DB::transaction(function () use ($employee, $details, $file, &$stored): EmployeeDocument {
                $document = $employee->documents()->create(array_intersect_key($details, array_flip(['document_type', 'title', 'document_number', 'issue_date', 'expiry_date', 'notes'])));

                if ($file !== null) {
                    $extension = strtolower($file->getClientOriginalExtension() ?: (string) $file->extension());
                    $stored = $file->storeAs(trim((string) config('hr.documents.directory', 'hr-documents'), '/').'/'.$employee->id, Str::uuid().($extension !== '' ? '.'.$extension : ''), $this->disk());
                    $document->file()->create([
                        'path' => $stored,
                        'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
                        'file_title' => $details['title'] ?? null,
                        'type' => 'hr_document.'.$document->document_type,
                        'extension' => $extension,
                        'size' => (string) $file->getSize(),
                    ]);
                }

                $this->log('hr_employee_document.added', "Added {$document->document_type} document of {$employee->activityLabel()}", $employee, ['document_id' => $document->id]);

                return $document->load('file');
            });
        } catch (\Throwable $exception) {
            // The database rolled back: do not leave the file behind.
            if ($stored !== null) {
                Storage::disk($this->disk())->delete($stored);
            }
            throw $exception;
        }
    }

    public function delete(EmployeeDocument $document): void
    {
        $file = $document->file;

        DB::transaction(function () use ($document, $file): void {
            $file?->delete();
            $document->delete();
            $this->log('hr_employee_document.deleted', "Deleted {$document->document_type} document #{$document->id}", $document->employee()->withoutGlobalScopes()->first(), ['document_id' => $document->id]);
        });

        if ($file !== null) {
            Storage::disk($this->disk())->delete($file->path);
        }
    }

    public function download(EmployeeDocument $document): StreamedResponse
    {
        $file = $document->file ?? throw HrException::invalid(__('This document has no file.'), 'no_file');

        return Storage::disk($this->disk())->download($file->path, $file->original_name ?: basename($file->path));
    }

    private function disk(): string
    {
        return (string) config('hr.documents.disk', 'local');
    }

    /** @param  array<string, mixed>  $properties */
    private function log(string $event, string $description, ?Employee $employee, array $properties): void
    {
        if (config('hr.log_activity', true)) {
            Activity::log($event, $description, $employee, array_filter(['branch_id' => $employee?->branch_id]) + $properties);
        }
    }
}
