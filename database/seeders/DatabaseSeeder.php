<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * The seeding order, and the line between what is real and what is scaffolding.
 *
 *   PermissionSeeder / RoleSeeder   RBAC first: roles and permissions must
 *                                   exist before anything can be granted.
 *   DepartmentSeeder                the actual Al-Raqi org tree, 118 rows read
 *                                   from the HR workbook and the org chart.
 *   DevDepartmentSeeder             invented units, invented staff, and login
 *                                   accounts — skipped in production.
 *
 * WHY THE SPLIT: this seeder used to build the org tree itself, out of three
 * divisions and sections named قسم تطوير البرمجيات and قسم ضمان الجودة. None of
 * them exist — Al-Raqi is a construction company. That was harmless while the
 * tree was a demo fixture, and stops being harmless the moment 242 real
 * employees are imported against `departments.code`: a developer cannot tell a
 * fabricated unit from a real one by looking, and neither can an import script.
 *
 * WHY THE ENVIRONMENT GATE rather than dropping the dev fixtures entirely:
 * `migrate:fresh --seed` still has to leave a working login behind, or every
 * developer invents their own throwaway user and the shape of the fixtures
 * drifts per machine. Gating on production keeps that convenience without ever
 * letting an invented row reach a real database.
 *
 * WHY MODEL EVENTS ARE LEFT ON (no WithoutModelEvents): the hierarchy
 * invariants live in the Department model's saving hook, so suppressing events
 * would let the seeders write unguarded — defeating the point. With events on,
 * every seeded row passes the SAME guard the API enforces. The audit rows this
 * produces carry user_id = null, which is what that column's nullability is
 * for: console actions, seeders, and jobs have no auth user.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
            DepartmentSeeder::class,
        ]);

        if (! app()->isProduction()) {
            $this->call(DevDepartmentSeeder::class);
        }
    }
}
