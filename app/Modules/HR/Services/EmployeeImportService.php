<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\ImportRunStatus;
use App\Modules\HR\Exceptions\EmployeeImportException;
use App\Modules\HR\Import\EmployeeImporter;
use App\Modules\HR\Import\EmployeeRowMapper;
use App\Modules\HR\Import\ImportReport;
use App\Modules\HR\Import\ImportReportWriter;
use App\Modules\HR\Import\WorkbookException;
use App\Modules\HR\Import\WorkbookReader;
use App\Modules\HR\Models\EmployeeImportRun;
use App\Shared\Services\AuditLogService;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The upload import (EMPLOYEE_IMPORT_UPLOAD_DESIGN.md): HR's one file — the
 * صرح columns merged in (2026-10-24) — uploaded, dry-run, and — once, within the hour — imported for
 * real from the very files the dry run read (U5). The importer is the one
 * `hr:import-employees` runs; this only stores, reads, locks and records.
 *
 * THE FILE holds more than the import reads — pay values, and whatever HR
 * merged in from صرح (religion, sect, ID scans). It waits on the private disk under a random
 * folder name, are never served, and go at the real run or at expiry.
 *
 * THE PASSWORDS exist in the real run's response and nowhere else (U4).
 */
class EmployeeImportService
{
    private const DISK = 'local';

    private const LOCK = 'hr-employee-import';

    /**
     * Where the report and the passwords file are built before they are sent:
     * on the private disk, not the shared system temp folder, where other
     * local users could read them while they exist (final review, Part 1).
     */
    private const TEMP = 'hr-import/tmp';

    /**
     * How long a check or a real run may take. A real run hashes one bcrypt
     * password per new account — ~30 s for 185 people on its own — so the
     * stock 30 s limit would kill the first import halfway, its passwords lost
     * (spec §8; final review 2026-10-22).
     */
    private const TIME_LIMIT_SECONDS = 600;

    /** Longer than any run may live, so the lock never lapses under one. */
    private const LOCK_SECONDS = 900;

    public function __construct(
        private readonly WorkbookReader $reader,
        private readonly EmployeeImporter $importer,
        private readonly AuditLogService $audit,
        private readonly ImportReportWriter $writer,
    ) {}

    /**
     * Store the file and dry-run it. A file the reader cannot read is refused
     * with nothing kept.
     */
    public function check(User $user, UploadedFile $workbook): EmployeeImportRun
    {
        return $this->locked(function () use ($user, $workbook): EmployeeImportRun {
            // A random folder: the names HR chose are data, never a path.
            $folder = 'hr-import/runs/'.Str::uuid();
            try {
                // Inside the cleanup's reach (final review, Part 1).
                $workbookPath = $this->store($workbook, $folder, 'workbook.xlsx');

                $report = $this->import($workbookPath, $workbook->getClientOriginalName(), dryRun: true);

                $run = EmployeeImportRun::create([
                    'created_by'      => $user->id,
                    'status'          => ImportRunStatus::Checked,
                    'workbook_name'   => $workbook->getClientOriginalName(),
                    'workbook_path'   => $workbookPath,
                    'workbook_sha256' => hash_file('sha256', $this->disk()->path($workbookPath)),
                    'summary'         => $report->summary(),
                    'report'          => $report->toArray(),
                    'importable_rows' => $this->importableRows($report),
                    'excluded_rows'   => [],
                    'checked_at'      => now(),
                    'expires_at'      => now()->addMinutes(EmployeeImportRun::LIFETIME_MINUTES),
                ]);
            } catch (Throwable $e) {
                $this->disk()->deleteDirectory($folder);

                throw $e;
            }

            $this->audit->logAction(
                event: 'employees_import_checked',
                description: 'فحص ملف استيراد الموظفين',
                metadata: $this->auditMetadata($run),
            );

            return $run;
        });
    }

    /**
     * The real import, on the files the dry run read, at most once and within
     * the hour (U5). The temporary passwords come back in the result and are
     * written nowhere else (U4): the credentials workbook is built in a
     * temporary file, read back, and deleted before this returns.
     *
     * Everything is re-read inside the lock, so a second press of the button
     * finds the run already executed and creates nobody.
     *
     * @param  array<int, int>|null  $shownExclusions  the list the screen showed, when it sends one;
     *                                                 another list than the saved one is refused (§12.2)
     * @return array{run: EmployeeImportRun, credentials: ?array{file_name: string, content_base64: string}}
     */
    public function execute(int $id, User $user, ?array $shownExclusions = null): array
    {
        return $this->locked(function () use ($id, $user, $shownExclusions): array {
            $run = $this->findOwned($id, $user);
            if ($run->status === ImportRunStatus::Executed) {
                throw EmployeeImportException::alreadyExecuted();
            }
            if ($run->isExpired() || $run->workbook_path === null) {
                throw EmployeeImportException::expired();
            }
            if ($shownExclusions !== null && ! $this->sameRows($shownExclusions, $run->excluded_rows ?? [])) {
                throw EmployeeImportException::staleExclusions();
            }
            if (! $this->filesUnchanged($run)) {
                throw EmployeeImportException::filesChanged();
            }

            // The saved list only (design §12): the real run imports what the
            // last re-check showed, whatever a screen may hold unapplied.
            $report = $this->import($run->workbook_path, $run->workbook_name, dryRun: false, excludedRows: $run->excluded_rows ?? []);
            $credentials = $this->credentials($report);

            $folder = dirname($run->workbook_path);
            $run->update([
                'status'        => ImportRunStatus::Executed,
                'executed_at'   => now(),
                'summary'       => $report->summary(),
                'report'        => $report->toArray(),
                'workbook_path' => null,
            ]);
            $this->disk()->deleteDirectory($folder);

            // Every other dry run was computed against the database as it was
            // before this import. Executing one now would reapply an older
            // workbook over this one's result (final review 2026-10-22).
            EmployeeImportRun::query()
                ->where('status', ImportRunStatus::Checked->value)
                ->get()
                ->each(fn (EmployeeImportRun $other) => $this->retire($other));

            $this->audit->logAction(
                event: 'employees_import_executed',
                description: 'استيراد الموظفين من ملف',
                metadata: $this->auditMetadata($run),
            );

            return ['run' => $run, 'credentials' => $credentials];
        });
    }

    /**
     * Runs past their hour lose their files and their report (§4.6): a run
     * never imported becomes Expired; an executed one keeps its status. Who,
     * when, which files and the counts stay for good.
     *
     * Under the import lock, but never waiting for it: a sweep beside a
     * re-check or a real run could expire the run under it, delete its files
     * mid-read, or have its report written back after the purge (final
     * reviews, Parts 1 and 2). While an import holds the lock this sweep is
     * skipped; the next one catches up.
     *
     * @return int|null how many runs were cleared; null when skipped
     */
    public function expire(?CarbonInterface $now = null): ?int
    {
        $lock = Cache::lock(self::LOCK, self::LOCK_SECONDS);
        if (! $lock->get()) {
            return null;
        }

        try {
            $runs = EmployeeImportRun::query()
                ->where('expires_at', '<=', $now ?? now())
                ->where(fn ($query) => $query
                    ->whereNotNull('report')
                    ->orWhereNotNull('workbook_path')
                    ->orWhere('status', ImportRunStatus::Checked->value))
                ->get();

            foreach ($runs as $run) {
                $this->retire($run);
                $run->update(['report' => null]);
            }

            return $runs->count();
        } finally {
            $lock->release();
        }
    }

    /**
     * A run that can no longer be executed: its files go, and a run never
     * imported is marked Expired. Its report stays until its hour ends.
     */
    private function retire(EmployeeImportRun $run): void
    {
        if ($run->workbook_path !== null) {
            $this->disk()->deleteDirectory(dirname($run->workbook_path));
        }

        $run->update([
            'status'        => $run->status === ImportRunStatus::Checked ? ImportRunStatus::Expired : $run->status,
            'workbook_path' => null,
        ]);
    }

    /**
     * Leave rows out of this import, and dry-run again without them
     * (design §12, U9): leaving a row out can change other rows — a national
     * ID it held, who is "not in the file" — so the report HR reviews is
     * recomputed, and the real run still imports exactly what was reviewed.
     * The list replaces the saved one; `[]` clears it. The hour does not
     * restart.
     *
     * @param  array<int, int>  $rows  sheet row numbers
     */
    public function exclude(int $id, User $user, array $rows): EmployeeImportRun
    {
        return $this->locked(function () use ($id, $user, $rows): EmployeeImportRun {
            $run = $this->findOwned($id, $user);
            if ($run->status === ImportRunStatus::Executed) {
                throw EmployeeImportException::alreadyExecuted();
            }
            if ($run->isExpired() || $run->workbook_path === null) {
                throw EmployeeImportException::expired();
            }

            $rows = array_values(array_unique(array_map('intval', $rows)));
            sort($rows);

            // Which rows may be excluded is judged afresh, on a dry run with
            // nothing excluded: the ERP may have changed since the upload — a
            // reinstated employee's row becomes importable — and the real
            // run will import whatever is importable then (final review,
            // Part 2). Every row is judged before anything is saved.
            $whole = $this->import($run->workbook_path, $run->workbook_name, dryRun: true);
            $importable = $this->importableRows($whole);
            $inFile = array_column($whole->rows(), 'row');
            foreach ($rows as $row) {
                if (! in_array($row, $inFile, true)) {
                    throw EmployeeImportException::rowNotInFile($row);
                }
                if (! in_array($row, $importable, true)) {
                    throw EmployeeImportException::rowNotImportable($row);
                }
            }

            $report = $rows === []
                ? $whole
                : $this->import($run->workbook_path, $run->workbook_name, dryRun: true, excludedRows: $rows);
            $run->update([
                'summary'         => $report->summary(),
                'report'          => $report->toArray(),
                'importable_rows' => $importable,
                'excluded_rows'   => $rows,
            ]);

            $this->audit->logAction(
                event: 'employees_import_excluded',
                description: 'استبعاد سطور من استيراد الموظفين',
                metadata: ['run_id' => $run->id, 'excluded_rows' => $rows],
            );

            return $run;
        });
    }

    /** A run of this user's, or 404: another's run is hidden, not refused (C9). */
    public function findOwned(int $id, User $user): EmployeeImportRun
    {
        return EmployeeImportRun::query()
            ->whereKey($id)
            ->where('created_by', $user->id)
            ->firstOrFail();
    }

    /**
     * The run's report as the xlsx the command writes, in a temporary file the
     * caller sends and deletes. A purged report is gone with its hour.
     */
    public function reportFile(EmployeeImportRun $run): string
    {
        if ($run->report === null) {
            throw EmployeeImportException::expired();
        }

        $path = $this->tempPath();
        $this->writer->writeReport(
            ImportReport::fromArray($run->report, dryRun: $run->status !== ImportRunStatus::Executed),
            $path,
            [
                'ملف الموارد'  => $run->workbook_name.' — '.EmployeeRowMapper::WORKBOOK_SHEET,
                'تاريخ التشغيل' => ($run->executed_at ?? $run->checked_at)->format('Y-m-d H:i'),
            ],
        );

        return $path;
    }

    /**
     * The credentials workbook, as data — or null when no account was made.
     *
     * @return ?array{file_name: string, content_base64: string}
     */
    private function credentials(ImportReport $report): ?array
    {
        if ($report->credentials() === []) {
            return null;
        }

        $path = $this->tempPath();
        try {
            $this->writer->writeCredentials($report, $path);
            $content = base64_encode((string) file_get_contents($path));
        } finally {
            @unlink($path);
        }

        return [
            'file_name'      => 'كلمات-المرور-المؤقتة-'.now()->format('Y-m-d-Hi').'.xlsx',
            'content_base64' => $content,
        ];
    }

    /**
     * Read the stored files and run the importer on them.
     *
     * @param  array<int, int>  $excludedRows  sheet rows left out (design §12)
     */
    private function import(string $workbookPath, string $workbookName, bool $dryRun, array $excludedRows = []): ImportReport
    {
        $rows = $this->read($workbookPath);

        return $this->importer->run($rows, $dryRun, $workbookName, EmployeeRowMapper::WORKBOOK_SHEET, $excludedRows);
    }

    /** A stored upload's path; a store that reports failure is one. */
    private function store(UploadedFile $file, string $folder, string $name): string
    {
        $path = $file->storeAs($folder, $name, self::DISK);
        if (! is_string($path)) {
            throw new RuntimeException("Could not store the uploaded {$name}.");
        }

        return $path;
    }

    /** A fresh file path in the private temp folder (TEMP). */
    private function tempPath(): string
    {
        $this->disk()->makeDirectory(self::TEMP);

        return $this->disk()->path(self::TEMP.'/'.Str::uuid().'.xlsx');
    }

    /**
     * The stored files are the ones the dry run read: "imports exactly what
     * was reviewed" (U5) is checked against the hashes taken at the check.
     */
    private function filesUnchanged(EmployeeImportRun $run): bool
    {
        $same = fn (string $path, ?string $hash): bool => $hash !== null
            && $this->disk()->exists($path)
            && hash_equals($hash, (string) hash_file('sha256', $this->disk()->path($path)));

        return $same($run->workbook_path, $run->workbook_sha256);
    }

    /**
     * The same sheet rows, order and repeats ignored.
     *
     * @param  array<int, int|string>  $a
     * @param  array<int, int|string>  $b
     */
    private function sameRows(array $a, array $b): bool
    {
        $normalise = static function (array $rows): array {
            $rows = array_values(array_unique(array_map('intval', $rows)));
            sort($rows);

            return $rows;
        };

        return $normalise($a) === $normalise($b);
    }

    /**
     * The rows a dry run with nothing excluded would import — what HR may
     * exclude (design §12.2).
     *
     * @return array<int, int>
     */
    private function importableRows(ImportReport $report): array
    {
        $rows = [];
        foreach ($report->rows() as $row) {
            if (in_array($row['outcome'], ['created', 'updated', 'restored'], true)) {
                $rows[] = $row['row'];
            }
        }

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private function read(string $path): array
    {
        try {
            return $this->reader->rows($this->disk()->path($path), EmployeeRowMapper::WORKBOOK_SHEET, EmployeeRowMapper::WORKBOOK_HEADER_ROW, EmployeeRowMapper::WORKBOOK_COLUMNS);
        } catch (WorkbookException $e) {
            throw EmployeeImportException::unreadable($e);
        }
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function locked(Closure $work): mixed
    {
        $lock = Cache::lock(self::LOCK, self::LOCK_SECONDS);
        if (! $lock->get()) {
            throw EmployeeImportException::busy();
        }
        set_time_limit(self::TIME_LIMIT_SECONDS);

        try {
            return $work();
        } finally {
            $lock->release();
        }
    }

    /**
     * What the trail keeps of a run: which files, and the counts. No name,
     * number or value of anyone in them (§4.5).
     *
     * @return array<string, mixed>
     */
    private function auditMetadata(EmployeeImportRun $run): array
    {
        return [
            'run_id'          => $run->id,
            'workbook_name'   => $run->workbook_name,
            'workbook_sha256' => $run->workbook_sha256,
            'summary'         => $run->summary,
            'excluded_rows'   => $run->excluded_rows ?? [],
        ];
    }

    private function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }
}
