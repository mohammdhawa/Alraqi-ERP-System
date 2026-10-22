<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\JobTitle;
use App\Shared\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EVERY timestamp this API returns carries the `+03:00` offset.
 *
 * ──────────────────────────────────────────────────────────────────────────
 * WHY THIS FILE EXISTS
 * ──────────────────────────────────────────────────────────────────────────
 * The app timezone is Asia/Damascus and every Resource serialises through
 * Carbon's `toIso8601String()`, which preserves the instance's zone — so this
 * has always been true of every endpoint. It was ASSERTED in exactly one place:
 * AuditLogTest, on one field of one endpoint, where it reads as a quirk of the
 * audit log.
 *
 * Meanwhile `API_DOCUMENTATION.md` showed `+00:00` in seventeen JSON examples
 * across six sections, and the rule appeared only in §9.6. A client
 * implementing against those examples treats every timestamp in the system as
 * UTC and renders the whole application three hours early — and it is a
 * PLAUSIBLE three hours: the dates stay right, nothing throws, and an evening
 * event simply reads as afternoon. That is the failure mode nobody reports as a
 * bug; they report it as "the times look a bit off".
 *
 * The examples are fixed. This holds them fixed, because the reason they were
 * wrong is that nothing could tell they were: the documentation and the wire
 * had no point of contact.
 *
 * ──────────────────────────────────────────────────────────────────────────
 * IT SWEEPS THE WHOLE PAYLOAD RATHER THAN NAMING FIELDS
 * ──────────────────────────────────────────────────────────────────────────
 * Any key ending in `_at`, at any depth, including inside the embedded objects
 * §1 promises beside every FK. Naming the fields would mean this test knows
 * about `created_at` and `last_login_at` and then silently ignores the next one
 * somebody adds — which is the same way the seventeen examples survived.
 */
class TimestampContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Asia/Damascus. A bare timestamp with NO offset fails too: it is the shape
     * that invites a browser to apply its own zone, which is the bug in a
     * different costume.
     */
    private const OFFSET_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+03:00$/';

    /**
     * The audit trail's before/after blobs, which the sweep does NOT descend
     * into — the one place in the API where a `_at` value legitimately has no
     * offset.
     *
     * They are not serialised fields. `HasAuditLog` copies `getAttributes()`
     * verbatim, so they hold the RAW STORED row — `"updated_at":
     * "2026-09-20 11:24:23"`, in DB format, Damascus local, no offset, exactly
     * as the column held it. Putting an offset on them would mean rewriting
     * history at read time on an append-only table, which is worse than the
     * inconsistency. Clients read a bare timestamp as Damascus local for
     * precisely this case.
     */
    private const OPAQUE_BLOBS = ['old_values', 'new_values'];

    /**
     * Collect `path => value` for every `_at` key anywhere in the payload.
     *
     * @return array<string, mixed>
     */
    private function timestampsIn(mixed $node, string $path = ''): array
    {
        if (! is_array($node)) {
            return [];
        }

        $found = [];

        foreach ($node as $key => $value) {
            if (is_string($key) && in_array($key, self::OPAQUE_BLOBS, true)) {
                continue;
            }

            $here = $path === '' ? (string) $key : $path.'.'.$key;

            if (is_string($key) && str_ends_with($key, '_at')) {
                $found[$here] = $value;

                continue;
            }

            $found = [...$found, ...$this->timestampsIn($value, $here)];
        }

        return $found;
    }

    /**
     * @param  array<int, string>  $endpoints
     */
    private function assertOffsetOnAll(array $endpoints): void
    {
        $checked = 0;

        foreach ($endpoints as $endpoint) {
            $payload = $this->getJson($endpoint)->assertOk()->json();

            foreach ($this->timestampsIn($payload) as $path => $value) {
                // Null is legitimate — `last_login_at` before a first login,
                // `email_verified_at` on an unverified account. Absent is the
                // permission case and never reaches here.
                if ($value === null) {
                    continue;
                }

                $this->assertMatchesRegularExpression(
                    self::OFFSET_PATTERN,
                    (string) $value,
                    "{$endpoint} → {$path} is '{$value}'.\n"
                        ."Every timestamp in this API is ISO-8601 with the +03:00 (Asia/Damascus) offset.\n"
                        .'A value without it, or with a different one, renders the whole system in the wrong hour.',
                );

                $checked++;
            }
        }

        // A sweep that found nothing would pass silently and prove nothing —
        // which is exactly the hole this file was written to close.
        $this->assertGreaterThan(0, $checked, 'the sweep found no timestamps to check');
    }

    public function test_every_list_endpoint_returns_damascus_offsets(): void
    {
        $this->actingAsAdmin();

        $employee = Employee::factory()->named('صاحب سجل')->create();
        JobTitle::create(['name' => 'محاسب']);
        $employee->warnings()->create(['type' => 'مخالفة', 'issued_on' => '2026-04-01']);

        // The same list as PaginationContractTest, and for the same reason: an
        // endpoint missing from this array is an endpoint nothing holds to the
        // contract.
        $this->assertOffsetOnAll([
            '/api/hr/employees',
            '/api/hr/job-titles',
            "/api/hr/employees/{$employee->id}/assignments",
            "/api/hr/employees/{$employee->id}/warnings",
            '/api/departments',
            '/api/auth/notifications',
            '/api/auth/users',
            '/api/auth/roles',
            '/api/auth/audit-logs',
        ]);
    }

    /**
     * Detail payloads carry fields the list omits — `updated_at` on several
     * resources, and the embedded objects §1 promises beside every FK.
     */
    public function test_every_detail_endpoint_returns_damascus_offsets(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::factory()->named('صاحب سجل')->create();
        $title    = JobTitle::create(['name' => 'محاسب']);
        $auditId  = (int) AuditLog::query()->value('id');

        // `/api/auth/users/{id}` is deliberately absent: there is no show route
        // for users. The list ships the same UserResource, which the list sweep
        // above already covers.
        $this->assertOffsetOnAll([
            '/api/auth/me',
            "/api/hr/employees/{$employee->id}",
            "/api/hr/job-titles/{$title->id}",
            "/api/auth/audit-logs/{$auditId}",
        ]);
    }

    /**
     * The one field that is written on a real clock rather than by a factory.
     *
     * `last_login_at` is set by the login flow itself, so it is the field most
     * likely to be stored in the wrong zone and the one a client compares
     * against "now" — "آخر دخول: منذ ٣ ساعات" for someone who just signed in is
     * how this class of bug is actually noticed.
     */
    public function test_a_freshly_written_timestamp_carries_the_offset(): void
    {
        $this->seedRbac();

        $user = User::factory()->create([
            'password' => bcrypt('password123'),
        ]);

        $login = $this->postJson('/api/auth/login', [
            'login'    => $user->email,
            'password' => 'password123',
        ])->assertOk();

        $this->assertMatchesRegularExpression(
            self::OFFSET_PATTERN,
            (string) $login->json('data.user.last_login_at'),
            'last_login_at is written by the login flow and must carry +03:00 like everything else',
        );
    }
}
