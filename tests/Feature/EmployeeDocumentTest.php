<?php

namespace Nit\AgraniHr\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Nit\AgraniHr\Tests\TestCase;
use Nit\Master\Models\File;

class EmployeeDocumentTest extends TestCase
{
    public function test_documents_are_stored_privately_listed_downloaded_and_deleted(): void
    {
        Storage::fake('local');
        $dhaka = $this->branch('Dhaka');
        $employee = $this->hire($dhaka);
        $headers = $this->bearer($this->inBranches($this->userWith(['EmployeeDocument::*']), $dhaka));

        $id = $this->post("/api/nit/hr/employees/{$employee->uuid}/documents", [
            'document_type' => 'passport',
            'title' => 'Passport',
            'document_number' => 'A1234567',
            'issue_date' => '2020-01-01',
            'expiry_date' => '2025-01-01',
            'file' => UploadedFile::fake()->create('passport.pdf', 120, 'application/pdf'),
        ], $headers)->assertCreated()
            ->assertJsonPath('data.expired', true)
            ->assertJsonPath('data.has_file', true)
            ->assertJsonPath('data.file_name', 'passport.pdf')
            ->json('data.id');

        $file = File::query()->sole();
        $this->assertSame('hr_document.passport', $file->type);
        Storage::disk('local')->assertExists($file->path);
        $this->assertStringStartsWith("hr-documents/{$employee->id}/", $file->path);

        $this->getJson("/api/nit/hr/employees/{$employee->uuid}/documents", $headers)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.document_number', 'A1234567');
        $this->get("/api/nit/hr/employees/{$employee->uuid}/documents/{$id}/download", $headers)->assertOk()->assertDownload('passport.pdf');

        $this->deleteJson("/api/nit/hr/employees/{$employee->uuid}/documents/{$id}", [], $headers)->assertNoContent();
        Storage::disk('local')->assertMissing($file->path);
        $this->assertSame(0, File::query()->count());
        $this->assertSoftDeleted('hr_employee_documents', ['id' => $id]);
    }

    public function test_uploads_are_validated(): void
    {
        Storage::fake('local');
        $dhaka = $this->branch('Dhaka');
        $employee = $this->hire($dhaka);
        $headers = $this->bearer($this->inBranches($this->userWith(['EmployeeDocument::*']), $dhaka));

        $this->post("/api/nit/hr/employees/{$employee->uuid}/documents", [
            'document_type' => 'Bad Type',
            'issue_date' => '2026-01-01',
            'expiry_date' => '2025-01-01',
            'file' => UploadedFile::fake()->create('run.exe', 10, 'application/x-msdownload'),
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors(['document_type', 'expiry_date', 'file']);

        config(['hr.documents.max_kilobytes' => 50]);
        $this->post("/api/nit/hr/employees/{$employee->uuid}/documents", ['document_type' => 'cv', 'file' => UploadedFile::fake()->create('cv.pdf', 60, 'application/pdf')], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors(['file']);

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_document_without_a_file_has_nothing_to_download_and_other_branches_see_nothing(): void
    {
        Storage::fake('local');
        $dhaka = $this->branch('Dhaka');
        $ctg = $this->branch('Chattogram', 'CTG');
        $employee = $this->hire($dhaka);
        $headers = $this->bearer($this->inBranches($this->userWith(['EmployeeDocument::*']), $dhaka));

        $id = $this->postJson("/api/nit/hr/employees/{$employee->uuid}/documents", ['document_type' => 'nid', 'document_number' => '1990123'], $headers)
            ->assertCreated()->assertJsonPath('data.has_file', false)->json('data.id');
        $this->getJson("/api/nit/hr/employees/{$employee->uuid}/documents/{$id}/download", $headers)->assertUnprocessable()->assertJsonPath('reason', 'no_file');

        $elsewhere = $this->bearer($this->inBranches($this->userWith(['EmployeeDocument::*']), $ctg));
        $this->getJson("/api/nit/hr/employees/{$employee->uuid}/documents", $elsewhere)->assertNotFound();
        $this->getJson("/api/nit/hr/employees/{$employee->uuid}/documents/{$id}/download", $elsewhere)->assertNotFound();
    }
}
