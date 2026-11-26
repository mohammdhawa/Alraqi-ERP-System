<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Modules\Auth\Models\User;
use App\Modules\Departments\Models\Department;
use App\Modules\Documents\Enums\DocumentKind;
use App\Modules\Documents\Enums\DocumentOrigin;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Exceptions\DocumentRouteException;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentCounter;
use App\Modules\Documents\Services\ExportNumbers;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The صادر register of an إدارة (documents design §8.4): legacy's counter
 * rules, claimed under a row lock.
 */
class ExportNumbersTest extends TestCase
{
    use RefreshDatabase;

    private function register(string $name = 'إدارة المالية'): Department
    {
        $root = Department::query()->where('level', 1)->first()
            ?? Department::create(['name' => 'الإدارة العامة', 'level' => 1]);

        return Department::create(['name' => $name, 'level' => 2, 'parent_id' => $root->id, 'sector' => 1]);
    }

    private function claim(Department $register, ?int $typed = null): int
    {
        return DB::transaction(fn () => app(ExportNumbers::class)->claim($register, $typed));
    }

    private function documentWith(Department $register, int $number, bool $deleted = false): Document
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->create(['employee_id' => $employee->id, 'must_change_password' => false]);

        $document = Document::create([
            'kind'             => DocumentKind::Internal,
            'origin'           => DocumentOrigin::Uploaded,
            'title'            => 'مستند',
            'creator_id'       => $employee->id,
            'creator_unit_id'  => $register->id,
            'register_unit_id' => $register->id,
            'created_by'       => $user->id,
            'export_number'    => $number,
            'status'           => DocumentStatus::Pending,
            'folder'           => (string) Str::uuid(),
            'original_path'    => 'documents/x/original.pdf',
        ]);

        if ($deleted) {
            $document->delete();
        }

        return $document;
    }

    public function test_a_cold_register_needs_a_typed_number(): void
    {
        $register = $this->register();
        $service = app(ExportNumbers::class);

        $this->assertNull($service->next($register));

        try {
            $this->claim($register);
            $this->fail('A cold register must refuse an untyped claim.');
        } catch (DocumentRouteException $e) {
            $this->assertSame('هذا أول مستند صادر في الإدارة، يرجى إدخال رقم الصادر لتحديد نقطة البداية.', $e->getMessage());
        }
    }

    public function test_the_first_typed_number_seeds_the_register(): void
    {
        $register = $this->register();

        $this->assertSame(186, $this->claim($register, 186));
        $this->assertSame(187, app(ExportNumbers::class)->next($register));
        $this->assertSame(187, $this->claim($register));
    }

    public function test_a_higher_typed_number_moves_the_counter(): void
    {
        $register = $this->register();
        $this->claim($register, 10);

        $this->assertSame(50, $this->claim($register, 50));
        $this->assertSame(50, DocumentCounter::where('unit_id', $register->id)->value('current_value'));
        $this->assertSame(51, $this->claim($register));
    }

    public function test_a_lower_typed_number_is_taken_and_the_counter_stays(): void
    {
        $register = $this->register();
        $this->claim($register, 100);

        $this->assertSame(40, $this->claim($register, 40));
        $this->assertSame(100, DocumentCounter::where('unit_id', $register->id)->value('current_value'));
        $this->assertSame(101, $this->claim($register));
    }

    public function test_a_used_number_is_refused_even_when_its_document_is_deleted(): void
    {
        $register = $this->register();
        $this->claim($register, 100);
        $this->documentWith($register, 40, deleted: true);

        $this->assertTrue(app(ExportNumbers::class)->isUsed($register, 40));
        $this->assertFalse(app(ExportNumbers::class)->isUsed($register, 41));

        try {
            $this->claim($register, 40);
            $this->fail('A used number must be refused.');
        } catch (DocumentRouteException $e) {
            $this->assertSame('رقم الصادر 40 مستخدم في سجل هذه الإدارة.', $e->getMessage());
        }

        $this->assertSame(100, DocumentCounter::where('unit_id', $register->id)->value('current_value'));
    }

    public function test_registers_are_apart(): void
    {
        $finance = $this->register('إدارة المالية');
        $hr = $this->register('إدارة الموارد البشرية');

        $this->claim($finance, 1);
        $this->claim($hr, 1);
        $this->documentWith($finance, 7);

        $this->assertSame(2, $this->claim($finance));
        $this->assertSame(2, $this->claim($hr));
        $this->assertFalse(app(ExportNumbers::class)->isUsed($hr, 7));
        $this->assertSame(7, $this->claim($hr, 7));
    }

    public function test_a_typed_number_outside_the_range_is_refused(): void
    {
        $register = $this->register();

        foreach ([0, -5, 1000000000] as $typed) {
            try {
                $this->claim($register, $typed);
                $this->fail("{$typed} must be refused.");
            } catch (DocumentRouteException $e) {
                $this->assertSame('رقم الصادر يجب أن يكون بين 1 و 999999999.', $e->getMessage());
            }
        }

        $this->assertNull(app(ExportNumbers::class)->next($register));
        $this->assertSame(999999999, $this->claim($register, 999999999));

        try {
            $this->claim($register);
            $this->fail('An untyped claim past the range must be refused.');
        } catch (DocumentRouteException $e) {
            $this->assertSame('رقم الصادر يجب أن يكون بين 1 و 999999999.', $e->getMessage());
        }

        $this->assertSame(999999999, DocumentCounter::where('unit_id', $register->id)->value('current_value'));
    }

    public function test_claims_in_turn_never_repeat(): void
    {
        $register = $this->register();
        $this->claim($register, 186);

        $claimed = [];
        for ($i = 0; $i < 10; $i++) {
            $claimed[] = $this->claim($register);
        }

        $this->assertSame(range(187, 196), $claimed);
        $this->assertSame(196, DocumentCounter::where('unit_id', $register->id)->value('current_value'));
    }
}
