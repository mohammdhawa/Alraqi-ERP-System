<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Support\PermissionCache;
use App\Modules\Departments\Models\Department;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Models\Document;
use App\Modules\HR\Enums\EmployeeDocumentType;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Pdfs;
use Tests\TestCase;

/**
 * Who sees a document sent to an employee, the employee's «مستنداتي» box, the
 * thread the recipient is kept out of, and deleting before an answer
 * (documents to employees, Task 5; documents design §4, §5, D31).
 */
class DocumentsToEmployeesSeeingTest extends TestCase
{
    use RefreshDatabase;

    private const GONE = 'حُذف هذا المستند.';

    private const ANSWERED = 'لا يمكن حذف مستند بعد أن أجاب عنه الموظف.';

    /** @var array<string, Employee> */
    private array $people = [];

    /** @var array<string, User> */
    private array $accounts = [];

    private Department $hr;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $root = Department::create(['name' => 'الإدارة العامة', 'level' => 1]);
        $this->hr = Department::create([
            'name'      => 'إدارة الموارد البشرية',
            'code'      => config('leave.hr_unit_code'),
            'level'     => 2,
            'parent_id' => $root->id,
            'sector'    => 1,
        ]);
        $this->hr->update(['manager_id' => $this->person('hr')->id]);

        $this->person('e');
        $this->person('other');
        $this->person('reader');
        $this->person('stranger');

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
    private function sendTo(string $recipient, string $title = 'إشعار بالدوام'): Document
    {
        $this->as('hr')->post('/api/documents/to-employee', [
            'title'        => $title,
            'recipient_id' => $this->people[$recipient]->id,
            'filing_type'  => EmployeeDocumentType::WorkCertificate->value,
            'file'         => UploadedFile::fake()->createWithContent('letter.pdf', Pdfs::plain()),
        ], ['Accept' => 'application/json'])->assertCreated();

        return Document::query()->latest('id')->firstOrFail();
    }

    public function test_the_recipient_sees_the_document_and_its_file(): void
    {
        $document = $this->sendTo('e');

        $this->as('e')->getJson("/api/documents/{$document->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $document->id)
            ->assertJsonPath('data.recipient.id', $this->people['e']->id)
            ->assertJsonPath('data.filing_type.value', EmployeeDocumentType::WorkCertificate->value)
            ->assertJsonPath('data.filing_type.label', EmployeeDocumentType::WorkCertificate->label())
            ->assertJsonPath('data.status.value', DocumentStatus::AwaitingSignature->value)
            ->assertJsonPath('data.can.answer', true)
            ->assertJsonPath('data.can.comment', false)
            ->assertJsonPath('data.can.delete', false);

        $this->as('e')->getJson("/api/documents/{$document->id}/file")->assertOk();

        // Anyone else with no part in it is refused.
        $this->as('other')->getJson("/api/documents/{$document->id}")->assertForbidden();
        $this->as('other')->getJson("/api/documents/{$document->id}/file")->assertForbidden();
    }

    public function test_the_mine_box_lists_what_was_sent_to_me(): void
    {
        $first = $this->sendTo('e', 'الأول');
        $second = $this->sendTo('e', 'الثاني');
        $elsewhere = $this->sendTo('other', 'لموظف آخر');
        $second->update(['status' => DocumentStatus::Signed, 'signed_at' => now()]);

        $ids = fn (TestResponse $r): array => collect($r->json('data'))->pluck('id')->all();

        $all = $this->as('e')->getJson('/api/documents?box=mine')->assertOk();
        $this->assertSame([$second->id, $first->id], $ids($all));

        $signed = $this->as('e')->getJson('/api/documents?box=mine&status='.DocumentStatus::Signed->value)->assertOk();
        $this->assertSame([$second->id], $ids($signed));
        $this->assertSame('موقّع', $signed->json('data.0.status.label'));

        $found = $this->as('e')->getJson('/api/documents?box=mine&search='.urlencode('الأول'))->assertOk();
        $this->assertSame([$first->id], $ids($found));

        // Another employee's documents never appear, nor does the sender's box mix in.
        $this->assertNotContains($elsewhere->id, $ids($all));
        $this->assertSame([$elsewhere->id], $ids($this->as('other')->getJson('/api/documents?box=mine')->assertOk()));
        $this->assertSame([], $ids($this->as('hr')->getJson('/api/documents?box=mine')->assertOk()));
        $this->as('stranger')->getJson('/api/documents?box=mine')->assertOk()->assertJsonPath('data', []);
    }

    public function test_the_senders_outgoing_box_shows_who_answered(): void
    {
        $document = $this->sendTo('e');
        $document->update([
            'status'         => DocumentStatus::Refused,
            'refused_at'     => now(),
            'refusal_reason' => 'بيانات غير صحيحة',
        ]);

        $row = $this->as('hr')->getJson('/api/documents?box=outgoing')->assertOk()->json('data.0');

        $this->assertSame($document->id, $row['id']);
        $this->assertSame($this->people['e']->id, $row['recipient']['id']);
        $this->assertSame($this->people['e']->full_name, $row['recipient']['full_name']);
        $this->assertSame(DocumentStatus::Refused->value, $row['status']['value']);
        $this->assertSame('بيانات غير صحيحة', $row['refusal_reason']);
        $this->assertNotNull($row['refused_at']);
        $this->assertNotNull($row['sent_at']);
        $this->assertNull($row['signed_at']);
    }

    public function test_the_recipient_is_kept_out_of_the_thread(): void
    {
        $document = $this->sendTo('e');

        $this->as('e')->getJson("/api/documents/{$document->id}/comments")->assertForbidden();
        $this->as('e')->postJson("/api/documents/{$document->id}/comments", ['body' => 'سؤال'])->assertForbidden();
        $this->assertSame(0, $document->comments()->count());

        $this->as('hr')->postJson("/api/documents/{$document->id}/comments", ['body' => 'ملاحظة'])->assertCreated();
        $this->as('reader')->postJson("/api/documents/{$document->id}/comments", ['body' => 'متابعة'])->assertCreated();
        $this->as('hr')->getJson("/api/documents/{$document->id}/comments")->assertOk()->assertJsonCount(2, 'data');
        $this->as('reader')->getJson("/api/documents/{$document->id}/comments")->assertOk()->assertJsonCount(2, 'data');
        $this->as('hr')->getJson("/api/documents/{$document->id}")->assertJsonPath('data.can.comment', true);
    }

    public function test_a_recipient_who_reads_everything_or_administers_is_still_kept_out_of_the_thread(): void
    {
        $document = $this->sendTo('e');

        // The recipient also holds `documents.view`: sees it, never the thread.
        $this->accounts['e']->roles()->attach(Role::query()->where('name', 'documents-reader')->value('id'));
        $this->as('e')->getJson("/api/documents/{$document->id}")->assertOk()->assertJsonPath('data.can.comment', false);
        $this->as('e')->getJson("/api/documents/{$document->id}/comments")->assertForbidden();
        $this->as('e')->postJson("/api/documents/{$document->id}/comments", ['body' => 'x'])->assertForbidden();

        // The recipient is also the system administrator: the bypass does not open the thread.
        $this->accounts['e']->roles()->attach(Role::query()->where('name', User::SUPER_ADMIN_ROLE)->value('id'));
        PermissionCache::flush();
        $this->assertTrue($this->accounts['e']->fresh()->isSuperAdmin(), 'the bypass is on');
        $this->as('e')->getJson("/api/documents/{$document->id}")->assertOk()
            ->assertJsonPath('data.can.comment', false)
            ->assertJsonPath('data.can.answer', true);
        $this->as('e')->getJson("/api/documents/{$document->id}/comments")
            ->assertForbidden()->assertJsonPath('message', 'ليس لديك صلاحية لتنفيذ هذا الإجراء.');
        $this->as('e')->postJson("/api/documents/{$document->id}/comments", ['body' => 'x'])
            ->assertForbidden()->assertJsonPath('message', 'ليس لديك صلاحية لتنفيذ هذا الإجراء.');
        $this->assertSame(0, $document->comments()->count());

        // An administrator who is not the recipient keeps the thread and the delete flag.
        $this->actingAsAdmin();
        $this->getJson("/api/documents/{$document->id}")->assertOk()
            ->assertJsonPath('data.can.comment', true)
            ->assertJsonPath('data.can.delete', true)
            ->assertJsonPath('data.can.answer', false);
        $this->getJson("/api/documents/{$document->id}/comments")->assertOk();
    }

    public function test_the_lists_cost_the_same_queries_however_many_rows(): void
    {
        $count = function (): int {
            $this->as('reader');
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/documents?box=all')->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->sendTo('e');
        $this->sendTo('other');
        $count(); // warms the permission cache
        $few = $count();

        foreach (range(1, 4) as $i) {
            $this->sendTo('e', "مستند {$i}");
        }

        $this->assertSame($few, $count());
    }

    public function test_the_sender_or_the_administrator_deletes_before_an_answer(): void
    {
        $bySender = $this->sendTo('e', 'يحذفه المرسل');
        $this->as('hr')->getJson("/api/documents/{$bySender->id}")->assertJsonPath('data.can.delete', true);

        $this->as('hr')->deleteJson("/api/documents/{$bySender->id}")
            ->assertOk()->assertJsonPath('message', 'تم حذف المستند.');

        $bySender->refresh();
        $this->assertTrue($bySender->trashed());
        $this->assertSame($this->accounts['hr']->id, (int) $bySender->deleted_by);
        $this->assertSame(DocumentStatus::AwaitingSignature, $bySender->status);

        $this->as('e')->getJson("/api/documents/{$bySender->id}")->assertStatus(410)->assertJsonPath('message', self::GONE);
        $this->as('e')->getJson('/api/documents?box=mine')->assertOk()->assertJsonPath('data', []);
        $this->as('hr')->getJson('/api/documents?box=outgoing')->assertOk()->assertJsonPath('data', []);
        $this->as('reader')->getJson('/api/documents?box=all')->assertOk()->assertJsonPath('data', []);
        $this->as('e')->getJson("/api/documents/{$bySender->id}/file")->assertStatus(410)->assertJsonPath('message', self::GONE);

        $byAdmin = $this->sendTo('e', 'يحذفه المدير');
        $admin = $this->actingAsAdmin();

        $this->deleteJson("/api/documents/{$byAdmin->id}")->assertOk();
        $this->assertTrue($byAdmin->refresh()->trashed());
        $this->assertSame($admin->id, (int) $byAdmin->deleted_by);

        // Not the recipient, and not another reader.
        $kept = $this->sendTo('e', 'يبقى');
        $this->as('e')->deleteJson("/api/documents/{$kept->id}")->assertForbidden();
        $this->as('reader')->deleteJson("/api/documents/{$kept->id}")->assertForbidden();
        $this->assertFalse($kept->refresh()->trashed());
    }

    public function test_an_answered_document_is_not_deleted(): void
    {
        foreach ([DocumentStatus::Signed, DocumentStatus::Refused] as $status) {
            $document = $this->sendTo('e');
            $document->update(['status' => $status]);

            $this->as('hr')->deleteJson("/api/documents/{$document->id}")
                ->assertStatus(422)->assertJsonPath('message', self::ANSWERED);

            $document->refresh();
            $this->assertFalse($document->trashed());
            $this->assertNull($document->deleted_by);

            $this->as('hr')->getJson("/api/documents/{$document->id}")->assertJsonPath('data.can.delete', false);
        }
    }
}
