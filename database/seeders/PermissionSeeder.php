<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Auth\Models\Permission;
use Illuminate\Database\Seeder;

/**
 * PermissionSeeder
 *
 * Registers the Phase 1 permission catalogue. Each `name` is exactly the
 * string a route passes to the `permission:` middleware, so these rows are
 * what make CheckPermission able to authorize requests.
 *
 * NAMING NOTE: Departments became its own module (prefix /api/departments), so
 * its permissions are `departments.*` — matching that module's route
 * middleware — rather than the `hr.departments.*` the original spec assumed
 * while departments still lived under HR. Employees remain `hr.employees.*`.
 *
 * Idempotent: re-running updates module/description without duplicating rows.
 */
class PermissionSeeder extends Seeder
{
    /**
     * @var array<string, array<int, string>> module => actions
     */
    private const RESOURCES = [
        // Departments module
        'departments' => ['departments.view', 'departments.create', 'departments.update', 'departments.delete'],
        // HR module
        'hr'   => ['hr.employees.view', 'hr.employees.create', 'hr.employees.update', 'hr.employees.delete'],
        // Auth module
        'auth' => [
            'auth.users.view', 'auth.users.create', 'auth.users.update', 'auth.users.delete',
            'auth.roles.view', 'auth.roles.create', 'auth.roles.update', 'auth.roles.delete',
        ],
    ];

    /**
     * Arabic display labels for each permission — the human-facing name an admin
     * UI shows (the `name` stays the machine identifier the middleware matches).
     *
     * @var array<string, string>
     */
    private const LABELS = [
        'departments.view'    => 'عرض الوحدات التنظيمية',
        'departments.create'  => 'إنشاء وحدة تنظيمية',
        'departments.update'  => 'تعديل وحدة تنظيمية',
        'departments.delete'  => 'حذف وحدة تنظيمية',
        'hr.employees.view'   => 'عرض الموظفين',
        'hr.employees.create' => 'إضافة موظف',
        'hr.employees.update' => 'تعديل موظف',
        'hr.employees.delete' => 'حذف موظف',
        'auth.users.view'     => 'عرض المستخدمين',
        'auth.users.create'   => 'إنشاء مستخدم',
        'auth.users.update'   => 'تعديل مستخدم',
        'auth.users.delete'   => 'حذف مستخدم',
        'auth.roles.view'     => 'عرض الأدوار',
        'auth.roles.create'   => 'إنشاء دور',
        'auth.roles.update'   => 'تعديل دور',
        'auth.roles.delete'   => 'حذف دور',
    ];

    public function run(): void
    {
        foreach (self::RESOURCES as $module => $permissions) {
            foreach ($permissions as $name) {
                Permission::updateOrCreate(
                    ['name' => $name],
                    [
                        'label'       => self::LABELS[$name] ?? $name,
                        'module'      => $module,
                        'description' => $this->describe($name),
                    ],
                );
            }
        }
    }

    /**
     * Build a human-readable description from a permission name.
     * "hr.employees.view" -> "View hr employees"
     */
    private function describe(string $name): string
    {
        $parts  = explode('.', $name);
        $action = array_pop($parts);

        return ucfirst($action) . ' ' . implode(' ', $parts);
    }
}
