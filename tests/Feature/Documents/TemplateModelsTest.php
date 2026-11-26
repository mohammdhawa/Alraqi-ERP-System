<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Modules\Auth\Resources\AuditLogDetailResource;
use App\Modules\Auth\Resources\AuditTargetStub;
use App\Modules\Departments\Models\Department;
use App\Modules\Documents\Enums\DocumentKind;
use App\Modules\Documents\Enums\DocumentOrigin;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Enums\FieldType;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentTemplate;
use App\Modules\Documents\Models\DocumentTemplateCategory;
use App\Modules\HR\Models\Employee;
use App\Shared\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The data behind document templates (documents design §6): a template, its
 * category, and the registrations every audited model needs.
 */
class TemplateModelsTest extends TestCase
{
    use RefreshDatabase;

    private function category(string $name = 'تعاميم'): DocumentTemplateCategory
    {
        return DocumentTemplateCategory::create(['name' => $name, 'sort' => 1]);
    }

    private function template(DocumentTemplateCategory $category, array $overrides = []): DocumentTemplate
    {
        return DocumentTemplate::create($overrides + [
            'category_id'      => $category->id,
            'name'             => 'تعميم عام',
            'reference_prefix' => 'TA',
            'fields'           => [
                ['key' => 'subject', 'label' => 'الموضوع', 'type' => FieldType::Text->value, 'required' => true],
            ],
            'body' => '<p><span data-placeholder="field.subject">الموضوع</span></p>',
        ]);
    }

    public function test_a_template_belongs_to_its_category_and_casts_its_fields(): void
    {
        $category = $this->category();
        $template = $this->template($category)->fresh();

        $this->assertTrue($template->category->is($category));
        $this->assertTrue($category->templates->first()->is($template));
        $this->assertIsArray($template->fields);
        $this->assertSame('subject', $template->fields[0]['key']);
        $this->assertTrue($template->is_active);
        $this->assertFalse($template->for_employees);
        $this->assertTrue($template->show_letterhead);
        $this->assertSame(1, $template->version);
        $this->assertSame('text', FieldType::Text->value);
        $this->assertCount(6, FieldType::cases());
    }

    public function test_a_template_in_use_counts_deleted_documents(): void
    {
        $template = $this->template($this->category());
        $this->assertFalse($template->isInUse());

        $root = Department::create(['name' => 'الإدارة العامة', 'level' => 1]);
        $employee = Employee::factory()->create();

        $document = Document::create([
            'kind'             => DocumentKind::Internal,
            'origin'           => DocumentOrigin::Template,
            'title'            => 'مستند',
            'creator_id'       => $employee->id,
            'creator_unit_id'  => $root->id,
            'register_unit_id' => $root->id,
            'template_id'      => $template->id,
            'status'           => DocumentStatus::Pending,
            'folder'           => (string) Str::uuid(),
            'original_path'    => 'documents/x/original.pdf',
        ]);

        $this->assertTrue($template->isInUse());
        $this->assertCount(1, $template->documents);

        $document->delete();

        $this->assertTrue($template->fresh()->isInUse());
    }

    public function test_templates_and_categories_are_audited_under_their_aliases(): void
    {
        $category = $this->category();
        $template = $this->template($category);

        $this->assertTrue(AuditLog::where('auditable_type', 'document_template')
            ->where('auditable_id', $template->id)->where('event', 'created')->exists());
        $this->assertTrue(AuditLog::where('auditable_type', 'document_template_category')
            ->where('auditable_id', $category->id)->where('event', 'created')->exists());

        $this->assertSame('قالب مستند', AuditTargetStub::typeLabel('document_template'));
        $this->assertSame('تصنيف قوالب المستندات', AuditTargetStub::typeLabel('document_template_category'));
        $this->assertSame(['documents.view'], AuditLogDetailResource::TYPE_VIEW_PERMISSIONS['document_template']);
        $this->assertSame(['documents.view'], AuditLogDetailResource::TYPE_VIEW_PERMISSIONS['document_template_category']);
    }

    public function test_the_active_scope_leaves_out_inactive_and_deleted_templates(): void
    {
        $category = $this->category();
        $live = $this->template($category, ['name' => 'فعّال']);
        $this->template($category, ['name' => 'موقوف', 'is_active' => false]);
        $this->template($category, ['name' => 'محذوف'])->delete();

        $this->assertSame([$live->id], DocumentTemplate::active()->pluck('id')->all());
    }
}
