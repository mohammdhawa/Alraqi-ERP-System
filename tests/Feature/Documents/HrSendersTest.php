<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Modules\Departments\Models\Department;
use App\Modules\Documents\Services\HrSenders;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who may send a document to an employee: the manager of HR or of a unit under
 * it (documents to employees, "Who").
 */
class HrSendersTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{root: Department, hr: Department, hrSection: Department, finance: Department}
     */
    private function chart(bool $withHr = true): array
    {
        $root = Department::create(['name' => 'الإدارة العامة', 'level' => 1]);
        $hr = Department::create([
            'name'      => 'إدارة الموارد البشرية',
            'code'      => $withHr ? config('leave.hr_unit_code') : 'OTHER',
            'level'     => 2,
            'parent_id' => $root->id,
            'sector'    => 1,
        ]);
        $hrSection = Department::create(['name' => 'قسم التوظيف', 'level' => 3, 'parent_id' => $hr->id]);
        $finance = Department::create(['name' => 'إدارة المالية', 'level' => 2, 'parent_id' => $root->id, 'sector' => 1]);

        foreach ([$root, $hr, $hrSection, $finance] as $unit) {
            $unit->update(['manager_id' => Employee::factory()->create()->id]);
        }

        return compact('root', 'hr', 'hrSection', 'finance');
    }

    public function test_the_hr_manager_and_a_manager_under_hr_send(): void
    {
        $chart = $this->chart();
        $senders = new HrSenders;

        $this->assertTrue($senders->hrUnit()->is($chart['hr']));
        $this->assertTrue($senders->isSender($chart['hr']->manager));
        $this->assertTrue($senders->isSender($chart['hrSection']->manager));
    }

    public function test_a_manager_outside_hr_does_not_send(): void
    {
        $chart = $this->chart();
        $senders = new HrSenders;

        $this->assertFalse($senders->isSender($chart['finance']->manager));
        $this->assertFalse($senders->isSender($chart['root']->manager));
        $this->assertFalse($senders->isSender(Employee::factory()->create()));
    }

    public function test_a_manager_of_an_inactive_unit_under_hr_does_not_send(): void
    {
        $chart = $this->chart();
        $senders = new HrSenders;

        $chart['hrSection']->update(['is_active' => false]);

        $this->assertFalse($senders->isSender($chart['hrSection']->manager));
        $this->assertTrue($senders->isSender($chart['hr']->manager));
    }

    public function test_nobody_sends_without_an_hr_unit(): void
    {
        $chart = $this->chart(withHr: false);
        $senders = new HrSenders;

        $this->assertNull($senders->hrUnit());

        foreach ($chart as $unit) {
            $this->assertFalse($senders->isSender($unit->manager));
        }
    }

    public function test_a_manager_deeper_under_hr_sends_even_through_an_inactive_unit(): void
    {
        $chart = $this->chart();
        $senders = new HrSenders;

        $office = Department::create(['name' => 'مكتب التوظيف', 'level' => 4, 'parent_id' => $chart['hrSection']->id]);
        $office->update(['manager_id' => Employee::factory()->create()->id]);

        $this->assertTrue($senders->isSender($office->manager));

        // An inactive unit in between still counts; only the sender's own must be active.
        $chart['hrSection']->update(['is_active' => false]);

        $this->assertTrue($senders->isSender($office->manager));
        $this->assertFalse($senders->isSender($chart['hrSection']->manager));
    }

    public function test_nobody_sends_when_the_hr_unit_is_deleted(): void
    {
        $chart = $this->chart();
        $senders = new HrSenders;

        $chart['hrSection']->delete();
        $chart['hr']->delete();

        $this->assertNull($senders->hrUnit());
        $this->assertFalse($senders->isSender($chart['hr']->manager));
        $this->assertFalse($senders->isSender($chart['hrSection']->manager));
    }
}
