<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\HR\Enums\ActivityType;
use App\Modules\HR\Enums\EngineeringRank;
use App\Modules\HR\Models\Employee;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The engineering ladder as approved on 2026-10-14 (work order §8): مشارك1–4
 * are added beside مشارك, استشاري stays, and ركن is not a rank.
 */
class EngineeringRankLadderTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_ladder_is_the_approved_list_in_pay_order(): void
    {
        $this->assertSame(
            ['لا يوجد', 'متدرب', 'مراقب فني', 'مساعد مهندس', 'مشارك', 'مشارك1', 'مشارك2', 'مشارك3', 'مشارك4', 'ممارس', 'رأي', 'استشاري'],
            array_column(EngineeringRank::options(), 'label'),
        );
    }

    public function test_the_existing_ranks_keep_their_stored_values(): void
    {
        // Rows already written, audit history and the dashboard's form all
        // carry these ints; moving them would relabel every one of them.
        $this->assertSame(1, EngineeringRank::None->value);
        $this->assertSame(2, EngineeringRank::Trainee->value);
        $this->assertSame(3, EngineeringRank::TechnicalMonitor->value);
        $this->assertSame(4, EngineeringRank::AssistantEngineer->value);
        $this->assertSame(5, EngineeringRank::Associate->value);
        $this->assertSame(6, EngineeringRank::Practitioner->value);
        $this->assertSame(7, EngineeringRank::Authority->value);
        $this->assertSame(8, EngineeringRank::Consultant->value);
    }

    public function test_the_numbered_associate_ranks_are_new_and_rukn_is_retired(): void
    {
        foreach ([10, 11, 12, 13] as $associate) {
            $this->assertTrue(EngineeringRank::isDefined($associate), "{$associate} is مشارك1–4");
        }
        $this->assertFalse(EngineeringRank::isDefined(9), 'ركن is not a rank');
    }

    public function test_an_employee_can_be_given_a_numbered_associate_rank_through_the_api(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/hr/employees', [
            'first_name'       => 'نور',
            'middle_name'      => 'أحمد',
            'last_name'        => 'الشامي',
            'gender'           => 1,
            'activity_type'    => ActivityType::Engineering->value,
            'engineering_rank' => EngineeringRank::Associate3->value,
        ])->assertCreated()->assertJsonPath('data.engineering_rank', 12);
    }

    public function test_rukn_is_refused_through_the_api(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/hr/employees', [
            'first_name'       => 'نور',
            'middle_name'      => 'أحمد',
            'last_name'        => 'الشامي',
            'gender'           => 1,
            'activity_type'    => ActivityType::Engineering->value,
            'engineering_rank' => 9,
        ])->assertUnprocessable()->assertJsonValidationErrors('engineering_rank');
    }

    // ── The migration ────────────────────────────────────────────────────

    private function migration(): Migration
    {
        return require database_path('migrations/HR/2026_10_14_100000_retire_unapproved_engineering_ranks.php');
    }

    private function engineerHolding(int $storedRank): int
    {
        $employee = Employee::factory()->create(['activity_type' => ActivityType::Engineering]);
        // Raw, past the cast and the guard: a row written before the ladder
        // changed, which today's model can no longer express.
        DB::table('employees')->where('id', $employee->id)->update(['engineering_rank' => $storedRank]);

        return $employee->id;
    }

    public function test_a_row_holding_rukn_moves_to_consultant_and_nothing_else_moves(): void
    {
        $rukn = $this->engineerHolding(9);
        $associate = $this->engineerHolding(5);
        $consultant = $this->engineerHolding(8);

        $this->migration()->up();

        $rank = fn (int $id) => (int) DB::table('employees')->where('id', $id)->value('engineering_rank');
        $this->assertSame(8, $rank($rukn));
        $this->assertSame(5, $rank($associate), 'مشارك is a rank and stays');
        $this->assertSame(8, $rank($consultant), 'استشاري is a rank and stays');

        Employee::all()->each(fn (Employee $e) => $this->assertInstanceOf(EngineeringRank::class, $e->engineering_rank));
    }
}
