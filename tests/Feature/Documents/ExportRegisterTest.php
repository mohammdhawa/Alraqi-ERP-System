<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Modules\Auth\Models\User;
use App\Modules\Departments\Models\Department;
use App\Modules\Documents\Enums\DocumentKind;
use App\Modules\Documents\Enums\DocumentOrigin;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Models\Document;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/documents/registers/{department}: سجل الصادر, every number of one
 * إدارة's register, deleted documents included and marked.
 */
class ExportRegisterTest extends TestCase
{
    use RefreshDatabase;

    private Department $root;

    private Department $division;

    private Department $section;

    private User $divisionManager;

    private User $sectionManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = Department::create(['name' => 'الإدارة العامة', 'level' => 1]);
        $this->division = Department::create(['name' => 'إدارة المالية', 'level' => 2, 'parent_id' => $this->root->id, 'sector' => 1]);
        $this->section = Department::create(['name' => 'قسم الحسابات', 'level' => 3, 'parent_id' => $this->division->id]);

        $this->divisionManager = $this->manage($this->division);
        $this->sectionManager = $this->manage($this->section);
    }

    private function manage(Department $unit): User
    {
        $employee = Employee::factory()->create();
        $unit->update(['manager_id' => $employee->id]);

        return User::factory()->create(['employee_id' => $employee->id, 'must_change_password' => false]);
    }

    private function document(int $number, string $title = 'مستند', DocumentStatus $status = DocumentStatus::Approved, ?Department $register = null): Document
    {
        $register ??= $this->division;

        return Document::create([
            'kind'             => DocumentKind::Internal,
            'origin'           => DocumentOrigin::Uploaded,
            'title'            => $title,
            'creator_id'       => $this->divisionManager->employee_id,
            'creator_unit_id'  => $register->id,
            'register_unit_id' => $register->id,
            'created_by'       => $this->divisionManager->id,
            'export_number'    => $number,
            'reference'        => "FIN-2026-{$number}",
            'status'           => $status,
            'folder'           => (string) Str::uuid(),
        ]);
    }

    public function test_the_division_manager_reads_the_register_with_deleted_rows_marked(): void
    {
        $this->document(1, 'الأول');
        $this->document(2, 'الثاني')->delete();
        $this->document(3, 'الثالث');
        $this->document(9, 'وثيقة بلا رقم صادر')->update(['export_number' => null]);
        $this->document(4, 'من سجل آخر', register: Department::create(['name' => 'إدارة أخرى', 'level' => 2, 'parent_id' => $this->root->id, 'sector' => 1]));

        Sanctum::actingAs($this->divisionManager);

        $response = $this->getJson("/api/documents/registers/{$this->division->id}")->assertOk();

        $this->assertSame([3, 2, 1], collect($response->json('data'))->pluck('export_number')->all());
        $response
            ->assertJsonPath('data.0.title', 'الثالث')
            ->assertJsonPath('data.0.reference', 'FIN-2026-3')
            ->assertJsonPath('data.0.deleted', false)
            ->assertJsonPath('data.0.deleted_label', null)
            ->assertJsonPath('data.0.status.value', DocumentStatus::Approved->value)
            ->assertJsonPath('data.0.creator.id', $this->divisionManager->employee_id)
            ->assertJsonPath('data.1.deleted', true)
            ->assertJsonPath('data.1.deleted_label', 'محذوف')
            ->assertJsonPath('meta.total', 3);
        $this->assertNotNull($response->json('data.0.created_at'));
        $this->assertNotNull($response->json('data.0.creator.full_name'));
    }

    public function test_search_matches_a_number_or_the_title(): void
    {
        $this->document(15, 'تعميم الرواتب');
        $this->document(16, 'طلب شراء');
        Sanctum::actingAs($this->divisionManager);

        $this->assertSame([16], collect($this->getJson("/api/documents/registers/{$this->division->id}?search=16")->assertOk()->json('data'))->pluck('export_number')->all());
        $this->assertSame([15], collect($this->getJson("/api/documents/registers/{$this->division->id}?search=".urlencode('الرواتب'))->assertOk()->json('data'))->pluck('export_number')->all());
    }

    public function test_a_section_manager_under_it_is_refused(): void
    {
        Sanctum::actingAs($this->sectionManager);

        $this->getJson("/api/documents/registers/{$this->division->id}")
            ->assertForbidden()
            ->assertJsonPath('message', 'لا يطّلع على سجل الصادر إلا مدير الإدارة أو من يملك صلاحية عرض المستندات.');
    }

    public function test_documents_view_reads_any_register(): void
    {
        $this->document(7);
        $this->actingAsUserWithPermissions(['documents.view']);

        $this->getJson("/api/documents/registers/{$this->division->id}")
            ->assertOk()
            ->assertJsonPath('data.0.export_number', 7);
    }

    public function test_a_section_is_not_a_register(): void
    {
        $this->actingAsUserWithPermissions(['documents.view']);

        $this->getJson("/api/documents/registers/{$this->section->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'هذه الوحدة لا تملك سجل صادر.');

        // Its own manager is let in, then told the same.
        Sanctum::actingAs($this->sectionManager);

        $this->getJson("/api/documents/registers/{$this->section->id}")
            ->assertUnprocessable();
    }

    public function test_someone_who_may_not_read_registers_is_refused_before_learning_a_unit_is_no_register(): void
    {
        Sanctum::actingAs(User::factory()->create(['must_change_password' => false]));

        $this->getJson("/api/documents/registers/{$this->section->id}")->assertForbidden();
    }

    public function test_a_deleted_unit_is_not_found(): void
    {
        $gone = Department::create(['name' => 'إدارة ملغاة', 'level' => 2, 'parent_id' => $this->root->id, 'sector' => 1]);
        $gone->delete();
        $this->actingAsUserWithPermissions(['documents.view']);

        $this->getJson("/api/documents/registers/{$gone->id}")->assertNotFound();
    }

    public function test_a_closed_document_shows_as_not_completed(): void
    {
        $this->document(5, 'مستند مغلق', DocumentStatus::NotCompleted);
        Sanctum::actingAs($this->divisionManager);

        $this->getJson("/api/documents/registers/{$this->division->id}")
            ->assertOk()
            ->assertJsonPath('data.0.status.value', 7)
            ->assertJsonPath('data.0.status.label', 'لم يُستكمل');
    }
}
