<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Shared\Support\AuditEventCatalogue;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * The event vocabulary is reference data, so it is held to the code that
 * produces it — the same way PermissionSeederTest holds the permission
 * catalogue to the strings route middleware passes.
 *
 * WHY THIS IS WORTH A TEST: an event the catalogue does not know serialises
 * with a null label and a null group, and — the part that actually costs
 * something — it is invisible to the `event_group` filter, whose default hides
 * the api_request firehose. So a new `logAction()` event added without a line
 * here does not merely look unlabelled: it does not appear on the audit screen
 * at all under the filter the screen applies by default. That is a new kind of
 * event going unseen on the one table whose job is to be seen, and nothing else
 * would announce it.
 */
class AuditEventCatalogueTest extends TestCase
{
    /**
     * The three model events, written by HasAuditLog as positional arguments
     * rather than the `event:` named form the scan below finds. Listed here so
     * the assertion covers the whole vocabulary and not just the half that
     * happens to match a regex.
     */
    private const MODEL_EVENTS = ['created', 'updated', 'deleted'];

    public function test_every_event_the_app_emits_is_in_the_catalogue(): void
    {
        $emitted = array_unique([...self::MODEL_EVENTS, ...$this->scanForLoggedEvents()]);

        sort($emitted);

        $this->assertNotEmpty($emitted, 'the scan found no audit events at all — has the call style changed?');

        $known = AuditEventCatalogue::eventNames();

        foreach ($emitted as $event) {
            $this->assertContains(
                $event,
                $known,
                "AuditLogService is called with '{$event}', which AuditEventCatalogue does not list — ".
                'it will render unlabelled and be invisible to the event_group filter.',
            );
        }
    }

    public function test_the_catalogue_labels_every_event_it_groups(): void
    {
        foreach (AuditEventCatalogue::eventNames() as $event) {
            $this->assertNotNull(
                AuditEventCatalogue::labelOf($event),
                "'{$event}' is grouped but carries no Arabic label (C20).",
            );
            $this->assertNotNull(AuditEventCatalogue::groupOf($event));
        }
    }

    public function test_an_unknown_event_resolves_to_null_rather_than_a_guess(): void
    {
        // A new backend event must LOOK unfamiliar, not look like something
        // else — a wrong label on an audit row is worse than no label.
        $this->assertNull(AuditEventCatalogue::groupOf('invented_event'));
        $this->assertNull(AuditEventCatalogue::labelOf('invented_event'));
        $this->assertNull(AuditEventCatalogue::groupOf(null));
    }

    /**
     * Every `event: 'literal'` named argument under app/ — the form every
     * logAction() call site uses.
     *
     * @return array<int, string>
     */
    private function scanForLoggedEvents(): array
    {
        $events = [];

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(app_path(), RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all(
                "/\bevent:\s*'([a-z0-9_]+)'/",
                (string) file_get_contents($file->getPathname()),
                $matches,
            );

            foreach ($matches[1] as $event) {
                $events[] = $event;
            }
        }

        return $events;
    }
}
