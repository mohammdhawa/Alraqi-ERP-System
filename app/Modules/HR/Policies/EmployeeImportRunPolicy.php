<?php

declare(strict_types=1);

namespace App\Modules\HR\Policies;

use App\Modules\Auth\Models\User;
use App\Shared\Services\AccessScope;
use App\Shared\Support\OutOfScope;
use Illuminate\Auth\Access\Response;

/**
 * Who may run the upload import, beyond `hr.employees.import` itself
 * (EMPLOYEE_IMPORT_UPLOAD_DESIGN.md §4.3).
 *
 * The import writes every employee's pay fields and national ID, and its
 * report shows them old → new. Decision 13 (من لا يرى لا يكتُب) says whoever
 * writes a field must be able to read it — so the importer must hold
 * `viewSalary` and `viewIdentity` too, and over the WHOLE company, because the
 * import reaches every unit. A grant scoped to one division reads that
 * division's pay; it may not overwrite everyone's.
 *
 * `hr.employees.import` is company-only by PermissionReach already (it is not
 * on the scoped allow-list); the two field permissions are row-scoped, which is
 * why their reach is checked here rather than by name. A super admin passes
 * through Gate::before.
 *
 * The refusal is an OutOfScope one — the grant's reach is short of the whole
 * company — so its message reaches the client as written.
 */
class EmployeeImportRunPolicy
{
    public function __construct(
        private readonly AccessScope $scopes,
    ) {}

    public function import(User $user): Response
    {
        return $this->scopes->reachesEverything($user, 'hr.employees.viewSalary')
            && $this->scopes->reachesEverything($user, 'hr.employees.viewIdentity')
            ? Response::allow()
            : OutOfScope::deny('يتطلب الاستيراد صلاحيتَي عرض الرواتب وأرقام الهوية على مستوى الشركة.');
    }
}
