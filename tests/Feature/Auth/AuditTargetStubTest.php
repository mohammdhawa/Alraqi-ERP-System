<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Resources\AuditLogDetailResource;
use App\Modules\Auth\Resources\AuditTargetStub;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The audit reader's type vocabulary, held against the enforced morph map.
 *
 * ──────────────────────────────────────────────────────────────────────────
 * WHY THIS TEST EXISTS
 * ──────────────────────────────────────────────────────────────────────────
 * `Relation::enforceMorphMap()` is the single source of what `auditable_type`
 * holds, and MorphMapTest already forces every audited model into it — so the
 * moment such a model ships, rows start carrying its alias whether or not the
 * audit reader has heard of it. Two hand-maintained tables in the Auth module
 * are keyed on that vocabulary, and each one fails differently when it drifts:
 *
 *   TYPE_LABELS           → the row lists with `auditable_label: null`.
 *                           Survivable; the client renders the raw key.
 *   TYPE_VIEW_PERMISSIONS → the values block fails CLOSED for everyone short
 *                           of a super admin. Also survivable, and silent.
 *
 * Both are degradations a user would have to report before anyone noticed, on
 * a screen people open precisely when they do not trust what they are being
 * shown. Adding the model is one line; finding out this way is not.
 *
 * The FILTER allow-list is deliberately NOT asserted here, because it is no
 * longer a copy — AuditTargetStub::typeKeys() reads the morph map itself. It
 * used to read TYPE_LABELS, which turned a legitimate stored value into a 422
 * and made the newest rows in the table the ones the drill-down could not
 * reach. That is the failure mode this file is here to keep closed.
 */
class AuditTargetStubTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_filter_allow_list_is_the_morph_map_itself(): void
    {
        $this->assertSame(array_keys(Relation::morphMap()), AuditTargetStub::typeKeys());
    }

    public function test_every_morph_alias_has_an_arabic_label(): void
    {
        $missing = array_diff(array_keys(Relation::morphMap()), array_keys(AuditTargetStub::TYPE_LABELS));

        $this->assertSame(
            [],
            array_values($missing),
            "these morph aliases have no Arabic label, so audit rows about them list unlabelled:\n  "
                .implode("\n  ", $missing)
                ."\nAdd them to AuditTargetStub::TYPE_LABELS.",
        );
    }

    public function test_every_morph_alias_has_a_view_permission(): void
    {
        $missing = array_diff(
            array_keys(Relation::morphMap()),
            array_keys(AuditLogDetailResource::TYPE_VIEW_PERMISSIONS),
        );

        $this->assertSame(
            [],
            array_values($missing),
            "these morph aliases gate nothing, so their audit values are refused to every non-super-admin:\n  "
                .implode("\n  ", $missing)
                ."\nAdd them to AuditLogDetailResource::TYPE_VIEW_PERMISSIONS.",
        );
    }

    /**
     * A gate naming a permission nobody can hold is a gate nobody passes, and it
     * would look exactly like a correctly-configured refusal.
     */
    public function test_every_gated_permission_exists_in_the_catalogue(): void
    {
        $this->seedRbac();

        $seeded = Permission::query()->pluck('name')->all();

        foreach (AuditLogDetailResource::TYPE_VIEW_PERMISSIONS as $alias => $permissions) {
            foreach ($permissions as $permission) {
                $this->assertContains(
                    $permission,
                    $seeded,
                    "AuditLogDetailResource gates '{$alias}' on '{$permission}', which is not a seeded permission.",
                );
            }
        }
    }

    /**
     * The map may only point at READ permissions. Gating a read on someone's
     * `.update` or `.delete` would mean handing history to the people allowed to
     * change records and withholding it from the people allowed to check them.
     */
    public function test_the_gate_uses_read_permissions_only(): void
    {
        foreach (AuditLogDetailResource::TYPE_VIEW_PERMISSIONS as $alias => $permissions) {
            foreach ($permissions as $permission) {
                // ⚙️ The assertion was `endsWith('.view')` until GAP-13 closed on
                // 2026-09-23 and added `hr.employees.viewSalary`, which is a read
                // permission whose action is not spelled `view`. Matching the
                // ACTION PREFIX keeps the rule the test was written for — no
                // `.create`, `.update` or `.delete` may appear here — while
                // admitting the field-level reads the catalogue actually has.
                $action = (string) strrchr($permission, '.');

                $this->assertStringStartsWith(
                    '.view',
                    $action,
                    "'{$alias}' is gated on '{$permission}', which is not a read permission.",
                );
            }
        }
    }
}
