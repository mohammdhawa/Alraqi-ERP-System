<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\HR\Services\EmployeeImportService;
use Illuminate\Console\Command;

/**
 * hr:expire-import-runs — an upload import's files and report are kept one
 * hour (EMPLOYEE_IMPORT_UPLOAD_DESIGN.md §4.6, U5): they hold names, national
 * IDs and pay values. Then the files are deleted and the report purged; the
 * run keeps who, when, which files and the counts. Scheduled every five
 * minutes in routes/console.php.
 */
class ExpireImportRuns extends Command
{
    protected $signature = 'hr:expire-import-runs';

    protected $description = 'Delete the files and report of upload imports past their hour; the runs are kept.';

    public function handle(EmployeeImportService $imports): int
    {
        $expired = $imports->expire();
        if ($expired === null) {
            // Never beside a running import (§4.6): the next sweep catches up.
            $this->warn('Skipped: an import is running.');

            return self::SUCCESS;
        }

        $this->info("Expired {$expired} import run(s).");

        return self::SUCCESS;
    }
}
