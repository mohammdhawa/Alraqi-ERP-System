<?php

declare(strict_types=1);

namespace App\Modules\Documents\Controllers;

use App\Modules\Departments\Enums\DepartmentLevel;
use App\Modules\Departments\Models\Department;
use App\Modules\Documents\Exceptions\NotARegisterException;
use App\Modules\Documents\Exceptions\NotAUnitManagerException;
use App\Modules\Documents\Exceptions\RegisterForbiddenException;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Requests\ExportRegisterIndexRequest;
use App\Modules\Documents\Resources\ExportRegisterRowResource;
use App\Modules\Documents\Services\ExportNumbers;
use App\Modules\Documents\Services\UnitManagers;
use App\Modules\HR\Models\Employee;
use App\Modules\Me\Services\SelfEmployee;
use App\Shared\Traits\ApiRespond;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The صادر register (documents design §8.4, §16).
 */
class ExportRegisterController extends Controller
{
    use ApiRespond;

    /**
     * GET /api/documents/numbers/next — the next number of the register of the
     * caller's unit, or null while the register is cold (the first must then
     * be typed).
     */
    public function next(Request $request, SelfEmployee $self, UnitManagers $managers, ExportNumbers $numbers): JsonResponse
    {
        $unit = $managers->unitOf($self->of($request->user())) ?? throw new NotAUnitManagerException;
        $register = $managers->registerOf($unit);
        $next = $numbers->next($register);

        return $this->success([
            'register'       => ['id' => $register->id, 'name' => $register->name],
            'next'           => $next,
            'first_required' => $next === null,
        ]);
    }

    /**
     * GET /api/documents/registers/{department} — every صادر number of one
     * register, newest first, deleted documents included and marked. The
     * caller is checked first, so a unit's level is told only to someone who
     * may read registers.
     */
    public function index(ExportRegisterIndexRequest $request, Department $department, UnitManagers $managers): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasPermission('documents.view')) {
            $employee = $user->employee_id === null ? null : Employee::query()->find($user->employee_id);
            $own = $employee === null ? null : $managers->unitOf($employee);

            $own?->is($department) || throw new RegisterForbiddenException;
        }

        if (! in_array($department->level, [DepartmentLevel::Division, DepartmentLevel::GeneralAdministration], true)) {
            throw new NotARegisterException;
        }

        $documents = Document::withTrashed()
            ->where('documents.register_unit_id', $department->id)
            ->whereNotNull('documents.export_number');

        if (($search = $request->search()) !== null) {
            $documents->matching($search);
        }

        $page = $documents
            ->orderByDesc('documents.export_number')
            ->with('creator')
            ->paginate($this->perPage($request));

        return $this->paginated(ExportRegisterRowResource::collection($page));
    }
}
