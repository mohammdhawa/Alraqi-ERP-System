<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Modules\Auth\Models\Notification;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Departments\Models\Department;
use App\Modules\Documents\Enums\Confirmation;
use App\Modules\Documents\Enums\DocumentKind;
use App\Modules\Documents\Enums\DocumentOrigin;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Enums\MarkKind;
use App\Modules\Documents\Enums\SignatureSource;
use App\Modules\Documents\Exceptions\DocumentDecisionException;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentMark;
use App\Modules\Documents\Models\EmployeeSignature;
use App\Modules\Documents\Pdf\CardLayout;
use App\Modules\Documents\Pdf\DocumentPrinter;
use App\Modules\Documents\Pdf\PdfWorkspace;
use App\Modules\Documents\Services\DocumentAnswers;
use App\Modules\Documents\Services\DocumentDeletion;
use App\Modules\Documents\Services\Signatures;
use App\Modules\Documents\Services\UnitStamps;
use App\Modules\HR\Enums\EmployeeDocumentType;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeDocument;
use App\Shared\Support\NotificationEventCatalogue;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Support\Pdfs;
use Tests\TestCase;

/**
 * The employee's answer to a document sent to them (documents to employees,
 * Task 6; documents design §9.2, §10.3): «قرأت ووافقت», their drawn signature
 * printed on it, or a refusal with a reason. Each is confirmed and final,
 * files the document on their record and tells the sender. Only the
 * recipient answers, the system administrator included; a second tap, a
 * second phone or a deletion in between finds the row locked and its answer
 * already given.
 */
class AnswerDocumentTest extends TestCase
{
    use RefreshDatabase;

    private const TITLE = 'إشعار بالدوام';

    private const NOT_AWAITING = 'هذا المستند ليس بانتظار توقيعك.';

    private const REASON_COPY = 'سبب الرفض مطلوب، من 3 إلى 500 حرف.';

    private const ANSWERED = 'لا يمكن حذف مستند بعد أن أجاب عنه الموظف.';

    private const GONE = 'حُذف هذا المستند.';

    private const FORBIDDEN = 'ليس لديك صلاحية لتنفيذ هذا الإجراء.';

    private const NO_SIGNATURE = 'لم ترسم توقيعك بعد. ارسمه ثم أعد المحاولة.';

    /** @var array<string, Employee> */
    private array $people = [];

    /** @var array<string, User> */
    private array $accounts = [];

    /** While true, the in-app notification's insert throws (`breakTheInbox()`). */
    private bool $inboxDown = false;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $root = Department::create(['name' => 'الإدارة العامة', 'level' => 1]);
        $hr = Department::create([
            'name'      => 'إدارة الموارد البشرية',
            'code'      => config('leave.hr_unit_code'),
            'level'     => 2,
            'parent_id' => $root->id,
            'sector'    => 1,
        ]);
        $hr->update(['manager_id' => $this->person('hr')->id]);

        $this->person('e');
        $this->person('other');
        $this->person('reader');

        $this->seedRbac();
        $role = Role::create(['name' => 'documents-reader']);
        $role->permissions()->sync(Permission::whereIn('name', ['documents.view'])->pluck('id'));
        $this->accounts['reader']->roles()->attach($role->id);
    }

    private function person(string $key): Employee
    {
        $employee = Employee::factory()->create();
        $this->people[$key] = $employee;
        $this->accounts[$key] = User::factory()->create(['employee_id' => $employee->id, 'must_change_password' => false]);

        return $employee;
    }

    private function as(string $key): static
    {
        Sanctum::actingAs($this->accounts[$key]);

        return $this;
    }

    /** A document sent by HR's manager through the real endpoint. */
    private function sendTo(string $recipient = 'e', string $title = self::TITLE): Document
    {
        $this->as('hr')->post('/api/documents/to-employee', [
            'title'        => $title,
            'recipient_id' => $this->people[$recipient]->id,
            'filing_type'  => EmployeeDocumentType::WorkCertificate->value,
            'file'         => UploadedFile::fake()->createWithContent('letter.pdf', Pdfs::plain()),
        ], ['Accept' => 'application/json'])->assertCreated();

        return Document::query()->latest('id')->firstOrFail();
    }

    private function drawFor(string $key): EmployeeSignature
    {
        return app(Signatures::class)->draw(
            $this->people[$key],
            UploadedFile::fake()->image('signature.png', 600, 200),
            SignatureSource::App,
            $this->accounts[$key],
        );
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function sign(Document $document, string $as = 'e', array $body = []): TestResponse
    {
        $this->as($as);

        return $this->postJson(
            "/api/documents/{$document->id}/sign",
            array_merge(['confirmed_by' => Confirmation::Biometric->value], $body),
        );
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function refuse(Document $document, string $reason = 'بيانات غير صحيحة', string $as = 'e', array $body = []): TestResponse
    {
        $this->as($as);

        return $this->postJson(
            "/api/documents/{$document->id}/refuse",
            array_merge(['reason' => $reason, 'confirmed_by' => Confirmation::Biometric->value], $body),
        );
    }

    /**
     * @return array<int, string>
     */
    private function printedCopies(Document $document): array
    {
        return Storage::disk('local')->allFiles("documents/{$document->folder}/printed");
    }

    /**
     * @return Collection<int, EmployeeDocument>
     */
    private function filed(Document $document): Collection
    {
        return EmployeeDocument::query()->where('document_id', $document->id)->get();
    }

    /**
     * What the sender was told about this document, of one type.
     *
     * @return Collection<int, Notification>
     */
    private function senderTold(Document $document, string $type): Collection
    {
        return Notification::query()
            ->where('user_id', $this->accounts['hr']->id)
            ->where('type', $type)
            ->where('reference_type', 'document')
            ->where('reference_id', $document->id)
            ->get();
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

    private function damascusDay(?Carbon $at): string
    {
        $this->assertNotNull($at);

        return $at->copy()->timezone('Asia/Damascus')->toDateString();
    }

    /** Nothing of an answer was written: no mark, no copy, no filing, no word to the sender. */
    private function assertUnanswered(Document $document): void
    {
        $document = Document::withTrashed()->findOrFail($document->id);

        $this->assertSame(DocumentStatus::AwaitingSignature, $document->status);
        $this->assertNull($document->signed_at);
        $this->assertNull($document->refused_at);
        $this->assertNull($document->refusal_reason);
        $this->assertNull($document->printed_path);
        $this->assertSame(0, DocumentMark::query()->where('document_id', $document->id)->count());
        $this->assertSame([], $this->printedCopies($document));
        $this->assertCount(0, $this->filed($document));
        $this->assertCount(0, $this->senderTold($document, NotificationEventCatalogue::DOCUMENT_SIGNED));
        $this->assertCount(0, $this->senderTold($document, NotificationEventCatalogue::DOCUMENT_REFUSED));
    }

    private function assertRefusedWith(string $message, Closure $act): void
    {
        try {
            $act();
        } catch (DocumentDecisionException $e) {
            $this->assertSame($message, $e->getMessage());

            return;
        }

        $this->fail("Expected the refusal «{$message}».");
    }

    public function test_the_recipient_signs(): void
    {
        $signature = $this->drawFor('e');
        $document = $this->sendTo();
        $name = $this->people['e']->full_name;

        $this->sign($document)
            ->assertOk()
            ->assertJsonPath('message', 'تم التوقيع على المستند.')
            ->assertJsonPath('data.id', $document->id)
            ->assertJsonPath('data.status.value', DocumentStatus::Signed->value)
            ->assertJsonPath('data.has_printed_copy', true)
            ->assertJsonPath('data.can.answer', false);

        $document->refresh();
        $this->assertSame(DocumentStatus::Signed, $document->status);
        $this->assertNotNull($document->signed_at);
        $this->assertNull($document->refused_at);
        $this->assertNotNull($document->printed_path);
        $this->assertNotSame($document->original_path, $document->printed_path);
        $this->assertSame([$document->printed_path], $this->printedCopies($document));

        // One block: the employee's signature, as drawn, and how they confirmed it.
        $mark = $document->marks()->sole();
        $this->assertSame(MarkKind::EmployeeSignature, $mark->kind);
        $this->assertSame(1, $mark->position);
        $this->assertSame($signature->id, $mark->signature_id);
        $this->assertSame(Confirmation::Biometric, $mark->confirmed_by);
        $this->assertSame($this->people['e']->id, $mark->employee_id);
        $this->assertSame($name, $mark->employee_name);
        $this->assertNull($mark->approval_step_id);
        $this->assertNull($mark->stamp_id);
        $this->assertNull($mark->unit_id);
        $this->assertFalse($mark->decisive);

        // Filed on the record from the printed copy.
        $row = $this->filed($document)->sole();
        $this->assertSame($this->people['e']->id, (int) $row->employee_id);
        $this->assertSame(EmployeeDocumentType::WorkCertificate, $row->type);
        $this->assertSame(self::TITLE, $row->title);
        $this->assertSame($document->printed_path, $row->file_path);
        $this->assertSame($this->accounts['hr']->id, (int) $row->uploaded_by);
        $this->assertSame($this->damascusDay($document->signed_at), $row->issued_at->toDateString());

        // The sender is told, once.
        $told = $this->senderTold($document, NotificationEventCatalogue::DOCUMENT_SIGNED)->sole();
        $this->assertSame("وقّع {$name} على المستند", $told->title);
        $this->assertSame("وقّع {$name} على المستند «".self::TITLE.'».', $told->body);
        $this->assertSame('وقّع موظف على مستند', NotificationEventCatalogue::labelOf(NotificationEventCatalogue::DOCUMENT_SIGNED));

        // The recipient's own view: answered, nothing left to do.
        $this->as('e')->getJson("/api/documents/{$document->id}")
            ->assertOk()
            ->assertJsonPath('data.can.answer', false)
            ->assertJsonPath('data.status.value', DocumentStatus::Signed->value);
    }

    public function test_the_first_signature_is_drawn_first(): void
    {
        $document = $this->sendTo();

        $this->sign($document)
            ->assertStatus(428)
            ->assertJsonPath('message', self::NO_SIGNATURE);

        // The signature is asked before the password is read.
        $this->sign($document, body: ['confirmed_by' => Confirmation::Password->value, 'password' => 'not-the-password'])
            ->assertStatus(428);

        $this->assertUnanswered($document);

        $this->as('e')->post('/api/me/signature', [
            'signature' => UploadedFile::fake()->image('signature.png', 600, 200),
            'drawn_on'  => SignatureSource::App->value,
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->sign($document)->assertOk();

        $this->assertSame(
            app(Signatures::class)->current($this->people['e'])?->id,
            $document->marks()->sole()->signature_id,
        );
    }

    public function test_a_password_confirmation_is_checked(): void
    {
        $this->drawFor('e');
        $document = $this->sendTo();
        $wrong = ['confirmed_by' => Confirmation::Password->value, 'password' => 'not-the-password'];

        $this->sign($document, body: $wrong)
            ->assertStatus(422)->assertJsonPath('message', 'كلمة المرور غير صحيحة.');
        $this->refuse($document, body: $wrong)
            ->assertStatus(422)->assertJsonPath('message', 'كلمة المرور غير صحيحة.');

        // piece 2's rules and words for the confirmation itself.
        $this->sign($document, body: ['confirmed_by' => null])
            ->assertStatus(422)->assertJsonPath('errors.confirmed_by.0', 'تأكيد الهوية مطلوب.');
        $this->refuse($document, body: ['confirmed_by' => 7])
            ->assertStatus(422)->assertJsonPath('errors.confirmed_by.0', 'تأكيد الهوية مطلوب.');
        $this->sign($document, body: ['confirmed_by' => Confirmation::Password->value])
            ->assertStatus(422)->assertJsonPath('errors.password.0', 'كلمة المرور مطلوبة للتأكيد.');
        $this->refuse($document, body: ['confirmed_by' => Confirmation::Password->value])
            ->assertStatus(422)->assertJsonPath('errors.password.0', 'كلمة المرور مطلوبة للتأكيد.');

        $this->assertUnanswered($document);

        $this->sign($document, body: ['confirmed_by' => Confirmation::Password->value, 'password' => 'password'])
            ->assertOk();

        $this->assertSame(Confirmation::Password, $document->marks()->sole()->confirmed_by);
    }

    public function test_the_recipient_refuses_with_a_reason(): void
    {
        // Refusing needs no signature drawn.
        $document = $this->sendTo();
        $name = $this->people['e']->full_name;

        $this->refuse($document, '  الراتب المذكور غير صحيح  ')
            ->assertOk()
            ->assertJsonPath('message', 'تم رفض المستند.')
            ->assertJsonPath('data.status.value', DocumentStatus::Refused->value)
            ->assertJsonPath('data.refusal_reason', 'الراتب المذكور غير صحيح')
            ->assertJsonPath('data.has_printed_copy', false)
            ->assertJsonPath('data.can.answer', false);

        $document->refresh();
        $this->assertSame(DocumentStatus::Refused, $document->status);
        $this->assertNotNull($document->refused_at);
        $this->assertNull($document->signed_at);
        $this->assertSame('الراتب المذكور غير صحيح', $document->refusal_reason);

        // Nothing is printed.
        $this->assertSame(0, $document->marks()->count());
        $this->assertNull($document->printed_path);
        $this->assertSame([], $this->printedCopies($document));

        // Filed on the record from the copy as sent.
        $row = $this->filed($document)->sole();
        $this->assertSame($this->people['e']->id, (int) $row->employee_id);
        $this->assertSame($document->original_path, $row->file_path);
        $this->assertSame($this->damascusDay($document->refused_at), $row->issued_at->toDateString());

        // The sender is told, with the reason.
        $told = $this->senderTold($document, NotificationEventCatalogue::DOCUMENT_REFUSED)->sole();
        $this->assertSame("رفض {$name} المستند", $told->title);
        $this->assertSame("رفض {$name} المستند «".self::TITLE.'»: الراتب المذكور غير صحيح', $told->body);
        $this->assertSame('رفض موظف مستنداً', NotificationEventCatalogue::labelOf(NotificationEventCatalogue::DOCUMENT_REFUSED));
        $this->assertCount(0, $this->senderTold($document, NotificationEventCatalogue::DOCUMENT_SIGNED));
    }

    public function test_a_reason_is_required(): void
    {
        $document = $this->sendTo();

        $this->as('e')->postJson("/api/documents/{$document->id}/refuse", ['confirmed_by' => Confirmation::Biometric->value])
            ->assertStatus(422)->assertJsonPath('errors.reason.0', self::REASON_COPY);

        foreach (['', '   ', 'لا', '  لا  ', str_repeat('م', 501)] as $reason) {
            $this->refuse($document, $reason)
                ->assertStatus(422)->assertJsonPath('errors.reason.0', self::REASON_COPY);
        }

        $this->assertUnanswered($document);

        $this->refuse($document, str_repeat('م', 500))->assertOk();
        $this->assertSame(500, mb_strlen((string) $document->fresh()->refusal_reason));
    }

    public function test_only_the_recipient_answers(): void
    {
        $this->drawFor('e');
        $this->drawFor('hr');
        $this->drawFor('other');
        $document = $this->sendTo();

        // The sender, a reader of every document, another employee.
        foreach (['hr', 'reader', 'other'] as $key) {
            $this->sign($document, as: $key)->assertForbidden()->assertJsonPath('message', self::FORBIDDEN);
            $this->refuse($document, as: $key)->assertForbidden()->assertJsonPath('message', self::FORBIDDEN);
        }

        // The system administrator: the gate's bypass passes the route, the
        // service refuses anyone but the recipient.
        $admin = $this->actingAsAdmin();
        $this->assertTrue($admin->fresh()->isSuperAdmin(), 'the bypass is on');
        $this->assertTrue(Gate::forUser($admin)->allows('answer', $document), 'the bypass passes can:answer');

        $this->postJson("/api/documents/{$document->id}/sign", ['confirmed_by' => Confirmation::Biometric->value])
            ->assertForbidden()->assertJsonPath('message', self::FORBIDDEN);
        $this->postJson("/api/documents/{$document->id}/refuse", ['reason' => 'بيانات غير صحيحة', 'confirmed_by' => Confirmation::Biometric->value])
            ->assertForbidden()->assertJsonPath('message', self::FORBIDDEN);

        $this->assertUnanswered($document);

        $this->sign($document)->assertOk();
    }

    public function test_an_answer_is_final(): void
    {
        $this->drawFor('e');

        $answer = fn (string $how, Document $document): TestResponse => $how === 'sign'
            ? $this->sign($document)
            : $this->refuse($document);

        foreach ([['sign', 'sign'], ['sign', 'refuse'], ['refuse', 'sign']] as [$first, $second]) {
            $document = $this->sendTo(title: "{$first} ثم {$second}");

            $answer($first, $document)->assertOk();
            $answer($second, $document)->assertStatus(422)->assertJsonPath('message', self::NOT_AWAITING);

            $signed = $first === 'sign';
            $document->refresh();
            $this->assertSame($signed ? DocumentStatus::Signed : DocumentStatus::Refused, $document->status);
            $this->assertSame($signed ? 1 : 0, $document->marks()->count());
            $this->assertCount($signed ? 1 : 0, $this->printedCopies($document));
            $this->assertCount(1, $this->filed($document));
            $this->assertCount($signed ? 1 : 0, $this->senderTold($document, NotificationEventCatalogue::DOCUMENT_SIGNED));
            $this->assertCount($signed ? 0 : 1, $this->senderTold($document, NotificationEventCatalogue::DOCUMENT_REFUSED));
            $this->assertSame($signed ? null : 'بيانات غير صحيحة', $document->refusal_reason);
        }

        // The second phone: a model loaded before the first answer still
        // reads AwaitingSignature, so only the check under the row lock can
        // refuse it.
        foreach (['sign', 'refuse'] as $first) {
            $document = $this->sendTo(title: "هاتفان: {$first}");
            $stale = Document::query()->findOrFail($document->id);

            $answer($first, $document)->assertOk();

            $this->assertSame(DocumentStatus::AwaitingSignature, $stale->status);
            $this->as('e');
            $answers = app(DocumentAnswers::class);
            $this->assertRefusedWith(self::NOT_AWAITING, fn () => $answers->sign($stale, $this->accounts['e'], Confirmation::Biometric, null));
            $this->assertRefusedWith(self::NOT_AWAITING, fn () => $answers->refuse($stale, $this->accounts['e'], 'سبب آخر', Confirmation::Biometric, null));

            $signed = $first === 'sign';
            $document->refresh();
            $this->assertSame($signed ? DocumentStatus::Signed : DocumentStatus::Refused, $document->status);
            $this->assertSame($signed ? 1 : 0, $document->marks()->count());
            $this->assertCount($signed ? 1 : 0, $this->printedCopies($document));
            $this->assertCount(1, $this->filed($document));
            $this->assertCount($signed ? 1 : 0, $this->senderTold($document, NotificationEventCatalogue::DOCUMENT_SIGNED));
            $this->assertCount($signed ? 0 : 1, $this->senderTold($document, NotificationEventCatalogue::DOCUMENT_REFUSED));
        }
    }

    public function test_a_delete_and_a_signature_do_not_both_win(): void
    {
        $this->drawFor('e');
        $answers = app(DocumentAnswers::class);
        $deletion = app(DocumentDeletion::class);

        // Both loaded before either acts. Signed first: the deletion's lock
        // reads the signature.
        $document = $this->sendTo(title: 'يوقَّع أولاً');
        $toSign = Document::query()->findOrFail($document->id);
        $toDelete = Document::query()->findOrFail($document->id);

        $this->as('e');
        $answers->sign($toSign, $this->accounts['e'], Confirmation::Biometric, null);

        $this->as('hr');
        $this->assertRefusedWith(self::ANSWERED, fn () => $deletion->delete($toDelete, $this->accounts['hr']));

        $document = Document::withTrashed()->findOrFail($document->id);
        $this->assertFalse($document->trashed());
        $this->assertNull($document->deleted_by);
        $this->assertSame(DocumentStatus::Signed, $document->status);
        $this->assertCount(1, $this->filed($document));

        // Deleted first: the answer's lock reads the deletion, and a deleted
        // document is never signed, refused or filed.
        $document = $this->sendTo(title: 'يُحذف أولاً');
        $toSign = Document::query()->findOrFail($document->id);
        $toRefuse = Document::query()->findOrFail($document->id);
        $toDelete = Document::query()->findOrFail($document->id);

        $this->as('hr');
        $deletion->delete($toDelete, $this->accounts['hr']);

        $this->as('e');
        $this->assertRefusedWith(self::NOT_AWAITING, fn () => $answers->sign($toSign, $this->accounts['e'], Confirmation::Biometric, null));
        $this->assertRefusedWith(self::NOT_AWAITING, fn () => $answers->refuse($toRefuse, $this->accounts['e'], 'بيانات غير صحيحة', Confirmation::Biometric, null));

        $this->assertTrue(Document::withTrashed()->findOrFail($document->id)->trashed());
        $this->assertUnanswered($document);
    }

    public function test_answering_a_deleted_document_answers_410_to_its_recipient(): void
    {
        $this->drawFor('e');
        $document = $this->sendTo();
        $this->as('hr')->deleteJson("/api/documents/{$document->id}")->assertOk();

        $this->sign($document)->assertStatus(410)->assertJsonPath('message', self::GONE);
        $this->refuse($document)->assertStatus(410)->assertJsonPath('message', self::GONE);

        // Anyone else is still refused at the door.
        $this->sign($document, as: 'other')->assertForbidden();

        $this->assertUnanswered($document);
    }

    public function test_a_print_failure_rolls_the_signature_back(): void
    {
        $this->drawFor('e');
        $document = $this->sendTo();

        $this->app->instance(DocumentPrinter::class, new class(app(UnitStamps::class), app(Signatures::class), app(PdfWorkspace::class), app(CardLayout::class)) extends DocumentPrinter
        {
            public function print(Document $document): string
            {
                throw new RuntimeException('The printer failed.');
            }
        });

        $this->sign($document)->assertStatus(500);

        $this->assertUnanswered($document);

        // Nothing was left behind to block the next try.
        $this->app->forgetInstance(DocumentPrinter::class);
        $this->sign($document)->assertOk();
        $this->assertCount(1, $this->filed($document));
    }

    public function test_a_failure_after_printing_deletes_the_new_copy(): void
    {
        $this->drawFor('e');
        $document = $this->sendTo();

        // The filing is the last write of the transaction, after the copy is
        // printed and recorded.
        EmployeeDocument::creating(static function (): void {
            throw new RuntimeException('The record could not be written.');
        });

        $this->sign($document)->assertStatus(500);

        $this->assertUnanswered($document);
        Storage::disk('local')->assertExists((string) $document->original_path);
    }

    /** A failure inside the refusal's transaction — the filing, its last write — rolls it back. */
    public function test_a_failure_inside_the_refusal_rolls_it_back(): void
    {
        $document = $this->sendTo();

        EmployeeDocument::creating(static function (): void {
            throw new RuntimeException('The record could not be written.');
        });

        $this->refuse($document)->assertStatus(500);

        $this->assertUnanswered($document);
        Storage::disk('local')->assertExists((string) $document->original_path);
    }

    /**
     * The sender's inbox row is written in the answer's transaction: when it
     * fails, the signature is not recorded — no mark, no copy, no filing — and
     * the answer is the error, so a retry signs it, once.
     */
    public function test_a_failed_notification_rolls_the_signature_back(): void
    {
        $this->drawFor('e');
        $document = $this->sendTo();

        $this->breakTheInbox();

        $this->sign($document)->assertStatus(500);

        $this->assertUnanswered($document);
        Storage::disk('local')->assertExists((string) $document->original_path);

        $this->inboxDown = false;

        $this->sign($document)->assertOk();
        $this->assertSame(1, $document->marks()->count());
        $this->assertCount(1, $this->printedCopies($document));
        $this->assertCount(1, $this->filed($document));
        $this->assertCount(1, $this->senderTold($document, NotificationEventCatalogue::DOCUMENT_SIGNED));
    }

    /** As the signature: a refusal whose inbox row fails is not recorded, and a retry refuses it, once. */
    public function test_a_failed_notification_rolls_the_refusal_back(): void
    {
        $document = $this->sendTo();

        $this->breakTheInbox();

        $this->refuse($document)->assertStatus(500);

        $this->assertUnanswered($document);

        $this->inboxDown = false;

        $this->refuse($document)->assertOk();
        $this->assertCount(1, $this->filed($document));
        $this->assertCount(1, $this->senderTold($document, NotificationEventCatalogue::DOCUMENT_REFUSED));
    }

    public function test_a_document_between_managers_cannot_be_signed_here(): void
    {
        $this->drawFor('e');
        $this->drawFor('hr');
        $folder = (string) Str::uuid();
        Storage::disk('local')->put("documents/{$folder}/original.pdf", Pdfs::plain());

        $unit = Department::query()->where('code', config('leave.hr_unit_code'))->firstOrFail();
        $document = Document::create([
            'kind'             => DocumentKind::Internal,
            'origin'           => DocumentOrigin::Uploaded,
            'title'            => 'تعميم داخلي',
            'creator_id'       => $this->people['hr']->id,
            'creator_unit_id'  => $unit->id,
            'register_unit_id' => $unit->id,
            'created_by'       => $this->accounts['hr']->id,
            'status'           => DocumentStatus::Pending,
            // Even naming an employee does not make a document between
            // managers theirs to sign.
            'recipient_id'     => $this->people['e']->id,
            'folder'           => $folder,
            'original_path'    => "documents/{$folder}/original.pdf",
        ]);

        foreach (['e', 'hr', 'reader'] as $key) {
            $this->sign($document, as: $key)->assertForbidden();
            $this->refuse($document, as: $key)->assertForbidden();
        }

        $this->actingAsAdmin();
        $this->postJson("/api/documents/{$document->id}/sign", ['confirmed_by' => Confirmation::Biometric->value])
            ->assertForbidden()->assertJsonPath('message', self::FORBIDDEN);

        $document->refresh();
        $this->assertSame(DocumentStatus::Pending, $document->status);
        $this->assertSame(0, $document->marks()->count());
        $this->assertNull($document->printed_path);
        $this->assertCount(0, $this->filed($document));
    }
}
