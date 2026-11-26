<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Modules\Approvals\Enums\RejectionPolicy;
use App\Modules\Auth\Models\Notification;
use App\Modules\Auth\Models\User;
use App\Modules\Departments\Models\Department;
use App\Modules\Documents\Enums\Confirmation;
use App\Modules\Documents\Enums\DocumentOrigin;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Enums\LetterheadPart;
use App\Modules\Documents\Enums\MarkKind;
use App\Modules\Documents\Enums\SignatureSource;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentCounter;
use App\Modules\Documents\Models\DocumentReferenceSequence;
use App\Modules\Documents\Models\DocumentTemplate;
use App\Modules\Documents\Models\DocumentTemplateCategory;
use App\Modules\Documents\Pdf\DocumentPrinter;
use App\Modules\Documents\Pdf\PdfIntake;
use App\Modules\Documents\Pdf\PdfSession;
use App\Modules\Documents\Pdf\PdfWorkspace;
use App\Modules\Documents\Pdf\RenderedTemplate;
use App\Modules\Documents\Pdf\TemplateHtml;
use App\Modules\Documents\Pdf\TemplatePrinter;
use App\Modules\Documents\Services\ExportNumbers;
use App\Modules\Documents\Services\Letterhead;
use App\Modules\Documents\Services\Signatures;
use App\Modules\Documents\Services\UnitStamps;
use App\Modules\Documents\Support\RecipientValues;
use App\Modules\Documents\Support\SystemValues;
use App\Modules\Documents\Templates\TemplateBody;
use App\Modules\HR\Models\Employee;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PDOException;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Tests\Support\Pdfs;
use Tests\TestCase;
use Throwable;

/**
 * Creating a document between managers from a template (documents design
 * §8.3, §8.4, §11.2): the values checked against the template as it is at
 * sending, the صادر number and the reference claimed in the creation's own
 * transaction, the page printed as the original, and the same route as an
 * upload. A refusal or a failure leaves no row, no file and no number.
 */
class CreateFromTemplateTest extends TestCase
{
    use RefreshDatabase;

    private const FIELDS = [
        ['key' => 'subject', 'label' => 'الموضوع', 'type' => 'text', 'required' => true],
        ['key' => 'amount', 'label' => 'المبلغ', 'type' => 'number', 'required' => false],
        ['key' => 'due', 'label' => 'تاريخ الاستحقاق', 'type' => 'date', 'required' => false],
        ['key' => 'items', 'label' => 'البنود', 'type' => 'table', 'required' => false, 'columns' => [
            ['key' => 'name', 'label' => 'البند'],
            ['key' => 'qty', 'label' => 'الكمية'],
        ]],
    ];

    private const PAGE = '<p>رقم الصادر: <span data-placeholder="system.export_number">رقم الصادر</span>'
        .' — المرجع: <span data-placeholder="system.reference">المرجع</span></p>'
        .'<p><span data-placeholder="field.subject">الموضوع</span></p>'
        .'<p><span data-placeholder="field.amount">المبلغ</span> <span data-placeholder="field.due">التاريخ</span></p>'
        .'<p><span data-placeholder="field.items">البنود</span></p>';

    private const COLD = 'هذا أول مستند صادر في الإدارة، يرجى إدخال رقم الصادر لتحديد نقطة البداية.';

    /** @var array<string, Employee> */
    private array $people = [];

    /** @var array<string, User> */
    private array $accounts = [];

    private Department $root;

    private Department $division;

    private DocumentTemplateCategory $category;

    private DocumentTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->root = Department::create(['name' => 'الإدارة العامة', 'level' => 1]);
        $this->division = Department::create(['name' => 'إدارة المالية', 'level' => 2, 'parent_id' => $this->root->id, 'sector' => 1]);

        $this->manage('gm', $this->root);
        $this->manage('c', $this->division);

        foreach (['a1' => 'قسم الحسابات', 'a2' => 'قسم الميزانية'] as $key => $name) {
            $this->manage($key, Department::create(['name' => $name, 'level' => 3, 'parent_id' => $this->division->id]));
        }

        $this->category = DocumentTemplateCategory::create(['name' => 'المالية', 'sort' => 1]);
        $this->template = $this->makeTemplate();
    }

    private function person(string $key): Employee
    {
        $employee = Employee::factory()->create();
        $this->people[$key] = $employee;
        $this->accounts[$key] = User::factory()->create(['employee_id' => $employee->id, 'must_change_password' => false]);

        return $employee;
    }

    private function manage(string $key, Department $unit): void
    {
        $unit->update(['manager_id' => $this->person($key)->id]);
    }

    /** A template as it is stored: its page through the sanitiser. */
    private function makeTemplate(array $overrides = []): DocumentTemplate
    {
        return DocumentTemplate::create($overrides + [
            'category_id'      => $this->category->id,
            'name'             => 'طلب صرف',
            'reference_prefix' => 'RE',
            'fields'           => self::FIELDS,
            'body'             => (new TemplateBody)->sanitize(self::PAGE),
            'show_letterhead'  => true,
            'version'          => 3,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title'        => 'طلب صرف مستحقات',
            'template_id'  => $this->template->id,
            'field_values' => ['subject' => 'صرف مستحقات الموردين'],
            'approvers'    => [$this->people['a1']->id, $this->people['a2']->id],
        ], $overrides);
    }

    private function send(array $payload, string $as = 'c'): TestResponse
    {
        Sanctum::actingAs($this->accounts[$as]);

        return $this->post('/api/documents', $payload, ['Accept' => 'application/json']);
    }

    private function counter(): ?int
    {
        $value = DocumentCounter::query()->where('unit_id', $this->division->id)->value('current_value');

        return $value === null ? null : (int) $value;
    }

    private function sequence(string $prefix = 'RE'): ?int
    {
        $value = DocumentReferenceSequence::query()->where('prefix', $prefix)->where('year', now()->year)->value('current_value');

        return $value === null ? null : (int) $value;
    }

    /** The first message under a validation key, dots and all (`field_values.subject`). */
    private function errorOf(TestResponse $response, string $key): ?string
    {
        return $response->json('errors')[$key][0] ?? null;
    }

    /**
     * @return array<int, string>
     */
    private function filesUnder(string $directory): array
    {
        return Storage::disk('local')->allFiles($directory);
    }

    private function pagesOf(string $path): ?int
    {
        return app(PdfWorkspace::class)->run(
            static fn (PdfSession $session): ?int => app(PdfIntake::class)->pageCount(Storage::disk('local')->path($path), $session),
        );
    }

    /** The register seeded with its first document, 186. */
    private function seedRegister(): Document
    {
        $this->send($this->payload(['export_number' => 186]))->assertCreated();

        return Document::query()->latest('id')->firstOrFail();
    }

    /** Everything a refusal must leave as it found it. */
    private function assertNothingWritten(int $documents, ?int $counter, ?int $sequence): void
    {
        $this->assertSame($documents, Document::withTrashed()->count());
        $this->assertCount($documents, $this->filesUnder('documents'));
        $this->assertSame([], $this->filesUnder('attachments'));
        $this->assertSame([], $this->filesUnder('pdf-work'));
        $this->assertSame($counter, $this->counter());
        $this->assertSame($sequence, $this->sequence());
    }

    public function test_a_manager_creates_a_document_from_a_template(): void
    {
        $response = $this->send($this->payload(['export_number' => 186, 'import_number' => 12]));

        $response->assertCreated()->assertJsonPath('message', 'تم إرسال المستند للاعتماد.');

        $year = now()->year;
        $document = Document::query()->firstOrFail();
        $this->assertSame(DocumentOrigin::Template, $document->origin);
        $this->assertSame(DocumentStatus::Pending, $document->status);
        $this->assertSame($this->division->id, $document->register_unit_id);
        $this->assertSame($this->template->id, $document->template_id);
        $this->assertSame(3, $document->template_version);
        $this->assertSame(186, $document->export_number);
        $this->assertSame(12, $document->import_number);
        $this->assertSame("RE-{$year}-0001", $document->reference);
        $this->assertNull($document->card_floor, 'no footer image is uploaded');
        $this->assertNull($document->uploaded_path);
        $this->assertSame("documents/{$document->folder}/original.pdf", $document->original_path);
        $this->assertStringStartsWith('%PDF', (string) Storage::disk('local')->get($document->original_path));
        $this->assertSame([], $this->filesUnder('pdf-work'));

        $this->assertSame(186, $this->counter());
        $this->assertSame(1, $this->sequence());

        // The route as an upload's (piece 2).
        $approval = $document->approval;
        $this->assertSame(RejectionPolicy::Advisory, $approval->rejection_policy);
        $steps = $approval->steps;
        $this->assertSame([$this->people['a1']->id, $this->people['a2']->id, $this->people['gm']->id], $steps->pluck('approver_id')->all());
        $this->assertSame([false, false, true], $steps->pluck('decisive')->all());
        $this->assertSame(1, $approval->currentOrder());
        $this->assertSame(1, Notification::query()->where('user_id', $this->accounts['a1']->id)->count());

        $response->assertJsonPath('data.origin', DocumentOrigin::Template->value)
            ->assertJsonPath('data.export_number', 186)
            ->assertJsonPath('data.import_number', 12)
            ->assertJsonPath('data.reference', "RE-{$year}-0001")
            ->assertJsonPath('data.template.id', $this->template->id)
            ->assertJsonPath('data.template.name', 'طلب صرف')
            ->assertJsonPath('data.template.version', 3)
            ->assertJsonPath('data.field_values.subject', 'صرف مستحقات الموردين')
            ->assertJsonCount(3, 'data.steps');

        // The next one takes the counter's next number and the next reference.
        $this->send($this->payload())->assertCreated()
            ->assertJsonPath('data.export_number', 187)
            ->assertJsonPath('data.reference', "RE-{$year}-0002");

        // A deleted template still shows on its documents.
        $this->template->delete();
        $this->getJson("/api/documents/{$document->id}")
            ->assertOk()
            ->assertJsonPath('data.template.id', $this->template->id)
            ->assertJsonPath('data.template.name', 'طلب صرف')
            ->assertJsonPath('data.template.version', 3);
    }

    public function test_the_first_document_of_a_register_needs_its_number(): void
    {
        $this->send($this->payload())
            ->assertStatus(422)
            ->assertJsonPath('message', self::COLD);

        $this->assertNothingWritten(0, null, null);

        // A section's manager numbers from the إدارة above it, cold as well.
        $this->send($this->payload(['approvers' => [$this->people['a2']->id]]), 'a1')
            ->assertStatus(422)
            ->assertJsonPath('message', self::COLD);

        $this->assertNothingWritten(0, null, null);

        $this->send($this->payload(['export_number' => 186]))->assertCreated();
        $this->send($this->payload(['approvers' => [$this->people['a2']->id]]), 'a1')
            ->assertCreated()
            ->assertJsonPath('data.export_number', 187);

        $this->assertSame($this->division->id, Document::query()->latest('id')->firstOrFail()->register_unit_id);
    }

    public function test_a_used_number_is_refused_and_nothing_is_written(): void
    {
        $first = $this->seedRegister();

        $this->send($this->payload(['export_number' => 186]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'رقم الصادر 186 مستخدم في سجل هذه الإدارة.');

        $this->assertNothingWritten(1, 186, 1);

        // A deleted document's number is never used again (D31.3).
        $first->delete();

        $this->send($this->payload(['export_number' => 186]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'رقم الصادر 186 مستخدم في سجل هذه الإدارة.');

        $this->assertNothingWritten(1, 186, 1);

        // A lower free number is taken as it is; the counter stays.
        $this->send($this->payload(['export_number' => 40]))->assertCreated()->assertJsonPath('data.export_number', 40);
        $this->assertSame(186, $this->counter());
    }

    public function test_a_file_and_a_template_together_are_refused(): void
    {
        $this->send($this->payload([
            'export_number' => 186,
            'file'          => UploadedFile::fake()->createWithContent('letter.pdf', Pdfs::plain()),
        ]))
            ->assertStatus(422)
            ->assertJsonPath('errors.template_id.0', 'أرفق ملفاً أو اختر قالباً، لا كليهما.');

        // Neither: an upload without its file, as before.
        $this->send($this->payload(['template_id' => null]))
            ->assertStatus(422)
            ->assertJsonPath('errors.file.0', 'الملف مطلوب.');

        $this->assertNothingWritten(0, null, null);
    }

    public function test_an_export_number_without_a_template_is_refused(): void
    {
        $this->send($this->payload([
            'template_id'   => null,
            'export_number' => 186,
            'file'          => UploadedFile::fake()->createWithContent('letter.pdf', Pdfs::plain()),
        ]))
            ->assertStatus(422)
            ->assertJsonPath('errors.export_number.0', 'رقم الصادر للمستندات المنشأة من قالب فقط.');

        foreach ([0, 1000000000, 'abc'] as $number) {
            $this->send($this->payload(['export_number' => $number]))
                ->assertStatus(422)
                ->assertJsonPath('errors.export_number.0', 'رقم الصادر يجب أن يكون بين 1 و 999999999.');
        }

        $this->assertNothingWritten(0, null, null);
    }

    public function test_an_inactive_or_employee_template_is_refused(): void
    {
        $inactive = $this->makeTemplate(['name' => 'موقوف', 'is_active' => false]);
        $deleted = $this->makeTemplate(['name' => 'محذوف']);
        $deleted->delete();
        $forEmployees = $this->makeTemplate(['name' => 'إشعار موظف', 'for_employees' => true]);

        foreach ([$inactive->id, $deleted->id, 999999] as $id) {
            $this->send($this->payload(['template_id' => $id, 'export_number' => 186]))
                ->assertStatus(422)
                ->assertJsonPath('message', 'القالب المختار غير موجود أو غير مفعّل.');
        }

        $this->send($this->payload(['template_id' => $forEmployees->id, 'export_number' => 186]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'هذا القالب للمستندات المرسلة إلى الموظفين.');

        $this->assertNothingWritten(0, null, null);
    }

    public function test_field_values_are_checked_and_stored_normalised(): void
    {
        $refused = $this->send($this->payload(['export_number' => 186, 'field_values' => ['amount' => 'كثير', 'due' => '2026/12/01']]))
            ->assertStatus(422);

        $this->assertSame('حقل «الموضوع» مطلوب.', $this->errorOf($refused, 'field_values.subject'));
        $this->assertSame('حقل «المبلغ» يجب أن يكون رقماً.', $this->errorOf($refused, 'field_values.amount'));
        $this->assertSame('حقل «تاريخ الاستحقاق» يجب أن يكون تاريخاً بصيغة سنة-شهر-يوم.', $this->errorOf($refused, 'field_values.due'));

        $this->assertNothingWritten(0, null, null);

        $typed = [
            'subject' => '  صرف <b>مستحقات</b> & {{ $x }}  ',
            'amount'  => ' 1250.50 ',
            'due'     => '2026-12-01',
            'items'   => [
                ['name' => ' ورق ', 'qty' => '10', 'colour' => 'أبيض'],
                ['name' => 'حبر'],
            ],
            'unknown' => 'يُهمل',
        ];

        $response = $this->send($this->payload(['export_number' => 186, 'field_values' => $typed]))->assertCreated();

        $normalised = [
            'subject' => 'صرف <b>مستحقات</b> & {{ $x }}',
            'amount'  => '1250.50',
            'due'     => '2026-12-01',
            'items'   => [
                ['name' => 'ورق', 'qty' => '10'],
                ['name' => 'حبر', 'qty' => ''],
            ],
        ];

        $this->assertSame($normalised, Document::query()->firstOrFail()->field_values);
        $response->assertJsonPath('data.field_values', $normalised);

        // An optional value left empty is stored as null.
        $this->send($this->payload())->assertCreated()->assertJsonPath('data.field_values', [
            'subject' => 'صرف مستحقات الموردين',
            'amount'  => null,
            'due'     => null,
            'items'   => null,
        ]);
    }

    /**
     * Ruling R15: the values are an object keyed by field key. A string, a
     * number or a list is refused as a whole; null, an absent key and an
     * empty object (`{}`, or `[]`, which JSON decodes alike) are no values,
     * and the fields answer for themselves. An empty object is served as `{}`.
     */
    public function test_field_values_must_be_an_object(): void
    {
        $this->seedRegister();
        Sanctum::actingAs($this->accounts['c']);

        foreach ([
            'a string'          => 'صرف مستحقات',
            'a number'          => 5,
            'a list'            => ['صرف', 'مستحقات'],
            'a list of objects' => [['subject' => 'صرف']],
        ] as $shape => $values) {
            $response = $this->postJson('/api/documents', $this->payload(['field_values' => $values]))->assertStatus(422);

            $this->assertSame('بيانات المستند غير صالحة.', $this->errorOf($response, 'field_values'), $shape);
        }

        $this->assertNothingWritten(1, 186, 1);

        $optional = $this->makeTemplate([
            'name'   => 'مذكرة',
            'fields' => [['key' => 'subject', 'label' => 'الموضوع', 'type' => 'text', 'required' => false]],
            'body'   => (new TemplateBody)->sanitize('<p><span data-placeholder="field.subject">الموضوع</span></p>'),
        ]);

        $absent = $this->payload(['template_id' => $optional->id]);
        unset($absent['field_values']);

        foreach ([
            'absent'          => $absent,
            'null'            => ['field_values' => null] + $absent,
            'an empty list'   => ['field_values' => []] + $absent,
            'an empty object' => ['field_values' => new \stdClass] + $absent,
        ] as $shape => $payload) {
            $response = $this->postJson('/api/documents', $payload)->assertCreated();

            $this->assertStringContainsString('"field_values":{"subject":null}', (string) $response->getContent(), $shape);
        }

        // A template with no fields keeps `{}`, never `[]`.
        $empty = $this->makeTemplate(['name' => 'إشعار', 'fields' => [], 'body' => (new TemplateBody)->sanitize('<p>إشعار عام</p>')]);
        $created = $this->postJson('/api/documents', ['template_id' => $empty->id] + $absent)->assertCreated();
        $this->assertStringContainsString('"field_values":{}', (string) $created->getContent());

        $shown = $this->getJson('/api/documents/'.$created->json('data.id'))->assertOk();
        $this->assertStringContainsString('"field_values":{}', (string) $shown->getContent());
    }

    /**
     * Ruling R16: the printed original carries the numbers claimed for it,
     * as text a reader can copy or search. Read back with pdftotext (xpdf or
     * poppler) where it is installed.
     */
    public function test_the_original_carries_its_numbers_as_text(): void
    {
        try {
            $probe = Process::run(['pdftotext', '-v']);
            $available = str_contains($probe->output().$probe->errorOutput(), 'pdftotext version');
        } catch (Throwable) {
            $available = false;
        }

        if (! $available) {
            $this->markTestSkipped('pdftotext is not installed here; nothing in the repository extracts text from a PDF.');
        }

        $this->send($this->payload(['export_number' => 186]))->assertCreated();
        $this->send($this->payload(['export_number' => 4321]))->assertCreated();

        $year = now()->year;

        foreach ([[186, "RE-{$year}-0001"], [4321, "RE-{$year}-0002"]] as [$number, $reference]) {
            $document = Document::query()->where('export_number', $number)->firstOrFail();

            $text = Process::run(['pdftotext', '-enc', 'UTF-8', Storage::disk('local')->path((string) $document->original_path), '-'])->throw()->output();
            // The extractor's direction marks out.
            $text = (string) preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $text);

            $this->assertMatchesRegularExpression('/(?<!\d)'.$number.'(?!\d)/', $text);
            $this->assertStringContainsString($reference, $text);
        }
    }

    /**
     * Review Focus 4: the template as it is at sending is the one checked,
     * printed and recorded; a template stopped meanwhile is refused.
     */
    public function test_an_edit_after_the_form_was_opened_uses_the_current_version(): void
    {
        $this->send($this->payload(['export_number' => 186]))->assertCreated()->assertJsonPath('data.template.version', 3);

        // Saved by a templates manager while the creator's form stood open.
        $this->actingAsUserWithPermissions(['documents.templates.manage']);
        $this->putJson("/api/documents/templates/{$this->template->id}", [
            'name'             => 'طلب صرف',
            'category_id'      => $this->category->id,
            'reference_prefix' => 'RE',
            'fields'           => [...self::FIELDS, ['key' => 'reason', 'label' => 'السبب', 'type' => 'text', 'required' => true]],
            'body'             => self::PAGE.'<p><span data-placeholder="field.reason">السبب</span></p>',
        ])->assertOk();
        $this->assertSame(4, $this->template->fresh()->version);

        // The values of the form as it was opened miss the new field.
        $this->assertSame('حقل «السبب» مطلوب.', $this->errorOf($this->send($this->payload())->assertStatus(422), 'field_values.reason'));

        $this->send($this->payload(['field_values' => ['subject' => 'صرف', 'reason' => 'نهاية العام']]))
            ->assertCreated()
            ->assertJsonPath('data.template.version', 4)
            ->assertJsonPath('data.field_values.reason', 'نهاية العام');
        $this->assertSame(4, Document::query()->latest('id')->firstOrFail()->template_version);

        $this->template->fresh()->update(['is_active' => false]);

        $this->send($this->payload(['field_values' => ['subject' => 'صرف', 'reason' => 'نهاية العام']]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'القالب المختار غير موجود أو غير مفعّل.');

        $this->assertNothingWritten(2, 187, 2);
    }

    /**
     * Ruling R4: an edit landing between the checks made before the
     * transaction and the transaction itself is caught there — the values
     * are checked again against the template re-read inside it, and the
     * version recorded is that re-read's, whose page is the one printed.
     */
    public function test_an_edit_during_the_send_is_checked_inside_the_transaction(): void
    {
        $this->seedRegister();

        // The pre-check's last read is the used-number check: the edit lands
        // right after it, once, before the transaction re-reads the template.
        $numbers = new class extends ExportNumbers
        {
            public ?\Closure $edit = null;

            public function isUsed(Department $register, int $number): bool
            {
                if ($this->edit !== null) {
                    $edit = $this->edit;
                    $this->edit = null;
                    $edit();
                }

                return parent::isUsed($register, $number);
            }
        };
        $this->app->instance(ExportNumbers::class, $numbers);

        // What was printed, as the printer was handed it.
        $printer = new class(app(Letterhead::class), app(TemplateHtml::class)) extends TemplatePrinter
        {
            /** @var array<int, array{int, string}> */
            public array $printed = [];

            public function print(DocumentTemplate $template, array $fieldValues, SystemValues $system, ?RecipientValues $recipient, PdfSession $session): RenderedTemplate
            {
                $this->printed[] = [$template->version, (string) $template->body];

                return parent::print($template, $fieldValues, $system, $recipient, $session);
            }
        };
        $this->app->instance(TemplatePrinter::class, $printer);

        $numbers->edit = fn () => $this->template->fresh()->update([
            'fields'  => [...self::FIELDS, ['key' => 'reason', 'label' => 'السبب', 'type' => 'text', 'required' => true]],
            'version' => 4,
        ]);

        $refused = $this->send($this->payload(['export_number' => 300]))->assertStatus(422);
        $this->assertSame('حقل «السبب» مطلوب.', $this->errorOf($refused, 'field_values.reason'));
        $this->assertSame([], $printer->printed);

        $this->assertNothingWritten(1, 186, 1);

        $numbers->edit = fn () => $this->template->fresh()->update([
            'body'    => (new TemplateBody)->sanitize('<p>الصفحة الجديدة: <span data-placeholder="field.subject">الموضوع</span></p>'),
            'version' => 5,
        ]);

        $this->send($this->payload(['export_number' => 300, 'field_values' => ['subject' => 'صرف', 'reason' => 'سبب']]))
            ->assertCreated()
            ->assertJsonPath('data.template.version', 5);

        $this->assertSame(5, Document::query()->latest('id')->firstOrFail()->template_version);
        $this->assertCount(1, $printer->printed);
        $this->assertSame(5, $printer->printed[0][0]);
        $this->assertStringContainsString('الصفحة الجديدة', $printer->printed[0][1]);
    }

    public function test_a_print_failure_rolls_the_numbers_back(): void
    {
        $this->seedRegister();

        $this->app->instance(TemplatePrinter::class, new class(app(Letterhead::class), app(TemplateHtml::class)) extends TemplatePrinter
        {
            public function print(DocumentTemplate $template, array $fieldValues, SystemValues $system, ?RecipientValues $recipient, PdfSession $session): RenderedTemplate
            {
                throw new RuntimeException('The printer is down.');
            }
        });

        $this->send($this->payload(['attachments' => [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]]))
            ->assertStatus(500);

        $this->assertNothingWritten(1, 186, 1);
    }

    public function test_a_failed_write_of_the_original_rolls_the_numbers_back(): void
    {
        $this->seedRegister();

        $folder = '3f0c1a52-6b7e-4c1d-9a55-0d1e2f3a4b5c';
        Str::createUuidsUsing(static fn () => Uuid::fromString($folder));

        try {
            // The local disk answers false to a write it cannot make; a
            // directory standing where the file must go makes one.
            Storage::fake('local', ['throw' => false]);
            Storage::disk('local')->makeDirectory("documents/{$folder}/original.pdf");

            $this->send($this->payload())->assertStatus(500);
        } finally {
            Str::createUuidsNormally();
        }

        $this->assertSame(1, Document::withTrashed()->count());
        $this->assertFalse(Storage::disk('local')->exists("documents/{$folder}"), 'the folder is removed');
        $this->assertSame([], $this->filesUnder('pdf-work'));
        $this->assertSame(186, $this->counter());
        $this->assertSame(1, $this->sequence());
    }

    /**
     * Review Focus 3, the race: a number committed by another creator while
     * this one waited. The pre-check and the claim both miss it; the unique
     * index refuses the row, which answers the "used" copy, and the claim
     * rolls back with the transaction.
     */
    public function test_a_unique_violation_on_the_export_number_answers_its_copy(): void
    {
        $this->seedRegister();

        $this->app->instance(ExportNumbers::class, new class extends ExportNumbers
        {
            public function isUsed(Department $register, int $number): bool
            {
                return false;
            }

            public function claim(Department $register, ?int $typed): int
            {
                parent::claim($register, $typed);

                return 186;
            }
        });

        $this->send($this->payload(['attachments' => [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'رقم الصادر 186 مستخدم في سجل هذه الإدارة.');

        $this->assertNothingWritten(1, 186, 1);
    }

    /**
     * The violation is told apart by the index the driver names, on SQLite
     * and on MySQL alike; a violation of any other index is no number's.
     */
    public function test_a_violation_is_told_apart_by_its_index(): void
    {
        $this->seedRegister();
        $violation = null;

        Document::creating(static function () use (&$violation): void {
            if ($violation !== null) {
                throw $violation;
            }
        });

        foreach ([
            ['sqlite', 'UNIQUE constraint failed: documents.register_unit_id, documents.export_number', 'رقم الصادر 187 مستخدم في سجل هذه الإدارة.'],
            ['mysql', "Duplicate entry '2-187' for key 'documents.documents_register_unit_id_export_number_unique'", 'رقم الصادر 187 مستخدم في سجل هذه الإدارة.'],
            ['sqlite', 'UNIQUE constraint failed: documents.register_unit_id, documents.live_import_number', 'رقم الوارد 55 مستخدم في سجل هذه الإدارة.'],
            ['mysql', "Duplicate entry '2-55' for key 'documents.documents_register_unit_id_live_import_number_unique'", 'رقم الوارد 55 مستخدم في سجل هذه الإدارة.'],
            ['sqlite', 'UNIQUE constraint failed: documents.reference', null],
            ['mysql', "Duplicate entry 'RE-2026-0002' for key 'documents.documents_reference_unique'", null],
        ] as [$driver, $detail, $copy]) {
            $pdo = new PDOException("SQLSTATE[23000]: Integrity constraint violation: {$detail}");
            $pdo->errorInfo = ['23000', $driver === 'mysql' ? 1062 : 19, $detail];
            $violation = new UniqueConstraintViolationException($driver, 'insert into documents', [], $pdo);

            $response = $this->send($this->payload(['import_number' => 55]));

            if ($copy === null) {
                $response->assertStatus(500);
            } else {
                $response->assertStatus(422)->assertJsonPath('message', $copy);
            }

            $this->assertNothingWritten(1, 186, 1);
        }
    }

    public function test_cards_print_above_the_footer(): void
    {
        app(Letterhead::class)->replace(LetterheadPart::Footer, UploadedFile::fake()->image('footer.png', 1200, 120), $this->accounts['gm']);

        $this->send($this->payload(['export_number' => 186]))->assertCreated();
        $framed = Document::query()->latest('id')->firstOrFail();
        $this->assertSame(32, $framed->card_floor);

        $plain = $this->makeTemplate(['name' => 'بلا ترويسة', 'show_letterhead' => false]);
        $this->send($this->payload(['template_id' => $plain->id]))->assertCreated();
        $this->assertNull(Document::query()->latest('id')->firstOrFail()->card_floor);

        // The printer honours the stored floor: 16 cards are four rows, 115 mm
        // high. Their top is 150 mm down an A4 page above a 32 mm floor, over
        // the 55% line (163.35 mm), so they take a new page; above 6 mm it is
        // 176 mm, and they stay.
        $unit = $this->division;
        $stamp = app(UnitStamps::class)->replace($unit, UploadedFile::fake()->image('stamp.png', 300, 300), $this->accounts['c']);
        $signature = app(Signatures::class)->draw($this->people['c'], UploadedFile::fake()->image('s.png', 600, 200), SignatureSource::App, $this->accounts['c']);

        foreach (range(1, 16) as $position) {
            $framed->marks()->create([
                'position'      => $position,
                'kind'          => MarkKind::ApprovalCard,
                'employee_id'   => $this->people['c']->id,
                'employee_name' => 'محمد الأحمد',
                'unit_id'       => $unit->id,
                'unit_name'     => $unit->name,
                'stamp_id'      => $stamp->id,
                'signature_id'  => $signature->id,
                'decisive'      => false,
                'confirmed_by'  => Confirmation::Password,
                'marked_at'     => now(),
            ]);
        }

        $pages = $this->pagesOf((string) $framed->original_path);
        $this->assertSame($pages + 1, $this->pagesOf(app(DocumentPrinter::class)->print($framed->fresh())));

        $framed->forceFill(['card_floor' => null])->save();
        $this->assertSame($pages, $this->pagesOf(app(DocumentPrinter::class)->print($framed->fresh())));
    }

    /**
     * Ruling R17: the document stores where its page's text ends, and the
     * printed copy keeps the cards off it. Text down to the foot of its last
     * page sends two cards to a new page above a 32 mm floor; short text
     * keeps them on the page.
     */
    public function test_cards_never_cover_the_pages_own_text(): void
    {
        app(Letterhead::class)->replace(LetterheadPart::Footer, UploadedFile::fake()->image('footer.png', 1200, 120), $this->accounts['gm']);

        $long = $this->makeTemplate([
            'name' => 'نص طويل',
            'body' => (new TemplateBody)->sanitize(str_repeat('<p>سطر من النص</p>', 21).'<p><span data-placeholder="field.subject">الموضوع</span></p>'),
        ]);
        $this->send($this->payload(['template_id' => $long->id, 'export_number' => 186]))->assertCreated();
        $low = Document::query()->latest('id')->firstOrFail();

        $this->send($this->payload())->assertCreated();
        $high = Document::query()->latest('id')->firstOrFail();

        // One row of cards over the 32 mm floor: its top is 240 mm down.
        $this->assertSame(32, $low->card_floor);
        $this->assertGreaterThan(240, $low->content_bottom, 'the text ends low on its page');
        $this->assertLessThan(100, $high->content_bottom, 'the text ends high on its page');

        $stamp = app(UnitStamps::class)->replace($this->division, UploadedFile::fake()->image('stamp.png', 300, 300), $this->accounts['c']);
        $signature = app(Signatures::class)->draw($this->people['c'], UploadedFile::fake()->image('s.png', 600, 200), SignatureSource::App, $this->accounts['c']);

        foreach ([$low, $high] as $document) {
            foreach ([1, 2] as $position) {
                $document->marks()->create([
                    'position'      => $position,
                    'kind'          => MarkKind::ApprovalCard,
                    'employee_id'   => $this->people['c']->id,
                    'employee_name' => 'محمد الأحمد',
                    'unit_id'       => $this->division->id,
                    'unit_name'     => $this->division->name,
                    'stamp_id'      => $stamp->id,
                    'signature_id'  => $signature->id,
                    'decisive'      => $position === 2,
                    'confirmed_by'  => Confirmation::Password,
                    'marked_at'     => now(),
                ]);
            }
        }

        $pages = $this->pagesOf((string) $low->original_path);
        $this->assertSame($pages + 1, $this->pagesOf(app(DocumentPrinter::class)->print($low->fresh())));

        $pages = $this->pagesOf((string) $high->original_path);
        $this->assertSame($pages, $this->pagesOf(app(DocumentPrinter::class)->print($high->fresh())));
    }
}
