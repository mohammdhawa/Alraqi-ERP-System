<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Departments\Enums\DepartmentLevel;
use App\Modules\Departments\Models\Department;
use App\Modules\HR\Enums\AssignmentReason;
use App\Modules\HR\Exceptions\AssignmentConflictException;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeAssignment;
use App\Modules\HR\Models\JobTitle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * سجل التعيين — the posting history that replaces the workbook's three frozen
 * `بداية التعيين` columns.
 *
 * The heart of this file is the SYNCHRONISATION PAIR: `employees.department_id`
 * is a denormalised copy of whatever the open assignment says, kept honest by a
 * model hook in each direction. Those hooks are the reason the copy is safe to
 * keep at all, so both directions are asserted, and so is the fact that neither
 * bounces off the other.
 */
class EmployeeAssignmentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{root: Department, finance: Department, engineering: Department}
     */
    private function tree(): array
    {
        $root = Department::create([
            'name'  => 'الإدارة العامة',
            'level' => DepartmentLevel::GeneralAdministration->value,
        ]);

        return [
            'root'    => $root,
            'finance' => Department::create([
                'name' => 'الإدارة المالية', 'parent_id' => $root->id,
                'level' => DepartmentLevel::Division->value,
            ]),
            'engineering' => Department::create([
                'name' => 'الإدارة الهندسية', 'parent_id' => $root->id,
                'level' => DepartmentLevel::Division->value,
            ]),
        ];
    }

    // ── The opening posting ──────────────────────────────────────────────

    /**
     * The first row of the history is the initial posting — the row that makes
     * the three frozen workbook columns a query instead of three columns.
     * It is written by the model hook, so the Batch 4 importer gets it simply
     * by creating the employee.
     */
    public function test_creating_an_employee_in_a_department_opens_a_posting(): void
    {
        $tree = $this->tree();

        $employee = Employee::factory()->named('نور الشامي')->create([
            'department_id' => $tree['finance']->id,
            'hire_date'     => '2026-03-01',
        ]);

        $this->assertCount(1, $employee->assignments);

        $opening = $employee->assignments()->first();
        $this->assertSame($tree['finance']->id, $opening->department_id);
        $this->assertSame('2026-03-01', $opening->from_date->toDateString());
        $this->assertNull($opening->to_date, 'the opening posting is the current one');
        $this->assertSame(AssignmentReason::Hire, $opening->reason);
    }

    /**
     * An employee with no unit has no posting to record. Inventing one would
     * put a row in the history claiming a placement that never happened.
     */
    public function test_an_employee_with_no_unit_gets_no_posting(): void
    {
        $employee = Employee::factory()->named('نور الشامي')->create();

        $this->assertCount(0, $employee->assignments);
    }

    // ── The synchronisation pair ─────────────────────────────────────────

    /**
     * Direction 1: writing the employee's pointer records history. This covers
     * tinker, the importer and any future module — not just HTTP.
     */
    public function test_changing_the_employees_department_closes_and_opens_a_posting(): void
    {
        $tree = $this->tree();
        $employee = Employee::factory()->named('نور الشامي')->create([
            'department_id' => $tree['finance']->id,
            'hire_date'     => '2026-01-01',
        ]);

        $employee->update(['department_id' => $tree['engineering']->id]);

        $history = $employee->refresh()->assignments()->get();
        $this->assertCount(2, $history, 'the previous posting is preserved, not overwritten');

        $current = $history->firstWhere('to_date', null);
        $this->assertSame($tree['engineering']->id, $current->department_id);
        $this->assertSame(AssignmentReason::Transfer, $current->reason);

        $closed = $history->first(fn (EmployeeAssignment $a): bool => $a->to_date !== null);
        $this->assertSame($tree['finance']->id, $closed->department_id);

        // Closed the day BEFORE the new one opens, exactly as the assignments
        // endpoint does it — see the overlap assertion below for why.
        $this->assertSame(now()->subDay()->toDateString(), $closed->to_date->toDateString());
        $this->assertSame(now()->toDateString(), $current->from_date->toDateString());
    }

    /**
     * The plain employee-update path must leave no overlapping day either.
     *
     * `scopeOnDate()` matches `from_date <= D AND (to_date IS NULL OR
     * to_date >= D)`, so closing the outgoing posting on the incoming one's
     * start date makes BOTH match and "which unit were they in on D?" has two
     * answers. Payroll asks that for every employee every month — and THIS is
     * the path the importer and every ordinary edit take, so a discrepancy here
     * would be the common case rather than the rare one.
     */
    public function test_a_transfer_through_the_employee_leaves_no_overlapping_day(): void
    {
        $tree = $this->tree();
        $employee = Employee::factory()->named('نور الشامي')->create([
            'department_id' => $tree['finance']->id,
            'hire_date'     => '2026-01-01',
        ]);

        $employee->update(['department_id' => $tree['engineering']->id]);

        $inForce = EmployeeAssignment::query()
            ->where('employee_id', $employee->id)
            ->onDate(now()->toDateString())
            ->get();

        $this->assertCount(1, $inForce, 'exactly one posting is in force on the handover date');
        $this->assertSame($tree['engineering']->id, $inForce->first()->department_id);
    }

    /**
     * A posting that has not yet elapsed is AMENDED, not closed.
     *
     * `hire_date` may legitimately be in the future — someone hired with a start
     * date next month — and the opening posting carries that date. Closing it
     * "yesterday" would date it to end before it began, which
     * EmployeeAssignment's own guard refuses outright. There is no history to
     * preserve in that case: the posting never described a period that elapsed.
     */
    public function test_a_future_dated_opening_posting_is_amended_not_closed(): void
    {
        $tree  = $this->tree();
        $start = now()->addMonth()->toDateString();

        $employee = Employee::factory()->named('نور الشامي')->create([
            'department_id' => $tree['finance']->id,
            'hire_date'     => $start,
        ]);

        $employee->update(['department_id' => $tree['engineering']->id]);

        $history = $employee->refresh()->assignments()->get();
        $this->assertCount(1, $history, 'the not-yet-started posting is corrected in place');
        $this->assertSame($tree['engineering']->id, $history->first()->department_id);
        $this->assertSame($start, $history->first()->from_date->toDateString());
        $this->assertNull($history->first()->to_date);
    }

    /**
     * Direction 2: writing an assignment updates the employee's pointer.
     *
     * This is the half that runs through DB::table() to break the recursion —
     * if it fired model events, direction 1 would bounce it straight back and
     * the two hooks would ping-pong.
     */
    public function test_recording_a_posting_updates_the_employees_pointer(): void
    {
        $tree  = $this->tree();
        $title = JobTitle::create(['name' => 'محاسب']);
        $employee = Employee::factory()->named('نور الشامي')->create([
            'department_id' => $tree['finance']->id,
            'hire_date'     => '2026-01-01',
        ]);

        // Close the opening posting first — two open postings are refused, by
        // design, and that is asserted separately below.
        $employee->currentAssignment()->first()->update(['to_date' => '2026-05-31']);

        $employee->assignments()->create([
            'department_id'        => $tree['engineering']->id,
            'job_title_id'         => $title->id,
            'organizational_grade' => 3,
            'from_date'            => '2026-06-01',
            'reason'               => AssignmentReason::Promotion,
        ]);

        // The pointer followed the OPEN posting, without anyone touching the
        // employee row directly.
        $employee->refresh();
        $this->assertSame($tree['engineering']->id, $employee->department_id);
        $this->assertSame($title->id, $employee->job_title_id);
        $this->assertSame(3, $employee->organizational_grade);

        // …and the write-back did not bounce off the employee hook into a third
        // posting. Two rows, not three.
        $this->assertCount(2, $employee->assignments()->get());
    }

    /**
     * At most one CURRENT posting per employee.
     *
     * Not an index, because "unique among rows where this column is NULL" is a
     * partial unique index and MySQL has none. It matters because two open
     * postings give the denormalised pointer two sources and the last writer
     * wins at random.
     */
    public function test_an_employee_cannot_hold_two_current_postings(): void
    {
        $tree = $this->tree();
        $employee = Employee::factory()->named('نور الشامي')->create([
            'department_id' => $tree['finance']->id,
            'hire_date'     => '2026-01-01',
        ]);

        $this->expectException(AssignmentConflictException::class);

        $employee->assignments()->create([
            'department_id' => $tree['engineering']->id,
            'from_date'     => now()->toDateString(),
            'reason'        => AssignmentReason::Transfer,
        ]);
    }

    public function test_a_posting_cannot_end_before_it_begins(): void
    {
        $tree = $this->tree();
        $employee = Employee::factory()->named('نور الشامي')->create();

        $this->expectException(AssignmentConflictException::class);

        $employee->assignments()->create([
            'department_id' => $tree['finance']->id,
            'from_date'     => '2026-05-01',
            'to_date'       => '2026-04-01',
            'reason'        => AssignmentReason::Transfer,
        ]);
    }

    // ── The endpoint ─────────────────────────────────────────────────────

    /**
     * The outgoing posting is closed the DAY BEFORE the new one starts, so that
     * "which department was this person in on 1 October?" has exactly one
     * answer. Payroll asks that for every employee every month.
     */
    public function test_recording_a_transfer_leaves_no_overlapping_day(): void
    {
        $this->actingAsAdmin();
        $tree  = $this->tree();
        $title = JobTitle::create(['name' => 'محاسب']);
        $employee = Employee::factory()->named('نور الشامي')->create([
            'department_id' => $tree['finance']->id,
            'hire_date'     => '2026-01-01',
        ]);

        $this->postJson("/api/hr/employees/{$employee->id}/assignments", [
            'department_id'        => $tree['engineering']->id,
            'job_title_id'         => $title->id,
            'organizational_grade' => 3,
            'from_date'            => '2026-06-01',
            'reason'               => AssignmentReason::Promotion->value,
            'notes'                => 'قرار رقم 41',
        ])
            ->assertCreated()
            ->assertJsonPath('data.is_current', true)
            ->assertJsonPath('data.reason_label', 'ترقية');

        // reorder(), not orderBy(): the relation already sorts newest-first, and
        // an added orderBy would be appended behind it rather than replacing it.
        $history = $employee->assignments()->reorder('from_date')->get();
        $this->assertCount(2, $history);
        $this->assertSame('2026-05-31', $history[0]->to_date->toDateString());
        $this->assertSame('2026-06-01', $history[1]->from_date->toDateString());

        // Exactly one posting is in force on the handover date.
        $this->assertCount(1, EmployeeAssignment::query()
            ->where('employee_id', $employee->id)
            ->onDate('2026-06-01')
            ->get());

        // And the employee's pointer followed.
        $this->assertSame($tree['engineering']->id, $employee->refresh()->department_id);
        $this->assertSame(3, $employee->organizational_grade);
    }

    public function test_index_returns_the_history_newest_first(): void
    {
        $this->actingAsAdmin();
        $tree = $this->tree();
        $employee = Employee::factory()->named('نور الشامي')->create([
            'department_id' => $tree['finance']->id,
            'hire_date'     => '2026-01-01',
        ]);

        $this->postJson("/api/hr/employees/{$employee->id}/assignments", [
            'department_id' => $tree['engineering']->id,
            'from_date'     => '2026-06-01',
            'reason'        => AssignmentReason::Transfer->value,
        ])->assertCreated();

        $this->getJson("/api/hr/employees/{$employee->id}/assignments")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.from_date', '2026-06-01')
            ->assertJsonPath('data.0.is_current', true)
            ->assertJsonPath('data.1.is_current', false);
    }

    public function test_a_future_dated_posting_is_rejected(): void
    {
        $this->actingAsAdmin();
        $tree = $this->tree();
        $employee = Employee::factory()->named('نور الشامي')->create();

        $this->postJson("/api/hr/employees/{$employee->id}/assignments", [
            'department_id' => $tree['finance']->id,
            'from_date'     => now()->addMonth()->toDateString(),
            'reason'        => AssignmentReason::Transfer->value,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('from_date');
    }

    /**
     * `reason` has no default. It carries the meaning of the whole row into an
     * append-only history, and a guessed one would be wrong most of the time.
     */
    public function test_a_reason_is_required(): void
    {
        $this->actingAsAdmin();
        $tree = $this->tree();
        $employee = Employee::factory()->named('نور الشامي')->create();

        $this->postJson("/api/hr/employees/{$employee->id}/assignments", [
            'department_id' => $tree['finance']->id,
            'from_date'     => now()->toDateString(),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
    }

    public function test_index_requires_authentication(): void
    {
        $employee = Employee::factory()->named('نور الشامي')->create();

        $this->getJson("/api/hr/employees/{$employee->id}/assignments")->assertUnauthorized();
    }

    /**
     * §10 test 4. Reading history and writing it are separate permissions, and
     * the history is append-only — there is no update or delete route at all.
     */
    public function test_view_only_user_cannot_record_a_posting(): void
    {
        $tree = $this->tree();
        $employee = Employee::factory()->named('نور الشامي')->create([
            'department_id' => $tree['finance']->id,
        ]);

        $this->actingAsUserWithPermissions(['hr.assignments.view']);

        $this->getJson("/api/hr/employees/{$employee->id}/assignments")->assertOk();

        $this->postJson("/api/hr/employees/{$employee->id}/assignments", [
            'department_id' => $tree['engineering']->id,
            'from_date'     => now()->toDateString(),
            'reason'        => AssignmentReason::Transfer->value,
        ])->assertForbidden();
    }

    /**
     * A promotion states the new GRADE and nothing else — that is the whole
     * shape of the call, `ترقية` being a first-class reason. The fields it does
     * not mention must carry forward.
     *
     * Read as a replacement instead, the posting would record the person as
     * belonging to no unit and holding no title, and `syncEmployeePointer()`
     * would push that onto the employee row. Silent data loss on a routine HR
     * operation, through an append-only endpoint with no update route to repair
     * it with.
     */
    public function test_an_omitted_field_carries_forward_rather_than_clearing(): void
    {
        $this->actingAsAdmin();
        $tree  = $this->tree();
        $title = JobTitle::create(['name' => 'محاسب']);
        $employee = Employee::factory()->named('نور الشامي')->create([
            'department_id'        => $tree['finance']->id,
            'job_title_id'         => $title->id,
            'organizational_grade' => 3,
            'hire_date'            => '2026-01-01',
        ]);

        $this->postJson("/api/hr/employees/{$employee->id}/assignments", [
            'organizational_grade' => 4,
            'from_date'            => '2026-06-01',
            'reason'               => AssignmentReason::Promotion->value,
        ])
            ->assertCreated()
            ->assertJsonPath('data.department_id', $tree['finance']->id)
            ->assertJsonPath('data.job_title_id', $title->id)
            ->assertJsonPath('data.organizational_grade', 4);

        // The employee's pointer kept the unit and title it already had.
        $employee->refresh();
        $this->assertSame($tree['finance']->id, $employee->department_id);
        $this->assertSame($title->id, $employee->job_title_id);
        $this->assertSame(4, $employee->organizational_grade);
    }

    /**
     * The counterpart: an EXPLICIT null still clears. "The caller said null" and
     * "the caller said nothing" are different instructions, and only the second
     * one carries forward.
     */
    public function test_an_explicit_null_still_clears_the_field(): void
    {
        $this->actingAsAdmin();
        $tree  = $this->tree();
        $title = JobTitle::create(['name' => 'محاسب']);
        $employee = Employee::factory()->named('نور الشامي')->create([
            'department_id' => $tree['finance']->id,
            'job_title_id'  => $title->id,
            'hire_date'     => '2026-01-01',
        ]);

        $this->postJson("/api/hr/employees/{$employee->id}/assignments", [
            'job_title_id' => null,
            'from_date'    => '2026-06-01',
            'reason'       => AssignmentReason::TitleChange->value,
        ])
            ->assertCreated()
            ->assertJsonPath('data.job_title_id', null)
            ->assertJsonPath('data.department_id', $tree['finance']->id);

        $this->assertNull($employee->refresh()->job_title_id);
    }

    /**
     * The history is append-only by ROUTING, not only by convention: no verb
     * reaches an existing row.
     */
    public function test_there_is_no_route_to_edit_or_delete_history(): void
    {
        $this->actingAsAdmin();
        $tree = $this->tree();
        $employee = Employee::factory()->named('نور الشامي')->create([
            'department_id' => $tree['finance']->id,
        ]);
        $assignment = $employee->assignments()->first();

        $this->putJson("/api/hr/assignments/{$assignment->id}", ['reason' => 1])
            ->assertNotFound();
        $this->deleteJson("/api/hr/assignments/{$assignment->id}")
            ->assertNotFound();
    }
}
