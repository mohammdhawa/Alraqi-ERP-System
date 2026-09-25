<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\HR\Models\Employee;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `2026_09_24_110000_drop_retired_columns_from_employees_table`.
 *
 * `legacy_number` and `direct_manager_id` were removed by editing an
 * already-run migration, which fixes fresh installs and nothing else. The test
 * suite always builds a FRESH schema, so it could never have noticed — which is
 * exactly why this test rebuilds the upgraded shape by hand: the columns, the
 * unique index and the FK, as the original restructure migration made them.
 */
class RetiredEmployeeColumnsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): Migration
    {
        return require database_path('migrations/HR/2026_09_24_110000_drop_retired_columns_from_employees_table.php');
    }

    public function test_an_upgraded_database_loses_both_columns(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('legacy_number')->nullable()->unique();
            $table->foreignId('direct_manager_id')->nullable()->constrained('employees')->nullOnDelete();
        });

        $manager = Employee::factory()->create();
        Employee::factory()->create()->forceFill([
            'legacy_number'     => 'M024333',
            'direct_manager_id' => $manager->id,
        ])->saveQuietly();

        $this->migration()->up();

        $this->assertFalse(Schema::hasColumn('employees', 'legacy_number'));
        $this->assertFalse(Schema::hasColumn('employees', 'direct_manager_id'));

        // The rows themselves survive the drop.
        $this->assertSame(2, Employee::count());
    }

    public function test_a_fresh_database_is_left_alone(): void
    {
        $this->assertFalse(Schema::hasColumn('employees', 'direct_manager_id'));

        $this->migration()->up();

        $this->assertTrue(Schema::hasColumn('employees', 'employee_number'));
    }
}
