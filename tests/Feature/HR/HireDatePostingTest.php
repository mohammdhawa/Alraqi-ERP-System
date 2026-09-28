<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Departments\Enums\DepartmentLevel;
use App\Modules\Departments\Models\Department;
use App\Modules\HR\Enums\AssignmentReason;
use App\Modules\HR\Exceptions\AssignmentConflictException;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GAP-19 — `hire_date` and the posting history say one thing.
 *
 * The opening تعيين posting is dated from the hire, and only its creation used
 * to read `hire_date`: correcting the date afterwards left the history saying
 * the person was posted before they were hired, or was employed on days they
 * held no unit. The Batch 4 importer re-runs against HR corrections, so this
 * had to hold before it was written.
 *
 * Model-level cases call the model directly, because the importer will: the
 * hooks are what bind it, not the HTTP layer.
 */
class HireDatePostingTest extends TestCase
{
    use RefreshDatabase;

    private Department $finance;

    private Department $engineering;

    protected function setUp(): void
    {
        parent::setUp();

        $root = Department::create(['name' => 'الإدارة العامة', 'level' => DepartmentLevel::GeneralAdministration->value]);
        $this->finance = Department::create(['name' => 'الإدارة المالية', 'parent_id' => $root->id, 'level' => DepartmentLevel::Division->value]);
        $this->engineering = Department::create(['name' => 'الإدارة الهندسية', 'parent_id' => $root->id, 'level' => DepartmentLevel::Division->value]);
    }

    private function hired(string $on): Employee
    {
        return Employee::factory()->named('نور الشامي')->create([
            'department_id' => $this->finance->id,
            'hire_date'     => $on,
        ]);
    }

    private function opening(Employee $employee): EmployeeAssignment
    {
        return $employee->assignments()->reorder()->orderBy('from_date')->orderBy('id')->firstOrFail();
    }

    // ── A correction moves the opening posting ───────────────────────────

    public function test_correcting_the_hire_date_later_moves_the_opening_posting(): void
    {
        $employee = $this->hired('2025-05-02');

        $employee->update(['hire_date' => '2025-05-05']);

        $this->assertSame('2025-05-05', $this->opening($employee)->from_date->toDateString());
        $this->assertCount(1, $employee->assignments()->get(), 'moved, not duplicated');
    }

    public function test_correcting_it_earlier_leaves_no_day_employed_in_no_unit(): void
    {
        $employee = $this->hired('2025-05-05');

        $employee->update(['hire_date' => '2025-04-01']);

        $this->assertSame('2025-04-01', $this->opening($employee)->from_date->toDateString());
        $this->assertSame(
            $this->finance->id,
            EmployeeAssignment::where('employee_id', $employee->id)->onDate('2025-04-01')->value('department_id'),
            'on the corrected hire date they hold their unit',
        );
    }

    public function test_the_opening_posting_moves_even_once_it_is_closed(): void
    {
        // A transfer since: the opening is closed, and a correction inside it
        // still moves its start.
        $employee = $this->hired('2025-01-01');
        $employee->update(['department_id' => $this->engineering->id]);

        $employee->refresh()->update(['hire_date' => '2025-02-01']);

        $this->assertSame('2025-02-01', $this->opening($employee)->from_date->toDateString());
        $this->assertCount(2, $employee->assignments()->get());
    }

    public function test_a_correction_and_a_transfer_in_one_save_both_land(): void
    {
        $employee = $this->hired('2025-01-01');

        $employee->update(['hire_date' => '2024-12-01', 'department_id' => $this->engineering->id]);

        $history = $employee->assignments()->reorder()->orderBy('from_date')->get();
        $this->assertSame('2024-12-01', $history->first()->from_date->toDateString());
        $this->assertSame($this->engineering->id, $history->last()->department_id);
        $this->assertNull($history->last()->to_date);
    }

    public function test_the_move_is_audited(): void
    {
        $employee = $this->hired('2025-05-02');

        $employee->update(['hire_date' => '2025-05-05']);

        $this->assertTrue(
            DB::table('audit_logs')
                ->where('auditable_type', (new EmployeeAssignment)->getMorphClass())
                ->where('auditable_id', $this->opening($employee)->id)
                ->where('event', 'updated')
                ->exists(),
            'the history was corrected, and the trail says so',
        );
    }

    /**
     * Found while building GAP-19: the posting hook read `wasRecentlyCreated`,
     * which Laravel leaves true for the instance's whole life — so ANY later
     * save of an employee created in the same process was taken for the
     * insert, and wrote a نقل داخلي posting dated today. The importer creates
     * and then updates; it would have filled the history with transfers that
     * never happened.
     */
    public function test_editing_an_unrelated_field_on_a_just_created_employee_writes_no_posting(): void
    {
        $employee = $this->hired('2025-01-01');

        $employee->update(['phone' => '+963900000000']);

        $this->assertCount(1, $employee->assignments()->get());
        $this->assertSame(AssignmentReason::Hire, $this->opening($employee)->reason);
    }

    // ── What a correction may not do ─────────────────────────────────────

    public function test_a_correction_past_the_end_of_the_opening_posting_is_refused(): void
    {
        $employee = $this->hired('2025-01-01');
        $employee->update(['department_id' => $this->engineering->id]);
        $closedOn = $this->opening($employee->refresh())->to_date->toDateString();

        try {
            $employee->update(['hire_date' => now()->toDateString()]);
            $this->fail('a correction crossing a later posting must be refused');
        } catch (AssignmentConflictException) {
        }

        $this->assertSame('2025-01-01', $employee->fresh()->hire_date->toDateString(), 'refused before the write');
        $this->assertSame($closedOn, $this->opening($employee)->to_date->toDateString());
    }

    public function test_a_first_posting_that_is_not_a_hire_does_not_move_and_may_not_precede_it(): void
    {
        // Hired with no unit, placed later by a transfer: that posting began on
        // its own date, not on the hire.
        $employee = Employee::factory()->named('نور الشامي')->create(['hire_date' => '2025-01-01']);
        $employee->assignments()->create([
            'department_id' => $this->finance->id,
            'from_date'     => '2025-03-01',
            'reason'        => AssignmentReason::Transfer,
        ]);

        $employee->refresh()->update(['hire_date' => '2025-02-01']);
        $this->assertSame('2025-03-01', $this->opening($employee)->from_date->toDateString(), 'not dragged');

        $this->expectException(AssignmentConflictException::class);
        $employee->refresh()->update(['hire_date' => '2025-04-01']);
    }

    public function test_setting_a_first_hire_date_moves_an_opening_dated_from_today(): void
    {
        // Created with no hire date, the opening posting was dated today.
        $employee = Employee::factory()->named('نور الشامي')->create(['department_id' => $this->finance->id, 'hire_date' => null]);

        $employee->update(['hire_date' => '2025-06-01']);

        $this->assertSame('2025-06-01', $this->opening($employee)->from_date->toDateString());
    }

    // ── No posting starts before the hire ────────────────────────────────

    public function test_a_posting_may_not_start_before_the_hire_from_any_writer(): void
    {
        $employee = $this->hired('2025-05-05');

        $this->expectException(AssignmentConflictException::class);
        $this->opening($employee)->update(['from_date' => '2025-05-01']);
    }

    public function test_closing_a_posting_that_predates_this_rule_is_not_refused(): void
    {
        // The dev database held exactly this: an opening three days before the
        // hire. Transferring such a person must still work.
        $employee = $this->hired('2025-05-05');
        DB::table('employee_assignments')->where('employee_id', $employee->id)->update(['from_date' => '2025-05-02']);

        $employee->refresh()->update(['department_id' => $this->engineering->id]);

        $this->assertCount(2, $employee->assignments()->get());
    }

    // ── Over HTTP: field-level 422s ──────────────────────────────────────

    public function test_the_employee_endpoint_names_hire_date(): void
    {
        $this->actingAsAdmin();
        $employee = $this->hired('2025-01-01');
        $employee->update(['department_id' => $this->engineering->id]);

        $this->putJson("/api/hr/employees/{$employee->id}", ['hire_date' => now()->toDateString()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['hire_date']);

        $this->putJson("/api/hr/employees/{$employee->id}", ['hire_date' => '2024-12-15'])
            ->assertOk();
        $this->assertSame('2024-12-15', $this->opening($employee)->from_date->toDateString());
    }

    public function test_the_assignments_endpoint_refuses_back_dating_past_the_hire(): void
    {
        // Back-dating is ordinary; back-dating to before the person worked here
        // amended the opening posting in place and broke the history.
        $this->actingAsAdmin();
        $employee = $this->hired('2025-05-05');

        $this->postJson("/api/hr/employees/{$employee->id}/assignments", [
            'department_id' => $this->engineering->id,
            'from_date'     => '2025-05-01',
            'reason'        => AssignmentReason::Transfer,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.from_date.0', 'تاريخ بدء التعيين لا يمكن أن يسبق تاريخ تعيين الموظف (2025-05-05).');

        $this->assertSame('2025-05-05', $this->opening($employee)->from_date->toDateString());
    }
}
