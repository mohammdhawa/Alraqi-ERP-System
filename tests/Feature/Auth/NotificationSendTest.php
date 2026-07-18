<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Services\NotificationService;
use App\Modules\Departments\Enums\DepartmentLevel;
use App\Modules\Departments\Models\Department;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fan-out send layer coverage (erp-phase1-architecture.md §7.2).
 *
 * Exercises NotificationService::sendToUser / sendToDepartment / sendToRole:
 * snapshot expansion of departments (subtree-inclusive) and roles into
 * per-user rows, the uniform active-account eligibility rule, silent empty
 * audiences, and duplicate-free delivery. The read-side endpoints are covered
 * separately in NotificationTest.
 */
class NotificationSendTest extends TestCase
{
    use RefreshDatabase;

    private NotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(NotificationService::class);
    }

    /**
     * Root -> division -> section, per the hierarchy invariants.
     *
     * @return array{root: Department, division: Department, section: Department}
     */
    private function makeTree(): array
    {
        $root = Department::create([
            'name'  => 'الإدارة العامة',
            'level' => DepartmentLevel::GeneralAdministration->value,
        ]);
        $division = Department::create([
            'name'      => 'الإدارة الهندسية',
            'parent_id' => $root->id,
            'level'     => DepartmentLevel::Division->value,
        ]);
        $section = Department::create([
            'name'      => 'قسم تطوير البرمجيات',
            'parent_id' => $division->id,
            'level'     => DepartmentLevel::Section->value,
        ]);

        return ['root' => $root, 'division' => $division, 'section' => $section];
    }

    /**
     * An employee in a department, with a linked user account.
     *
     * @return array{employee: Employee, user: User}
     */
    private function makeMember(Department $department, string $name, bool $activeAccount = true): array
    {
        $employee = Employee::create(['name' => $name, 'department_id' => $department->id]);
        $user = User::factory()->create([
            'employee_id' => $employee->id,
            'is_active'   => $activeAccount,
        ]);

        return ['employee' => $employee, 'user' => $user];
    }

    public function test_send_to_user_creates_exactly_one_row(): void
    {
        $user = User::factory()->create();
        $reference = Employee::create(['name' => 'Subject Person']);

        $created = $this->service->sendToUser(
            $user,
            title: 'تمت إضافة موظف جديد',
            body: 'انضم موظف جديد إلى القسم.',
            type: 'employee_created',
            reference: $reference,
        );

        $this->assertSame(1, $created);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseHas('notifications', [
            'user_id'        => $user->id,
            'title'          => 'تمت إضافة موظف جديد',
            'type'           => 'employee_created',
            'reference_type' => Employee::class,
            'reference_id'   => $reference->id,
            'is_read'        => false,
        ]);
    }

    public function test_send_to_user_skips_an_inactive_account(): void
    {
        $inactive = User::factory()->inactive()->create();

        $created = $this->service->sendToUser($inactive, title: 'إشعار');

        $this->assertSame(0, $created);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_send_to_division_reaches_its_sections_members(): void
    {
        $tree = $this->makeTree();
        $rootMember     = $this->makeMember($tree['root'], 'Root Staff');
        $divisionMember = $this->makeMember($tree['division'], 'Division Staff');
        $sectionMember  = $this->makeMember($tree['section'], 'Section Staff');

        $created = $this->service->sendToDepartment($tree['division'], title: 'اجتماع الإدارة');

        // Subtree-inclusive: the division member AND the section member — and
        // nobody above the target unit.
        $this->assertSame(2, $created);
        $this->assertDatabaseHas('notifications', ['user_id' => $divisionMember['user']->id]);
        $this->assertDatabaseHas('notifications', ['user_id' => $sectionMember['user']->id]);
        $this->assertDatabaseMissing('notifications', ['user_id' => $rootMember['user']->id]);
    }

    public function test_send_to_department_excludes_ineligible_people(): void
    {
        $tree = $this->makeTree();

        // Eligible: active account, live employee, in the target unit.
        $eligible = $this->makeMember($tree['division'], 'Eligible');

        // No account at all.
        Employee::create(['name' => 'No Login', 'department_id' => $tree['division']->id]);

        // Account disabled.
        $this->makeMember($tree['division'], 'Disabled Account', activeAccount: false);

        // Employee soft-deleted (their account survives but must not receive).
        $trashed = $this->makeMember($tree['division'], 'Former Staff');
        $trashed['employee']->delete();

        $created = $this->service->sendToDepartment($tree['division'], title: 'إشعار');

        $this->assertSame(1, $created);
        $this->assertDatabaseHas('notifications', ['user_id' => $eligible['user']->id]);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_send_to_an_empty_or_trashed_department_is_a_silent_no_op(): void
    {
        $tree = $this->makeTree();

        // A unit with no staff at all: zero rows, no exception.
        $this->assertSame(0, $this->service->sendToDepartment($tree['section'], title: 'إشعار'));

        // A soft-deleted unit reads as missing: zero rows, no exception.
        $tree['section']->delete();
        $this->assertSame(0, $this->service->sendToDepartment($tree['section']->id, title: 'إشعار'));

        // An id that never existed: zero rows, no exception.
        $this->assertSame(0, $this->service->sendToDepartment(999999, title: 'إشعار'));

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_send_to_role_reaches_only_active_holders(): void
    {
        $role = Role::create(['name' => 'announcers']);
        $holder = User::factory()->create();
        $holder->roles()->attach($role->id);

        $inactiveHolder = User::factory()->inactive()->create();
        $inactiveHolder->roles()->attach($role->id);

        $bystander = User::factory()->create();

        $created = $this->service->sendToRole($role, title: 'تعميم');

        $this->assertSame(1, $created);
        $this->assertDatabaseHas('notifications', ['user_id' => $holder->id]);
        $this->assertDatabaseMissing('notifications', ['user_id' => $inactiveHolder->id]);
        $this->assertDatabaseMissing('notifications', ['user_id' => $bystander->id]);
    }

    public function test_sent_rows_flow_through_the_existing_read_endpoints(): void
    {
        $tree = $this->makeTree();
        $member = $this->makeMember($tree['division'], 'Reader');

        $this->service->sendToDepartment($tree['division'], title: 'إشعار جديد', type: 'announcement');

        \Laravel\Sanctum\Sanctum::actingAs($member['user']);

        $this->getJson('/api/auth/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1);

        $this->getJson('/api/auth/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'إشعار جديد')
            ->assertJsonPath('data.0.is_read', false);
    }
}
