<?php

declare(strict_types=1);

namespace Tests\Unit\HR;

use App\Modules\HR\Enums\EmployeeDocumentType;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeDocumentTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_new_types_and_their_labels(): void
    {
        $this->assertSame(9, EmployeeDocumentType::AppointmentDecision->value);
        $this->assertSame(10, EmployeeDocumentType::PromotionDecision->value);
        $this->assertSame(11, EmployeeDocumentType::WorkCertificate->value);
        $this->assertSame(12, EmployeeDocumentType::Warning->value);

        $this->assertSame('قرار تعيين', EmployeeDocumentType::AppointmentDecision->label());
        $this->assertSame('قرار ترقية', EmployeeDocumentType::PromotionDecision->label());
        $this->assertSame('شهادة عمل', EmployeeDocumentType::WorkCertificate->label());
        $this->assertSame('إنذار', EmployeeDocumentType::Warning->label());

        $this->assertCount(12, EmployeeDocumentType::options());
        $this->assertTrue(EmployeeDocumentType::isDefined(12));
        $this->assertFalse(EmployeeDocumentType::isDefined(13));
    }

    public function test_the_salary_types(): void
    {
        $salary = array_values(array_filter(
            EmployeeDocumentType::cases(),
            static fn (EmployeeDocumentType $type): bool => $type->readsThroughSalary(),
        ));

        $this->assertEqualsCanonicalizing([4, 7, 9, 10], array_map(static fn ($type) => $type->value, $salary));
        $this->assertTrue(EmployeeDocumentType::ShamCash->isPaymentInstrument());
        $this->assertFalse(EmployeeDocumentType::Contract->isPaymentInstrument());

        $employee = Employee::factory()->create();

        foreach (EmployeeDocumentType::cases() as $type) {
            EmployeeDocument::create([
                'employee_id' => $employee->id,
                'type'        => $type,
                'file_path'   => "employees/{$type->value}.pdf",
            ]);
        }

        $readable = EmployeeDocument::query()->readableWithout()->get()
            ->map(static fn (EmployeeDocument $row): int => $row->type->value)
            ->sort()->values()->all();

        $this->assertSame([1, 2, 3, 5, 6, 8, 11, 12], $readable);
    }

    public function test_the_types_a_sent_document_may_be_filed_as(): void
    {
        $this->assertSame(
            [7, 8, 9, 10, 11, 12],
            array_map(static fn (EmployeeDocumentType $type): int => $type->value, EmployeeDocumentType::forSentDocuments()),
        );
    }
}
