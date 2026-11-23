<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Modules\Auth\Models\User;
use App\Modules\Departments\Models\Department;
use App\Modules\Documents\Enums\Confirmation;
use App\Modules\Documents\Enums\DocumentKind;
use App\Modules\Documents\Enums\DocumentOrigin;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Enums\MarkKind;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentComment;
use App\Modules\Documents\Models\DocumentMark;
use App\Modules\Documents\Models\EmployeeSignature;
use App\Modules\Documents\Models\UnitStamp;
use App\Modules\HR\Models\Employee;
use App\Shared\Support\NotificationEventCatalogue;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The tables behind documents between managers (documents design §6.1, §6.2):
 * what a document, its printed marks and its comments keep, and the
 * registrations every audited model and notification type needs.
 */
class DocumentModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_document_round_trips_its_enums_and_relations(): void
    {
        $root = Department::create(['name' => 'الإدارة العامة', 'level' => 1]);
        $division = Department::create(['name' => 'إدارة الموارد البشرية', 'level' => 2, 'parent_id' => $root->id, 'sector' => 1]);
        $employee = Employee::factory()->create();
        $user = User::factory()->create(['employee_id' => $employee->id, 'must_change_password' => false]);

        $document = Document::create([
            'kind'             => DocumentKind::Internal,
            'origin'           => DocumentOrigin::Uploaded,
            'title'            => 'تعميم داخلي',
            'creator_id'       => $employee->id,
            'creator_unit_id'  => $division->id,
            'register_unit_id' => $division->id,
            'created_by'       => $user->id,
            'import_number'    => 15,
            'status'           => DocumentStatus::Pending,
            'folder'           => (string) Str::uuid(),
            'original_path'    => 'documents/x/original.pdf',
        ]);

        $stamp = UnitStamp::create(['department_id' => $division->id, 'path' => 's.enc', 'mime_type' => 'image/png']);
        $signature = EmployeeSignature::create(['employee_id' => $employee->id, 'path' => 'g.enc', 'drawn_on' => 1]);

        $mark = fn (int $position, MarkKind $kind) => $document->marks()->create([
            'position'      => $position,
            'kind'          => $kind,
            'employee_id'   => $employee->id,
            'employee_name' => 'اسم',
            'unit_id'       => $division->id,
            'unit_name'     => 'إدارة',
            'stamp_id'      => $stamp->id,
            'signature_id'  => $signature->id,
            'decisive'      => false,
            'confirmed_by'  => Confirmation::Password,
            'marked_at'     => now(),
        ]);
        $mark(2, MarkKind::RejectionCard);
        $mark(1, MarkKind::ApprovalCard);

        $document->comments()->create(['employee_id' => $employee->id, 'body' => 'ملاحظة']);

        $fresh = Document::findOrFail($document->id);

        $this->assertSame(DocumentKind::Internal, $fresh->kind);
        $this->assertSame(DocumentOrigin::Uploaded, $fresh->origin);
        $this->assertSame(DocumentStatus::Pending, $fresh->status);
        $this->assertSame('قيد الاعتماد', $fresh->status->label());
        $this->assertTrue($fresh->creator->is($employee));
        $this->assertTrue($fresh->creatorUnit->is($division));
        $this->assertTrue($fresh->registerUnit->is($division));
        $this->assertNull($fresh->approval);
        $this->assertCount(0, $fresh->attachments);
        $this->assertCount(1, $fresh->comments);

        $this->assertSame([1, 2], $fresh->marks->pluck('position')->all());
        $this->assertSame(MarkKind::ApprovalCard, $fresh->marks->first()->kind);
        $this->assertSame(Confirmation::Password, $fresh->marks->first()->confirmed_by);
        $this->assertTrue($fresh->marks->first()->stamp->is($stamp));

        $signature->delete();
        $this->assertTrue(DocumentMark::first()->signature->is($signature));
    }

    public function test_the_new_types_are_registered(): void
    {
        $map = Relation::morphMap();

        $this->assertSame(Document::class, $map['document']);
        $this->assertSame(DocumentMark::class, $map['document_mark']);
        $this->assertSame(DocumentComment::class, $map['document_comment']);

        $this->assertSame('رفض مدير المستند', NotificationEventCatalogue::labelOf('document.rejected_by_manager'));
        $this->assertSame('تمت الموافقة على المستند', NotificationEventCatalogue::labelOf('document.approved'));
        $this->assertSame('تم رفض المستند', NotificationEventCatalogue::labelOf('document.rejected'));
        $this->assertSame('ختم وحدة غير مرفوع', NotificationEventCatalogue::labelOf('document.stamp_missing'));
    }
}
