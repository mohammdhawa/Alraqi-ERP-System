<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Modules\Auth\Models\User;
use App\Modules\Auth\Services\NotificationService;
use App\Modules\Departments\Models\Department;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The wire contract every client depends on: ONE envelope, on success AND on
 * error, with no internal detail in it (C12, C15, C20).
 *
 * WHY THIS FILE EXISTS: nothing tested the error paths, and all of them were
 * broken. Probed on 2026-09-08, `401`, `404`, `405`, `422`, `429` and `5xx` each
 * returned a bare `{"message": …}` with NO `success` key, and the `404` from
 * route-model binding leaked the fully-qualified model class — with
 * APP_DEBUG=false. API_DOCUMENTATION.md meanwhile told the frontend that every
 * error except 401 and 429 carried the envelope. The suite was green throughout,
 * because every test asserted a status code and none asserted a body.
 *
 * These tests are the regression guard on that. They are deliberately blunt:
 * they assert the SHAPE, not the wording.
 */
class ApiEnvelopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_request_carries_the_envelope(): void
    {
        $this->getJson('/api/departments')
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['success', 'message']);
    }

    public function test_forbidden_request_carries_the_envelope(): void
    {
        $this->actingAsRolelessUser();

        $this->getJson('/api/departments')
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['success', 'message']);
    }

    public function test_validation_failure_carries_success_false_alongside_errors(): void
    {
        $this->actingAsAdmin();

        // The single most common error any client handles, and the one the docs
        // promised carried `success` while it did not.
        $this->postJson('/api/departments', [])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['success', 'message', 'errors'])
            ->assertJsonValidationErrors(['name', 'level']);
    }

    public function test_missing_row_returns_an_enveloped_404_that_names_no_model_class(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/api/departments/999999')
            ->assertNotFound()
            ->assertJsonPath('success', false);

        $body = $response->getContent();

        // The old body was:
        //   No query results for model [App\Modules\Departments\Models\Department] 999999
        $this->assertStringNotContainsString('App\\', $body);
        $this->assertStringNotContainsString('Department', $body);
        $this->assertStringNotContainsString('query results', $body);
    }

    public function test_unknown_route_returns_the_same_generic_404(): void
    {
        $this->actingAsAdmin();

        // §6: a 404 must never distinguish "no such row" from "no such route",
        // or it confirms which rows exist.
        $unknownRoute = $this->getJson('/api/nope')->assertNotFound();
        $missingRow   = $this->getJson('/api/departments/999999')->assertNotFound();

        $this->assertSame($unknownRoute->json('message'), $missingRow->json('message'));
    }

    public function test_method_not_allowed_carries_the_envelope_and_leaks_nothing(): void
    {
        $this->actingAsAdmin();

        $response = $this->deleteJson('/api/auth/me')
            ->assertStatus(405)
            ->assertJsonPath('success', false);

        $this->assertStringNotContainsString('Symfony', $response->getContent());
    }

    public function test_a_domain_exception_renders_centrally_with_its_own_status(): void
    {
        $this->actingAsAdmin();

        $root = Department::create(['name' => 'الإدارة العامة', 'level' => 1]);
        Department::create(['name' => 'إدارة', 'level' => 2, 'parent_id' => $root->id]);

        // DepartmentHasChildrenException carries 409 as data; nothing about it
        // renders. It is matched by the DomainException interface, so a new
        // module's exceptions work with no edit to bootstrap/app.php.
        $this->deleteJson("/api/departments/{$root->id}")
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['success', 'message']);
    }

    public function test_every_response_carries_a_request_id_header(): void
    {
        $this->actingAsAdmin();

        $header = $this->getJson('/api/departments')->headers->get('X-Request-Id');

        $this->assertNotNull($header);
        $this->assertNotSame('', $header);
    }

    /**
     * "Every response" has to include the ones that never reached a route.
     *
     * SetRequestContext was originally registered on the `api` middleware
     * GROUP, which only runs on a matched route — so an unknown path (404) and
     * a wrong verb (405) came back with no correlation id, which are precisely
     * the failures a confused client reports. It is global middleware now, so
     * it wraps routing itself.
     */
    public function test_routing_failures_also_carry_a_request_id(): void
    {
        $this->actingAsAdmin();

        $this->assertNotNull(
            $this->getJson('/api/nope')->headers->get('X-Request-Id'),
            'unknown route has no request id',
        );

        $this->assertNotNull(
            $this->deleteJson('/api/auth/me')->headers->get('X-Request-Id'),
            'method-not-allowed has no request id',
        );

        $this->assertNotNull(
            $this->getJson('/api/departments')->headers->get('X-Request-Id'),
            'unauthenticated request has no request id',
        );
    }

    public function test_no_response_leaks_a_php_class_name(): void
    {
        $user = $this->actingAsAdmin();

        // The morph map's job: `reference.type` was emitting
        // "App\Modules\HR\Models\Employee" to clients (C12), and
        // API_DOCUMENTATION.md published that as the contract.
        $employee = Employee::factory()->named('مرجع')->create();
        app(NotificationService::class)
            ->sendToUser($user, 'عنوان', reference: $employee);

        $response = $this->getJson('/api/auth/notifications')->assertOk();

        $response->assertJsonPath('data.0.reference.type', 'employee');
        $this->assertStringNotContainsString('App\\', $response->getContent());
    }

    public function test_a_client_supplied_request_id_is_echoed_and_recorded_in_the_audit_row(): void
    {
        $this->actingAsAdmin();

        $this->withHeader('X-Request-Id', 'trace-abc-123')
            ->getJson('/api/departments')
            ->assertOk()
            ->assertHeader('X-Request-Id', 'trace-abc-123');

        // This is what GAP-5 was about: an audit row you can tie to a log line
        // and to the response the client actually received.
        $this->assertDatabaseHas('audit_logs', [
            'event'      => 'api_request',
            'request_id' => 'trace-abc-123',
        ]);
    }

    public function test_an_implausible_inbound_request_id_is_replaced_not_trusted(): void
    {
        $this->actingAsAdmin();

        $header = $this->withHeader('X-Request-Id', "bad id with spaces\nand a newline")
            ->getJson('/api/departments')
            ->headers->get('X-Request-Id');

        $this->assertNotSame("bad id with spaces\nand a newline", $header);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._-]+$/', (string) $header);
    }

    public function test_audit_rows_written_outside_http_still_have_a_request_id(): void
    {
        // AuditLogService used to read the Request facade directly, so rows
        // written from the CLI silently got null ip/user_agent (GAP-6). The
        // context now returns honest values off-request instead.
        User::factory()->create();

        $this->assertDatabaseHas('audit_logs', ['event' => 'created']);
        $this->assertNotNull(
            \DB::table('audit_logs')->where('event', 'created')->value('request_id'),
        );
    }
}
