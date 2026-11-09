<?php

declare(strict_types=1);

namespace Tests\Feature\Leave;

use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Leave\Concerns\LeaveFixtures;
use Tests\TestCase;

/**
 * `details` on a row of GET /api/me/approvals: what an approver needs to read
 * a leave without opening it — its dates, its days, and the requester's
 * balance after it. Being on the chain is what admits them: no permission.
 */
class ApproverInboxDetailsTest extends TestCase
{
    use LeaveFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->buildLeaveTree();
    }

    /**
     * @return array<string, mixed> the filed leave request
     */
    private function file(string $type, string $from, string $to): array
    {
        $this->actingAsPerson($this->people['staff']);

        $body = ['leave_type_id' => $this->type($type)->id, 'start_date' => $from, 'end_date' => $to, 'reason' => 'سبب'];
        if ($type === 'sick') {
            $body['attachments'] = [UploadedFile::fake()->create('تقرير.pdf', 100, 'application/pdf')];
        }

        return $this->post('/api/me/leave-requests', $body, ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data.leave_request');
    }

    /**
     * @param  array<string, mixed>  $leave
     */
    private function approve(array $leave, Employee $approver): void
    {
        $this->actingAsPerson($approver);
        $step = collect($leave['approval']['steps'])->firstWhere('approver.id', $approver->id);

        $this->postJson("/api/approvals/{$leave['approval']['id']}/steps/{$step['id']}/approve")->assertOk();
    }

    private function inboxOf(Employee $approver, string $query = ''): TestResponse
    {
        $this->actingAsPerson($approver);

        return $this->getJson('/api/me/approvals'.$query)->assertOk();
    }

    public function test_a_pending_deducting_leave_carries_its_dates_days_and_balance(): void
    {
        $leave = $this->file('annual', '2026-06-21', '2026-06-23');

        $details = $this->inboxOf($this->people['headA'])->json('data.0.details');

        $this->assertSame('2026-06-21', $details['start_date']);
        $this->assertSame('2026-06-23', $details['end_date']);
        $this->assertSame($leave['requested_days'], $details['requested_days']);
        $this->assertSame(2026, $details['balance']['year']);
        $this->assertSame(15, $details['balance']['remaining_days']);
        $this->assertSame(15 - $leave['requested_days'], $details['balance']['after_request']);
    }

    public function test_a_non_deducting_leave_has_a_null_balance_and_keeps_its_dates(): void
    {
        $this->file('sick', '2026-06-21', '2026-06-22');

        $details = $this->inboxOf($this->people['headA'])->json('data.0.details');

        $this->assertSame('2026-06-21', $details['start_date']);
        $this->assertNull($details['balance']);
    }

    public function test_a_leave_starting_next_year_reads_next_years_balance(): void
    {
        $this->file('annual', '2027-01-04', '2027-01-05');

        $this->inboxOf($this->people['headA'])->assertJsonPath('data.0.details.balance.year', 2027);
    }

    public function test_after_request_goes_negative_rather_than_stopping_at_zero(): void
    {
        $first = $this->file('annual', '2026-06-01', '2026-06-10');
        $second = $this->file('annual', '2026-07-01', '2026-07-10');

        $this->approve($first, $this->people['headA']);
        $this->approve($first, $this->people['hrHead']);

        $balance = $this->inboxOf($this->people['headA'])->json('data.0.details.balance');

        $this->assertSame(15 - $first['requested_days'], $balance['remaining_days']);
        $this->assertSame(15 - $first['requested_days'] - $second['requested_days'], $balance['after_request']);
        $this->assertLessThan(0, $balance['after_request']);
    }

    public function test_a_decided_row_has_a_null_balance_and_keeps_its_dates(): void
    {
        $leave = $this->file('annual', '2026-06-21', '2026-06-22');
        $this->approve($leave, $this->people['headA']);
        $this->approve($leave, $this->people['hrHead']);

        $details = $this->inboxOf($this->people['headA'], '?status=decided')->json('data.0.details');

        $this->assertSame('2026-06-21', $details['start_date']);
        $this->assertNull($details['balance']);
    }

    public function test_a_row_the_caller_decided_has_a_null_balance_while_the_leave_goes_on(): void
    {
        $leave = $this->file('annual', '2026-06-21', '2026-06-22');
        $this->approve($leave, $this->people['headA']);

        $row = $this->inboxOf($this->people['headA'], '?status=decided')->json('data.0');

        $this->assertSame(1, $row['approval_status'], 'the leave still waits on the HR step');
        $this->assertSame('2026-06-21', $row['details']['start_date']);
        $this->assertNull($row['details']['balance']);
    }

    public function test_more_rows_cost_no_more_queries(): void
    {
        $this->file('annual', '2026-03-01', '2026-03-01');
        $this->file('annual', '2026-03-03', '2026-03-03');
        $few = $this->queriesFor($this->people['headA']);

        foreach (['05', '07', '09', '11'] as $day) {
            $this->file('annual', "2026-03-{$day}", "2026-03-{$day}");
        }

        $this->assertSame($few, $this->queriesFor($this->people['headA']));
    }

    private function queriesFor(Employee $approver): int
    {
        $this->actingAsPerson($approver);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/me/approvals')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
