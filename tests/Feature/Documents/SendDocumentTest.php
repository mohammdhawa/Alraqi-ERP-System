<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Modules\Approvals\Models\Approval;
use App\Modules\Auth\Models\Notification;
use App\Modules\Auth\Models\User;
use App\Modules\Departments\Models\Department;
use App\Modules\Documents\Enums\DocumentKind;
use App\Modules\Documents\Enums\DocumentOrigin;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentCounter;
use App\Modules\Documents\Models\DocumentReferenceSequence;
use App\Modules\Documents\Models\DocumentTemplate;
use App\Modules\Documents\Models\DocumentTemplateCategory;
use App\Modules\Documents\Pdf\PdfSession;
use App\Modules\Documents\Pdf\RenderedTemplate;
use App\Modules\Documents\Pdf\TemplateHtml;
use App\Modules\Documents\Pdf\TemplatePrinter;
use App\Modules\Documents\Services\Letterhead;
use App\Modules\Documents\Support\RecipientValues;
use App\Modules\Documents\Support\SystemValues;
use App\Modules\Documents\Templates\TemplateBody;
use App\Modules\HR\Enums\EmployeeDocumentType;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\JobTitle;
use App\Shared\Support\NotificationEventCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Normalizer;
use RuntimeException;
use Tests\Support\Pdfs;
use Tests\TestCase;
use Throwable;

/**
 * Sending a document to one employee (documents to employees, Task 2;
 * documents design §1.4, §9): one of HR's managers sends an upload or a
 * `for_employees` template, with no approval at all. The document waits for
 * the recipient's signature, and the recipient is told once it is committed.
 * A refusal or a failure leaves no row, no file and no number.
 */
class SendDocumentTest extends TestCase
{
    use RefreshDatabase;

    private const FIELDS = [
        ['key' => 'subject', 'label' => 'الموضوع', 'type' => 'text', 'required' => true],
    ];

    private const PAGE = '<p>إلى: <span data-placeholder="recipient.name">اسم الموظف</span>'
        .' — الرقم الوظيفي: <span data-placeholder="recipient.employee_number">الرقم الوظيفي</span></p>'
        .'<p><span data-placeholder="recipient.job_title">المسمى الوظيفي</span>'
        .' — <span data-placeholder="recipient.unit">الوحدة</span></p>'
        .'<p>رقم الصادر: <span data-placeholder="system.export_number">رقم الصادر</span>'
        .' — المرجع: <span data-placeholder="system.reference">المرجع</span></p>'
        .'<p><span data-placeholder="field.subject">الموضوع</span></p>';

    private const COLD = 'هذا أول مستند صادر في الإدارة، يرجى إدخال رقم الصادر لتحديد نقطة البداية.';

    private const NOT_HR = 'لا يرسل المستندات إلى الموظفين إلا مديرو الموارد البشرية.';

    /** @var array<string, Employee> */
    private array $people = [];

    /** @var array<string, User> */
    private array $accounts = [];

    private Department $root;

    private Department $hr;

    private Department $hrSection;

    private Department $finance;

    private DocumentTemplateCategory $category;

    private DocumentTemplate $template;

    /** While true, the in-app notification's insert throws (`breakTheInbox()`). */
    private bool $inboxDown = false;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->root =Department::create(['name' => 'الإدارة العامة', 'level' => 1]);
        $this->hr = Department::create([
            'name'      => 'إدارة الموارد البشرية',
            'code'      => config('leave.hr_unit_code'),
            'level'     => 2,
            'parent_id' => $this->root->id,
            'sector'    => 1,
        ]);
        $this->hrSection = Department::create(['name' => 'قسم شؤون الموظفين', 'level' => 3, 'parent_id' => $this->hr->id]);
        $this->finance = Department::create(['name' => 'إدارة المالية', 'level' => 2, 'parent_id' => $this->root->id, 'sector' => 1]);

        $this->manage('gm', $this->root);
        $this->manage('hr', $this->hr);
        $this->manage('section', $this->hrSection);
        $this->manage('finance', $this->finance);

        $this->person('e', Employee::factory()->named('سامي خالد الحداد')->create([
            'department_id' => $this->finance->id,
            'job_title_id'  => JobTitle::create(['name' => 'محاسب'])->id,
        ]));
        $this->person('plain');

        $this->category = DocumentTemplateCategory::create(['name' => 'شؤون الموظفين', 'sort' => 1]);
        $this->template = $this->makeTemplate();
    }

    private function person(string $key, ?Employee $employee = null): Employee
    {
        $employee ??= Employee::factory()->create();
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
            'name'             => 'شهادة عمل',
            'reference_prefix' => 'EM',
            'for_employees'    => true,
            'fields'           => self::FIELDS,
            'body'             => (new TemplateBody)->sanitize(self::PAGE),
            'show_letterhead'  => true,
            'version'          => 2,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function upload(array $overrides = []): array
    {
        return array_merge([
            'title'        => 'إشعار بالدوام',
            'recipient_id' => $this->people['e']->id,
            'filing_type'  => EmployeeDocumentType::WorkCertificate->value,
            'file'         => UploadedFile::fake()->createWithContent('letter.pdf', Pdfs::plain()),
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function fromTemplate(array $overrides = []): array
    {
        return array_merge([
            'title'        => 'شهادة عمل',
            'recipient_id' => $this->people['e']->id,
            'filing_type'  => EmployeeDocumentType::AppointmentDecision->value,
            'template_id'  => $this->template->id,
            'field_values' => ['subject' => 'بناءً على طلبه'],
        ], $overrides);
    }

    private function send(array $payload, string $as = 'hr'): TestResponse
    {
        Sanctum::actingAs($this->accounts[$as]);

        return $this->post('/api/documents/to-employee', $payload, ['Accept' => 'application/json']);
    }

    private function counter(Department $register): ?int
    {
        $value = DocumentCounter::query()->where('unit_id', $register->id)->value('current_value');

        return $value === null ? null : (int) $value;
    }

    private function sequence(string $prefix = 'EM'): ?int
    {
        $value = DocumentReferenceSequence::query()->where('prefix', $prefix)->where('year', now()->year)->value('current_value');

        return $value === null ? null : (int) $value;
    }

    /**
     * @return array<int, string>
     */
    private function filesUnder(string $directory): array
    {
        return Storage::disk('local')->allFiles($directory);
    }

    /** Everything a refusal must leave as it found it. */
    private function assertNothingWritten(int $documents = 0, ?int $counter = null, ?int $sequence = null): void
    {
        $this->assertSame($documents, Document::withTrashed()->count());
        $this->assertCount($documents, $this->filesUnder('documents'));
        $this->assertCount($documents, Storage::disk('local')->directories('documents'), 'one folder per document, no empty one left');
        $this->assertSame([], $this->filesUnder('pdf-work'));
        $this->assertSame($counter, $this->counter($this->hr));
        $this->assertSame($sequence, $this->sequence());
        $this->assertSame($documents, Notification::query()->count());
        $this->assertSame(0, Approval::query()->count());
    }

    /** A printer that prints, and records what it was handed. */
    private function recordingPrinter(): TemplatePrinter
    {
        $printer = new class(app(Letterhead::class), app(TemplateHtml::class)) extends TemplatePrinter
        {
            /** @var array<int, RecipientValues|null> */
            public array $recipients = [];

            /** @var array<int, SystemValues> */
            public array $systems = [];

            public function print(DocumentTemplate $template, array $fieldValues, SystemValues $system, ?RecipientValues $recipient, PdfSession $session): RenderedTemplate
            {
                $this->recipients[] = $recipient;
                $this->systems[] = $system;

                return parent::print($template, $fieldValues, $system, $recipient, $session);
            }
        };

        $this->app->instance(TemplatePrinter::class, $printer);

        return $printer;
    }

    /**
     * From now on, while `inboxDown` holds, the in-app notification's insert
     * throws, as a lost connection or a full disk would make it.
     */
    private function breakTheInbox(): void
    {
        $this->inboxDown = true;

        DB::connection()->beforeExecuting(function (string $query): void {
            if ($this->inboxDown && preg_match('/^insert into [`"]?notifications\b/i', $query) === 1) {
                throw new RuntimeException('The notification could not be written.');
            }
        });
    }

    private function pdftotextAvailable(): bool
    {
        try {
            $probe = Process::run(['pdftotext', '-v']);

            return str_contains($probe->output().$probe->errorOutput(), 'pdftotext version');
        } catch (Throwable) {
            return false;
        }
    }

    /** The original's text, presentation forms back to letters and the extractor's direction marks out. */
    private function textOf(Document $document): string
    {
        $text = Process::run(['pdftotext', '-enc', 'UTF-8', Storage::disk('local')->path((string) $document->original_path), '-'])->throw()->output();

        return (string) preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', (string) Normalizer::normalize($text, Normalizer::FORM_KC));
    }

    public function test_an_hr_manager_sends_an_upload_to_an_employee(): void
    {
        $response = $this->send($this->upload(['description' => 'يرجى الاطلاع والتوقيع']));

        $response->assertCreated()->assertJsonPath('message', 'تم إرسال المستند إلى الموظف.');

        $document = Document::query()->firstOrFail();
        $this->assertSame(DocumentKind::ToEmployee, $document->kind);
        $this->assertSame(DocumentOrigin::Uploaded, $document->origin);
        $this->assertSame(DocumentStatus::AwaitingSignature, $document->status);
        $this->assertNotNull($document->sent_at);
        $this->assertSame($this->people['e']->id, $document->recipient_id);
        $this->assertSame(EmployeeDocumentType::WorkCertificate, $document->filing_type);
        $this->assertSame('إشعار بالدوام', $document->title);
        $this->assertSame('يرجى الاطلاع والتوقيع', $document->description);
        $this->assertSame($this->people['hr']->id, $document->creator_id);
        $this->assertSame($this->hr->id, $document->creator_unit_id);
        $this->assertSame($this->hr->id, $document->register_unit_id);
        $this->assertSame($this->accounts['hr']->id, $document->created_by);
        $this->assertNull($document->import_number);
        $this->assertNull($document->export_number);
        $this->assertNull($document->reference);
        $this->assertNull($document->template_id);
        $this->assertNull($document->uploaded_path);
        $this->assertSame("documents/{$document->folder}/original.pdf", $document->original_path);
        $this->assertStringStartsWith('%PDF', (string) Storage::disk('local')->get($document->original_path));
        $this->assertSame([], $this->filesUnder('pdf-work'));

        // Workflow 1 never touches the engine.
        $this->assertNull($document->approval);
        $this->assertSame(0, Approval::query()->count());

        // The recipient is told, and no one else.
        $this->assertSame(1, Notification::query()->count());
        $notification = Notification::query()->where('user_id', $this->accounts['e']->id)->firstOrFail();
        $this->assertSame('مستند جديد بانتظار توقيعك', $notification->title);
        $this->assertSame("أرسل إليك {$this->people['hr']->full_name} المستند «إشعار بالدوام».", $notification->body);
        $this->assertSame(NotificationEventCatalogue::DOCUMENT_AWAITING_SIGNATURE, $notification->type);
        $this->assertSame('مستند بانتظار توقيع موظف', NotificationEventCatalogue::labelOf($notification->type));
        $this->assertSame('document', $notification->reference_type);
        $this->assertSame($document->id, (int) $notification->reference_id);

        $response->assertJsonPath('data.id', $document->id)
            ->assertJsonPath('data.kind', DocumentKind::ToEmployee->value)
            ->assertJsonPath('data.origin', DocumentOrigin::Uploaded->value)
            ->assertJsonPath('data.status.value', DocumentStatus::AwaitingSignature->value)
            ->assertJsonPath('data.creator.id', $this->people['hr']->id)
            ->assertJsonPath('data.creator_unit.id', $this->hr->id)
            ->assertJsonCount(0, 'data.steps')
            ->assertJsonPath('data.can.decide', false);
    }

    public function test_an_hr_manager_sends_a_template_with_the_recipients_chips(): void
    {
        $printer = $this->recordingPrinter();
        $year = now()->year;

        // A section under HR numbers from HR's register, cold until its first number.
        $this->send($this->fromTemplate(), 'section')
            ->assertStatus(422)
            ->assertJsonPath('message', self::COLD);
        $this->assertNothingWritten();

        $response = $this->send($this->fromTemplate(['export_number' => 500]), 'section');

        $response->assertCreated()->assertJsonPath('message', 'تم إرسال المستند إلى الموظف.');

        $document = Document::query()->firstOrFail();
        $this->assertSame(DocumentKind::ToEmployee, $document->kind);
        $this->assertSame(DocumentOrigin::Template, $document->origin);
        $this->assertSame(DocumentStatus::AwaitingSignature, $document->status);
        $this->assertNotNull($document->sent_at);
        $this->assertSame(EmployeeDocumentType::AppointmentDecision, $document->filing_type);
        $this->assertSame($this->people['section']->id, $document->creator_id);
        $this->assertSame($this->hrSection->id, $document->creator_unit_id);
        $this->assertSame($this->hr->id, $document->register_unit_id, "the sender's إدارة keeps the register");
        $this->assertSame($this->template->id, $document->template_id);
        $this->assertSame(2, $document->template_version);
        $this->assertSame(['subject' => 'بناءً على طلبه'], $document->field_values);
        $this->assertSame(500, $document->export_number);
        $this->assertSame("EM-{$year}-0001", $document->reference);
        $this->assertNull($document->import_number);
        $this->assertNull($document->card_floor, 'no footer image is uploaded');
        $this->assertNotNull($document->content_bottom);
        $this->assertSame("documents/{$document->folder}/original.pdf", $document->original_path);
        $this->assertStringStartsWith('%PDF', (string) Storage::disk('local')->get($document->original_path));
        $this->assertNull($document->approval);

        $this->assertSame(500, $this->counter($this->hr));
        $this->assertNull($this->counter($this->hrSection));
        $this->assertNull($this->counter($this->finance));
        $this->assertSame(1, $this->sequence());

        // The recipient's chips and the sender's numbers, as the printer was handed them.
        $e = $this->people['e'];
        $this->assertEquals(new RecipientValues('سامي خالد الحداد', 'محاسب', 'إدارة المالية', (string) $e->employee_number), $printer->recipients[0]);
        $this->assertSame(500, $printer->systems[0]->exportNumber);
        $this->assertSame("EM-{$year}-0001", $printer->systems[0]->reference);
        $this->assertNull($printer->systems[0]->importNumber);
        $this->assertSame($this->people['section']->full_name, $printer->systems[0]->creatorName);
        $this->assertSame('قسم شؤون الموظفين', $printer->systems[0]->creatorUnit);

        $response->assertJsonPath('data.export_number', 500)
            ->assertJsonPath('data.reference', "EM-{$year}-0001")
            ->assertJsonPath('data.template.id', $this->template->id)
            ->assertJsonPath('data.field_values.subject', 'بناءً على طلبه');

        // The next takes the register's next number, whoever of HR sends it.
        $this->send($this->fromTemplate())->assertCreated()
            ->assertJsonPath('data.export_number', 501)
            ->assertJsonPath('data.reference', "EM-{$year}-0002");
        $this->assertSame($this->hr->id, Document::query()->latest('id')->firstOrFail()->register_unit_id);

        if (! $this->pdftotextAvailable()) {
            $this->markTestSkipped('pdftotext is not installed here; the printed text is not read back.');
        }

        $text = $this->textOf($document);

        foreach (['سامي', 'خالد', 'الحداد', 'محاسب'] as $word) {
            $this->assertStringContainsString($word, $text);
        }
        $this->assertMatchesRegularExpression('/(?<!\d)'.$e->employee_number.'(?!\d)/', $text);
        $this->assertMatchesRegularExpression('/(?<!\d)500(?!\d)/', $text);
        $this->assertStringContainsString("EM-{$year}-0001", $text);
    }

    /** Review Focus 4: the chips the recipient cannot fill print empty, and the document goes. */
    public function test_a_recipient_with_no_job_title_or_unit_still_receives_a_template(): void
    {
        $printer = $this->recordingPrinter();
        $bare = $this->person('bare', Employee::factory()->named('ليلى عمر')->create());
        $this->assertNull($bare->department_id);
        $this->assertNull($bare->job_title_id);

        $this->send($this->fromTemplate(['recipient_id' => $bare->id, 'export_number' => 10]))->assertCreated();

        $document = Document::query()->firstOrFail();
        $this->assertSame($bare->id, $document->recipient_id);
        $this->assertSame(DocumentStatus::AwaitingSignature, $document->status);
        $this->assertEquals(new RecipientValues('ليلى عمر', '', '', (string) $bare->employee_number), $printer->recipients[0]);
        $this->assertSame(1, Notification::query()->where('user_id', $this->accounts['bare']->id)->count());

        if (! $this->pdftotextAvailable()) {
            $this->markTestSkipped('pdftotext is not installed here; the printed text is not read back.');
        }

        $text = $this->textOf($document);
        $this->assertStringContainsString('ليلى', $text);
        $this->assertMatchesRegularExpression('/(?<!\d)'.$bare->employee_number.'(?!\d)/', $text);

        // The chips the recipient cannot fill print nothing, not their labels
        // («المسمى الوظيفي», «الوحدة»); the page's own words read back, so
        // their absence is the page's and not the extractor's.
        $this->assertStringNotContainsString('المسمى', $text);
        $this->assertStringNotContainsString('الوحدة', $text);
        $this->assertStringContainsString('المرجع', $text);
    }

    public function test_only_hr_managers_send(): void
    {
        foreach (['finance', 'gm', 'plain', 'e'] as $who) {
            $this->send($this->upload(['recipient_id' => $this->people['plain']->id]), $who)
                ->assertForbidden()
                ->assertJsonPath('message', self::NOT_HR);

            $this->send($this->fromTemplate(['recipient_id' => $this->people['plain']->id, 'export_number' => 5]), $who)
                ->assertForbidden()
                ->assertJsonPath('message', self::NOT_HR);
        }

        // The refusal of the sender comes before the recipient's.
        $this->send($this->upload(['recipient_id' => 999999]), 'finance')
            ->assertForbidden()
            ->assertJsonPath('message', self::NOT_HR);

        $this->assertNothingWritten();
        $this->assertNull($this->counter($this->finance));

        // With no HR unit, nobody sends.
        $this->hr->update(['code' => 'OTHER']);

        $this->send($this->upload())->assertForbidden()->assertJsonPath('message', self::NOT_HR);
        $this->send($this->upload(), 'section')->assertForbidden()->assertJsonPath('message', self::NOT_HR);

        $this->assertNothingWritten();
    }

    public function test_the_recipient_rules(): void
    {
        $payload = $this->upload();
        unset($payload['recipient_id']);

        $this->send($payload)
            ->assertStatus(422)
            ->assertJsonPath('errors.recipient_id.0', 'الموظف المستلم مطلوب.');

        $this->send($this->upload(['recipient_id' => 'أحد']))
            ->assertStatus(422)
            ->assertJsonPath('errors.recipient_id.0', 'الموظف المحدد غير موجود.');

        $this->send($this->upload(['recipient_id' => 999999]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'الموظف المحدد غير موجود.');

        $this->send($this->upload(['recipient_id' => $this->people['hr']->id]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'لا يمكنك إرسال مستند إلى نفسك.');

        foreach ([EmployeeStatus::Suspended, EmployeeStatus::Terminated] as $status) {
            $away = $this->person("away-{$status->value}");
            $away->update(['status' => $status]);

            $this->send($this->upload(['recipient_id' => $away->id]))
                ->assertStatus(422)
                ->assertJsonPath('message', "لا يمكن إرسال مستند إلى «{$away->full_name}» لأنه ليس على رأس عمله.");
        }

        $unlinked = Employee::factory()->create();
        $this->send($this->upload(['recipient_id' => $unlinked->id]))
            ->assertStatus(422)
            ->assertJsonPath('message', "لا يمكن إرسال مستند إلى «{$unlinked->full_name}» لأنه لا يملك حساباً فعّالاً.");

        $disabled = $this->person('disabled');
        $this->accounts['disabled']->update(['is_active' => false]);
        $this->send($this->upload(['recipient_id' => $disabled->id]))
            ->assertStatus(422)
            ->assertJsonPath('message', "لا يمكن إرسال مستند إلى «{$disabled->full_name}» لأنه لا يملك حساباً فعّالاً.");

        $gone = $this->person('gone');
        $gone->delete();
        $this->send($this->upload(['recipient_id' => $gone->id]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'الموظف المحدد غير موجود.');

        // A template's refusal of the recipient spends no number.
        $this->send($this->fromTemplate(['recipient_id' => $unlinked->id, 'export_number' => 5]))
            ->assertStatus(422)
            ->assertJsonPath('message', "لا يمكن إرسال مستند إلى «{$unlinked->full_name}» لأنه لا يملك حساباً فعّالاً.");

        $this->assertNothingWritten();
    }

    public function test_the_filing_type_must_be_one_offered(): void
    {
        $payload = $this->upload();
        unset($payload['filing_type']);

        $this->send($payload)
            ->assertStatus(422)
            ->assertJsonPath('errors.filing_type.0', 'نوع الحفظ في ملف الموظف مطلوب.');

        foreach ([EmployeeDocumentType::ShamCash->value, EmployeeDocumentType::IdFront->value, 99, 'عقد'] as $type) {
            $this->send($this->upload(['filing_type' => $type]))
                ->assertStatus(422)
                ->assertJsonPath('errors.filing_type.0', 'نوع الحفظ غير صالح.');
        }

        $this->assertNothingWritten();

        foreach (EmployeeDocumentType::forSentDocuments() as $type) {
            $this->send($this->upload(['filing_type' => $type->value]))->assertCreated();

            $this->assertSame($type, Document::query()->latest('id')->firstOrFail()->filing_type);
        }

        $this->assertSame([7, 8, 9, 10, 11, 12], Document::query()->orderBy('id')->pluck('filing_type')->map->value->all());
    }

    public function test_a_template_not_for_employees_is_refused(): void
    {
        $between = $this->makeTemplate(['name' => 'مذكرة داخلية', 'for_employees' => false]);
        $inactive = $this->makeTemplate(['name' => 'موقوف', 'is_active' => false]);
        $deleted = $this->makeTemplate(['name' => 'محذوف']);
        $deleted->delete();

        $this->send($this->fromTemplate(['template_id' => $between->id, 'export_number' => 5]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'هذا القالب ليس للمستندات المرسلة إلى الموظفين.');

        foreach ([$inactive->id, $deleted->id, 999999] as $id) {
            $this->send($this->fromTemplate(['template_id' => $id, 'export_number' => 5]))
                ->assertStatus(422)
                ->assertJsonPath('message', 'القالب المختار غير موجود أو غير مفعّل.');
        }

        // The values are checked against the template, field by field.
        $refused = $this->send($this->fromTemplate(['field_values' => [], 'export_number' => 5]))->assertStatus(422);
        $this->assertSame('حقل «الموضوع» مطلوب.', $refused->json('errors')['field_values.subject'][0] ?? null);

        $this->assertNothingWritten();
    }

    public function test_a_used_export_number_is_refused(): void
    {
        $this->send($this->fromTemplate(['export_number' => 186]))->assertCreated();

        $this->send($this->fromTemplate(['export_number' => 186]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'رقم الصادر 186 مستخدم في سجل هذه الإدارة.');

        $this->assertNothingWritten(1, 186, 1);
    }

    public function test_approvers_import_number_and_attachments_are_refused(): void
    {
        $this->send($this->upload(['approvers' => [$this->people['finance']->id]]))
            ->assertStatus(422)
            ->assertJsonPath('errors.approvers.0', 'لا يُحدَّد معتمدون لمستند يُرسل إلى موظف.');

        $this->send($this->upload(['import_number' => 12]))
            ->assertStatus(422)
            ->assertJsonPath('errors.import_number.0', 'لا يُحدَّد رقم وارد لمستند يُرسل إلى موظف.');

        $this->send($this->upload(['attachments' => [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]]))
            ->assertStatus(422)
            ->assertJsonPath('errors.attachments.0', 'لا تُرفق ملفات داعمة بمستند يُرسل إلى موظف.');

        $this->send($this->fromTemplate(['export_number' => 5, 'import_number' => 12]))
            ->assertStatus(422)
            ->assertJsonPath('errors.import_number.0', 'لا يُحدَّد رقم وارد لمستند يُرسل إلى موظف.');

        $this->assertNothingWritten();
        $this->assertSame([], $this->filesUnder('attachments'));
    }

    /** The document's own fields answer in pieces 2–3's words. */
    public function test_the_document_is_validated_as_pieces_2_and_3_validate_it(): void
    {
        $this->send($this->upload(['title' => '']))
            ->assertStatus(422)->assertJsonPath('errors.title.0', 'عنوان المستند مطلوب.');

        $this->send($this->upload(['file' => null]))
            ->assertStatus(422)->assertJsonPath('errors.file.0', 'الملف مطلوب.');

        $this->send($this->fromTemplate(['export_number' => 5, 'file' => UploadedFile::fake()->createWithContent('letter.pdf', Pdfs::plain())]))
            ->assertStatus(422)->assertJsonPath('errors.template_id.0', 'أرفق ملفاً أو اختر قالباً، لا كليهما.');

        $this->send($this->upload(['export_number' => 5]))
            ->assertStatus(422)->assertJsonPath('errors.export_number.0', 'رقم الصادر للمستندات المنشأة من قالب فقط.');

        $this->send($this->fromTemplate(['export_number' => 0]))
            ->assertStatus(422)->assertJsonPath('errors.export_number.0', 'رقم الصادر يجب أن يكون بين 1 و 999999999.');

        $this->send($this->fromTemplate(['export_number' => 5, 'field_values' => 'نص']))
            ->assertStatus(422)->assertJsonPath('errors.field_values.0', 'بيانات المستند غير صالحة.');

        $this->send($this->upload(['file' => UploadedFile::fake()->create('letter.docx', 20, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')]))
            ->assertStatus(422)->assertJsonPath('message', 'يُرجى رفع الملف بصيغة PDF أو صورة.');

        $this->assertNothingWritten();
    }

    public function test_a_failure_leaves_nothing(): void
    {
        $this->send($this->fromTemplate(['export_number' => 186]))->assertCreated();

        // The row refused after the page was printed and stored and its
        // numbers claimed: the folder, the number and the reference all go.
        $refuse = true;
        $stored = false;
        Document::creating(static function (Document $document) use (&$refuse, &$stored): void {
            if ($refuse) {
                $stored = Storage::disk('local')->exists((string) $document->original_path);

                throw new RuntimeException('The database is down.');
            }
        });

        $this->send($this->fromTemplate())->assertStatus(500);

        $this->assertTrue($stored, 'the original was stored before the row was refused');
        $this->assertNothingWritten(1, 186, 1);

        // The printer failing: nothing was stored, and the numbers roll back.
        $refuse = false;

        $this->app->instance(TemplatePrinter::class, new class(app(Letterhead::class), app(TemplateHtml::class)) extends TemplatePrinter
        {
            public function print(DocumentTemplate $template, array $fieldValues, SystemValues $system, ?RecipientValues $recipient, PdfSession $session): RenderedTemplate
            {
                throw new RuntimeException('The printer is down.');
            }
        });

        $this->send($this->fromTemplate())->assertStatus(500);

        $this->assertNothingWritten(1, 186, 1);
    }

    /**
     * Ruling R1: an upload is stored before the transaction; a throw inside it
     * removes the folder all the same.
     */
    public function test_a_failure_after_an_upload_is_stored_leaves_no_folder(): void
    {
        $stored = false;
        Document::creating(static function (Document $document) use (&$stored): void {
            $stored = Storage::disk('local')->exists((string) $document->original_path);

            throw new RuntimeException('The database is down.');
        });

        $this->send($this->upload())->assertStatus(500);

        $this->assertTrue($stored, 'the upload was stored before the row was refused');
        $this->assertNothingWritten();
    }

    /**
     * The recipient's inbox row is written in the sending's transaction: when
     * it fails, nothing was sent — no document, no folder, no number — and the
     * answer is the error, so a retry sends one document, not two.
     */
    public function test_a_failed_notification_sends_nothing(): void
    {
        $year = now()->year;
        $this->send($this->fromTemplate(['export_number' => 186]))->assertCreated();

        $this->breakTheInbox();

        $this->send($this->fromTemplate())->assertStatus(500);

        $this->assertNothingWritten(1, 186, 1);

        // Nothing was spent: the retry takes the numbers the failure gave back.
        $this->inboxDown = false;

        $this->send($this->fromTemplate())->assertCreated()
            ->assertJsonPath('data.export_number', 187)
            ->assertJsonPath('data.reference', "EM-{$year}-0002");
        $this->assertSame(2, Document::query()->count());
        $this->assertSame(2, Notification::query()->where('user_id', $this->accounts['e']->id)->count());
    }

    /** Ruling R1's upload, stored before the transaction, goes with it all the same. */
    public function test_a_failed_notification_after_an_upload_leaves_no_folder(): void
    {
        $this->breakTheInbox();

        $this->send($this->upload())->assertStatus(500);

        $this->assertNothingWritten();
    }
}
