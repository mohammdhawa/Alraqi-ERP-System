<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\HR\Import\EmployeeImporter;
use App\Modules\HR\Import\EmployeeRowMapper;
use App\Modules\HR\Import\ImportReport;
use App\Modules\HR\Import\ImportReportWriter;
use App\Modules\HR\Import\WorkbookException;
use App\Modules\HR\Import\WorkbookReader;
use Illuminate\Console\Command;

/**
 * hr:import-employees — the Batch 3 employee import (work order §4.4).
 *
 * Reads `قاعدة البيانات` of HR's workbook and the صرح export, runs
 * EmployeeImporter, and writes HR's report. Re-runnable: each run updates the
 * people the last one made (the kobo:<ID_Sarah> key).
 *
 * THE PASSWORDS. A real run creates accounts with temporary passwords, and
 * they are written to the --credentials file and nowhere else: not the
 * report, not this command's output, not the log. So a real run REFUSES to
 * start without --credentials, and refuses a path that already exists — an
 * earlier run's file may hold passwords HR has not handed out yet.
 *
 * Always dry-run first and review the report: a dry run imports inside a
 * transaction it rolls back, so it reports exactly what a real run would do
 * and leaves the database as it was.
 */
class ImportEmployees extends Command
{
    /** Row 1–2 are a banner; the headers are row 3 (docs/WORKBOOK_COLUMN_MAP.md). */
    private const WORKBOOK_HEADER_ROW = 3;

    private const SARH_HEADER_ROW = 1;

    protected $signature = 'hr:import-employees
                            {workbook : HR\'s workbook, e.g. Program_HR_10_2026.xlsx}
                            {--sheet=قاعدة البيانات : The sheet of active employees}
                            {--sarh= : The صرح export (مستقبل صرح …xlsx) — national ID, mother\'s name, children}
                            {--sarh-sheet=Sheet1 : The sheet of the صرح export}
                            {--dry-run : Import, report, and roll everything back}
                            {--report= : Where to write the report (default: storage/app/private/hr-import/)}
                            {--credentials= : Where to write the temporary passwords — required on a real run}
                            {--force : Do not ask before a real run}';

    protected $description = 'Import active employees from HR\'s workbook and the صرح export, and report row by row.';

    public function handle(WorkbookReader $reader, EmployeeImporter $importer, ImportReportWriter $writer): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $workbook = (string) $this->argument('workbook');
        $sheet = (string) $this->option('sheet');
        $sarh = $this->option('sarh');
        $credentials = $this->option('credentials');
        $reportPath = $this->option('report') ?: $this->defaultReportPath($dryRun);

        if (! $dryRun && ($problem = $this->credentialsProblem($credentials)) !== null) {
            $this->error($problem);

            return self::FAILURE;
        }
        if ($dryRun && $credentials !== null) {
            $this->warn('--credentials ignored: a dry run creates no account, so there is no password to write.');
        }
        if (! is_dir(dirname($reportPath)) && ! @mkdir(dirname($reportPath), 0775, true)) {
            $this->error("Cannot create the report's folder: ".dirname($reportPath));

            return self::FAILURE;
        }

        try {
            $rows = $reader->rows($workbook, $sheet, self::WORKBOOK_HEADER_ROW, EmployeeRowMapper::WORKBOOK_COLUMNS);
            $sarhById = $sarh === null ? [] : EmployeeRowMapper::sarhById(
                $reader->rows((string) $sarh, (string) $this->option('sarh-sheet'), self::SARH_HEADER_ROW, EmployeeRowMapper::SARH_COLUMNS),
            );
        } catch (WorkbookException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf('Read %d rows from «%s», %s.', count($rows), $sheet, $sarh === null ? 'no صرح file' : count($sarhById).' صرح records'));
        if ($sarh === null) {
            $this->warn('Without --sarh, national ID, mother\'s name and children are not imported.');
        }

        if (! $dryRun && ! $this->option('force')
            && ! $this->confirm('This writes employees and creates accounts for real. Did you review a dry run\'s report?')) {
            $this->line('Nothing imported.');

            return self::FAILURE;
        }

        $report = $importer->run($rows, $sarhById, $dryRun, basename($workbook), $sheet);

        $writer->writeReport($report, $reportPath, [
            'ملف الموارد'  => basename($workbook).' — '.$sheet,
            'ملف صرح'      => $sarh === null ? 'لم يُقرأ' : basename((string) $sarh),
            'تاريخ التشغيل' => now()->format('Y-m-d H:i'),
        ]);

        $this->summarise($report);
        $this->info("Report: {$reportPath}");

        if (! $dryRun) {
            $this->writeCredentials($writer, $report, (string) $credentials);
        }

        return self::SUCCESS;
    }

    /**
     * Checked BEFORE anything is imported: a run that cannot write the
     * passwords would leave accounts nobody can sign in to.
     */
    private function credentialsProblem(mixed $path): ?string
    {
        if (! is_string($path) || $path === '') {
            return 'A real run needs --credentials=<file.xlsx>: the temporary passwords are written there and nowhere else. (Or use --dry-run.)';
        }
        if (file_exists($path)) {
            return "{$path} already exists. It may hold passwords from an earlier run that HR has not handed out — choose a new file.";
        }
        if (! is_dir(dirname($path)) || ! is_writable(dirname($path))) {
            return 'The folder for --credentials does not exist or cannot be written: '.dirname($path);
        }

        return null;
    }

    private function writeCredentials(ImportReportWriter $writer, ImportReport $report, string $path): void
    {
        $count = count($report->credentials());
        if ($count === 0) {
            $this->line('No account was created, so no credentials file was written.');

            return;
        }

        $writer->writeCredentials($report, $path);
        // The count and the path only — never a password on the console.
        $this->info("Temporary passwords for {$count} new account(s): {$path}");
        $this->warn('Hand them to HR to distribute, then delete the file. Each is changed at first sign-in.');
    }

    private function summarise(ImportReport $report): void
    {
        $this->newLine();
        $this->line($report->dryRun ? '<comment>DRY RUN — nothing was saved.</comment>' : '<info>Imported.</info>');
        $this->table(['Outcome', 'Rows'], array_map(
            static fn (string $outcome): array => [$outcome, $report->count($outcome)],
            ImportReport::OUTCOMES,
        ));
        $this->line(sprintf(
            'Issues: %d error(s), %d warning(s), %d for the record — every one is in the report.',
            $report->issueCount('error'),
            $report->issueCount('warning'),
            $report->issueCount('info'),
        ));
    }

    private function defaultReportPath(bool $dryRun): string
    {
        return storage_path('app/private/hr-import/employee-import-'.now()->format('Ymd-His').($dryRun ? '-dry-run' : '').'.xlsx');
    }
}
