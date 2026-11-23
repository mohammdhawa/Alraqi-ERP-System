<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Modules\Departments\Models\Department;
use App\Modules\Documents\Enums\DocumentKind;
use App\Modules\Documents\Enums\DocumentOrigin;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentTemplate;
use App\Modules\Documents\Models\DocumentTemplateCategory;
use App\Modules\Documents\Templates\TemplateBody;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The templates' API (documents design §6, §16): read by everyone signed in,
 * written with `documents.templates.manage`.
 */
class TemplatesApiTest extends TestCase
{
    use RefreshDatabase;

    private function category(string $name = 'تعاميم', int $sort = 1): DocumentTemplateCategory
    {
        return DocumentTemplateCategory::create(['name' => $name, 'sort' => $sort]);
    }

    private function template(DocumentTemplateCategory $category, array $overrides = []): DocumentTemplate
    {
        return DocumentTemplate::create($overrides + [
            'category_id'      => $category->id,
            'name'             => 'تعميم عام',
            'reference_prefix' => 'CIR',
            'fields'           => [],
            'body'             => '<p>نص</p>',
        ]);
    }

    private function documentOf(DocumentTemplate $template): Document
    {
        $root = Department::create(['name' => 'الإدارة العامة', 'level' => 1]);
        $employee = Employee::factory()->create();

        return Document::create([
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
    }

    private function payload(DocumentTemplateCategory $category, array $overrides = []): array
    {
        return $overrides + [
            'name'             => 'قالب جديد',
            'category_id'      => $category->id,
            'reference_prefix' => 'NEW',
            'fields'           => [['key' => 'subject', 'label' => 'الموضوع', 'type' => 'text', 'required' => true]],
            'body'             => '<p><span data-placeholder="field.subject">الموضوع</span></p>',
        ];
    }

    public function test_anyone_signed_in_lists_active_templates_without_their_pages(): void
    {
        $category = $this->category();
        $this->template($category, ['name' => 'مفعّل']);
        $this->template($category, ['name' => 'موقوف', 'is_active' => false]);
        $this->actingAsUserWithPermissions([]);

        $response = $this->getJson('/api/documents/templates?status=inactive')->assertOk();

        $response->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'مفعّل')
            ->assertJsonPath('data.0.category.name', 'تعاميم')
            ->assertJsonPath('meta.per_page', 15);
        foreach (['body', 'layout_key', 'show_letterhead', 'reference_prefix', 'in_use'] as $hidden) {
            $response->assertJsonMissingPath("data.0.{$hidden}");
        }

        $id = DocumentTemplate::where('name', 'مفعّل')->value('id');
        $this->getJson("/api/documents/templates/{$id}")->assertOk()->assertJsonMissingPath('data.body');
        $inactive = DocumentTemplate::where('name', 'موقوف')->value('id');
        $this->getJson("/api/documents/templates/{$inactive}")->assertNotFound();
    }

    public function test_a_template_manager_lists_all_with_pages_and_in_use(): void
    {
        $category = $this->category();
        $used = $this->template($category, ['name' => 'مستخدم']);
        $this->template($category, ['name' => 'موقوف', 'is_active' => false]);
        $this->actingAsUserWithPermissions(['documents.templates.manage']);
        $this->documentOf($used);

        $rows = $this->getJson('/api/documents/templates')->assertOk()->assertJsonCount(2, 'data')->json('data');
        $byName = collect($rows)->keyBy('name');
        $this->assertTrue($byName['مستخدم']['in_use']);
        $this->assertFalse($byName['موقوف']['in_use']);
        $this->assertSame('<p>نص</p>', $byName['مستخدم']['body']);
        $this->assertSame('CIR', $byName['مستخدم']['reference_prefix']);

        $this->getJson('/api/documents/templates?status=inactive')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'موقوف');
        $this->getJson('/api/documents/templates?status=active')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'مستخدم');
        $this->getJson('/api/documents/templates?status=bogus')->assertUnprocessable();
        $this->getJson("/api/documents/templates/{$byName['موقوف']['id']}")->assertOk()->assertJsonPath('data.in_use', false);
    }

    public function test_filters_by_category_search_and_for_employees(): void
    {
        $a = $this->category('أ');
        $b = $this->category('ب');
        $this->template($a, ['name' => 'طلب 100%']);
        $this->template($a, ['name' => 'طلب عادي', 'for_employees' => true]);
        $this->template($b, ['name' => 'إشعار']);
        $this->actingAsUserWithPermissions([]);

        $this->getJson("/api/documents/templates?category_id={$b->id}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'إشعار');
        $this->getJson('/api/documents/templates?search=طلب')->assertJsonCount(2, 'data');
        $this->getJson('/api/documents/templates?search='.urlencode('%'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'طلب 100%');
        $this->getJson('/api/documents/templates?for_employees=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'طلب عادي');
        $this->getJson('/api/documents/templates?for_employees=0')->assertJsonCount(2, 'data');
    }

    public function test_creating_a_template_stores_a_sanitised_page(): void
    {
        $category = $this->category();
        $this->actingAsUserWithPermissions(['documents.templates.manage']);
        $dirty = '<p style="background:url(file:///etc/passwd);text-align:center">'
            .'<script>alert(1)</script><img src="/etc/passwd"><a href="http://x">رابط</a>'
            .'<span data-placeholder="field.subject">الموضوع</span></p>';

        $response = $this->postJson('/api/documents/templates', $this->payload($category, ['body' => $dirty]))
            ->assertCreated()
            ->assertJsonPath('message', 'تم إنشاء القالب.')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.in_use', false);

        $stored = DocumentTemplate::sole();
        $this->assertSame($stored->body, $response->json('data.body'));
        foreach (['<img', '<script', 'url(', '<a ', 'passwd', 'alert'] as $bad) {
            $this->assertStringNotContainsString($bad, (string) $stored->body);
        }
        $this->assertStringContainsString('data-placeholder="field.subject"', (string) $stored->body);
        $this->assertStringContainsString('text-align', (string) $stored->body);
        $this->assertTrue($stored->is_active);
        $this->assertTrue($stored->show_letterhead);
        $this->assertFalse($stored->for_employees);
        $this->assertNull($stored->layout_key);
    }

    public function test_an_unknown_chip_is_refused(): void
    {
        $category = $this->category();
        $this->actingAsUserWithPermissions(['documents.templates.manage']);
        $body = '<p><span data-placeholder="field.missing">x</span><span data-placeholder="recipient.name">y</span></p>';

        $response = $this->postJson('/api/documents/templates', $this->payload($category, ['body' => $body]))
            ->assertUnprocessable();

        $this->assertEqualsCanonicalizing(
            ['العنصر «field.missing» في صفحة القالب غير معرّف.', 'العنصر «recipient.name» في صفحة القالب غير معرّف.'],
            $response->json('errors.body'),
        );
        $this->assertSame(0, DocumentTemplate::count());

        $this->postJson('/api/documents/templates', $this->payload($category, ['body' => $body, 'for_employees' => true]))
            ->assertUnprocessable()->assertJsonCount(1, 'errors.body');
    }

    public function test_page_or_layout_exactly_one(): void
    {
        $category = $this->category();
        $this->actingAsUserWithPermissions(['documents.templates.manage']);

        $this->postJson('/api/documents/templates', $this->payload($category, ['body' => null]))
            ->assertUnprocessable()->assertJsonPath('errors.body.0', 'اكتب صفحة القالب أو اختر تخطيطاً جاهزاً.');
        $this->postJson('/api/documents/templates', $this->payload($category, ['layout_key' => 'purchase_request']))
            ->assertUnprocessable()->assertJsonPath('errors.body.0', 'اكتب صفحة القالب أو اختر تخطيطاً جاهزاً، لا كليهما.');
        $this->postJson('/api/documents/templates', $this->payload($category, ['body' => null, 'layout_key' => 'nope']))
            ->assertUnprocessable()->assertJsonPath('errors.layout_key.0', 'التخطيط المختار غير موجود.');

        $this->postJson('/api/documents/templates', $this->payload($category, ['body' => null, 'layout_key' => 'purchase_request']))
            ->assertCreated()->assertJsonPath('data.layout_key', 'purchase_request')->assertJsonPath('data.body', null);
    }

    public function test_the_page_size_is_counted_in_bytes_before_sanitising(): void
    {
        $category = $this->category();
        $this->actingAsUserWithPermissions(['documents.templates.manage']);

        // 103 000 Arabic letters are under 204 800 characters but over 200 KB.
        $body = '<p>'.str_repeat('ع', 103000).'</p>';
        $this->assertGreaterThan(TemplateBody::MAX_BYTES, strlen($body));

        $this->postJson('/api/documents/templates', $this->payload($category, ['body' => $body]))
            ->assertUnprocessable()->assertJsonPath('errors.body.0', 'صفحة القالب لا تزيد على 200 كيلوبايت.');
    }

    public function test_the_fields_and_other_inputs_are_checked(): void
    {
        $category = $this->category();
        $this->template($category, ['name' => 'مكرر']);
        $deleted = $this->template($category, ['name' => 'محذوف']);
        $deleted->delete();
        $this->actingAsUserWithPermissions(['documents.templates.manage']);

        $this->postJson('/api/documents/templates', $this->payload($category, ['name' => 'مكرر']))
            ->assertUnprocessable()->assertJsonPath('errors.name.0', 'يوجد قالب بهذا الاسم.');
        $this->postJson('/api/documents/templates', $this->payload($category, ['name' => 'محذوف']))->assertCreated();
        $this->postJson('/api/documents/templates', $this->payload($category, ['name' => '', 'category_id' => 999, 'reference_prefix' => 'ab']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'اسم القالب مطلوب.')
            ->assertJsonPath('errors.category_id.0', 'التصنيف المختار غير موجود.')
            ->assertJsonPath('errors.reference_prefix.0', 'بادئة المرجع من 2 إلى 10 أحرف إنجليزية كبيرة.');
        $this->postJson('/api/documents/templates', $this->payload($category, [
            'name'   => 'س',
            'fields' => [['key' => 'a', 'label' => 'أ', 'type' => 'select'], ['key' => 'a', 'label' => 'ب', 'type' => 'text']],
        ]))->assertUnprocessable()->assertJsonFragment(['حقل القائمة «أ» يحتاج إلى خيار واحد على الأقل.'])
            ->assertJsonFragment(['مفتاح الحقل «a» مكرر.']);
    }

    public function test_every_update_bumps_the_version(): void
    {
        $category = $this->category();
        $template = $this->template($category);
        $this->actingAsUserWithPermissions(['documents.templates.manage']);

        $this->putJson("/api/documents/templates/{$template->id}", $this->payload($category, ['name' => 'تعميم عام']))
            ->assertOk()->assertJsonPath('message', 'تم تحديث القالب.')->assertJsonPath('data.version', 2);
        $this->putJson("/api/documents/templates/{$template->id}", $this->payload($category, ['name' => 'تعميم عام', 'is_active' => false]))
            ->assertOk()->assertJsonPath('data.version', 3)->assertJsonPath('data.is_active', false);

        $this->assertSame(3, $template->fresh()->version);
    }

    public function test_a_used_template_is_not_deleted_but_an_unused_one_is(): void
    {
        $category = $this->category();
        $used = $this->template($category, ['name' => 'مستخدم']);
        $free = $this->template($category, ['name' => 'حر']);
        $this->actingAsUserWithPermissions(['documents.templates.manage']);
        $document = $this->documentOf($used);
        $document->delete();

        $this->deleteJson("/api/documents/templates/{$used->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'استُخدم هذا القالب في مستندات، فلا يُحذف. يمكنك إيقافه بدلاً من ذلك.');
        $this->assertNotSoftDeleted($used);

        $this->deleteJson("/api/documents/templates/{$free->id}")->assertOk()->assertJsonPath('message', 'تم حذف القالب.');
        $this->assertSoftDeleted($free);
        $this->getJson("/api/documents/templates/{$free->id}")->assertNotFound();
    }

    public function test_writing_needs_the_permission(): void
    {
        $category = $this->category();
        $template = $this->template($category);
        $this->actingAsUserWithPermissions(['documents.view']);

        $this->postJson('/api/documents/templates', $this->payload($category))->assertForbidden();
        $this->putJson("/api/documents/templates/{$template->id}", $this->payload($category))->assertForbidden();
        $this->deleteJson("/api/documents/templates/{$template->id}")->assertForbidden();
        $this->assertSame(1, $template->fresh()->version);
    }
}
