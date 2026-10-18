<?php

declare(strict_types=1);

namespace Tests\Feature\Leave;

use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Leave\Concerns\LeaveFixtures;
use Tests\TestCase;

/**
 * The leave-type catalogue — legacy's LeaveTypeTest on ERP routes
 * (LEAVE_BUILD.md §3).
 *
 * Seeded with the nine types legacy's database holds (Q3), with their live
 * flags except that sick leave does not deduct and requires an attachment.
 * Reading the active types is open to any authenticated caller — the
 * employee's leave form needs them; the writes are company-only grants.
 */
class LeaveTypeTest extends TestCase
{
    use LeaveFixtures;
    use RefreshDatabase;

    private function codes(TestResponse $response): array
    {
        return array_column($response->json('data'), 'code');
    }

    private function valid(array $overrides = []): array
    {
        return $overrides + [
            'code'            => 'hajj',
            'name_ar'         => 'إجازة حج',
            'name_en'         => 'Hajj leave',
            'deducts_balance' => false,
            'for_requests'    => true,
            'for_excuses'     => false,
            'requires_reason' => false,
        ];
    }

    // ── The seed ─────────────────────────────────────────────────────────

    public function test_the_migration_seeds_the_nine_live_types(): void
    {
        $this->assertSame(
            ['annual', 'emergency', 'sick', 'unpaid', 'official_mission', 'bereavement', 'attendance_excused', 'external_task', 'study_leave'],
            LeaveType::orderBy('id')->pluck('code')->all(),
        );

        $sick = $this->type('sick');
        $this->assertFalse($sick->deducts_balance, 'Q3: sick leave does not deduct');
        $this->assertTrue($sick->requires_reason);
        $this->assertTrue($sick->requires_attachment, 'Q3: the medical report');

        $annual = $this->type('annual');
        $this->assertTrue($annual->deducts_balance);
        $this->assertTrue($annual->for_requests);
        $this->assertTrue($annual->for_excuses);

        $mission = $this->type('official_mission');
        $this->assertFalse($mission->for_requests);
        $this->assertTrue($mission->for_excuses);

        $this->assertSame('حضور مبرر', $this->type('attendance_excused')->name_ar);
        $this->assertSame(['sick'], LeaveType::where('requires_attachment', true)->pluck('code')->all());
        $this->assertSame(['annual', 'emergency'], LeaveType::where('deducts_balance', true)->orderBy('id')->pluck('code')->all());
    }

    // ── Reading ──────────────────────────────────────────────────────────

    public function test_any_authenticated_user_reads_the_active_types_in_order(): void
    {
        $this->getJson('/api/leave/types')->assertUnauthorized();

        $this->actingAsRolelessUser();

        $response = $this->getJson('/api/leave/types')->assertOk()->assertJsonCount(9, 'data');
        $this->assertSame('annual', $response->json('data.0.code'));
        $this->assertSame('إجازة سنوية', $response->json('data.0.name_ar'));
        $this->assertTrue($response->json('data.2.requires_attachment'));
    }

    public function test_for_requests_and_for_excuses_filter_to_each_form(): void
    {
        $this->actingAsRolelessUser();

        $this->assertSame(['annual', 'emergency', 'sick', 'unpaid'], $this->codes($this->getJson('/api/leave/types?for=requests')->assertOk()));
        $this->getJson('/api/leave/types?for=excuses')->assertOk()->assertJsonCount(9, 'data');
        $this->getJson('/api/leave/types?for=other')->assertStatus(422)->assertJsonValidationErrors('for');
    }

    public function test_it_filters_by_balance_impact(): void
    {
        $this->actingAsRolelessUser();

        $this->assertSame(['annual', 'emergency'], $this->codes($this->getJson('/api/leave/types?deducts_balance=1')->assertOk()));
        $this->getJson('/api/leave/types?deducts_balance=0')->assertOk()->assertJsonCount(7, 'data');
    }

    public function test_retired_types_are_hidden_and_listed_only_for_leave_types_update(): void
    {
        $this->type('study_leave')->update(['is_active' => false]);

        $this->actingAsRolelessUser();
        $this->getJson('/api/leave/types')->assertOk()->assertJsonCount(8, 'data');
        $this->getJson('/api/leave/types?active=0')->assertForbidden();

        $this->actingAsUserWithPermissions(['leave.types.update']);
        $this->assertSame(['study_leave'], $this->codes($this->getJson('/api/leave/types?active=0')->assertOk()));
    }

    // ── Writing ──────────────────────────────────────────────────────────

    public function test_creating_a_type(): void
    {
        $this->actingAsUserWithPermissions(['leave.types.create']);

        $this->postJson('/api/leave/types', $this->valid())
            ->assertCreated()
            ->assertJsonPath('message', 'تم إنشاء نوع الإجازة.')
            ->assertJsonPath('data.code', 'hajj')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.requires_attachment', false);

        $this->assertSame('إجازة حج', $this->type('hajj')->name_ar);
    }

    public function test_a_duplicate_code_or_name_is_refused(): void
    {
        $this->actingAsUserWithPermissions(['leave.types.create']);

        $this->postJson('/api/leave/types', $this->valid(['code' => 'annual']))
            ->assertStatus(422)->assertJsonValidationErrors('code');
        $this->postJson('/api/leave/types', $this->valid(['name_ar' => 'إجازة سنوية']))
            ->assertStatus(422)->assertJsonValidationErrors('name_ar');
        $this->postJson('/api/leave/types', $this->valid(['name_en' => 'Annual leave']))
            ->assertStatus(422)->assertJsonValidationErrors('name_en');
    }

    public function test_the_code_is_lower_snake_case(): void
    {
        $this->actingAsUserWithPermissions(['leave.types.create']);

        $this->postJson('/api/leave/types', $this->valid(['code' => 'Bad-Code']))
            ->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_a_type_keeps_its_own_name_on_update(): void
    {
        $this->actingAsUserWithPermissions(['leave.types.update']);
        $annual = $this->type('annual');

        $this->patchJson("/api/leave/types/{$annual->id}", ['name_ar' => 'إجازة سنوية', 'code' => 'annual'])
            ->assertOk()
            ->assertJsonPath('message', 'تم تعديل نوع الإجازة.');

        $this->patchJson("/api/leave/types/{$annual->id}", ['name_ar' => 'إجازة طارئة'])
            ->assertStatus(422)->assertJsonValidationErrors('name_ar');
    }

    public function test_the_balance_policy_can_be_changed(): void
    {
        $this->actingAsUserWithPermissions(['leave.types.update']);
        $unpaid = $this->type('unpaid');

        $this->patchJson("/api/leave/types/{$unpaid->id}", ['deducts_balance' => true])
            ->assertOk()->assertJsonPath('data.deducts_balance', true);

        $this->assertTrue($unpaid->fresh()->deducts_balance);
    }

    public function test_each_write_needs_its_own_permission(): void
    {
        $annual = $this->type('annual');
        $this->actingAsUserWithPermissions(['leave.types.update']);

        $this->postJson('/api/leave/types', $this->valid())->assertForbidden();
        $this->deleteJson("/api/leave/types/{$annual->id}")->assertForbidden();

        $this->actingAsUserWithPermissions(['leave.types.create']);
        $this->patchJson("/api/leave/types/{$annual->id}", ['name_ar' => 'جديد'])->assertForbidden();
    }

    public function test_an_unused_type_is_deleted(): void
    {
        $this->actingAsUserWithPermissions(['leave.types.delete']);
        $study = $this->type('study_leave');

        $this->deleteJson("/api/leave/types/{$study->id}")
            ->assertOk()
            ->assertJsonPath('message', 'تم حذف نوع الإجازة.');

        $this->assertSoftDeleted($study);
    }

    public function test_a_type_in_use_is_deactivated_rather_than_deleted(): void
    {
        $this->actingAsUserWithPermissions(['leave.types.delete']);
        $annual = $this->type('annual');
        LeaveRequest::factory()->forType('annual')->create();

        $this->deleteJson("/api/leave/types/{$annual->id}")
            ->assertOk()
            ->assertJsonPath('message', 'نوع الإجازة مستخدم، فتم إيقافه بدلاً من حذفه.')
            ->assertJsonPath('data.is_active', false);

        $this->assertNotSoftDeleted($annual);
        $this->assertFalse($annual->fresh()->is_active);
    }

    public function test_an_unknown_type_is_not_found(): void
    {
        $this->actingAsUserWithPermissions(['leave.types.update']);

        $this->patchJson('/api/leave/types/999999', ['name_ar' => 'x'])->assertNotFound();
    }
}
