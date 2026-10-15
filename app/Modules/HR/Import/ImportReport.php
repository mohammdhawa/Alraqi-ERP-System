<?php

declare(strict_types=1);

namespace App\Modules\HR\Import;

/**
 * What one import run did, row by row — the file HR reviews between runs —
 * and, separately, the temporary passwords of the accounts it created.
 *
 * The two are kept apart on purpose. The report is for review and may be
 * shared; the credentials are a secret HR distributes by hand and are written
 * to their own file, nowhere else — not to the report, not to the log
 * (EMPLOYEE_APP_REQUIREMENTS.md §7.1 C).
 */
final class ImportReport
{
    public const OUTCOMES = ['created', 'updated', 'restored', 'skipped', 'failed'];

    /** @var array<int, array{row: int, name: string, key: ?string, outcome: string, employee_number: ?string, issues: array<int, array{severity: string, field: ?string, message: string}>}> */
    private array $rows = [];

    /** @var array<int, array{employee_number: string, name: string, password: string}> */
    private array $credentials = [];

    public function __construct(
        public readonly bool $dryRun = false,
    ) {}

    /**
     * @param  array<int, array{severity: string, field: ?string, message: string}>  $issues
     */
    public function addRow(int $row, string $name, ?string $key, string $outcome, ?string $employeeNumber, array $issues): void
    {
        $this->rows[] = [
            'row'             => $row,
            'name'            => $name,
            'key'             => $key,
            'outcome'         => $outcome,
            'employee_number' => $employeeNumber,
            'issues'          => $issues,
        ];
    }

    public function addCredential(string $employeeNumber, string $name, string $password): void
    {
        $this->credentials[] = ['employee_number' => $employeeNumber, 'name' => $name, 'password' => $password];
    }

    /**
     * @return array<int, array{row: int, name: string, key: ?string, outcome: string, employee_number: ?string, issues: array<int, array{severity: string, field: ?string, message: string}>}>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    /**
     * Empty on a dry run: no account was made, so no password exists.
     *
     * @return array<int, array{employee_number: string, name: string, password: string}>
     */
    public function credentials(): array
    {
        return $this->dryRun ? [] : $this->credentials;
    }

    public function count(string $outcome): int
    {
        return count(array_filter($this->rows, static fn (array $row): bool => $row['outcome'] === $outcome));
    }

    public function issueCount(string $severity): int
    {
        $count = 0;
        foreach ($this->rows as $row) {
            foreach ($row['issues'] as $issue) {
                $count += $issue['severity'] === $severity ? 1 : 0;
            }
        }

        return $count;
    }
}
