<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Modules\Documents\Models\DocumentTemplate;
use App\Modules\Documents\Models\DocumentTemplateCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TemplateCategoriesApiTest extends TestCase
{
    use RefreshDatabase;

    private function template(DocumentTemplateCategory $category, bool $active = true): DocumentTemplate
    {
        return DocumentTemplate::create([
            'category_id'      => $category->id,
            'name'             => 'قالب '.DocumentTemplate::withTrashed()->count(),
            'reference_prefix' => 'CIR',
            'fields'           => [],
            'body'             => '<p>x</p>',
            'is_active'        => $active,
        ]);
    }

    public function test_anyone_signed_in_lists_the_categories_with_active_counts(): void
    {
        $b = DocumentTemplateCategory::create(['name' => 'ب', 'sort' => 1]);
        $a = DocumentTemplateCategory::create(['name' => 'أ', 'sort' => 1]);
        $first = DocumentTemplateCategory::create(['name' => 'ز', 'sort' => 0]);
        DocumentTemplateCategory::create(['name' => 'محذوف', 'sort' => 0])->delete();
        $this->template($a);
        $this->template($a);
        $this->template($a, false);
        $this->actingAsUserWithPermissions([]);

        $rows = $this->getJson('/api/documents/template-categories')->assertOk()->assertJsonCount(3, 'data')->json('data');

        $this->assertSame(['ز', 'أ', 'ب'], array_column($rows, 'name'));
        $this->assertSame([0, 2, 0], array_column($rows, 'templates_count'));
        $this->assertSame($first->id, $rows[0]['id']);
        $this->assertSame($b->id, $rows[2]['id']);
    }

    /**
     * Ruling R19: the managers' picker sends `for_employees=0`, HR's `1`, to
     * both lists, so each count matches the list it opens.
     */
    public function test_the_counts_take_the_for_employees_filter(): void
    {
        $category = DocumentTemplateCategory::create(['name' => 'عام', 'sort' => 1]);
        $this->template($category);
        $this->template($category);
        $this->template($category, false);
        $this->template($category)->update(['for_employees' => true]);
        $this->actingAsUserWithPermissions([]);

        $this->getJson('/api/documents/template-categories')->assertOk()->assertJsonPath('data.0.templates_count', 3);
        $this->getJson('/api/documents/template-categories?for_employees=0')->assertOk()->assertJsonPath('data.0.templates_count', 2);
        $this->getJson('/api/documents/template-categories?for_employees=1')->assertOk()->assertJsonPath('data.0.templates_count', 1);
        $this->getJson('/api/documents/templates?for_employees=0')->assertJsonCount(2, 'data');

        $this->getJson('/api/documents/template-categories?for_employees=x')
            ->assertUnprocessable()->assertJsonPath('errors.for_employees.0', 'نوع القالب غير صالح.');
    }

    public function test_a_category_is_created_and_renamed(): void
    {
        $this->actingAsUserWithPermissions(['documents.templates.manage']);

        $id = $this->postJson('/api/documents/template-categories', ['name' => 'عقود', 'sort' => 3])
            ->assertCreated()->assertJsonPath('message', 'تم إنشاء التصنيف.')
            ->assertJsonPath('data.name', 'عقود')->assertJsonPath('data.sort', 3)->json('data.id');

        $this->putJson("/api/documents/template-categories/{$id}", ['name' => 'عقود جديدة'])
            ->assertOk()->assertJsonPath('message', 'تم تحديث التصنيف.')->assertJsonPath('data.name', 'عقود جديدة')
            ->assertJsonPath('data.sort', 3);
        // Keeping its own name is not a duplicate.
        $this->putJson("/api/documents/template-categories/{$id}", ['name' => 'عقود جديدة', 'sort' => 5])->assertOk();
        $this->assertSame(5, DocumentTemplateCategory::findOrFail($id)->sort);
    }

    public function test_the_name_is_required_and_unique_among_live_categories(): void
    {
        DocumentTemplateCategory::create(['name' => 'قائم']);
        DocumentTemplateCategory::create(['name' => 'ممسوح'])->delete();
        $this->actingAsUserWithPermissions(['documents.templates.manage']);

        $this->postJson('/api/documents/template-categories', ['name' => ''])
            ->assertUnprocessable()->assertJsonPath('errors.name.0', 'اسم التصنيف مطلوب.');
        $this->postJson('/api/documents/template-categories', ['name' => 'قائم'])
            ->assertUnprocessable()->assertJsonPath('errors.name.0', 'يوجد تصنيف بهذا الاسم.');
        $this->postJson('/api/documents/template-categories', ['name' => 'ممسوح'])->assertCreated();
    }

    public function test_a_category_with_a_template_is_not_deleted_but_an_empty_one_is(): void
    {
        $full = DocumentTemplateCategory::create(['name' => 'ممتلئ']);
        $this->template($full, false);
        $empty = DocumentTemplateCategory::create(['name' => 'فارغ']);
        $this->actingAsUserWithPermissions(['documents.templates.manage']);

        $this->deleteJson("/api/documents/template-categories/{$full->id}")
            ->assertUnprocessable()->assertJsonPath('message', 'لا يُحذف تصنيف فيه قوالب.');
        $this->assertNotSoftDeleted($full);

        $this->deleteJson("/api/documents/template-categories/{$empty->id}")
            ->assertOk()->assertJsonPath('message', 'تم حذف التصنيف.');
        $this->assertSoftDeleted($empty);
    }

    public function test_writing_needs_the_permission(): void
    {
        $category = DocumentTemplateCategory::create(['name' => 'تصنيف']);
        $this->actingAsUserWithPermissions(['documents.view']);

        $this->postJson('/api/documents/template-categories', ['name' => 'جديد'])->assertForbidden();
        $this->putJson("/api/documents/template-categories/{$category->id}", ['name' => 'جديد'])->assertForbidden();
        $this->deleteJson("/api/documents/template-categories/{$category->id}")->assertForbidden();
        $this->assertNotSoftDeleted($category);
    }
}
