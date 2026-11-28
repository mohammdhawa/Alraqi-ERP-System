<?php

declare(strict_types=1);

namespace App\Modules\HR\Controllers;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeDocument;
use App\Modules\HR\Resources\EmployeeResource;
use App\Modules\HR\Resources\FiledDocumentResource;
use App\Shared\Support\OutOfScope;
use App\Shared\Support\RequestRowScopes;
use App\Shared\Traits\ApiRespond;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * FiledDocumentController — the files on an employee's record (documents to
 * employees, "The filed list").
 *
 * ENDPOINTS (module prefix /api/hr), both read-only:
 *   GET /api/hr/employees/{employee}/filed-documents
 *   GET /api/hr/employees/{employee}/filed-documents/{document}/file
 *
 * THE RECORD'S OWN RULES: `can:view,employee` on the route is the row scope.
 * On top of it the contract, the appointment and promotion decisions and the
 * Sham Cash photo read only for a caller whose `hr.employees.viewSalary` grant
 * reaches the employee — the rule `EmployeeResource` applies to the pay block.
 * The list leaves those rows out; their files answer 403, whatever the id
 * guessed. Another employee's row is a 404 (scoped binding).
 *
 * Every file is on the `local` disk (ruling R2), never public.
 */
class FiledDocumentController extends Controller
{
    use ApiRespond;

    /**
     * GET /api/hr/employees/{employee}/filed-documents — newest first.
     */
    public function index(Request $request, Employee $employee): JsonResponse
    {
        $rows = $employee->documents()
            ->with('document')
            ->when(! $this->readsSalary($request->user(), $employee), fn ($query) => $query->readableWithout())
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return $this->paginated(
            data: FiledDocumentResource::collection($rows),
            message: 'تم جلب وثائق الموظف.',
        );
    }

    /**
     * GET /api/hr/employees/{employee}/filed-documents/{document}/file — the
     * file, streamed inline and never cached.
     */
    public function file(Request $request, Employee $employee, EmployeeDocument $document): StreamedResponse
    {
        if ($document->type->readsThroughSalary() && ! $this->readsSalary($request->user(), $employee)) {
            OutOfScope::deny('لا تملك صلاحية الاطلاع على هذه الوثيقة.')->authorize();
        }

        $disk = Storage::disk('local');

        abort_if(! $disk->exists($document->file_path), 404);

        return $disk->response($document->file_path, headers: [
            'Content-Type'        => $document->mime_type,
            'Content-Disposition' => 'inline',
            'Cache-Control'       => 'private, no-store',
        ]);
    }

    /**
     * Whether the caller's viewSalary grant reaches this employee's unit.
     */
    private function readsSalary(?User $user, Employee $employee): bool
    {
        return $user !== null && RequestRowScopes::forUser($user, EmployeeResource::VIEW_SALARY)
            ->covers($employee->department_id === null ? null : (int) $employee->department_id);
    }
}
