<?php

declare(strict_types=1);

namespace App\Modules\Documents\Controllers;

use App\Modules\Departments\Models\Department;
use App\Modules\Documents\Exceptions\NoStampException;
use App\Modules\Documents\Models\UnitStamp;
use App\Modules\Documents\Requests\UnitStampRequest;
use App\Modules\Documents\Services\UnitStamps;
use App\Shared\Traits\ApiRespond;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The units' stamps page of the dashboard (documents design §10.2, §15.2):
 * every unit and whether it has a stamp, the upload, and the image. All
 * behind `can:documents.stamps.manage`: the system administrator alone
 * (D23.1).
 */
class UnitStampController extends Controller
{
    use ApiRespond;

    public function __construct(private readonly UnitStamps $stamps) {}

    /**
     * GET /api/documents/stamps — every non-deleted unit; `?missing=1` keeps
     * those without a stamp (D21.2: every stamp in before the release).
     */
    public function index(Request $request): JsonResponse
    {
        $current = UnitStamp::query()
            ->whereNull('replaced_at')
            ->get(['department_id', 'created_at'])
            ->keyBy('department_id');

        $units = $request->boolean('missing')
            ? $this->stamps->unitsWithout()
            : Department::query()->orderBy('level')->orderBy('id')->get();

        return $this->success($units->map(static fn (Department $unit): array => [
            'id'                => $unit->id,
            'name'              => $unit->name,
            'level'             => $unit->level->value,
            'parent_id'         => $unit->parent_id,
            'has_stamp'         => $current->has($unit->id),
            'stamp_uploaded_at' => $current->get($unit->id)?->created_at?->toIso8601String(),
        ])->values()->all());
    }

    /**
     * POST /api/documents/stamps/{department}
     */
    public function store(UnitStampRequest $request, Department $department): JsonResponse
    {
        $stamp = $this->stamps->replace($department, $request->file('stamp'), $request->user());

        return $this->created(
            data: ['uploaded_at' => $stamp->created_at?->toIso8601String()],
            message: 'تم حفظ ختم الوحدة.',
        );
    }

    /**
     * GET /api/documents/stamps/{department}/image — the current version,
     * decrypted in memory, for the administrator to check what was uploaded.
     */
    public function image(Department $department): StreamedResponse
    {
        $stamp = $this->stamps->current($department) ?? throw new NoStampException;
        $bytes = $this->stamps->bytes($stamp);

        return response()->stream(static function () use ($bytes): void {
            echo $bytes;
        }, 200, [
            'Content-Type'  => $stamp->mime_type,
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
