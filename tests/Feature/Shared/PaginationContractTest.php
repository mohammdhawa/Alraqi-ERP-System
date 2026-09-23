<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\JobTitle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The list contract: every collection endpoint pages the same way, so a client
 * writes ONE pager and it works everywhere (C21, C27, C28, C29).
 *
 * WHY THIS FILE EXISTS: `grep` for `per_page`, `page=2` or `meta` across the
 * whole test suite returned NOTHING. That absence is why three separate defects
 * shipped and stayed invisible while 143 tests passed — `per_page` was accepted
 * and silently ignored on every endpoint, two of the five list endpoints
 * returned unbounded collections with no `meta` at all, and every list query
 * sorted on a column whose values were tied.
 */
class PaginationContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The six keys are the WHOLE contract. Not five, not seven, and `meta` is a
     * sibling of `data` rather than nested inside it.
     */
    private const META_KEYS = [
        'current_page', 'last_page', 'per_page', 'total', 'from', 'to',
    ];

    private function seedEmployees(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Employee::factory()->named("employee {$i}")->create();
        }
    }

    public function test_every_list_endpoint_returns_the_same_six_meta_keys(): void
    {
        $this->actingAsAdmin();
        $this->seedEmployees(3);

        // Batch 2 added four more list endpoints, so they are enumerated here
        // in the same breath as the original five. A list that ships without
        // appearing in this array is a list nothing holds to the contract —
        // which is precisely how the three original defects survived.
        $employee = Employee::factory()->named('صاحب سجل')->create();
        JobTitle::create(['name' => 'محاسب']);
        $employee->warnings()->create(['type' => 'مخالفة', 'issued_on' => '2026-04-01']);

        $endpoints = [
            '/api/hr/employees',
            '/api/hr/job-titles',
            "/api/hr/employees/{$employee->id}/assignments",
            "/api/hr/employees/{$employee->id}/warnings",
            '/api/departments',
            '/api/auth/notifications',
            '/api/auth/users',
            '/api/auth/roles',
            // The audit trail pages like everything else, and it is the list
            // that most needs to: it is the one table in the system that grows
            // without bound.
            '/api/auth/audit-logs',
        ];

        foreach ($endpoints as $endpoint) {
            $response = $this->getJson($endpoint)->assertOk();

            $this->assertSame(
                self::META_KEYS,
                array_keys($response->json('meta') ?? []),
                "{$endpoint} does not return exactly the six meta keys",
            );
            $this->assertIsArray($response->json('data'), "{$endpoint} data is not an array");
        }
    }

    public function test_per_page_is_honoured(): void
    {
        $this->actingAsAdmin();
        $this->seedEmployees(20);

        $response = $this->getJson('/api/hr/employees?per_page=5')->assertOk();

        $this->assertSame(5, $response->json('meta.per_page'));
        $this->assertCount(5, $response->json('data'));
        $this->assertSame(4, $response->json('meta.last_page'));
    }

    public function test_per_page_is_clamped_rather_than_rejected(): void
    {
        $this->actingAsAdmin();
        $this->seedEmployees(3);

        // The upper clamp is the one that matters: without it ?per_page=1000000
        // is a cheap denial of service against a table that will hold hundreds
        // of thousands of audit rows.
        foreach ([1000000 => 100, 101 => 100, 0 => 1, -5 => 1] as $requested => $expected) {
            $this->getJson("/api/hr/employees?per_page={$requested}")
                ->assertOk()
                ->assertJsonPath('meta.per_page', $expected);
        }
    }

    public function test_a_non_numeric_per_page_falls_back_to_the_default(): void
    {
        $this->actingAsAdmin();
        $this->seedEmployees(3);

        $this->getJson('/api/hr/employees?per_page=abc')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 15);
    }

    /**
     * The checklist item this suite was missing: page 2 must return the rows
     * page 1 did not.
     *
     * This is the assertion that a tied ORDER BY breaks. Every employee here is
     * created in one loop and shares a single created_at, which was the exact
     * precondition in production data — all 119 seeded departments share one
     * created_at too — so ->latest() alone carried no ordering information and
     * nothing required two consecutive queries to agree on row placement.
     */
    public function test_paging_returns_every_row_exactly_once(): void
    {
        $this->actingAsAdmin();
        $this->seedEmployees(25);

        $this->assertSame(
            1,
            Employee::query()->distinct()->pluck('created_at')->count(),
            'precondition: the rows are tied on the sort column',
        );

        $seen = [];

        for ($page = 1; $page <= 3; $page++) {
            $response = $this->getJson("/api/hr/employees?per_page=10&page={$page}")->assertOk();
            $seen = array_merge($seen, array_column($response->json('data'), 'id'));
        }

        $this->assertCount(25, $seen);
        $this->assertCount(25, array_unique($seen), 'a row appeared on two pages');
    }

    public function test_an_out_of_range_page_is_an_empty_array_with_correct_meta_not_a_404(): void
    {
        $this->actingAsAdmin();
        $this->seedEmployees(3);

        $response = $this->getJson('/api/hr/employees?page=99')->assertOk();

        // `data` is [] — never null, never an object keyed by id. from/to are
        // null on an empty page, and clients must handle that.
        $this->assertSame([], $response->json('data'));
        $this->assertNull($response->json('meta.from'));
        $this->assertNull($response->json('meta.to'));
        $this->assertSame(3, $response->json('meta.total'));
    }

    public function test_users_and_roles_are_paginated(): void
    {
        $this->actingAsAdmin();
        User::factory()->count(20)->create();

        $users = $this->getJson('/api/auth/users?per_page=5')->assertOk();
        $this->assertCount(5, $users->json('data'));
        $this->assertSame(5, $users->json('meta.per_page'));
        $this->assertGreaterThan(1, $users->json('meta.last_page'));

        for ($i = 0; $i < 20; $i++) {
            Role::create(['name' => "role-{$i}"]);
        }

        $roles = $this->getJson('/api/auth/roles?per_page=5')->assertOk();
        $this->assertCount(5, $roles->json('data'));
        $this->assertSame(5, $roles->json('meta.per_page'));
    }

    public function test_last_page_is_one_when_there_are_no_results(): void
    {
        $this->actingAsAdmin();

        // Never 0 — a client dividing by it, or rendering "page 1 of 0", is the
        // failure this pins down.
        $this->getJson('/api/hr/employees')
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.last_page', 1);
    }
}
