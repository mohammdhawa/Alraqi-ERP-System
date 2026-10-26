<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeImportSource;
use App\Shared\Exceptions\DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * hr:renumber-from-kobo — a one-off for data numbered before the Kobo id
 * became the employee number (KOBO_EMPLOYEE_NUMBER_DESIGN.md §5).
 *
 * Every employee the import knows by a `kobo:<id>` key gets `<id>` as their
 * number, and every session of their account ends: their login changed. An
 * employee with no such key (hand-added test data) keeps their number; one
 * whose `<id>` another employee already holds is skipped, and one the model
 * refuses (a guard added after they were imported, a key the number rule no
 * longer accepts) is refused; both are named, by employee id, and the rest
 * carry on. Running it again changes nothing.
 *
 * `--dry-run` makes every write and rolls them all back, so it meets every
 * refusal the real run would — the model's guards included — and its counts
 * are the real run's.
 *
 * The number never changes otherwise (K2): Employee::renumberTo() is this
 * command's way past that guard, and no other writer's.
 */
class RenumberFromKobo extends Command
{
    protected $signature = 'hr:renumber-from-kobo {--dry-run : Count what would change, write nothing}';

    protected $description = 'Give every imported employee their Kobo id as their number, and end their sessions.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $counts = ['renumbered' => 0, 'already' => 0, 'taken' => 0, 'refused' => 0, 'deleted' => 0];
        $notes = [];

        DB::beginTransaction();

        try {
            $sources = EmployeeImportSource::query()
                ->where('source_key', 'like', 'kobo:%')
                ->orderBy('id')
                ->get();

            foreach ($sources as $source) {
                $employee = Employee::withTrashed()->find($source->employee_id);
                if ($employee === null) {
                    continue;
                }

                // A deleted person holds no Kobo id (I1): it would keep the
                // number from its next owner. A restore by the import gives
                // them theirs back.
                if ($employee->trashed()) {
                    $counts['deleted']++;
                    $notes[] = "Left deleted employee #{$employee->getKey()} alone.";

                    continue;
                }

                $number = substr($source->source_key, strlen('kobo:'));
                if ($employee->employee_number === $number) {
                    $counts['already']++;

                    continue;
                }
                $holder = Employee::withTrashed()->where('employee_number', $number)->first();
                if ($holder !== null) {
                    $counts['taken']++;
                    $notes[] = "Skipped employee #{$employee->getKey()}: {$number} is held by employee #{$holder->getKey()}.";

                    continue;
                }

                try {
                    // A savepoint each: a refusal undoes this person only.
                    DB::transaction(function () use ($employee, $number): void {
                        $employee->renumberTo($number);
                        User::query()->where('employee_id', $employee->getKey())->first()?->endAllSessions();
                    });
                } catch (DomainException $e) {
                    $counts['refused']++;
                    $notes[] = "Refused employee #{$employee->getKey()} ({$source->source_key}): {$e->getMessage()}";

                    continue;
                }

                $counts['renumbered']++;
            }
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        $dryRun ? DB::rollBack() : DB::commit();

        foreach ($notes as $note) {
            $this->line($note);
        }
        $this->info(sprintf(
            '%sRenumbered %d, already numbered %d, skipped %d (number taken), refused %d, deleted %d (left alone).',
            $dryRun ? 'DRY RUN — nothing written. ' : '',
            $counts['renumbered'],
            $counts['already'],
            $counts['taken'],
            $counts['refused'],
            $counts['deleted'],
        ));

        return self::SUCCESS;
    }
}
