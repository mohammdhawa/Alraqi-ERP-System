<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Enums\RejectionReason;
use App\Modules\Auth\Enums\ScopeType;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Attendance\Concerns\AttendanceTree;
use Tests\TestCase;

/**
 * GET /api/attendance/records — HR's and managers' list (legacy's
 * /attendance/records), row-scoped by GAP-3 through the employee's CURRENT
 * unit (D6).
 *
 * Legacy: admins and `can_view_attendance` saw everyone; managers and chiefs
 * their own department. The ERP says the same with grants: a company-wide
 * grant sees everyone, a unit grant the unit's subtree, a managed grant the
 * units its holder manages.
 */
class AttendanceRecordsOversightTest extends TestCase
{
    use AttendanceTree;
    use RefreshDatabase;

    private const VIEW = ['attendance.records.view'];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-21 18:00:00');
        $this->buildTree();

        foreach (['a', 'a1', 'b'] as $key) {
            $this->checkIn($this->people[$key]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @param  array<string, mixed>  $query */
    private function list(array $query = []): TestResponse
    {
        return $this->getJson('/api/attendance/records?'.http_build_query($query));
    }

    /** @return array<int, int> */
    private function employeeIds(TestResponse $response): array
    {
        $ids = array_column($response->json('data'), 'employee_id');
        sort($ids);

        return $ids;
    }

    /** @return array<int, int> */
    private function idsOf(string ...$keys): array
    {
        $ids = array_map(fn (string $key): int => $this->people[$key]->id, $keys);
        sort($ids);

        return $ids;
    }

    // ── Who sees what ────────────────────────────────────────────────────

    public function test_a_company_wide_grant_sees_everyone(): void
    {
        $this->actingAsScopedUser(self::VIEW, ScopeType::Company);

        $this->assertSame($this->idsOf('a', 'a1', 'b'), $this->employeeIds($this->list()->assertOk()));
    }

    public function test_a_unit_grant_sees_its_subtree_only(): void
    {
        $this->actingAsScopedUser(self::VIEW, ScopeType::Unit, $this->units['a']->id);

        $response = $this->list()->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertSame($this->idsOf('a', 'a1'), $this->employeeIds($response));
    }

    public function test_a_managed_grant_sees_the_units_its_holder_manages(): void
    {
        $this->actingAsScopedUser(self::VIEW, ScopeType::Managed, employeeId: $this->people['headA']->id);

        $this->assertSame($this->idsOf('a', 'a1'), $this->employeeIds($this->list()->assertOk()));
    }

    public function test_scope_follows_the_employees_current_unit(): void
    {
        // D6: no unit snapshot on the row — after a transfer the new unit's
        // manager sees the history and the old one does not.
        $this->people['b']->update(['department_id' => $this->units['a1']->id]);
        $this->actingAsScopedUser(self::VIEW, ScopeType::Unit, $this->units['a']->id);

        $this->assertSame($this->idsOf('a', 'a1', 'b'), $this->employeeIds($this->list()));
    }

    public function test_a_foreign_unit_filter_leaks_nothing(): void
    {
        $this->actingAsScopedUser(self::VIEW, ScopeType::Unit, $this->units['a']->id);

        $this->list(['department_id' => $this->units['b']->id])->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_without_the_permission_it_is_403(): void
    {
        $this->actingAsRolelessUser();

        $this->list()->assertForbidden();
    }

    public function test_an_employees_own_account_reaches_nothing_here(): void
    {
        Sanctum::actingAs($this->accountOf($this->people['a']));

        $this->list()->assertForbidden();
    }

    // ── Filters ──────────────────────────────────────────────────────────

    public function test_a_unit_filter_takes_its_whole_subtree(): void
    {
        $this->actingAsScopedUser(self::VIEW, ScopeType::Company);

        $this->assertSame($this->idsOf('a', 'a1'), $this->employeeIds($this->list(['department_id' => $this->units['a']->id])));
        $this->assertSame($this->idsOf('a1'), $this->employeeIds($this->list(['department_id' => $this->units['a1']->id])));
    }

    public function test_employee_and_unit_lists(): void
    {
        $this->actingAsScopedUser(self::VIEW, ScopeType::Company);

        $this->assertSame($this->idsOf('a', 'b'), $this->employeeIds($this->list([
            'employee_ids' => $this->people['a']->id.','.$this->people['b']->id,
        ])));
        $this->assertSame($this->idsOf('a1', 'b'), $this->employeeIds($this->list([
            'department_ids' => $this->units['a1']->id.','.$this->units['b']->id,
        ])));
    }

    public function test_search_matches_a_name_part_or_the_employee_number(): void
    {
        // The tree's names come from faker's ar_SA list, which holds زهير too —
        // pin the neighbours' names so only b can match it.
        foreach (['a', 'a1'] as $key) {
            $this->people[$key]->update(['first_name' => 'سالم', 'middle_name' => 'سالم', 'last_name' => 'العتيبي']);
        }
        $this->people['b']->update(['first_name' => 'زهير']);
        $this->actingAsScopedUser(self::VIEW, ScopeType::Company);

        $this->assertSame($this->idsOf('b'), $this->employeeIds($this->list(['search' => 'زهير'])));
        $this->assertSame($this->idsOf('a'), $this->employeeIds($this->list(['search' => $this->people['a']->employee_number])));
        $this->list(['search' => '%'])->assertJsonPath('meta.total', 0);
    }

    public function test_the_shared_filters_and_the_rejection_reason(): void
    {
        $this->checkOut($this->people['a'], attributes: ['rejected_at' => now(), 'rejection_reason' => RejectionReason::Location]);
        $this->actingAsScopedUser(self::VIEW, ScopeType::Company);

        $this->list(['rejected' => 1])->assertJsonPath('meta.total', 1);
        $this->list(['rejection_reason' => RejectionReason::Location->value])->assertJsonPath('meta.total', 1);
        $this->list(['rejection_reason' => RejectionReason::Selfie->value])->assertJsonPath('meta.total', 0);
        $this->list(['date' => '2026-06-20'])->assertJsonPath('meta.total', 0);
    }

    public function test_bad_filters_are_422(): void
    {
        $this->actingAsScopedUser(self::VIEW, ScopeType::Company);

        $this->list(['employee_ids' => 'a,b'])->assertStatus(422)->assertJsonValidationErrors('employee_ids.0');
        $this->list(['rejection_reason' => 9])->assertStatus(422)->assertJsonValidationErrors('rejection_reason');
        $this->list(['sort' => 'latitude'])->assertStatus(422)->assertJsonValidationErrors('sort');
    }

    // ── The row ──────────────────────────────────────────────────────────

    public function test_each_row_embeds_its_employee(): void
    {
        $this->actingAsScopedUser(self::VIEW, ScopeType::Company);

        $row = collect($this->list()->json('data'))->firstWhere('employee_id', $this->people['a']->id);
        $this->assertSame([
            'id'              => $this->people['a']->id,
            'full_name'       => $this->people['a']->full_name,
            'employee_number' => $this->people['a']->employee_number,
        ], $row['employee']);
    }

    public function test_a_refusal_names_who_refused_it(): void
    {
        $reviewer = $this->actingAsScopedUser(self::VIEW, ScopeType::Company);
        $this->checkOut($this->people['a'], attributes: [
            'rejected_at' => now(), 'rejected_by' => $reviewer->id, 'rejection_reason' => RejectionReason::Selfie,
        ]);

        $row = collect($this->list(['rejected' => 1])->json('data'))->sole();
        $this->assertSame($reviewer->id, $row['rejected_by']['id']);
        $this->assertArrayHasKey('name', $row['rejected_by']);
        $this->assertNull(collect($this->list(['rejected' => 0])->json('data'))->first()['rejected_by']);
    }

    public function test_the_list_does_not_query_per_row(): void
    {
        $reviewer = $this->actingAsScopedUser(self::VIEW, ScopeType::Company);
        foreach (['a', 'a1', 'b'] as $key) {
            $this->checkOut($this->people[$key], attributes: ['rejected_at' => now(), 'rejected_by' => $reviewer->id, 'rejection_reason' => RejectionReason::Other]);
        }

        $this->list()->assertOk();   // warm the permission cache, so both measurements see the same

        $this->assertSame($this->queriesFor(6), $this->queriesFor(9, function () use ($reviewer): void {
            foreach (['a', 'a1', 'b'] as $key) {
                $this->checkIn($this->people[$key], '2026-06-21 09:00:00', ['rejected_at' => now(), 'rejected_by' => $reviewer->id]);
            }
        }), 'three more rows cost no more queries');
    }

    /** The queries one list request runs, after $before has added rows unmeasured. */
    private function queriesFor(int $rows, ?\Closure $before = null): int
    {
        if ($before !== null) {
            $before();
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->list()->assertOk()->assertJsonCount($rows, 'data');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
