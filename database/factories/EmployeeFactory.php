<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Enums\Gender;
use App\Modules\HR\Enums\SalaryMode;
use App\Modules\HR\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * EmployeeFactory
 *
 * WHY THIS EXISTS NOW AND DID NOT BEFORE: an employee used to be
 * `Employee::create(['name' => 'Jane Doe'])` — one required field. Batch 2 split
 * the name into three parts and added a gender, so every test that wanted "an
 * employee called Jane Doe" would otherwise restate four columns it does not
 * care about. A factory keeps the test saying what it means.
 *
 * The defaults are deliberately the MINIMUM that satisfies
 * EmployeeClassificationGuard and nothing more. In particular no classification
 * axis is set by default: a test asserting that the pay block is hidden must
 * not depend on a factory having quietly populated it, and a test that wants an
 * engineer says so.
 *
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    /** A per-process counter: every employee gets a unique digit number (spec §3). */
    private static int $number = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // A Kobo id is digits; 7 + eight digits keeps test numbers clear
            // of the 5xxxxxxxx ids of HR's real workbook.
            'employee_number' => '7'.str_pad((string) ++self::$number, 8, '0', STR_PAD_LEFT),
            'first_name'  => $this->faker->firstName(),
            'middle_name' => $this->faker->firstName('male'),
            'last_name'   => $this->faker->lastName(),
            'gender'      => Gender::Male,
            'status'      => EmployeeStatus::Active,
            'salary_mode' => SalaryMode::Scale,
        ];
    }

    /**
     * An employee whose `full_name` reads back exactly as the string given.
     *
     * The split mirrors how the accessor joins the parts, so
     * `named('Jane Doe')->create()->full_name === 'Jane Doe'` holds for one,
     * two and three-part names alike — which is what makes it usable in an
     * assertion rather than only in a fixture.
     */
    public function named(string $fullName): self
    {
        $parts = preg_split('/\s+/', trim($fullName)) ?: [];

        return $this->state(match (count($parts)) {
            0       => [],
            1       => ['first_name' => $parts[0], 'middle_name' => null, 'last_name' => null],
            2       => ['first_name' => $parts[0], 'middle_name' => null, 'last_name' => $parts[1]],
            default => [
                'first_name'  => $parts[0],
                'middle_name' => $parts[1],
                'last_name'   => implode(' ', array_slice($parts, 2)),
            ],
        });
    }

    /**
     * Someone on the negotiated pay track. The amount is required for إتفاق and
     * forbidden for نظام رواتب, so the two always travel together.
     */
    public function onAgreedSalary(float $amount = 1500.00): self
    {
        return $this->state([
            'salary_mode'   => SalaryMode::Agreed,
            'agreed_salary' => $amount,
        ]);
    }
}
