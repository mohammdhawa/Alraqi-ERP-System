<?php

declare(strict_types=1);

namespace App\Modules\HR\Import;

use App\Modules\Auth\Models\User;
use App\Modules\Auth\Services\UserService;
use App\Modules\HR\Enums\AssignmentReason;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeAssignment;
use App\Modules\HR\Models\EmployeeImportSource;
use App\Modules\HR\Models\JobTitle;
use App\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * The Batch 3 employee importer (work order §4.4, EMPLOYEE_APP_REQUIREMENTS.md
 * §7.1 C). It takes rows already read from the workbook — header => value —
 * and the صرح records keyed by their `_id`, so it knows nothing about files.
 *
 * WHAT IT GUARANTEES
 *
 * - Re-runnable. Each row is keyed `kobo:<ID_Sarah>` in employee_import_sources;
 *   a second run updates the person the first run made. A changed unit, title
 *   or grade is applied with Employee::correct(): the record was wrong, the
 *   person did not move, so no transfer is invented.
 * - Reports rather than absorbs. Every issue the mapper, the unit resolver or
 *   the database raises goes into the report against its row. A row with no
 *   key is skipped; a row the model refuses fails alone — each row is its own
 *   transaction — and the run goes on.
 * - Writes through the models. The classification guard, the hire-date guard,
 *   the posting hooks and the audit trail all see every write.
 * - Accounts once. An employee without an account gets one, with a temporary
 *   password and must_change_password (UserService::create). An existing
 *   account is never touched, so a re-run cannot reset anybody's password. A
 *   soft-deleted employee or account is restored, never inserted beside.
 * - Passwords in one place. A temporary password exists only in the report's
 *   credentials, added after its row commits — never in the report rows, never
 *   in the log.
 * - A dry run writes nothing: the whole run is rolled back, and the report
 *   says what would have happened.
 */
final class EmployeeImporter
{
    /** Readable by someone typing it on a phone: no 0/O, 1/l/I. */
    private const PASSWORD_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    private const PASSWORD_LENGTH = 10;

    /** Columns unique across employees: a value someone else holds is dropped and reported. */
    private const UNIQUE_COLUMNS = [
        'national_id'       => 'الرقم الوطني',
        'sham_cash_account' => 'رقم الشام كاش',
    ];

    /** @var array<string, int> key(name) => job title id, for this run */
    private array $titles = [];

    public function __construct(
        private readonly EmployeeRowMapper $mapper,
        private readonly UserService $users,
    ) {}

    /**
     * @param  iterable<int, array<string, mixed>>  $rows  sheet row number => header => value
     * @param  array<int|string, array<string, mixed>>  $sarhById  the صرح records by `_id`
     */
    public function run(
        iterable $rows,
        array $sarhById,
        bool $dryRun = false,
        string $sourceFile = '',
        string $sourceSheet = 'قاعدة البيانات',
    ): ImportReport {
        $report = new ImportReport($dryRun);
        $this->titles = [];

        if (! $dryRun) {
            $this->importAll($rows, $sarhById, $report, $sourceFile, $sourceSheet);

            return $report;
        }

        DB::beginTransaction();
        try {
            $this->importAll($rows, $sarhById, $report, $sourceFile, $sourceSheet);
        } finally {
            DB::rollBack();
        }

        return $report;
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $rows
     * @param  array<int|string, array<string, mixed>>  $sarhById
     */
    private function importAll(iterable $rows, array $sarhById, ImportReport $report, string $sourceFile, string $sourceSheet): void
    {
        $resolver = UnitResolver::fromDatabase();
        $seen = [];

        foreach ($rows as $rowNumber => $row) {
            $kobo = EmployeeRowMapper::koboId($row['ID_Sarah'] ?? null) ?? '';
            $mapped = $this->mapper->map($row, $sarhById[$kobo] ?? null);
            $issues = $mapped->issues;

            if ($mapped->sourceKey === null) {
                $report->addRow($rowNumber, $mapped->displayName, null, 'skipped', null, $issues);

                continue;
            }

            if (isset($seen[$mapped->sourceKey])) {
                $issues[] = $this->issue('error', null, 'رقم كوبو '.$kobo.' مكرر في الملف (السطر '.$seen[$mapped->sourceKey].') — لم يُستورد هذا السطر.');
                $report->addRow($rowNumber, $mapped->displayName, $mapped->sourceKey, 'skipped', null, $issues);

                continue;
            }
            $seen[$mapped->sourceKey] = $rowNumber;

            if ($sarhById !== [] && ! isset($sarhById[$kobo])) {
                $issues[] = $this->issue('warning', null, 'لا يوجد سجل لهذا الموظف في ملف صرح — الرقم الوطني واسم الأم وعدد الأبناء غير متوفرة بعد.');
            }

            $unit = $resolver->resolve(...$mapped->unit);
            foreach ($unit->issues as $unitIssue) {
                $issues[] = $this->issue($unitIssue['severity'], 'department_id', $unitIssue['message']);
            }

            try {
                // A closure with &$issues, not an arrow function: an arrow fn
                // captures by value, and the warnings importOne() adds inside
                // the transaction would be lost from the report.
                [$outcome, $employee, $password] = DB::transaction(
                    function () use ($mapped, $unit, &$issues, $sourceFile, $sourceSheet, $rowNumber): array {
                        return $this->importOne($mapped, $unit->departmentId, $issues, $sourceFile, $sourceSheet, $rowNumber);
                    },
                );
            } catch (Throwable $e) {
                // The row's transaction rolled back, and with it any job title
                // it created — so the cache may now hold ids that no longer
                // exist. Start it again rather than hand the next row a dead FK.
                $this->titles = [];
                $issues[] = $this->issue('error', null, $this->failureMessage($e));
                $report->addRow($rowNumber, $mapped->displayName, $mapped->sourceKey, 'failed', null, $issues);

                continue;
            }

            if ($password !== null) {
                $report->addCredential((string) $employee->employee_number, $mapped->displayName, $password);
            }
            $report->addRow($rowNumber, $mapped->displayName, $mapped->sourceKey, $outcome, $employee->employee_number, $issues);
        }
    }

    /**
     * One row, inside its own transaction.
     *
     * @param  array<int, array{severity: string, field: ?string, message: string}>  $issues  appended to
     * @return array{0: string, 1: Employee, 2: ?string} outcome, employee, temporary password or null
     */
    private function importOne(MappedRow $mapped, ?int $departmentId, array &$issues, string $sourceFile, string $sourceSheet, int $rowNumber): array
    {
        $existing = EmployeeImportSource::employeeFor($mapped->sourceKey);

        $attributes = $mapped->attributes + [
            'department_id' => $departmentId,
            'job_title_id'  => $this->jobTitleId($mapped->currentTitle),
        ];
        $attributes = $this->dropTakenUniques($attributes, $existing?->getKey(), $issues);

        if ($existing === null) {
            $employee = Employee::create($attributes);
            $outcome = 'created';
        } else {
            $outcome = $existing->trashed() ? 'restored' : 'updated';
            if ($existing->trashed()) {
                $existing->restore();
            }
            $existing->correct($attributes);
            $employee = $existing;
        }

        EmployeeImportSource::remember($mapped->sourceKey, $employee, [
            'source_file'  => $sourceFile,
            'source_sheet' => $sourceSheet,
            'source_row'   => $rowNumber,
            'source_name'  => $mapped->displayName,
        ]);

        $this->recordOpeningPosting($employee, $mapped);

        return [$outcome, $employee, $this->ensureAccount($employee)];
    }

    /**
     * A unique value another employee already holds would fail the whole row
     * at the index; dropping it keeps the person, and the report says whose.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array{severity: string, field: ?string, message: string}>  $issues
     * @return array<string, mixed>
     */
    private function dropTakenUniques(array $attributes, int|string|null $ownId, array &$issues): array
    {
        foreach (self::UNIQUE_COLUMNS as $column => $label) {
            $value = $attributes[$column] ?? null;
            if ($value === null) {
                continue;
            }

            $holder = Employee::withTrashed()
                ->where($column, $value)
                ->when($ownId !== null, fn ($query) => $query->whereKeyNot($ownId))
                ->first();

            if ($holder !== null) {
                // The holder's sheet row as well as their number: on a dry
                // run the number is one the rollback takes back.
                $row = EmployeeImportSource::where('employee_id', $holder->getKey())->orderByDesc('id')->value('source_row');
                $where = $row === null ? '' : "، السطر {$row}";
                $issues[] = $this->issue('warning', $column, "{$label} «{$value}» مسجّل لموظف آخر ({$holder->employee_number} — {$holder->full_name}{$where}) — لم يُسجَّل لهذا الموظف.");
                $attributes[$column] = null;
            }
        }

        return $attributes;
    }

    /**
     * The catalogue title for $name, deduplicated by ArabicText::key() and
     * created when missing. A soft-deleted title of the same name is restored:
     * `job_titles.name` is unique across trashed rows too.
     */
    private function jobTitleId(?string $name): ?int
    {
        if ($name === null) {
            return null;
        }

        $key = ArabicText::key($name);
        if (isset($this->titles[$key])) {
            return $this->titles[$key];
        }

        $title = JobTitle::withTrashed()->get(['id', 'name', 'deleted_at'])
            ->first(fn (JobTitle $t): bool => ArabicText::key($t->name) === $key);

        if ($title === null) {
            $title = JobTitle::create(['name' => $name]);
        } elseif ($title->trashed()) {
            $title->restore();
        }

        return $this->titles[$key] = (int) $title->getKey();
    }

    /**
     * The workbook's first workplace and first manager, as free text on the
     * opening تعيين posting (which the model hook wrote from hire_date).
     */
    private function recordOpeningPosting(Employee $employee, MappedRow $mapped): void
    {
        $opening = $employee->assignments()
            ->reorder()
            ->where('reason', AssignmentReason::Hire)
            ->orderBy('from_date')
            ->orderBy('id')
            ->first();

        if (! $opening instanceof EmployeeAssignment) {
            return;
        }

        $opening->fill($mapped->openingPosting);
        if ($opening->isDirty()) {
            $opening->save();
        }
    }

    /**
     * Make sure the employee can sign in: create the account, or restore a
     * soft-deleted one. Returns the temporary password when one was issued.
     */
    private function ensureAccount(Employee $employee): ?string
    {
        $user = User::withTrashed()->where('employee_id', $employee->getKey())->first();

        if ($user !== null && ! $user->trashed()) {
            return null;
        }

        $password = $this->temporaryPassword();

        if ($user === null) {
            $this->users->create(['employee_id' => $employee->getKey(), 'password' => $password]);

            return $password;
        }

        $user->restore();
        $this->users->update($user, ['password' => $password, 'is_active' => true]);

        return $password;
    }

    private function temporaryPassword(): string
    {
        $password = '';
        for ($i = 0; $i < self::PASSWORD_LENGTH; $i++) {
            $password .= self::PASSWORD_ALPHABET[random_int(0, strlen(self::PASSWORD_ALPHABET) - 1)];
        }

        return $password;
    }

    private function failureMessage(Throwable $e): string
    {
        // A domain refusal carries its own Arabic message for the reader.
        if ($e instanceof DomainException) {
            return 'رُفض السطر: '.$e->getMessage();
        }

        return 'تعذّر استيراد السطر: '.($e instanceof RuntimeException || $e instanceof \LogicException ? $e->getMessage() : $e::class.' — '.$e->getMessage());
    }

    /**
     * @return array{severity: string, field: ?string, message: string}
     */
    private function issue(string $severity, ?string $field, string $message): array
    {
        return ['severity' => $severity, 'field' => $field, 'message' => $message];
    }
}
