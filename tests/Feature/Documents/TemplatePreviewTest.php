<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Modules\Departments\Models\Department;
use App\Modules\Documents\Models\DocumentTemplate;
use App\Modules\Documents\Models\DocumentTemplateCategory;
use App\Modules\Documents\Pdf\PdfSession;
use App\Modules\Documents\Pdf\RenderedTemplate;
use App\Modules\Documents\Pdf\TemplateHtml;
use App\Modules\Documents\Pdf\TemplatePrinter;
use App\Modules\Documents\Services\Letterhead;
use App\Modules\Documents\Support\RecipientValues;
use App\Modules\Documents\Support\SystemValues;
use App\Modules\HR\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * POST /api/documents/templates/preview: the page an administrator is
 * editing, printed with sample values; nothing is saved.
 */
class TemplatePreviewTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/documents/templates/preview';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function fields(): array
    {
        return [
            ['key' => 'subject', 'label' => 'الموضوع', 'type' => 'text', 'required' => true],
            ['key' => 'details', 'label' => 'التفاصيل', 'type' => 'textarea', 'required' => false],
            ['key' => 'amount', 'label' => 'المبلغ', 'type' => 'number', 'required' => false],
            ['key' => 'due', 'label' => 'الموعد', 'type' => 'date', 'required' => false],
            ['key' => 'priority', 'label' => 'الأولوية', 'type' => 'select', 'required' => false, 'options' => ['عادية', 'عاجلة']],
            ['key' => 'items', 'label' => 'البنود', 'type' => 'table', 'required' => false, 'columns' => [['key' => 'name', 'label' => 'الصنف'], ['key' => 'qty', 'label' => 'الكمية']]],
        ];
    }

    private function payload(array $overrides = []): array
    {
        $chips = implode(' ', array_map(
            static fn (string $key) => "<span data-placeholder=\"{$key}\">{$key}</span>",
            ['field.subject', 'field.details', 'field.amount', 'field.due', 'field.priority', 'system.export_number', 'system.reference', 'system.creator_name'],
        ));

        return $overrides + [
            'name'             => 'تعميم عام',
            'reference_prefix' => 'CIR',
            'fields'           => $this->fields(),
            'body'             => "<p>{$chips}</p><p><span data-placeholder=\"field.items\">البنود</span></p>",
        ];
    }

    /** A printer that records what it was given, then prints. */
    private function recordingPrinter(): TemplatePrinter
    {
        $spy = new class(app(Letterhead::class), app(TemplateHtml::class)) extends TemplatePrinter
        {
            /** @var array<int, array<string, mixed>> */
            public array $calls = [];

            public function print(DocumentTemplate $template, array $fieldValues, SystemValues $system, ?RecipientValues $recipient, PdfSession $session): RenderedTemplate
            {
                $this->calls[] = compact('template', 'fieldValues', 'system', 'recipient');

                return parent::print($template, $fieldValues, $system, $recipient, $session);
            }
        };
        $this->app->instance(TemplatePrinter::class, $spy);

        return $spy;
    }

    public function test_a_template_manager_gets_the_pdf_inline_and_nothing_is_saved(): void
    {
        $category = DocumentTemplateCategory::create(['name' => 'تعاميم', 'sort' => 1]);
        DocumentTemplate::create(['category_id' => $category->id, 'name' => 'تعميم عام', 'reference_prefix' => 'CIR', 'fields' => [], 'body' => '<p>نص</p>', 'version' => 1]);
        $this->actingAsUserWithPermissions(['documents.templates.manage']);

        // The name of an existing template, and no category: an edit previews.
        $response = $this->post(self::URL, $this->payload(), ['Accept' => 'application/json'])->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame('inline; filename="preview.pdf"', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringStartsWith('%PDF-', (string) $response->getContent());
        $this->assertSame(1, DocumentTemplate::count());
        $this->assertSame([], Storage::disk('local')->allFiles('pdf-work'));

        $this->postJson(self::URL, $this->payload(['body' => null, 'layout_key' => 'purchase_request']))->assertOk();
        $this->postJson(self::URL, $this->payload(['category_id' => $category->id, 'show_letterhead' => false]))->assertOk();
    }

    public function test_the_preview_prints_the_sample_values(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-24 10:00:00'));
        $spy = $this->recordingPrinter();
        $this->actingAsUserWithPermissions(['documents.templates.manage']);

        $this->postJson(self::URL, $this->payload())->assertOk();

        $call = $spy->calls[0];
        $this->assertFalse($call['template']->exists);
        $this->assertSame('تعميم عام', $call['template']->name);
        $this->assertSame([
            'subject'  => 'نص تجريبي',
            'details'  => "سطر أول\nسطر ثانٍ",
            'amount'   => '1250',
            'due'      => '2026-11-24',
            'priority' => 'عادية',
            'items'    => [['name' => 'قيمة', 'qty' => 'قيمة'], ['name' => 'قيمة', 'qty' => 'قيمة']],
        ], $call['fieldValues']);
        $this->assertSame([
            'system.export_number' => '123',
            'system.import_number' => '45',
            'system.reference'     => 'CIR-2026-0001',
            'system.date'          => '2026/11/24',
            'system.creator_name'  => 'اسم المنشئ',
            'system.creator_unit'  => 'اسم الوحدة',
        ], $call['system']->toArray());
        $this->assertNull($call['recipient']);
    }

    public function test_the_caller_and_the_recipient_are_sampled(): void
    {
        $spy = $this->recordingPrinter();
        $user = $this->actingAsUserWithPermissions(['documents.templates.manage']);
        $employee = Employee::factory()->create(['first_name' => 'محمد', 'middle_name' => 'علي', 'last_name' => 'الأحمد']);
        Department::create(['name' => 'إدارة الموارد البشرية', 'level' => 1, 'manager_id' => $employee->id]);
        $user->update(['employee_id' => $employee->id]);

        $this->postJson(self::URL, $this->payload([
            'for_employees' => true,
            'body'          => '<p><span data-placeholder="recipient.name">الموظف</span> <span data-placeholder="recipient.employee_number">الرقم</span></p>',
        ]))->assertOk();

        $call = $spy->calls[0];
        $this->assertSame('محمد علي الأحمد', $call['system']->creatorName);
        $this->assertSame('إدارة الموارد البشرية', $call['system']->creatorUnit);
        $this->assertSame([
            'recipient.name'            => 'اسم الموظف',
            'recipient.job_title'       => 'المسمى الوظيفي',
            'recipient.unit'            => 'الوحدة',
            'recipient.employee_number' => '0000',
        ], $call['recipient']->toArray());
    }

    public function test_anyone_else_is_refused(): void
    {
        $this->actingAsUserWithPermissions(['documents.view']);

        $this->postJson(self::URL, $this->payload())->assertForbidden();
    }

    public function test_an_unknown_chip_is_refused_as_when_saving(): void
    {
        $this->actingAsUserWithPermissions(['documents.templates.manage']);

        $this->postJson(self::URL, $this->payload(['body' => '<p><span data-placeholder="field.missing">x</span></p>']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.body', ['العنصر «field.missing» في صفحة القالب غير معرّف.']);

        $this->postJson(self::URL, $this->payload(['name' => '']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.name', ['اسم القالب مطلوب.']);

        $this->postJson(self::URL, $this->payload(['category_id' => 999]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.category_id', ['التصنيف المختار غير موجود.']);
    }
}
