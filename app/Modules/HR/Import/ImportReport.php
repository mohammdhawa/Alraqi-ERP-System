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
    /** `excluded`: left out of this import by HR on the upload screen (design §12). */
    public const OUTCOMES = ['created', 'updated', 'restored', 'excluded', 'skipped', 'failed'];

    /** @var array<int, array{row: int, name: string, key: ?string, outcome: string, employee_number: ?string, issues: array<int, array{severity: string, field: ?string, message: string}>}> */
    private array $rows = [];

    /** @var array<int, array{employee_number: string, name: string, password: string}> */
    private array $credentials = [];

    /** @var array<int, array{row: int, name: string, employee_number: ?string, fields: array<int, array{field: string, label: string, old: ?string, new: ?string}>}> */
    private array $changes = [];

    /** @var array<int, array{employee_id: int, employee_number: string, name: string, unit: ?string, imported: bool}> */
    private array $notInFile = [];

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
     * What a row changes on someone already in the ERP (design §5.1). The
     * workbook is the source (U2), so this is where an overwritten ERP edit
     * shows before it happens.
     *
     * @param  array<int, array{field: string, label: string, old: ?string, new: ?string}>  $fields
     */
    public function addChanges(int $row, string $name, ?string $employeeNumber, array $fields): void
    {
        $this->changes[] = ['row' => $row, 'name' => $name, 'employee_number' => $employeeNumber, 'fields' => $fields];
    }

    /**
     * Someone on the roll this file did not match (design §5.2): reported,
     * never changed — a leaver is terminated by hand (U3).
     */
    public function addNotInFile(int $employeeId, string $employeeNumber, string $name, ?string $unit, bool $imported): void
    {
        $this->notInFile[] = [
            'employee_id'     => $employeeId,
            'employee_number' => $employeeNumber,
            'name'            => $name,
            'unit'            => $unit,
            'imported'        => $imported,
        ];
    }

    /**
     * @return array<int, array{row: int, name: string, employee_number: ?string, fields: array<int, array{field: string, label: string, old: ?string, new: ?string}>}>
     */
    public function changes(): array
    {
        return $this->changes;
    }

    /**
     * @return array<int, array{employee_id: int, employee_number: string, name: string, unit: ?string, imported: bool}>
     */
    public function notInFile(): array
    {
        return $this->notInFile;
    }

    /**
     * The counts a run keeps for good, after its report is purged.
     *
     * @return array{created: int, updated: int, restored: int, skipped: int, failed: int, errors: int, warnings: int, info: int}
     */
    public function summary(): array
    {
        $summary = [];
        foreach (self::OUTCOMES as $outcome) {
            $summary[$outcome] = $this->count($outcome);
        }

        return $summary + [
            'errors'   => $this->issueCount('error'),
            'warnings' => $this->issueCount('warning'),
            'info'     => $this->issueCount('info'),
        ];
    }

    /**
     * The report as data, for the upload screen. Never the credentials. A dry
     * run's new employee has no number: the rollback took it back, and the
     * real run may hand out another (as ImportReportWriter blanks it).
     *
     * @return array{rows: array<int, array<string, mixed>>, changes: array<int, array<string, mixed>>, not_in_file: array<int, array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'rows' => array_map(
                fn (array $row): array => $this->dryRun && $row['outcome'] === 'created' ? array_replace($row, ['employee_number' => null]) : $row,
                $this->rows,
            ),
            'changes'     => $this->changes,
            'not_in_file' => $this->notInFile,
        ];
    }

    /**
     * A report rebuilt from toArray(), to write a stored run's report again as
     * the xlsx. It carries no credentials: toArray() never held any.
     *
     * @param  array{rows?: array<int, array<string, mixed>>, changes?: array<int, array<string, mixed>>, not_in_file?: array<int, array<string, mixed>>}  $data
     */
    public static function fromArray(array $data, bool $dryRun): self
    {
        $report = new self($dryRun);
        $report->rows = array_values($data['rows'] ?? []);
        $report->changes = array_values($data['changes'] ?? []);
        $report->notInFile = array_values($data['not_in_file'] ?? []);

        return $report;
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
