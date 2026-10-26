<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Support\PermissionCache;
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
     * 28 route-level permissions + 3 field-level (C11).
     *
     * Batch 2 added ten: the job-title catalogue (4), posting history (2 — view
     * and create ONLY, because سجل التعيين is append-only), and warnings (4).
     *
     * سجل التدقيق added two: `auth.audit.view` guards both audit routes, and
     * `auth.audit.viewValues` is field-level — it gates the before/after blobs
     * inside the detail payload and no route carries it. There is deliberately
     * no create/update/delete: the table is append-only, so there is no such
     * power to grant.
     *
     * GAP-15 added one: `auth.roles.assign`, split out of `auth.roles.update`
     * so that assigning (bounded by the assigner's reach) and editing a role's
     * definition (company-wide by nature) are two grants, not one.
     *
     * Attendance added three (2026-10-16): `attendance.records.view`,
     * `.correct` and `.reject`.
     *
     * The approvals engine added one (2026-10-16): `approvals.view`; its
     * endpoints a second (2026-10-18): `approvals.reassign`.
     *
     * Leave added nine (2026-10-18): `leave.types.create`, `.update`,
     * `.delete`, `leave.balances.view`, `.update`, `leave.requests.view`,
     * `.returnToWork`, and `leave.excuses.create`, `.delete`.
     */
    private const TOTAL = 46;

    public function test_it_seeds_exactly_the_expected_catalogue(): void
    {
        $this->seed(PermissionSeeder::class);

        $this->assertSame(self::TOTAL, Permission::count());

        $expected = [
            'approvals.reassign', 'approvals.view',
            'attendance.records.correct', 'attendance.records.reject', 'attendance.records.view',
            'auth.audit.view', 'auth.audit.viewValues',
            'auth.roles.assign', 'auth.roles.create', 'auth.roles.delete', 'auth.roles.update', 'auth.roles.view',
            'auth.users.create', 'auth.users.delete', 'auth.users.update', 'auth.users.view',
            'departments.create', 'departments.delete', 'departments.update', 'departments.view',
            'hr.assignments.create', 'hr.assignments.view',
            'hr.employees.create', 'hr.employees.delete', 'hr.employees.import', 'hr.employees.update', 'hr.employees.view',
            'hr.employees.viewIdentity', 'hr.employees.viewSalary',
            'hr.job_titles.create', 'hr.job_titles.delete', 'hr.job_titles.update', 'hr.job_titles.view',
            'hr.warnings.create', 'hr.warnings.delete', 'hr.warnings.update', 'hr.warnings.view',
            'leave.balances.update', 'leave.balances.view', 'leave.excuses.create', 'leave.excuses.delete', 'leave.requests.returnToWork', 'leave.requests.view',
            'leave.types.create', 'leave.types.delete', 'leave.types.update',
        ];

        $this->assertSame($expected, Permission::orderBy('name')->pluck('name')->all());
    }

    public function test_running_it_twice_changes_nothing(): void
    {
        $this->seed(PermissionSeeder::class);
        $this->seed(PermissionSeeder::class);

        $this->assertSame(self::TOTAL, Permission::count());
    }

    /**
     * Seeding the catalogue must invalidate every cached permission set.
     *
     * A super admin's cached entry IS the catalogue — the Gate bypass expands to
     * whatever is seeded — so without the flush, adding a permission leaves
     * already-signed-in super admins without it, and without the UI it gates,
     * for up to the full hour of TTL. Nothing else would announce that.
     */
    public function test_seeding_invalidates_cached_permission_sets(): void
    {
        $before = PermissionCache::version();

        $this->seed(PermissionSeeder::class);

        $this->assertGreaterThan($before, PermissionCache::version());
    }

    /**
     * AND SO DOES EVERY OTHER DOOR, because the flush is on the MODEL.
     *
     * It used to live in the seeder, which meant the invariant held for exactly
     * one caller: a permission created or renamed from the admin UI these Arabic
     * labels are seeded for, from a data migration, or from tinker still left
     * every signed-in super admin's cached entry — which IS the whole catalogue
     * — stale for up to the full hour of TTL. Same failure, different door.
     */
    public function test_any_write_to_a_permission_invalidates_cached_sets(): void
    {
        $this->seed(PermissionSeeder::class);

        $created = PermissionCache::version();
        $extra   = Permission::create(['name' => 'hr.trials.view', 'module' => 'hr']);
        $this->assertGreaterThan($created, PermissionCache::version(), 'creating a permission must flush');

        $renamed = PermissionCache::version();
        $extra->update(['name' => 'hr.trials.read']);
        $this->assertGreaterThan($renamed, PermissionCache::version(), 'renaming a permission must flush');

        $deleted = PermissionCache::version();
        $extra->delete();
        $this->assertGreaterThan($deleted, PermissionCache::version(), 'deleting a permission must flush');
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
