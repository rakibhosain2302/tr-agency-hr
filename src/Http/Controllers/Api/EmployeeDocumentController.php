<?php

namespace Nit\AgraniHr\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Nit\AgraniHr\Models\Employee;
use Nit\AgraniHr\Models\EmployeeDocument;
use Nit\AgraniHr\Services\EmployeeDocumentService;
use Nit\Master\Attributes\Permission;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documents of an employee the user can see; the files are private and served only here.
 */
class EmployeeDocumentController extends Controller
{
    public function __construct(private EmployeeDocumentService $documents) {}

    #[Permission('hr_employee_document_view', 'View and download employee documents')]
    public function index(Employee $employee): JsonResponse
    {
        return response()->json(['data' => $employee->documents()->with('file')->get()->map(fn (EmployeeDocument $d): array => $this->present($d))->all()]);
    }

    #[Permission('hr_employee_document_manage', 'Add and delete employee documents')]
    public function store(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'document_type' => ['required', 'string', 'regex:/^[a-z0-9_]{1,40}$/'],
            'title' => ['nullable', 'string', 'max:191'],
            'document_number' => ['nullable', 'string', 'max:100'],
            'issue_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'notes' => ['nullable', 'string', 'max:500'],
            'file' => ['nullable', 'file', 'max:'.(int) config('hr.documents.max_kilobytes', 10240), 'mimes:'.implode(',', (array) config('hr.documents.mimes', []))],
        ]);

        $document = $this->documents->store($employee, collect($data)->except('file')->all(), $request->file('file'));

        return response()->json(['data' => $this->present($document)], 201);
    }

    #[Permission('hr_employee_document_view', 'View and download employee documents')]
    public function download(Employee $employee, int $document): StreamedResponse
    {
        return $this->documents->download($employee->documents()->with('file')->findOrFail($document));
    }

    #[Permission('hr_employee_document_manage', 'Add and delete employee documents')]
    public function destroy(Employee $employee, int $document): Response
    {
        $this->documents->delete($employee->documents()->with('file')->findOrFail($document));

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function present(EmployeeDocument $document): array
    {
        return [
            'id' => $document->id,
            'document_type' => $document->document_type,
            'title' => $document->title,
            'document_number' => $document->document_number,
            'issue_date' => $document->issue_date?->toDateString(),
            'expiry_date' => $document->expiry_date?->toDateString(),
            'expired' => $document->isExpired(),
            'notes' => $document->notes,
            'has_file' => $document->relationLoaded('file') && $document->file !== null,
            'file_name' => $document->file?->original_name,
        ];
    }
}
