<?php

declare(strict_types=1);

namespace App\Modules\HR\Import;

use App\Modules\Auth\Models\User;
use App\Modules\Auth\Services\UserService;
use App\Modules\Auth\Support\TemporaryPassword;
use App\Modules\HR\Enums\AssignmentReason;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Exceptions\ImportSourceConflictException;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeAssignment;
use App\Modules\HR\Models\EmployeeImportSource;
use App\Modules\HR\Models\JobTitle;
use App\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

/**
 * The Batch 3 employee importer (work order §4.4, EMPLOYEE_APP_REQUIREMENTS.md
 * §7.1 C). It takes rows already read from HR's one file — header => value,
 * the صرح columns merged in (2026-10-24) — so it knows nothing about files.
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
    /** Columns unique across employees: a value someone else holds is dropped and reported. */
    private const UNIQUE_COLUMNS = [
        'national_id'       => 'الرقم الوطني',
        'sham_cash_account' => 'رقم الشام كاش',
    ];

    /** @var array<string, int> key(name) => job title id, for this run */
    private array $titles = [];

    private bool $dryRun = false;

    /**
     * One hash for every account a dry run makes: those accounts are rolled
     * back, and bcrypt per account cost a 185-person dry run ~30 s (final
     * review, 2026-10-22). A real run hashes each temporary password.
     */
    private ?string $dryRunHash = null;

    public function __construct(
        private readonly EmployeeRowMapper $mapper,
        private readonly UserService $users,
    ) {}

    /**
     * @param  iterable<int, array<string, mixed>>  $rows  sheet row number => header => value
     * @param  array<int, int>  $excludedRows  sheet row numbers left out of this import (design §12)
     */
    public function run(
        iterable $rows,
        bool $dryRun = false,
        string $sourceFile = '',
        string $sourceSheet = 'قاعدة البيانات',
        array $excludedRows = [],
    ): ImportReport {
        $report = new ImportReport($dryRun);
        $this->titles = [];
        $this->dryRun = $dryRun;
        $this->dryRunHash = null;

        if (! $dryRun) {
            $this->importAll($rows, $report, $sourceFile, $sourceSheet, $excludedRows);

            return $report;
        }

        DB::beginTransaction();
        try {
            $this->importAll($rows, $report, $sourceFile, $sourceSheet, $excludedRows);
        } finally {
            DB::rollBack();
        }

        return $report;
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $rows
     * @param  array<int, int>  $excludedRows
     */
    private function importAll(iterable $rows, ImportReport $report, string $sourceFile, string $sourceSheet, array $excludedRows): void
    {
        $excluded = array_flip($excludedRows);
        $resolver = UnitResolver::fromDatabase();
        $seen = [];

        foreach ($rows as $rowNumber => $row) {
            $kobo = EmployeeRowMapper::koboId($row['ID_Sarah'] ?? null) ?? '';
            $mapped = $this->mapper->map($row);
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

            // Left out of this import by HR (design §12): nothing is written.
            // Its key is already in $seen, so the not-in-file list still
            // counts the person as present; and it never reaches the unique
            // checks, so a later row keeps a number this one would hold.
            if (isset($excluded[$rowNumber])) {
                $report->addRow($rowNumber, $mapped->displayName, $mapped->sourceKey, 'excluded',
                    EmployeeImportSource::employeeFor($mapped->sourceKey)?->employee_number,
                    [$this->issue('info', null, 'استُبعد هذا السطر من الاستيراد في هذه المرة.')]);

                continue;
            }

            $unit = $resolver->resolve(...$mapped->unit);
            foreach ($unit->issues as $unitIssue) {
                $issues[] = $this->issue($unitIssue['severity'], 'department_id', $unitIssue['message']);
            }

            try {
                // A closure with &$issues, not an arrow function: an arrow fn
                // captures by value, and the warnings importOne() adds inside
                // the transaction would be lost from the report.
                [$outcome, $employee, $password, $changes] = DB::transaction(
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
            if ($changes !== []) {
                $report->addChanges($rowNumber, $mapped->displayName, $employee->employee_number, $changes);
            }
            $report->addRow($rowNumber, $mapped->displayName, $mapped->sourceKey, $outcome, $employee->employee_number, $issues);
        }

        $this->reportNotInFile($report, array_keys($seen));
    }

    /**
     * Everyone on the roll this file did not match (design §5.2) — not by
     * outcome but by the Kobo keys the file holds, so a row that failed or was
     * left alone still accounts for its person. Inside a dry run's transaction
     * the people it would create are matched too. Reported only (U3).
     *
     * @param  array<int, string>  $keys  the source keys seen in the file
     */
    private function reportNotInFile(ImportReport $report, array $keys): void
    {
        $matched = EmployeeImportSource::query()->whereIn('source_key', $keys)->select('employee_id');
        $imported = array_flip(EmployeeImportSource::query()->pluck('employee_id')->all());

        $missing = Employee::query()
            ->where('status', '!=', EmployeeStatus::Terminated->value)
            ->whereNotIn('id', $matched)
            ->orderBy('employee_number')
            ->get();

        foreach ($missing as $employee) {
            $report->addNotInFile(
                (int) $employee->getKey(),
                (string) $employee->employee_number,
                (string) $employee->full_name,
                EmployeeImportFields::display($employee, 'department_id', $employee->department_id),
                isset($imported[$employee->getKey()]),
            );
        }
    }

    /**
     * May this row adopt the hand-added employee holding its number? Only a
     * live one the import does not know yet, with the row's name — compared
     * folded (ArabicText::key), so أحمد and احمد are one name.
     */
    private function assertAdoptable(Employee $holder, MappedRow $mapped, string $number): void
    {
        if (EmployeeImportSource::query()->where('employee_id', $holder->getKey())->exists()) {
            throw new ImportSourceConflictException("الرقم الوظيفي {$number} مسجّل لموظف مستورد من سطر آخر — لم يُستورد هذا السطر.");
        }
        if ($holder->trashed()) {
            throw new ImportSourceConflictException("الرقم الوظيفي {$number} مسجّل لموظف محذوف (#{$holder->getKey()}) — لم يُستورد هذا السطر.");
        }

        $name = fn (array $parts): string => ArabicText::key(implode(' ', array_filter($parts)));
        $theirs = $name([$holder->first_name, $holder->middle_name, $holder->last_name]);
        $ours = $name([$mapped->attributes['first_name'] ?? null, $mapped->attributes['middle_name'] ?? null, $mapped->attributes['last_name'] ?? null]);
        if ($theirs !== $ours) {
            $shown = fn (array $parts): string => implode(' ', array_filter($parts));
            throw new ImportSourceConflictException(sprintf(
                'الرقم الوظيفي %s مسجّل للموظف «%s»، والاسم في الملف «%s» — لم يُستورد هذا السطر. صحّح الرقم أو الاسم ثم أعد الاستيراد.',
                $number,
                $shown([$holder->first_name, $holder->middle_name, $holder->last_name]),
                $shown([$mapped->attributes['first_name'] ?? null, $mapped->attributes['middle_name'] ?? null, $mapped->attributes['last_name'] ?? null]),
            ));
        }
    }

    /**
     * One row, inside its own transaction.
     *
     * @param  array<int, array{severity: string, field: ?string, message: string}>  $issues  appended to
     * @return array{0: string, 1: Employee, 2: ?string, 3: array<int, array{field: string, label: string, old: ?string, new: ?string}>} outcome, employee, temporary password or null, what an update changed
     */
    private function importOne(MappedRow $mapped, ?int $departmentId, array &$issues, string $sourceFile, string $sourceSheet, int $rowNumber): array
    {
        $existing = EmployeeImportSource::employeeFor($mapped->sourceKey);
        // The person's Kobo id, which is also their number
        // (KOBO_EMPLOYEE_NUMBER_DESIGN.md §4).
        $number = substr((string) $mapped->sourceKey, strlen('kobo:'));

        // Someone HR added by hand holds this number already: adopt them —
        // record the key, update them as an existing person — rather than
        // create a duplicate. Only the same person, though (I1, 2026-10-24):
        // adopting hands the row their record, history and account, so a
        // holder the import knows under another key, a deleted one, or one
        // under another name is refused for this row, never merged.
        if ($existing === null) {
            $holder = Employee::withTrashed()->where('employee_number', $number)->first();
            if ($holder !== null) {
                $this->assertAdoptable($holder, $mapped, $number);
            }
            $existing = $holder;
        }

        // R1 (2026-10-22): someone terminated or suspended in the ERP is left
        // as they are. The mapper writes every row as نشط, so an update would
        // bring them back to duty without the account their termination
        // disabled — and leavers are ended by hand in the ERP (U3), while
        // nobody acts on an employee who is not on duty (C-3). The row is still
        // remembered, so the report does not also list them as missing.
        if ($existing !== null && ! $existing->trashed() && ! $existing->isOnDuty()) {
            $issues[] = $this->issue('warning', 'status', 'الموظف ليس على رأس عمله في النظام — لم يُحدَّث من الملف. إن عاد إلى العمل فأعِد حالته من شاشة الموظف.');
            $this->rememberSource($mapped, $existing, $sourceFile, $sourceSheet, $rowNumber);

            return ['skipped', $existing, null, []];
        }

        $attributes = $mapped->attributes + [
            'department_id' => $departmentId,
            'job_title_id'  => $this->jobTitleId($mapped->currentTitle),
        ];
        // A blank person cell is unknown, not "erase it" (project owner,
        // 2026-10-24): for someone already here it is left out of the update.
        if ($existing !== null) {
            foreach (EmployeeRowMapper::KEPT_WHEN_BLANK as $kept) {
                if (array_key_exists($kept, $attributes) && $attributes[$kept] === null) {
                    unset($attributes[$kept]);
                }
            }
        }
        $attributes = $this->dropTakenUniques($attributes, $existing?->getKey(), $issues);

        $changes = [];
        if ($existing === null) {
            // It never changes, so only a create writes it.
            $employee = Employee::create($attributes + ['employee_number' => $number]);
            $outcome = 'created';
        } else {
            // Judged before the save: afterwards the old values are gone.
            $changes = EmployeeImportFields::changes($existing, $attributes);
            $outcome = $existing->trashed() ? 'restored' : 'updated';
            if ($existing->trashed()) {
                $existing->restore();
            }
            $existing->correct($attributes);
            $employee = $existing;
        }

        $this->rememberSource($mapped, $employee, $sourceFile, $sourceSheet, $rowNumber);

        $postingChanges = $this->recordOpeningPosting($employee, $mapped);
        if ($existing !== null) {
            $changes = [...$changes, ...$postingChanges];
        }

        return [$outcome, $employee, $this->ensureAccount($employee), $changes];
    }

    private function rememberSource(MappedRow $mapped, Employee $employee, string $sourceFile, string $sourceSheet, int $rowNumber): void
    {
        EmployeeImportSource::remember((string) $mapped->sourceKey, $employee, [
            'source_file'  => $sourceFile,
            'source_sheet' => $sourceSheet,
            'source_row'   => $rowNumber,
            'source_name'  => $mapped->displayName,
        ]);
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
     * Returns what it changed, for the report's changes list.
     *
     * @return array<int, array{field: string, label: string, old: ?string, new: ?string}>
     */
    private function recordOpeningPosting(Employee $employee, MappedRow $mapped): array
    {
        $opening = $employee->assignments()
            ->reorder()
            ->where('reason', AssignmentReason::Hire)
            ->orderBy('from_date')
            ->orderBy('id')
            ->first();

        if (! $opening instanceof EmployeeAssignment) {
            return [];
        }

        $changes = EmployeeImportFields::changes($opening, $mapped->openingPosting);
        $opening->fill($mapped->openingPosting);
        if ($opening->isDirty()) {
            $opening->save();
        }

        return $changes;
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

        $password = TemporaryPassword::generate();
        // The `hashed` cast stores an existing hash as it is.
        $stored = $this->dryRun ? ($this->dryRunHash ??= Hash::make(Str::random(40))) : $password;

        if ($user === null) {
            $this->users->create(['employee_id' => $employee->getKey(), 'password' => $stored]);

            return $password;
        }

        $user->restore();
        $this->users->update($user, ['password' => $stored, 'is_active' => true]);

        return $password;
    }

    private function failureMessage(Throwable $e): string
    {
        // A domain refusal carries its own Arabic message for the reader.
        if ($e instanceof DomainException) {
            return 'رُفض السطر: '.$e->getMessage();
        }

        // Anything else is the server's failure, and its message may carry
        // SQL, the schema, bound values or a class name — which the upload
        // screen's report would hand to the client (C12, final review
        // 2026-10-22). The detail goes to the log; the row says what happened.
        report($e);

        return 'تعذّر استيراد السطر بسبب خطأ في الخادم.';
    }

    /**
     * @return array{severity: string, field: ?string, message: string}
     */
    private function issue(string $severity, ?string $field, string $message): array
    {
        return ['severity' => $severity, 'field' => $field, 'message' => $message];
    }
}
