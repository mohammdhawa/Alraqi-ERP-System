<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\HR\Enums\ActivityType;
use App\Modules\HR\Exceptions\JobTitleInUseException;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\JobTitle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The governed job-title catalogue — دليل المسميات الوظيفية.
 *
 * Covers the five tests §10 requires of every resource, plus the two properties
 * that are specific to this table: ONE shared company-wide entry per name
 * (ratified 2026-09-13), and the interaction between its unique index and its
 * soft deletes.
 */
class JobTitleTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_the_catalogue_alphabetically(): void
    {
        $this->actingAsAdmin();
        JobTitle::create(['name' => 'مهندس مدني']);
        JobTitle::create(['name' => 'أمين صندوق']);
        JobTitle::create(['name' => 'محاسب']);

        $response = $this->getJson('/api/hr/job-titles')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        // A catalogue is read as a catalogue: محاسب belongs where محاسب
        // belongs, not wherever it happened to be created.
        $names = array_column($response->json('data'), 'name');
        $sorted = $names;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $names);
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/hr/job-titles')->assertUnauthorized();
    }

    public function test_index_forbidden_without_permission(): void
    {
        $this->actingAsRolelessUser();

        $this->getJson('/api/hr/job-titles')->assertForbidden();
    }

    public function test_store_creates_a_title(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/hr/job-titles', [
            'name'                         => 'محاسب',
            'default_activity_type'        => ActivityType::Financial->value,
            'default_organizational_grade' => 4,
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'محاسب')
            ->assertJsonPath('data.default_activity_type_label', 'مالي')
            ->assertJsonPath('data.is_described', false);

        $this->assertDatabaseHas('job_titles', ['name' => 'محاسب']);
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => 'job_title', 'event' => 'created']);
    }

    /**
     * ONE shared catalogue: محاسب is a single row whichever divisions employ
     * accountants. There is no department_id to make two of them distinct.
     */
    public function test_a_title_is_unique_company_wide(): void
    {
        $this->actingAsAdmin();
        JobTitle::create(['name' => 'محاسب']);

        $this->postJson('/api/hr/job-titles', ['name' => 'محاسب'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    /**
     * ⚠️ The unique index counts trashed rows, so the validation rule must too.
     * Excluding them would let validation pass and the INSERT then fail — a 500
     * where the caller had earned a 422.
     */
    public function test_a_trashed_title_still_holds_its_name(): void
    {
        $this->actingAsAdmin();
        $title = JobTitle::create(['name' => 'محاسب']);
        $title->delete();

        $this->postJson('/api/hr/job-titles', ['name' => 'محاسب'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_store_validates_the_grade_scale(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/hr/job-titles', [
            'name'                         => 'محاسب',
            'default_organizational_grade' => 9,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('default_organizational_grade');
    }

    public function test_update_marks_a_title_described(): void
    {
        $this->actingAsAdmin();
        $title = JobTitle::create(['name' => 'محاسب']);

        $this->putJson("/api/hr/job-titles/{$title->id}", [
            'is_described' => true,
            'description'  => 'مسك الدفاتر وإعداد القيود.',
        ])
            ->assertOk()
            ->assertJsonPath('data.is_described', true);

        $this->assertDatabaseHas('job_titles', ['id' => $title->id, 'is_described' => true]);
    }

    public function test_destroy_soft_deletes_an_unused_title(): void
    {
        $this->actingAsAdmin();
        $title = JobTitle::create(['name' => 'مسمى قديم']);

        $this->deleteJson("/api/hr/job-titles/{$title->id}")->assertOk();

        $this->assertSoftDeleted('job_titles', ['id' => $title->id]);
    }

    /**
     * 409, not 422: nothing about the request is malformed, it conflicts with
     * protected state.
     *
     * The guard has to exist because the FK is nullOnDelete and a SOFT delete
     * fires no FK behaviour at all — the employee's title would quietly render
     * blank while still pointing at a hidden row.
     */
    public function test_a_title_staff_still_hold_cannot_be_deleted(): void
    {
        $this->actingAsAdmin();
        $title = JobTitle::create(['name' => 'محاسب']);
        Employee::factory()->named('نور الشامي')->create(['job_title_id' => $title->id]);

        $this->deleteJson("/api/hr/job-titles/{$title->id}")
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('job_titles', ['id' => $title->id, 'deleted_at' => null]);
    }

    /**
     * The history half, which is the one that matters more: a title nobody
     * currently holds may still be the title someone held in 2024, and that row
     * is what a payslip from 2024 is explained by.
     */
    public function test_a_title_only_held_historically_cannot_be_deleted(): void
    {
        $title    = JobTitle::create(['name' => 'محاسب']);
        $employee = Employee::factory()->named('نور الشامي')->create(['job_title_id' => $title->id]);

        // Move them off it: the title is now held by nobody.
        $employee->update(['job_title_id' => null]);
        $this->assertSame(0, $title->employees()->count());

        $this->expectException(JobTitleInUseException::class);
        $title->delete();
    }

    /**
     * §10 test 4 — a read-only role must not be able to mutate the catalogue.
     */
    public function test_view_only_user_cannot_write(): void
    {
        $this->actingAsUserWithPermissions(['hr.job_titles.view']);
        $title = JobTitle::create(['name' => 'محاسب']);

        $this->getJson('/api/hr/job-titles')->assertOk();
        $this->getJson("/api/hr/job-titles/{$title->id}")->assertOk();

        $this->postJson('/api/hr/job-titles', ['name' => 'مهندس'])->assertForbidden();
        $this->putJson("/api/hr/job-titles/{$title->id}", ['name' => 'غير ذلك'])->assertForbidden();
        $this->deleteJson("/api/hr/job-titles/{$title->id}")->assertForbidden();

        $this->assertDatabaseMissing('job_titles', ['name' => 'مهندس']);
    }

    public function test_the_undescribed_filter_is_the_analysis_departments_worklist(): void
    {
        $this->actingAsAdmin();
        JobTitle::create(['name' => 'محاسب', 'is_described' => true]);
        JobTitle::create(['name' => 'مهندس', 'is_described' => false]);

        $this->getJson('/api/hr/job-titles?is_described=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'مهندس');
    }

    /**
     * A query string carries only strings, and `(bool) "false"` is TRUE — so a
     * plain cast returns exactly the rows the caller asked to EXCLUDE, with a
     * 200 and a response that looks perfectly correct. `false` is what a
     * JavaScript client sends without thinking about it, so both spellings have
     * to mean the same thing.
     */
    public function test_the_word_false_filters_the_same_way_as_zero(): void
    {
        $this->actingAsAdmin();
        JobTitle::create(['name' => 'محاسب', 'is_described' => true,  'is_active' => true]);
        JobTitle::create(['name' => 'مهندس', 'is_described' => false, 'is_active' => false]);

        foreach (['false', '0'] as $spelling) {
            $this->getJson("/api/hr/job-titles?is_described={$spelling}")
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.name', 'مهندس');

            $this->getJson("/api/hr/job-titles?is_active={$spelling}")
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.name', 'مهندس');
        }

        foreach (['true', '1'] as $spelling) {
            $this->getJson("/api/hr/job-titles?is_active={$spelling}")
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.name', 'محاسب');
        }
    }

    /**
     * An unparseable filter is a 422, not a guess — the same rule the employees
     * index holds to (C30, P3).
     */
    public function test_an_unparseable_boolean_filter_is_rejected(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/hr/job-titles?is_active=maybe')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_active');
    }

    /**
     * An unknown sort key is a 422, never a silent fallback: quietly ignoring it
     * returns a correct-looking page in the wrong order and the caller has no
     * way to tell (API_BUILD_STANDARD C30 / P3).
     */
    public function test_an_unknown_sort_is_rejected_rather_than_ignored(): void
    {
        $this->actingAsAdmin();
        JobTitle::create(['name' => 'محاسب']);

        $this->getJson('/api/hr/job-titles?sort=grade')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sort');

        $this->getJson('/api/hr/job-titles?sort=-name')
            ->assertOk();
    }
}
