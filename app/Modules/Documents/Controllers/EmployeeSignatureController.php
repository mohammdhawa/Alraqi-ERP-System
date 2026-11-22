<?php

declare(strict_types=1);

namespace App\Modules\Documents\Controllers;

use App\Modules\Documents\Models\EmployeeSignature;
use App\Modules\Documents\Requests\DeleteSignatureRequest;
use App\Modules\Documents\Services\Signatures;
use App\Modules\HR\Models\Employee;
use App\Shared\Traits\ApiRespond;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * The system administrator's view of an employee's signature (documents
 * design §10.1, D15): its versions and dates, never the image, and the
 * deletion. Both behind `can:documents.signatures.delete`, which only the
 * super-admin bypass passes.
 */
class EmployeeSignatureController extends Controller
{
    use ApiRespond;

    public function __construct(private readonly Signatures $signatures) {}

    /**
     * GET /api/hr/employees/{employee}/signature
     */
    public function show(Employee $employee): JsonResponse
    {
        $versions = EmployeeSignature::withTrashed()
            ->where('employee_id', $employee->getKey())
            ->orderByDesc('id')
            ->get();

        $current = $versions->first(static fn (EmployeeSignature $version): bool => ! $version->trashed());

        return $this->success([
            'current' => $current === null ? null : [
                'drawn_at' => $current->created_at?->toIso8601String(),
                'drawn_on' => $current->drawn_on->value,
            ],
            'deleted' => $versions
                ->filter(static fn (EmployeeSignature $version): bool => $version->trashed())
                ->map(static fn (EmployeeSignature $version): array => [
                    'drawn_at'      => $version->created_at?->toIso8601String(),
                    'drawn_on'      => $version->drawn_on->value,
                    'deleted_at'    => $version->deleted_at?->toIso8601String(),
                    'delete_reason' => $version->delete_reason,
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * DELETE /api/hr/employees/{employee}/signature
     */
    public function destroy(DeleteSignatureRequest $request, Employee $employee): JsonResponse
    {
        $this->signatures->delete($employee, $request->user(), (string) $request->validated('reason'));

        return $this->success(message: 'تم حذف التوقيع.');
    }
}
