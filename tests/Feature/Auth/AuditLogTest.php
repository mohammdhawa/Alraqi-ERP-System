<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Modules\Auth\Models\User;
use App\Modules\Auth\Services\AuditTrailService;
use App\Modules\HR\Models\Employee;
use App\Shared\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use LogicException;
use Tests\TestCase;

/**
 * سجل التدقيق — the read surface over audit_logs.
 *
 * ──────────────────────────────────────────────────────────────────────────
 * TWO THINGS ABOUT TESTING THIS TABLE THAT ARE NOT TRUE OF ANY OTHER
 * ──────────────────────────────────────────────────────────────────────────
 * 1. ROWS ARE INSERTED THE WAY PRODUCTION INSERTS THEM — through DB::table(),
 *    never through the model. That is not a workaround for the model's write
 *    guard, it is the point of it: AuditLogService writes this way so model
 *    events cannot fire and recurse, and a test that wrote differently would be
 *    asserting against a shape the app never produces.
 *
 * 2. THE TRAIL IS NEVER EMPTY AND NEVER HOLDS STILL. seedRbac() alone writes a
 *    `created` row per permission and per role; every factory on an audited
 *    model writes one; and the `audit` middleware appends an `api_request` row
 *    for each request the test itself makes, so the table grows while it is
 *    being read. So every LIST assertion here is scoped to SCENARIO_IP, which
 *    only the rows a test inserted on purpose carry. A total asserted without
 *    that scope passes today and breaks the first time seeding grows by one row
 *    — which is a test that fails for a reason unrelated to what it checks.
 */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    /**
     * TEST-NET-3 (RFC 5737). Nothing else in the trail can carry it: rows
     * written outside HTTP have a null ip, and the test client's own requests
     * carry 127.0.0.1.
     */
    private const SCENARIO_IP = '203.0.113.7';

    /**
     * Insert one audit row exactly as AuditLogService would.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function insertLog(array $overrides = []): int
    {
        return DB::table('audit_logs')->insertGetId(array_merge([
            'user_id'        => null,
            'event'          => 'updated',
            'auditable_type' => null,
            'auditable_id'   => null,
            'old_values'     => null,
            'new_values'     => null,
            'description'    => null,
            'ip_address'     => self::SCENARIO_IP,
            'user_agent'     => 'PHPUnit',
            'request_id'     => 'req-default',
            'created_at'     => '2026-09-16 14:03:11',
        ], $overrides));
    }

    /**
     * A list query scoped to this test's own rows. `$query` is appended as-is.
     */
    private function listScoped(string $query = ''): TestResponse
    {
        return $this->getJson('/api/auth/audit-logs?ip_address='.self::SCENARIO_IP.'&per_page=100'.$query);
    }

    // ───────────────────────────────────────────────────────────────────────
    // §10 — the five tests every resource needs
    // ───────────────────────────────────────────────────────────────────────

    public function test_it_lists_the_trail_with_the_documented_shape(): void
    {
        $this->actingAsAdmin();

        $employee = Employee::factory()->named('أحمد خالد الحمصي')->create();

        $id = $this->insertLog([
            'event'          => 'updated',
            'auditable_type' => 'employee',
            'auditable_id'   => $employee->id,
            'old_values'     => json_encode(['workplace' => 'دمشق']),
            'new_values'     => json_encode(['workplace' => 'حلب']),
        ]);

        $row = $this->listScoped()->assertOk()->assertJsonPath('success', true)->json('data.0');

        $this->assertSame([
            'id', 'event', 'event_label', 'event_group', 'description',
            'actor_type', 'user_id', 'user',
            'auditable_type', 'auditable_label', 'auditable_id', 'auditable', 'changed_fields',
            'ip_address', 'request_id', 'created_at',
        ], array_keys($row));

        $this->assertSame($id, $row['id']);
        $this->assertSame('updated', $row['event']);
        $this->assertSame('تعديل', $row['event_label']);
        $this->assertSame('model', $row['event_group']);
        $this->assertSame('employee', $row['auditable_type']);
        $this->assertSame('موظف', $row['auditable_label']);
        $this->assertSame(['workplace'], $row['changed_fields']);
        $this->assertSame($employee->id, $row['auditable']['id']);
        $this->assertStringContainsString('أحمد خالد الحمصي', $row['auditable']['label']);

        // The LIST never carries the blobs, for anyone — a deleted employee row
        // holds ~50 keys, and a hundred of those per page is a fat response.
        $this->assertArrayNotHasKey('old_values', $row);
        $this->assertArrayNotHasKey('new_values', $row);
        $this->assertArrayNotHasKey('user_agent', $row);
    }

    public function test_it_shows_one_row_in_full(): void
    {
        $this->actingAsAdmin();

        $id = $this->insertLog([
            'old_values' => json_encode(['workplace' => 'دمشق']),
            'new_values' => json_encode(['workplace' => 'حلب']),
        ]);

        $this->getJson("/api/auth/audit-logs/{$id}")
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.old_values.workplace', 'دمشق')
            ->assertJsonPath('data.new_values.workplace', 'حلب')
            ->assertJsonPath('data.user_agent', 'PHPUnit');
    }

    public function test_an_unauthenticated_caller_is_rejected(): void
    {
        $id = $this->insertLog();

        $this->getJson('/api/auth/audit-logs')->assertUnauthorized();
        $this->getJson("/api/auth/audit-logs/{$id}")->assertUnauthorized();
    }

    public function test_a_roleless_user_is_forbidden(): void
    {
        $this->actingAsRolelessUser();
        $id = $this->insertLog();

        $this->getJson('/api/auth/audit-logs')->assertForbidden();
        $this->getJson("/api/auth/audit-logs/{$id}")->assertForbidden();
    }

    /**
     * The §10 test people skip. Holding a different `.view` must not open this
     * one — and the DETAIL route is checked too, because a detail endpoint
     * guarded more loosely than its list is a back door around the list.
     */
    public function test_another_permission_does_not_open_the_trail(): void
    {
        $this->actingAsUserWithPermissions(['auth.users.view']);
        $id = $this->insertLog();

        $this->getJson('/api/auth/audit-logs')->assertForbidden();
        $this->getJson("/api/auth/audit-logs/{$id}")->assertForbidden();
    }

    public function test_an_unknown_sort_or_filter_is_a_422(): void
    {
        $this->actingAsAdmin();

        // Silently ignoring a sort returns a correct-looking page in the wrong
        // order and the caller cannot tell (C30, P3).
        $this->getJson('/api/auth/audit-logs?sort=ip_address')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sort']);

        $this->getJson('/api/auth/audit-logs?event=not_an_event')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['event.0']);

        // One bad key in a list of otherwise valid ones is still a 422 —
        // accepting the rest and dropping it is the silent fallback.
        $this->getJson('/api/auth/audit-logs?event=created,not_an_event')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['event.1']);

        $this->getJson('/api/auth/audit-logs?event_group=nope')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['event_group.0']);

        // A PHP class name is not a type key, and probing with one must not be
        // a way to learn the namespace layout (C12).
        $this->getJson('/api/auth/audit-logs?auditable_type='.urlencode(Employee::class))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['auditable_type']);

        $this->getJson('/api/auth/audit-logs?date_from=16-09-2026')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date_from']);

        $this->getJson('/api/auth/audit-logs?date_from=2026-09-16&date_to=2026-09-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date_to']);
    }

    /**
     * An id with no type matches row 42 of every table at once — not a question
     * anyone means to ask.
     */
    public function test_an_auditable_id_without_a_type_is_a_422(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/auth/audit-logs?auditable_id=42')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['auditable_type']);
    }

    // ───────────────────────────────────────────────────────────────────────
    // The values gate (C11)
    // ───────────────────────────────────────────────────────────────────────

    public function test_without_view_values_the_blobs_are_absent_not_null(): void
    {
        $this->actingAsUserWithPermissions(['auth.audit.view']);

        $id = $this->insertLog([
            'old_values' => json_encode(['organizational_grade' => 3]),
            'new_values' => json_encode(['organizational_grade' => 5]),
        ]);

        $data = $this->getJson("/api/auth/audit-logs/{$id}")->assertOk()->json('data');

        // ABSENT, never null. A null would say "this event changed nothing",
        // which is a claim about the data; an absent key is a statement about
        // the reader (API doc §1).
        $this->assertArrayNotHasKey('old_values', $data);
        $this->assertArrayNotHasKey('new_values', $data);

        // Everything else still arrives — the trail is readable without the
        // values, which is the whole reason the permissions are separate.
        $this->assertSame(['organizational_grade'], $data['changed_fields']);
        $this->assertSame('PHPUnit', $data['user_agent']);
    }

    public function test_with_view_values_the_blobs_arrive(): void
    {
        $this->actingAsUserWithPermissions(['auth.audit.view', 'auth.audit.viewValues']);

        $id = $this->insertLog([
            'old_values' => json_encode(['organizational_grade' => 3]),
            'new_values' => json_encode(['organizational_grade' => 5]),
        ]);

        $this->getJson("/api/auth/audit-logs/{$id}")
            ->assertOk()
            ->assertJsonPath('data.old_values.organizational_grade', 3)
            ->assertJsonPath('data.new_values.organizational_grade', 5);
    }

    /**
     * VIEWVALUES IS NOT A READ-AROUND FOR EVERY OTHER MODULE'S PERMISSIONS.
     *
     * HasAuditLog fills new_values from getAttributes(), so without this gate a
     * caller holding auth.audit.view + viewValues and nothing else could page
     * `?event=created,updated,deleted&auditable_type=employee` and reconstruct
     * the whole employees table — minus only the eleven strings sanitize()
     * redacts — with no HR permission ever granted. The same trick read
     * departments, job_titles, users and roles. An audit row is a copy of
     * someone else's record, and permission to read audit rows is not permission
     * to read that record.
     */
    public function test_values_also_require_the_target_module_s_own_view_permission(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAsUserWithPermissions(['auth.audit.view', 'auth.audit.viewValues']);

        $id = $this->insertLog([
            'auditable_type' => 'employee',
            'auditable_id'   => $employee->id,
            'new_values'     => json_encode(['organizational_grade' => 5]),
        ]);

        $data = $this->getJson("/api/auth/audit-logs/{$id}")->assertOk()->json('data');

        // Absent, not null — the §1 rule: this is a statement about the reader.
        $this->assertArrayNotHasKey('old_values', $data);
        $this->assertArrayNotHasKey('new_values', $data);

        // The rest of the trail still arrives. "Someone changed this employee's
        // grade at 14:03" remains answerable without reading the grade.
        $this->assertSame(['organizational_grade'], $data['changed_fields']);
        $this->assertSame('employee', $data['auditable_type']);
    }

    public function test_the_permissions_together_open_the_values(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAsUserWithPermissions([
            'auth.audit.view', 'auth.audit.viewValues',
            'hr.employees.view', 'hr.employees.viewSalary',
        ]);

        $id = $this->insertLog([
            'auditable_type' => 'employee',
            'auditable_id'   => $employee->id,
            'new_values'     => json_encode(['organizational_grade' => 5]),
        ]);

        $this->getJson("/api/auth/audit-logs/{$id}")
            ->assertOk()
            ->assertJsonPath('data.new_values.organizational_grade', 5);
    }

    /**
     * ✅ GAP-13, closed 2026-09-23.
     *
     * `sanitize()` redacts credentials, `national_id`, `salary`,
     * `agreed_salary` and `sham_cash_account` — and NOT the nine classification
     * axes, because they are ordinary integers on the row. But the axes are what
     * the pay is COMPUTED FROM, which is why `hr.employees.viewSalary` gates them
     * on the employee resource. So `hr.employees.view` + `viewValues` used to
     * read a grade straight out of history that the same caller was refused on
     * the employee itself: the gate closed at the front door and open at the
     * back.
     *
     * ⚠️ The fix requires the permission rather than redacting the axes, and
     * that choice is the reversible one. Redaction would have destroyed "who
     * changed this person's grade, and to what" permanently, on a table that can
     * be neither scrubbed nor un-scrubbed after the fact.
     */
    public function test_employee_values_also_require_the_salary_permission(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAsUserWithPermissions([
            'auth.audit.view', 'auth.audit.viewValues', 'hr.employees.view',
        ]);

        $id = $this->insertLog([
            'auditable_type' => 'employee',
            'auditable_id'   => $employee->id,
            'new_values'     => json_encode(['organizational_grade' => 5]),
        ]);

        $data = $this->getJson("/api/auth/audit-logs/{$id}")->assertOk()->json('data');

        $this->assertArrayNotHasKey('new_values', $data);
        $this->assertArrayNotHasKey('old_values', $data);

        // And the trail itself still answers the question it is for: someone
        // changed this employee's grade, and here is when and who.
        $this->assertSame(['organizational_grade'], $data['changed_fields']);
    }

    /**
     * The extra requirement is scoped to `employee` — the only audited type
     * whose columns include the pay criteria. Requiring a salary permission to
     * read a department's history would be a gate on the wrong door.
     */
    public function test_the_salary_requirement_does_not_spread_to_other_types(): void
    {
        $this->actingAsUserWithPermissions([
            'auth.audit.view', 'auth.audit.viewValues', 'departments.view',
        ]);

        $id = $this->insertLog([
            'auditable_type' => 'department',
            'auditable_id'   => 1,
            'new_values'     => json_encode(['name' => 'إدارة الإمداد والتوريد']),
        ]);

        $this->getJson("/api/auth/audit-logs/{$id}")
            ->assertOk()
            ->assertJsonPath('data.new_values.name', 'إدارة الإمداد والتوريد');
    }

    /**
     * The gate is scoped per TYPE, not granted wholesale: holding HR's read
     * permission does not open the values on an Auth row.
     */
    public function test_the_target_gate_does_not_leak_across_modules(): void
    {
        $subject = User::factory()->create();

        $this->actingAsUserWithPermissions([
            'auth.audit.view', 'auth.audit.viewValues', 'hr.employees.view',
        ]);

        $id = $this->insertLog([
            'auditable_type' => 'user',
            'auditable_id'   => $subject->id,
            'new_values'     => json_encode(['email' => 'new@example.com']),
        ]);

        $this->assertArrayNotHasKey(
            'new_values',
            $this->getJson("/api/auth/audit-logs/{$id}")->assertOk()->json('data'),
        );
    }

    /**
     * A row with NO target is nobody else's record: an api_request row holds
     * {method, path, status_code} and an auth event holds its own metadata, so
     * viewValues alone is the whole gate. The raw column decides this, not the
     * public alias — a row whose type no longer resolves still HAS a target and
     * must not borrow the action-level row's reasoning.
     */
    public function test_an_action_level_row_needs_only_view_values(): void
    {
        $this->actingAsUserWithPermissions(['auth.audit.view', 'auth.audit.viewValues']);

        $action = $this->insertLog([
            'event'      => 'api_request',
            'new_values' => json_encode(['method' => 'GET', 'status_code' => 200]),
        ]);

        $unresolvable = $this->insertLog([
            'event'          => 'updated',
            'auditable_type' => 'App\\Modules\\HR\\Models\\Retired',
            'auditable_id'   => 11,
            'new_values'     => json_encode(['salary_band' => 4]),
        ]);

        $this->getJson("/api/auth/audit-logs/{$action}")
            ->assertOk()
            ->assertJsonPath('data.new_values.status_code', 200);

        // Fails closed: we cannot say whose record this was, so we do not serve
        // it. An unmapped type is a build error (AuditTargetStubTest), not a
        // default-open case.
        $this->assertArrayNotHasKey(
            'new_values',
            $this->getJson("/api/auth/audit-logs/{$unresolvable}")->assertOk()->json('data'),
        );
    }

    /**
     * `created` has no old values and `deleted` has no new ones. Those keys are
     * NULL for a caller who may read them — a different fact from absent.
     */
    public function test_null_values_are_null_and_not_missing_for_a_permitted_caller(): void
    {
        $this->actingAsAdmin();

        $created = $this->insertLog(['event' => 'created', 'new_values' => json_encode(['name' => 'س'])]);
        $deleted = $this->insertLog(['event' => 'deleted', 'old_values' => json_encode(['name' => 'س'])]);

        $createdData = $this->getJson("/api/auth/audit-logs/{$created}")->assertOk()->json('data');
        $this->assertArrayHasKey('old_values', $createdData);
        $this->assertNull($createdData['old_values']);

        $deletedData = $this->getJson("/api/auth/audit-logs/{$deleted}")->assertOk()->json('data');
        $this->assertArrayHasKey('new_values', $deletedData);
        $this->assertNull($deletedData['new_values']);
    }

    /**
     * A redacted field is a fourth state again: the key was there, and the real
     * value never reached this table.
     */
    public function test_a_redacted_value_survives_to_the_client_verbatim(): void
    {
        $this->actingAsAdmin();

        $id = $this->insertLog([
            'event'      => 'created',
            'new_values' => json_encode(['national_id' => '***REDACTED***']),
        ]);

        $this->getJson("/api/auth/audit-logs/{$id}")
            ->assertOk()
            ->assertJsonPath('data.new_values.national_id', '***REDACTED***')
            ->assertJsonPath('data.changed_fields', ['national_id']);
    }

    // ───────────────────────────────────────────────────────────────────────
    // The three kinds of actor
    // ───────────────────────────────────────────────────────────────────────

    public function test_the_three_actor_cases_are_three_distinct_answers(): void
    {
        $this->actingAsAdmin();

        $employee = Employee::factory()->named('سامر خليل')->create();
        $actor    = User::factory()->create(['employee_id' => $employee->id]);
        $departed = User::factory()->create();
        $departed->delete();   // soft delete: the row survives, the account is gone

        $system = $this->insertLog(['user_id' => null]);
        $byUser = $this->insertLog(['user_id' => $actor->id]);
        $byGone = $this->insertLog(['user_id' => $departed->id]);

        $rows = collect($this->listScoped()->assertOk()->json('data'))->keyBy('id');

        // A seeder, console command or queued job.
        $this->assertSame('system', $rows[$system]['actor_type']);
        $this->assertNull($rows[$system]['user']);
        $this->assertNull($rows[$system]['user_id']);

        // A live account: the name comes from the linked employee, since the
        // account stores none of its own.
        $this->assertSame('user', $rows[$byUser]['actor_type']);
        $this->assertSame('سامر خليل', $rows[$byUser]['user']['name']);
        $this->assertSame($actor->email, $rows[$byUser]['user']['email']);

        // A deleted account — the case an audit is most often opened to answer.
        // user_id still names them; the object is gone.
        $this->assertSame('user', $rows[$byGone]['actor_type']);
        $this->assertSame($departed->id, $rows[$byGone]['user_id']);
        $this->assertNull($rows[$byGone]['user']);
    }

    // ───────────────────────────────────────────────────────────────────────
    // The target
    // ───────────────────────────────────────────────────────────────────────

    public function test_a_soft_deleted_target_resolves_to_null_with_its_label_intact(): void
    {
        $this->actingAsAdmin();

        $employee = Employee::factory()->named('موظف مغادر')->create();
        $employee->delete();

        $id = $this->insertLog([
            'auditable_type' => 'employee',
            'auditable_id'   => $employee->id,
        ]);

        $row = $this->getJson("/api/auth/audit-logs/{$id}")->assertOk()->json('data');

        // The client renders "{auditable_label} #{auditable_id}" from these.
        $this->assertNull($row['auditable']);
        $this->assertSame('employee', $row['auditable_type']);
        $this->assertSame('موظف', $row['auditable_label']);
        $this->assertSame($employee->id, $row['auditable_id']);
    }

    /**
     * C12: a fully-qualified class name must never reach a client — including
     * from the rows written before the morph map was enforced, which stored
     * exactly that.
     */
    public function test_a_legacy_class_name_is_translated_to_its_public_alias(): void
    {
        $this->actingAsAdmin();

        $employee = Employee::factory()->named('سجل قديم')->create();

        $id = $this->insertLog([
            'auditable_type' => Employee::class,
            'auditable_id'   => $employee->id,
        ]);

        $response = $this->getJson("/api/auth/audit-logs/{$id}")->assertOk();

        $response->assertJsonPath('data.auditable_type', 'employee');
        $response->assertJsonPath('data.auditable.id', $employee->id);
        $this->assertStringNotContainsString('App\\\\Modules', $response->getContent() ?: '');

        // And the filter finds it, or the oldest history silently vanishes from
        // the drill-down that exists to reach it. Scoped to `updated` so the
        // factory's own `created` row is not what is being counted.
        $this->listScoped("&auditable_type=employee&auditable_id={$employee->id}&event=updated")
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    /**
     * THE ROW THAT USED TO TAKE THE WHOLE PAGE DOWN WITH IT.
     *
     * Eager-loading a morphTo calls `new $class` once per distinct stored type,
     * and a type with no live class behind it raises an **Error** — uncatchable
     * by any renderer, so a 500. On an append-only table the row cannot be
     * corrected, so the page containing it would have been a 500 forever.
     *
     * Two stored values do it and both are reachable: a class name for a model
     * since renamed or moved (the legacy merge is going to move classes between
     * modules) and an alias whose model has been retired. Both are asserted, in
     * a page that also holds a resolvable row — because the failure is per
     * DISTINCT TYPE on the page, so a broken row poisons its neighbours too.
     */
    public function test_a_stored_type_with_no_live_class_does_not_take_the_page_down(): void
    {
        $this->actingAsAdmin();

        $employee = Employee::factory()->named('موظف حي')->create();

        $goneClass = $this->insertLog([
            'auditable_type' => 'App\\Modules\\HR\\Models\\Retired',
            'auditable_id'   => 11,
        ]);

        $goneAlias = $this->insertLog([
            'auditable_type' => 'contract',
            'auditable_id'   => 12,
        ]);

        $live = $this->insertLog([
            'auditable_type' => 'employee',
            'auditable_id'   => $employee->id,
        ]);

        $rows = collect($this->listScoped()->assertOk()->json('data'))->keyBy('id');

        // A class name that resolves to nothing must not reach the wire (C12),
        // and there is no honest alias to offer instead.
        $this->assertNull($rows[$goneClass]['auditable_type']);
        $this->assertNull($rows[$goneClass]['auditable']);
        $this->assertSame(11, $rows[$goneClass]['auditable_id']);

        // A retired ALIAS is already a public name and is kept: it is the one
        // fact the broken row still carries, and it leaks nothing.
        $this->assertSame('contract', $rows[$goneAlias]['auditable_type']);
        $this->assertNull($rows[$goneAlias]['auditable_label']);
        $this->assertNull($rows[$goneAlias]['auditable']);

        // And the resolvable row on the same page is unaffected.
        $this->assertSame('employee', $rows[$live]['auditable_type']);
        $this->assertSame($employee->id, $rows[$live]['auditable']['id']);

        $this->getJson("/api/auth/audit-logs/{$goneClass}")->assertOk();
        $this->getJson("/api/auth/audit-logs/{$goneAlias}")->assertOk();
    }

    public function test_an_unknown_event_key_renders_raw_rather_than_guessed(): void
    {
        $this->actingAsAdmin();

        $id = $this->insertLog(['event' => 'something_new']);

        $this->getJson("/api/auth/audit-logs/{$id}")
            ->assertOk()
            ->assertJsonPath('data.event', 'something_new')
            ->assertJsonPath('data.event_label', null)
            ->assertJsonPath('data.event_group', null)
            // Not a model event, so no field names are claimed for it.
            ->assertJsonPath('data.changed_fields', []);
    }

    /**
     * An api_request row stores {method, path, status_code, route_name} in
     * new_values. Listing those as "changed fields" would present request
     * metadata as a record edit.
     */
    public function test_request_metadata_is_not_reported_as_changed_fields(): void
    {
        $this->actingAsAdmin();

        $id = $this->insertLog([
            'event'      => 'api_request',
            'new_values' => json_encode(['method' => 'GET', 'path' => 'api/hr/employees', 'status_code' => 200]),
        ]);

        $this->getJson("/api/auth/audit-logs/{$id}")
            ->assertOk()
            ->assertJsonPath('data.event_group', 'request')
            ->assertJsonPath('data.changed_fields', []);
    }

    public function test_a_deleted_event_names_the_fields_that_vanished(): void
    {
        $this->actingAsAdmin();

        $id = $this->insertLog([
            'event'      => 'deleted',
            'old_values' => json_encode(['name' => 'س', 'workplace' => 'دمشق']),
            'new_values' => null,
        ]);

        $this->getJson("/api/auth/audit-logs/{$id}")
            ->assertOk()
            ->assertJsonPath('data.changed_fields', ['name', 'workplace']);
    }

    // ───────────────────────────────────────────────────────────────────────
    // Filters
    // ───────────────────────────────────────────────────────────────────────

    public function test_the_event_group_filter_separates_real_changes_from_the_firehose(): void
    {
        $this->actingAsAdmin();

        $this->insertLog(['event' => 'updated']);
        $this->insertLog(['event' => 'user_logged_in']);
        $this->insertLog(['event' => 'api_request']);
        $this->insertLog(['event' => 'api_request']);

        // The default the UI applies: everything except the request firehose.
        $this->listScoped('&event_group=model,auth')->assertOk()->assertJsonPath('meta.total', 2);
        $this->listScoped('&event_group=request')->assertOk()->assertJsonPath('meta.total', 2);
        $this->listScoped('&event=updated')->assertOk()->assertJsonPath('meta.total', 1);
    }

    /**
     * `event` and `event_group` INTERSECT rather than union. A client combining
     * them and getting rows from either set would be reading a filter that
     * quietly did nothing.
     */
    public function test_event_and_event_group_narrow_each_other(): void
    {
        $this->actingAsAdmin();

        $this->insertLog(['event' => 'created']);
        $this->insertLog(['event' => 'user_logged_in']);

        $this->listScoped('&event=created&event_group=auth')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->listScoped('&event=created&event_group=model')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    /**
     * AN EMPTY LIST IS A 422, NOT AN IGNORED FILTER.
     *
     * `?event=` and `?event=,,,` used to survive the CSV split as `[]`, pass
     * `sometimes|array`, and then be skipped by the service — a full page of
     * unfiltered rows, 200, and no way for the caller to tell their filter had
     * been discarded. On `event_group` it was worse than generic: that is the
     * parameter the UI sends to hide the api_request firehose, so asking for
     * real changes silently returned the firehose.
     */
    public function test_an_empty_event_filter_is_refused_rather_than_ignored(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/auth/audit-logs?event=')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['event']);

        $this->getJson('/api/auth/audit-logs?event=,,,')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['event']);

        $this->getJson('/api/auth/audit-logs?event_group=')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['event_group']);
    }

    /**
     * "What happened outside any user session" — the case where nobody is
     * accountable, and the only one of the three documented actor types with no
     * way to ask for it. `user_id` matches an id and no value of it expresses
     * IS NULL, so this was reachable only by paging the whole unbounded table.
     */
    public function test_the_actor_type_filter_isolates_system_rows(): void
    {
        $actor = $this->actingAsAdmin();

        $bySystem = $this->insertLog(['user_id' => null]);
        $byUser   = $this->insertLog(['user_id' => $actor->id]);

        $this->assertSame(
            [$bySystem],
            array_column($this->listScoped('&actor_type=system')->assertOk()->json('data'), 'id'),
        );

        $this->assertSame(
            [$byUser],
            array_column($this->listScoped('&actor_type=user')->assertOk()->json('data'), 'id'),
        );

        $this->getJson('/api/auth/audit-logs?actor_type=robot')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['actor_type']);
    }

    /**
     * The console entry point gets a named failure, not the whole trail. HTTP
     * callers are 422'd upstream, so a typo here could only come from a command
     * or a job — and handing back every row to code that asked for half of them
     * is the silent fallback this surface exists to refuse.
     */
    public function test_an_unknown_actor_type_raises_rather_than_filtering_nothing(): void
    {
        $this->insertLog();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Unknown actor_type "robot"');

        app(AuditTrailService::class)->paginate(filters: ['actor_type' => 'robot']);
    }

    public function test_the_request_id_drilldown_returns_the_whole_operation(): void
    {
        $this->actingAsAdmin();

        // One user action: the request row plus a model row per touched model.
        $this->insertLog(['event' => 'api_request', 'request_id' => 'req-abc']);
        $this->insertLog(['event' => 'updated', 'request_id' => 'req-abc']);
        $this->insertLog(['event' => 'created', 'request_id' => 'req-abc']);
        $this->insertLog(['event' => 'updated', 'request_id' => 'req-other']);

        $this->listScoped('&request_id=req-abc')->assertOk()->assertJsonPath('meta.total', 3);
    }

    /**
     * date_from == date_to must return that WHOLE day, its last hour included.
     * Asking for one day and getting everything except the evening is the bug
     * this pins down.
     */
    public function test_a_single_day_range_includes_the_end_of_that_day(): void
    {
        $this->actingAsAdmin();

        $this->insertLog(['created_at' => '2026-09-16 00:00:01']);
        $this->insertLog(['created_at' => '2026-09-16 23:59:59']);
        $this->insertLog(['created_at' => '2026-09-17 00:00:01']);

        $this->listScoped('&date_from=2026-09-16&date_to=2026-09-16')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    /**
     * Every assertion here needs a fixture that CONTAINS the character being
     * searched for. The previous version asserted `search=%` returned 0 against
     * two fixtures holding no `%` at all, so it passed whether or not any
     * escaping ran — and in fact none did on SQLite, which has no default LIKE
     * escape character and was never sent an ESCAPE clause. A wildcard test
     * that cannot fail when escaping is deleted is not a wildcard test.
     */
    public function test_search_matches_the_description_and_escapes_wildcards(): void
    {
        $this->actingAsAdmin();

        $this->insertLog(['description' => 'GET api/hr/employees → 200']);
        $this->insertLog(['description' => 'POST api/auth/roles → 201']);
        $this->insertLog(['description' => 'quota 50% reached']);
        $this->insertLog(['description' => 'path C:\\reports\\q3 written']);
        $this->insertLog(['description' => 'flag is_active toggled']);

        $this->listScoped('&search=employees')->assertOk()->assertJsonPath('meta.total', 1);

        // A literal % finds the one row containing a percent sign — not all
        // five, which is what an unescaped wildcard would return.
        $this->listScoped('&search='.urlencode('%'))->assertOk()->assertJsonPath('meta.total', 1);

        // `_` is LIKE's single-character wildcard: unescaped, `is_active` would
        // also match a hypothetical `isXactive`, and more to the point the term
        // must match the row that literally contains it.
        $this->listScoped('&search='.urlencode('is_active'))->assertOk()->assertJsonPath('meta.total', 1);

        // THE BACKSLASH. It is the escape character on MySQL, so a term
        // containing one used to corrupt the escaping it was supposed to
        // receive: `search=\` produced `LIKE '%\%'`, where `\%` is a LITERAL
        // percent, and the search for a backslash returned every row holding
        // a '%' instead.
        $this->listScoped('&search='.urlencode('\\'))->assertOk()->assertJsonPath('meta.total', 1);
        $this->listScoped('&search='.urlencode('C:\\reports'))->assertOk()->assertJsonPath('meta.total', 1);

        // And the escape character this codebase actually uses is escaped too,
        // so a user typing it searches for it.
        $this->insertLog(['description' => 'alert! disk full']);
        $this->listScoped('&search='.urlencode('alert!'))->assertOk()->assertJsonPath('meta.total', 1);
        $this->listScoped('&search='.urlencode('!%'))->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_the_default_order_is_newest_first_and_survives_a_tied_timestamp(): void
    {
        $this->actingAsAdmin();

        // Rows written by one request share a created_at by construction, which
        // is exactly the precondition a missing tie-break breaks on.
        $first  = $this->insertLog(['created_at' => '2026-09-16 14:03:11']);
        $second = $this->insertLog(['created_at' => '2026-09-16 14:03:11']);
        $older  = $this->insertLog(['created_at' => '2026-01-01 08:00:00']);

        $this->assertSame(
            [$second, $first, $older],
            array_column($this->listScoped()->assertOk()->json('data'), 'id'),
        );

        $this->assertSame(
            [$older, $first, $second],
            array_column($this->listScoped('&sort=created_at')->assertOk()->json('data'), 'id'),
        );
    }

    /**
     * EVERY FILTER AND SORT KEY IS BACKED BY AN INDEX, asserted rather than
     * promised.
     *
     * AuditTrailService says so twice in its own docblocks, and `ip_address`
     * shipped as a first-class filter on an unindexed column anyway — on the one
     * table in the system that grows without bound, which makes it a full scan
     * that nothing would report except a slow page. Nobody reads a comment while
     * adding a filter; this fails the build instead.
     *
     * LEFTMOST COLUMN, because that is what an index can actually serve: a
     * composite `(event, created_at)` covers `event`, and a key that only ever
     * appears second in some composite is not indexed for this purpose.
     *
     * FOREIGN KEYS COUNT AS INDEXED AND ARE NOT DECLARED TWICE. InnoDB creates
     * an index for every FK automatically, so `user_id` is indexed in
     * production; declaring one as well would put a second index on the column
     * and be paid for on every INSERT, on the table that takes the most INSERTs
     * in the system. SQLite does NOT do this, which is why the exemption is read
     * from the schema's own foreign keys rather than hard-coded — a column that
     * stops being an FK stops being exempt on the same day.
     */
    public function test_every_filterable_and_sortable_column_is_indexed(): void
    {
        $leftmost = array_map(
            static fn (array $index): string => (string) ($index['columns'][0] ?? ''),
            Schema::getIndexes('audit_logs'),
        );

        $foreignKeys = array_merge(
            ...array_map(
                static fn (array $key): array => $key['columns'],
                Schema::getForeignKeys('audit_logs'),
            ),
        );

        // A key that is never queried ALONE does not need to lead an index; it
        // needs to sit in a composite behind the key it always arrives with.
        // `auditable_id` is the only one: AuditLogIndexRequest 422s an id with
        // no type, because row 42 of every table at once is not a question
        // anyone means to ask — so the query is always
        // `auditable_type IN (…) AND auditable_id = ?`, which the existing
        // composite serves exactly. Its own index would serve nothing.
        $paired = ['auditable_id' => 'auditable_type'];

        $composites = array_map(
            static fn (array $index): array => $index['columns'],
            Schema::getIndexes('audit_logs'),
        );

        $keys = array_merge(
            array_values(AuditTrailService::FILTERABLE),
            array_values(AuditTrailService::SORTABLE),
        );

        foreach (array_unique($keys) as $column) {
            if (isset($paired[$column])) {
                $this->assertContains(
                    [$paired[$column], $column],
                    $composites,
                    "audit_logs.{$column} is only ever queried beside {$paired[$column]}, and there is no "
                        ."({$paired[$column]}, {$column}) index to serve that.",
                );

                continue;
            }

            $this->assertContains(
                $column,
                array_merge($leftmost, $foreignKeys),
                "audit_logs.{$column} is filterable or sortable but leads no index, so that query is a "
                    ."full scan of the one table that grows without bound.\nAdd the index, or drop the key.",
            );
        }
    }

    /**
     * The default screen — `event_group=model,auth` ordered newest-first —
     * against a table that is ~99% api_request rows. Without the composite,
     * MySQL walks created_at backwards discarding about 99 rows per row kept.
     */
    public function test_the_default_screen_query_has_its_composite_index(): void
    {
        $composites = array_map(
            static fn (array $index): array => $index['columns'],
            Schema::getIndexes('audit_logs'),
        );

        $this->assertContains(['event', 'created_at'], $composites);
    }

    // ───────────────────────────────────────────────────────────────────────
    // The service's OTHER entry point
    //
    // AuditTrailService and AuditLogController both advertise that the service
    // "stays callable from a console command" with a plain filter array. Nothing
    // exercised that, so two behaviours differed from the HTTP path in ways the
    // HTTP path's 422s hid completely.
    // ───────────────────────────────────────────────────────────────────────

    public function test_the_service_accepts_the_filter_spellings_a_console_caller_would_use(): void
    {
        $service = app(AuditTrailService::class);

        $this->insertLog(['event' => 'created']);
        $this->insertLog(['event' => 'updated']);

        // A bare scalar — the natural spelling, and the shape the HTTP layer
        // itself holds before prepareForValidation reshapes it. This used to
        // reach whereIn() as a string and throw a TypeError.
        $this->assertSame(1, $service->paginate(
            filters: ['event' => 'created', 'ip_address' => self::SCENARIO_IP],
        )->total());

        // And a CSV string, which used to be matched as one literal event name
        // and silently return nothing.
        $this->assertSame(2, $service->paginate(
            filters: ['event' => 'created,updated', 'ip_address' => self::SCENARIO_IP],
        )->total());
    }

    /**
     * An unresolved sort key takes the whole default, DIRECTION INCLUDED.
     * Reading the direction off the raw string independently of whether the key
     * resolved meant `sort: 'ip_address'` fell back to `created_at` but kept the
     * ascending it had inferred from the missing `-` — the oldest rows first, in
     * a correct-looking page. HTTP callers are 422'd and never saw it.
     */
    public function test_an_unresolved_sort_key_still_returns_newest_first(): void
    {
        $service = app(AuditTrailService::class);

        $older = $this->insertLog(['created_at' => '2026-01-01 08:00:00']);
        $newer = $this->insertLog(['created_at' => '2026-09-16 14:03:11']);

        $page = $service->paginate(
            filters: ['ip_address' => self::SCENARIO_IP],
            sort: 'ip_address',
        );

        $this->assertSame([$newer, $older], $page->getCollection()->pluck('id')->all());
    }

    // ───────────────────────────────────────────────────────────────────────
    // Contract details the frontend depends on
    // ───────────────────────────────────────────────────────────────────────

    /**
     * The app timezone is Asia/Damascus and now() writes local time. Serialising
     * without the offset would move every event three hours the instant a
     * browser parsed it.
     */
    public function test_created_at_carries_the_damascus_offset(): void
    {
        $this->actingAsAdmin();

        $this->insertLog(['created_at' => '2026-09-16 14:03:11']);

        $this->listScoped()
            ->assertOk()
            ->assertJsonPath('data.0.created_at', '2026-09-16T14:03:11+03:00');
    }

    /**
     * The whole reason the frontend menu stayed hidden: the permission has to
     * exist in the catalogue before a super admin's payload can carry it. A
     * super admin holds no explicit grants — the bypass expands to whatever is
     * seeded — so seeding IS the grant.
     */
    public function test_a_super_admin_receives_the_audit_permissions_in_their_payload(): void
    {
        $admin = $this->actingAsAdmin();

        $permissions = $admin->allPermissionNames();

        $this->assertContains('auth.audit.view', $permissions);
        $this->assertContains('auth.audit.viewValues', $permissions);
    }

    /**
     * Reading the trail appends to it, and that is deliberate — "who read the
     * audit log" is itself a security-relevant fact. The consequence for clients
     * (never poll this endpoint) is documented; here it is simply pinned, so
     * nobody later "fixes" it by dropping the middleware.
     */
    public function test_reading_the_trail_is_itself_audited(): void
    {
        $this->actingAsAdmin();

        $before = AuditLog::query()->where('event', 'api_request')->count();

        $this->getJson('/api/auth/audit-logs')->assertOk();

        $this->assertSame(
            $before + 1,
            AuditLog::query()->where('event', 'api_request')->count(),
        );
    }

    /**
     * There is no route that could mutate the trail, and the model refuses too —
     * belt and braces, because the production grants (INSERT + SELECT only) are
     * the third layer and no developer machine has them.
     */
    public function test_the_trail_cannot_be_mutated(): void
    {
        $this->actingAsAdmin();
        $id = $this->insertLog();

        $this->putJson("/api/auth/audit-logs/{$id}", ['event' => 'tampered'])->assertStatus(405);
        $this->deleteJson("/api/auth/audit-logs/{$id}")->assertStatus(405);

        $log = AuditLog::findOrFail($id);

        $this->expectException(LogicException::class);
        $log->delete();
    }
}
