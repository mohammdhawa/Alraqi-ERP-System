<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Departments\Models\Department;
use App\Modules\HR\Models\Employee;
use Illuminate\Console\Command;

/**
 * Who manages more than one unit (documents round D27.1, design §13). Run it
 * before deploying the one-unit rule, and after: the rule refuses naming such
 * a manager, but rows from before it stand until HR fixes them. Exits 1 when
 * any person is listed, so a deploy script can stop on it.
 */
class ListManagersOfSeveralUnits extends Command
{
    protected $signature = 'departments:managers-of-several';

    protected $description = 'List employees who manage more than one unit (the one-unit rule, D27.1); exits 1 if any.';

    public function handle(): int
    {
        $groups = Department::query()
            ->whereNotNull('manager_id')
            ->orderBy('id')
            ->get(['id', 'name', 'manager_id'])
            ->groupBy('manager_id')
            ->filter(static fn ($units): bool => $units->count() > 1);

        if ($groups->isEmpty()) {
            $this->info('لا يوجد موظف يدير أكثر من وحدة.');

            return self::SUCCESS;
        }

        $employees = Employee::query()->withTrashed()->whereKey($groups->keys()->all())->get()->keyBy('id');

        $this->table(
            ['employee_id', 'name', 'units'],
            $groups->map(static fn ($units, $employeeId): array => [
                $employeeId,
                $employees->get($employeeId)?->full_name ?? '—',
                $units->pluck('name')->implode('، '),
            ])->values()->all(),
        );

        return self::FAILURE;
    }
}
