<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Modules\Auth\Models\Permission;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The permission catalogue is reference data, so it is asserted EXACTLY (§10) —
 * a silent change to it fails the build, the way DepartmentSeederTest guards the
 * org tree.
 *
 * This matters more here than anywhere else in the system: the permission name
 * is the string the route middleware passes AND the string seeded into the
 * table, with no mapping layer between them. A typo in either place is a
 * silently unguarded route or a permanently denied one, and neither announces
 * itself.
 */
class PermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 26 route-level permissions + 2 field-level (C11).
     *
     * Batch 2 added ten: the job-title catalogue (4), posting history (2 — view
     * and create ONLY, because سجل التعيين is append-only), and warnings (4).
     */
    private const TOTAL = 28;

    public function test_it_seeds_exactly_the_expected_catalogue(): void
    {
        $this->seed(PermissionSeeder::class);

        $this->assertSame(self::TOTAL, Permission::count());

        $expected = [
            'auth.roles.create', 'auth.roles.delete', 'auth.roles.update', 'auth.roles.view',
            'auth.users.create', 'auth.users.delete', 'auth.users.update', 'auth.users.view',
            'departments.create', 'departments.delete', 'departments.update', 'departments.view',
            'hr.assignments.create', 'hr.assignments.view',
            'hr.employees.create', 'hr.employees.delete', 'hr.employees.update', 'hr.employees.view',
            'hr.employees.viewIdentity', 'hr.employees.viewSalary',
            'hr.job_titles.create', 'hr.job_titles.delete', 'hr.job_titles.update', 'hr.job_titles.view',
            'hr.warnings.create', 'hr.warnings.delete', 'hr.warnings.update', 'hr.warnings.view',
        ];

        $this->assertSame($expected, Permission::orderBy('name')->pluck('name')->all());
    }

    public function test_running_it_twice_changes_nothing(): void
    {
        $this->seed(PermissionSeeder::class);
        $this->seed(PermissionSeeder::class);

        $this->assertSame(self::TOTAL, Permission::count());
    }

    public function test_every_permission_the_routes_reference_is_seeded(): void
    {
        $this->seed(PermissionSeeder::class);

        $seeded = Permission::pluck('name')->all();

        // Read the permission strings straight off the registered middleware, so
        // a route guarded by a permission nobody seeded — which would deny every
        // caller forever, including a legitimate admin — fails here rather than
        // in production.
        $referenced = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware)) {
                    continue;
                }

                // Routes declare the alias (`permission:departments.view`);
                // accept the resolved class form too, so this keeps working if
                // the alias is ever bypassed.
                if (str_starts_with($middleware, 'permission:') || str_contains($middleware, 'CheckPermission:')) {
                    $referenced[] = explode(':', $middleware, 2)[1];
                }
            }
        }

        $this->assertNotEmpty($referenced, 'no permission-guarded routes were found');

        foreach (array_unique($referenced) as $permission) {
            $this->assertContains(
                $permission,
                $seeded,
                "route middleware references '{$permission}', which PermissionSeeder does not create",
            );
        }
    }
}
