<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Modules\Auth\Enums\ScopeType;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Support\PermissionCache;
use App\Modules\Departments\Models\Department;
use App\Modules\Documents\Enums\DocumentKind;
use App\Modules\Documents\Enums\DocumentOrigin;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Models\Document;
use App\Modules\HR\Enums\EmployeeDocumentType;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * An employee's filed documents on their HR record (documents to employees,
 * "The filed list"): read under the record's own rules, with the contract,
 * appointment and promotion decisions and the Sham Cash photo behind the
 * salary permission (Review Focus 3).
 */
class FiledDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private const REFUSED = 'لا أوافق على البنود';

    private const SALARY = [
        EmployeeDocumentType::Contract,
        EmployeeDocumentType::AppointmentDecision,
        EmployeeDocumentType::PromotionDecision,
        EmployeeDocumentType::ShamCash,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function unit(string $name): Department
    {
        $root = Department::query()->where('level', 1)->first()
            ?? Department::create(['name' => 'الإدارة العامة', 'level' => 1]);

        return Department::create(['name' => $name, 'level' => 2, 'parent_id' => $root->id, 'sector' => 1]);
    }

    private function row(Employee $employee, EmployeeDocumentType $type, array $extra = []): EmployeeDocument
    {
        $path = 'employee-documents/'.uniqid().'.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4 '.$type->name);

        return EmployeeDocument::create([
            'employee_id'   => $employee->id,
            'type'          => $type,
            'title'         => $type->label(),
            'file_path'     => $path,
            'original_name' => $type->label().'.pdf',
            'mime_type'     => 'application/pdf',
            'size_bytes'    => strlen('%PDF-1.4 '.$type->name),
            'issued_at'     => '2026-11-01',
            ...$extra,
        ]);
    }

    private function sentDocument(Employee $recipient, DocumentStatus $status): Document
    {
        $sender = Employee::factory()->create();
        $account = User::factory()->create(['employee_id' => $sender->id]);
        $unit = $this->unit('الموارد البشرية');

        return Document::create([
            'kind'             => DocumentKind::ToEmployee,
            'origin'           => DocumentOrigin::Uploaded,
            'title'            => 'مستند مرسل',
            'creator_id'       => $sender->id,
            'creator_unit_id'  => $unit->id,
            'register_unit_id' => $unit->id,
            'created_by'       => $account->id,
            'status'           => $status,
            'recipient_id'     => $recipient->id,
            'filing_type'      => EmployeeDocumentType::Other,
            'sent_at'          => Carbon::now()->subDay(),
            'signed_at'        => $status === DocumentStatus::Signed ? Carbon::now() : null,
            'refused_at'       => $status === DocumentStatus::Refused ? Carbon::now() : null,
            'refusal_reason'   => $status === DocumentStatus::Refused ? self::REFUSED : null,
            'folder'           => 'f-'.uniqid(),
            'original_path'    => 'documents/x/original.pdf',
        ]);
    }

    public function test_hr_reads_an_employees_filed_documents(): void
    {
        $employee = Employee::factory()->create();
        $signedDoc = $this->sentDocument($employee, DocumentStatus::Signed);
        $refusedDoc = $this->sentDocument($employee, DocumentStatus::Refused);

        $signed = $this->row($employee, EmployeeDocumentType::WorkCertificate, ['document_id' => $signedDoc->id, 'issued_at' => '2026-11-20']);
        $refused = $this->row($employee, EmployeeDocumentType::Warning, ['document_id' => $refusedDoc->id, 'issued_at' => '2026-11-25']);
        $imported = $this->row($employee, EmployeeDocumentType::IdFront, ['issued_at' => '2026-01-01']);
        $this->row(Employee::factory()->create(), EmployeeDocumentType::Cv);

        $this->actingAsUserWithPermissions(['hr.employees.view']);

        $response = $this->getJson("/api/hr/employees/{$employee->id}/filed-documents")->assertOk();
        $rows = $response->json('data');

        $this->assertSame([$refused->id, $signed->id, $imported->id], array_column($rows, 'id'));

        $this->assertSame([
            'id'             => $refused->id,
            'type'           => ['value' => 12, 'label' => 'إنذار'],
            'title'          => 'إنذار',
            'issued_at'      => '2026-11-25',
            'original_name'  => 'إنذار.pdf',
            'mime_type'      => 'application/pdf',
            'size_bytes'     => $refused->size_bytes,
            'document'       => [
                'id'             => $refusedDoc->id,
                'status'         => ['value' => 6, 'label' => DocumentStatus::Refused->label()],
                'refused'        => true,
                'refusal_reason' => self::REFUSED,
            ],
            'refused_label'  => 'مرفوض',
        ], $rows[0]);

        $this->assertFalse($rows[1]['document']['refused']);
        $this->assertNull($rows[1]['document']['refusal_reason']);
        $this->assertNull($rows[1]['refused_label']);
        $this->assertSame(5, $rows[1]['document']['status']['value']);

        $this->assertNull($rows[2]['document']);
        $this->assertNull($rows[2]['refused_label']);
        $this->assertSame(1, $rows[2]['type']['value']);
    }

    public function test_the_list_is_paginated(): void
    {
        $employee = Employee::factory()->create();
        foreach (range(1, 3) as $day) {
            $this->row($employee, EmployeeDocumentType::Other, ['issued_at' => "2026-11-0{$day}"]);
        }
        $this->actingAsUserWithPermissions(['hr.employees.view']);

        $this->getJson("/api/hr/employees/{$employee->id}/filed-documents?per_page=2")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3);
    }

    public function test_salary_rows_need_the_salary_reach(): void
    {
        $employee = Employee::factory()->create();
        $open = $this->row($employee, EmployeeDocumentType::Other);
        $gated = array_map(fn (EmployeeDocumentType $type) => $this->row($employee, $type), self::SALARY);

        $this->actingAsUserWithPermissions(['hr.employees.view']);

        // Left out before paging: the total does not count them either.
        $list = $this->getJson("/api/hr/employees/{$employee->id}/filed-documents")->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertSame([$open->id], array_column($list->json('data'), 'id'));

        $this->getJson("/api/hr/employees/{$employee->id}/filed-documents/{$open->id}/file")->assertOk();

        foreach ($gated as $row) {
            $this->getJson("/api/hr/employees/{$employee->id}/filed-documents/{$row->id}/file")
                ->assertForbidden()
                ->assertJsonPath('message', 'لا تملك صلاحية الاطلاع على هذه الوثيقة.');
        }

        $this->actingAsUserWithPermissions(['hr.employees.view', 'hr.employees.viewSalary']);

        $list = $this->getJson("/api/hr/employees/{$employee->id}/filed-documents")->assertOk()->assertJsonPath('meta.total', 5);
        $this->assertEqualsCanonicalizing(
            [$open->id, ...array_map(fn (EmployeeDocument $row) => $row->id, $gated)],
            array_column($list->json('data'), 'id'),
        );

        foreach ($gated as $row) {
            $this->getJson("/api/hr/employees/{$employee->id}/filed-documents/{$row->id}/file")->assertOk();
        }
    }

    public function test_a_salary_grant_over_another_unit_does_not_reach(): void
    {
        $a = $this->unit('إدارة أ');
        $b = $this->unit('إدارة ب');
        $employee = Employee::factory()->create(['department_id' => $b->id]);
        $contract = $this->row($employee, EmployeeDocumentType::Contract);

        $user = $this->actingAsScopedUser(['hr.employees.view'], ScopeType::Company);
        $role = Role::create(['name' => 'pay-a-'.uniqid()]);
        $role->permissions()->sync(Permission::where('name', 'hr.employees.viewSalary')->pluck('id'));
        $user->roles()->attach($role->id, ['scope_type' => ScopeType::Unit->value, 'scope_department_id' => $a->id]);
        PermissionCache::flush();

        $this->getJson("/api/hr/employees/{$employee->id}/filed-documents")->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/hr/employees/{$employee->id}/filed-documents/{$contract->id}/file")->assertForbidden();
    }

    public function test_the_administrator_reads_the_salary_rows(): void
    {
        $employee = Employee::factory()->create();
        $contract = $this->row($employee, EmployeeDocumentType::Contract);
        $this->actingAsAdmin();

        $this->getJson("/api/hr/employees/{$employee->id}/filed-documents")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/hr/employees/{$employee->id}/filed-documents/{$contract->id}/file")->assertOk();
    }

    public function test_another_employees_row_is_not_found(): void
    {
        $employee = Employee::factory()->create();
        $other = $this->row(Employee::factory()->create(), EmployeeDocumentType::Other);
        $this->actingAsUserWithPermissions(['hr.employees.view', 'hr.employees.viewSalary']);

        $this->getJson("/api/hr/employees/{$employee->id}/filed-documents/{$other->id}/file")->assertNotFound();
    }

    public function test_a_reader_out_of_reach_is_refused(): void
    {
        $a = $this->unit('إدارة أ');
        $b = $this->unit('إدارة ب');
        $employee = Employee::factory()->create(['department_id' => $b->id]);
        $row = $this->row($employee, EmployeeDocumentType::Other);
        $this->actingAsScopedUser(['hr.employees.view'], ScopeType::Unit, $a->id);

        $this->getJson("/api/hr/employees/{$employee->id}/filed-documents")
            ->assertForbidden()->assertJsonPath('message', 'هذا الموظف خارج نطاق صلاحياتك.');
        $this->getJson("/api/hr/employees/{$employee->id}/filed-documents/{$row->id}/file")
            ->assertForbidden()->assertJsonPath('message', 'هذا الموظف خارج نطاق صلاحياتك.');
    }

    public function test_the_file_streams_privately(): void
    {
        $employee = Employee::factory()->create();
        $row = $this->row($employee, EmployeeDocumentType::Other, ['mime_type' => 'image/png']);
        $this->actingAsUserWithPermissions(['hr.employees.view']);

        $response = $this->get("/api/hr/employees/{$employee->id}/filed-documents/{$row->id}/file", ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('%PDF-1.4 Other', $response->streamedContent());
    }

    public function test_a_missing_file_is_not_found(): void
    {
        $employee = Employee::factory()->create();
        $row = $this->row($employee, EmployeeDocumentType::Other);
        Storage::disk('local')->delete($row->file_path);
        $this->actingAsUserWithPermissions(['hr.employees.view']);

        $this->getJson("/api/hr/employees/{$employee->id}/filed-documents/{$row->id}/file")->assertNotFound();
    }
}
