<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Modules\Auth\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * The documents permissions (design §5). Two are grants a role may carry;
 * two rights are the system administrator's alone (D15, D23.1, §19 Q3) and
 * are therefore NOT permissions: a Gate ability no one passes but the
 * super-admin bypass, so no role editor can ever give them to HR.
 */
class DocumentsPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_two_document_permissions_are_seeded_with_their_labels(): void
    {
        $this->seedRbac();

        $this->assertSame('عرض جميع المستندات', Permission::where('name', 'documents.view')->value('label'));
        $this->assertSame('إدارة قوالب المستندات', Permission::where('name', 'documents.templates.manage')->value('label'));
    }

    public function test_system_administrator_abilities_pass_only_for_the_super_admin(): void
    {
        $this->actingAsAdmin();

        // Defined, not merely unknown: an unknown ability also passes for a
        // super admin and fails for everyone else, and would read as the same.
        $this->assertTrue(Gate::has('documents.stamps.manage'));
        $this->assertTrue(Gate::has('documents.signatures.delete'));
        $this->assertTrue(Gate::allows('documents.stamps.manage'));
        $this->assertTrue(Gate::allows('documents.signatures.delete'));
    }

    public function test_holding_every_permission_does_not_grant_them(): void
    {
        $this->seedRbac();
        $this->actingAsUserWithPermissions(Permission::pluck('name')->all());

        $this->assertFalse(Gate::allows('documents.stamps.manage'));
        $this->assertFalse(Gate::allows('documents.signatures.delete'));
    }

    public function test_the_abilities_are_not_permission_rows(): void
    {
        $this->seedRbac();

        $this->assertFalse(Permission::whereIn('name', ['documents.stamps.manage', 'documents.signatures.delete'])->exists());
    }
}
