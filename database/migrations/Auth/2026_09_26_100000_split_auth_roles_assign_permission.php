<?php

declare(strict_types=1);

use App\Modules\Auth\Support\PermissionCache;
use App\Modules\Auth\Support\PermissionReach;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * GAP-15 — `auth.roles.assign` is split out of `auth.roles.update`.
 *
 * The assign/unassign routes required `auth.roles.update` until now, so every
 * role that holds it is a role whose holders assign roles today. The routes
 * now require `auth.roles.assign`; without this migration each of those
 * holders would lose the ability on deploy, silently, the moment the code
 * went out. So every role carrying `auth.roles.update` is given
 * `auth.roles.assign` as well, and nobody loses the ability to ASSIGN on
 * deploy.
 *
 * ⚠️ THAT IS NOT "NOBODY'S REACH CHANGES". The same release filters every
 * grant narrower than the company through PermissionReach, so an existing unit
 * or managed grant stops conferring its company-only permissions
 * (`departments.update`, `auth.users.*`, `auth.audit.view`, …) the moment the
 * code is live. That shrinking is GAP-15's whole point. Every grant is
 * documented as company-wide today, so it should touch nobody — and `up()`
 * REPORTS any grant it does touch (to the log and to STDERR), so a deploy on a
 * database where that is not true says exactly what shrank.
 *
 * ⚠️ AND IT REFUSES TO RE-CREATE GAP-16. A role carrying `auth.roles.update`
 * granted over a unit would, after the copy, be a scoped `auth.roles.assign` —
 * exactly what GAP-16 says not to grant. Its holders could assign within reach
 * before the split too, so this is not new exposure; it is the migration
 * silently writing a grant the documentation forbids. So `up()` stops, before
 * writing anything, and names every such grant: a person narrows each to
 * company or revokes it, and migrates again. There is no "accept it" — the
 * server refuses that grant from now on too (AssignRoleRequest).
 *
 * Grants held by SOFT-DELETED accounts are left out of both the refusal and
 * the report. `UserService::delete()` keeps their `user_roles` rows, but they
 * confer nothing — and the API cannot revoke them (unassign requires a live
 * user), so refusing on one would block the deploy on a row nobody can clear.
 *
 * WHY A MIGRATION AND NOT THE SEEDER: the seeder owns the CATALOGUE — which
 * permissions exist — and never decides what a role carries. Adding a
 * permission to existing roles, once, because of a split, is a data change
 * with a date on it. The permission row is inserted here too, because this
 * runs before any seeder on a deploy; the seeder then finds it and leaves it.
 *
 * ⚠️ DEPLOY ORDER: RUN THIS BEFORE THE NEW CODE TAKES TRAFFIC. The new routes
 * require `auth.roles.assign`, which only this migration creates and hands
 * out — so code live before `migrate` means every role administrator short of
 * a super admin gets a 403 on assign/unassign until it runs. The other order
 * is harmless: the old code never reads the new permission or its rows.
 *
 * The cache is flushed at the end: role_permissions is written through the
 * query builder, which fires no model event, and every cached permission set
 * would otherwise be stale until its TTL (the invariant PermissionCache
 * documents for anything writing the pivots directly).
 */
return new class extends Migration
{
    private const ASSIGN = 'auth.roles.assign';

    private const UPDATE = 'auth.roles.update';

    /**
     * `ScopeType::Company`, as a literal — a migration must not change meaning
     * if the enum is ever renumbered (the same choice the GAP-3 migration made).
     */
    private const COMPANY = 1;

    public function up(): void
    {
        $this->refuseScopedGrantsOfRolesThatCanAssign();

        $assignId = DB::table('permissions')->where('name', self::ASSIGN)->value('id');

        if ($assignId === null) {
            $now = now();

            $assignId = DB::table('permissions')->insertGetId([
                'name'        => self::ASSIGN,
                'label'       => 'إسناد الأدوار للمستخدمين',
                'module'      => 'auth',
                'description' => 'Assign auth roles',
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }

        $updateId = DB::table('permissions')->where('name', self::UPDATE)->value('id');

        if ($updateId !== null) {
            $roleIds = DB::table('role_permissions')
                ->where('permission_id', $updateId)
                ->pluck('role_id');

            foreach ($roleIds as $roleId) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id'       => $roleId,
                    'permission_id' => $assignId,
                ]);
            }
        }

        $this->reportScopedGrantsThatShrink();

        PermissionCache::flush();
    }

    /**
     * Removes the permission and every role's hold on it. The assign routes of
     * a rolled-back release require `auth.roles.update` again.
     *
     * ⚠️ REFUSES WHILE A ROLE HOLDS `assign` WITHOUT `update`. Roles that
     * existed before `up()` kept `update` and lose nothing. A role created
     * after the deploy with `assign` alone would be left unable to assign at
     * all — and the obvious repair, handing it `update`, would give it role
     * EDITING, a company-level power it never had, silently, in the middle of a
     * rollback. Neither is this migration's call: it stops and names the roles,
     * so whoever is rolling back chooses.
     */
    public function down(): void
    {
        $assignId = DB::table('permissions')->where('name', self::ASSIGN)->value('id');

        if ($assignId !== null) {
            // Live roles only: a soft-deleted role grants nothing, and blocking a
            // rollback on one would ask a person to decide about a role nobody
            // can use — the same reason up() leaves deleted roles out.
            $stranded = DB::table('roles')
                ->whereNull('deleted_at')
                ->whereIn('id', DB::table('role_permissions')->where('permission_id', $assignId)->select('role_id'))
                ->whereNotIn('id', DB::table('role_permissions')
                    ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
                    ->where('permissions.name', self::UPDATE)
                    ->select('role_permissions.role_id'))
                ->orderBy('name')
                ->pluck('name')
                ->all();

            if ($stranded !== []) {
                throw new RuntimeException(sprintf(
                    'Cannot roll back the auth.roles.assign split: role(s) %s hold auth.roles.assign without auth.roles.update and would be left unable to assign. Grant them auth.roles.update (which also lets them edit role definitions), or remove auth.roles.assign from them to accept the loss, then roll back again.',
                    implode(', ', $stranded),
                ));
            }

            DB::table('role_permissions')->where('permission_id', $assignId)->delete();
            DB::table('permissions')->where('id', $assignId)->delete();
        }

        PermissionCache::flush();
    }

    /**
     * Stop, before writing anything, if a role that can edit roles today is
     * granted over anything narrower than the company. See the class docblock.
     */
    private function refuseScopedGrantsOfRolesThatCanAssign(): void
    {
        $scoped = DB::table('user_roles')
            ->join('users', 'users.id', '=', 'user_roles.user_id')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->join('role_permissions', 'role_permissions.role_id', '=', 'roles.id')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('permissions.name', self::UPDATE)
            ->where('user_roles.scope_type', '!=', self::COMPANY)
            ->whereNull('users.deleted_at')
            ->whereNull('roles.deleted_at')
            ->orderBy('user_roles.id')
            ->get(['user_roles.id', 'user_roles.user_id', 'roles.name', 'user_roles.scope_type', 'user_roles.scope_department_id'])
            ->map(static fn (object $g): string => sprintf(
                'grant #%d (user %d, role %s, scope %d%s)',
                $g->id,
                $g->user_id,
                $g->name,
                $g->scope_type,
                $g->scope_department_id === null ? '' : ", unit {$g->scope_department_id}",
            ))
            ->all();

        if ($scoped !== []) {
            throw new RuntimeException(sprintf(
                'Refusing to split auth.roles.assign out of auth.roles.update: these grants would become a scoped auth.roles.assign, which GAP-16 forbids — %s. Narrow each to company-wide or revoke it, then migrate again.',
                implode('; ', $scoped),
            ));
        }
    }

    /**
     * Say which existing scoped grants stop conferring something when this
     * release goes live. Not a refusal — the shrinking is GAP-15's point — but
     * nothing about it should be silent.
     */
    private function reportScopedGrantsThatShrink(): void
    {
        $grants = DB::table('user_roles')
            ->join('users', 'users.id', '=', 'user_roles.user_id')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('user_roles.scope_type', '!=', self::COMPANY)
            ->whereNull('users.deleted_at')
            ->whereNull('roles.deleted_at')
            ->orderBy('user_roles.id')
            ->get(['user_roles.id', 'user_roles.user_id', 'user_roles.role_id', 'roles.name']);

        foreach ($grants as $grant) {
            $permissions = DB::table('role_permissions')
                ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
                ->where('role_permissions.role_id', $grant->role_id)
                ->pluck('permissions.name')
                ->all();

            $lost = array_values(array_diff($permissions, PermissionReach::effectiveForScopedGrant($permissions)));

            if ($lost === []) {
                continue;
            }

            $line = sprintf(
                'GAP-15: scoped grant #%d (user %d, role %s) no longer confers: %s',
                $grant->id,
                $grant->user_id,
                $grant->name,
                implode(', ', $lost),
            );

            Log::warning($line);

            // To the terminal as well, so `php artisan migrate` itself shows it —
            // except under the test suite, where it would land in PHPUnit's
            // output; the log line above is what the tests assert on.
            if (defined('STDERR') && ! app()->runningUnitTests()) {
                fwrite(STDERR, $line.PHP_EOL);
            }
        }
    }
};
