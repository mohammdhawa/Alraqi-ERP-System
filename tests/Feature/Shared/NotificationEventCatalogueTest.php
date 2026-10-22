<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Modules\Auth\Models\Notification;
use App\Modules\Auth\Models\User;
use App\Shared\Support\NotificationEventCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The notification `type` vocabulary (work order §4.3, mapping §8,
 * EMPLOYEE_APP_REQUIREMENTS.md §5.7) — the AuditEventCatalogue pattern: a lens
 * the reader applies, never a constraint on the writer.
 */
class NotificationEventCatalogueTest extends TestCase
{
    use RefreshDatabase;

    /** The v1 events, as mapping §8 names them. */
    private const V1 = [
        'leave.submitted',
        'approval.step_pending',
        'leave.approved',
        'leave.rejected',
        'leave.cancelled',
        'leave.excuse_filed',
        'leave.excuse_cancelled',
        'attendance.checkout_reminder',
        'attendance.missing_checkout',
        'attendance.record_rejected',
        'attendance.record_unrejected',
    ];

    public function test_every_v1_event_is_catalogued_with_an_arabic_label(): void
    {
        foreach (self::V1 as $event) {
            $this->assertContains($event, NotificationEventCatalogue::eventNames());
            $this->assertNotNull(NotificationEventCatalogue::labelOf($event), "'{$event}' has no label (C20).");
        }
    }

    public function test_an_unknown_type_resolves_to_null_rather_than_a_guess(): void
    {
        $this->assertNull(NotificationEventCatalogue::labelOf('invented.event'));
        $this->assertNull(NotificationEventCatalogue::labelOf(null));
    }

    public function test_every_type_the_app_sends_is_in_the_catalogue(): void
    {
        $sent = $this->typesSentUnderApp();

        // Nothing sends yet — leave and attendance arrive later in v1. The
        // scan must still run, so it is not a test that cannot fail.
        $this->assertIsArray($sent);

        foreach ($sent as $type) {
            $this->assertContains($type, NotificationEventCatalogue::eventNames(),
                "'{$type}' is sent but not catalogued — add it to NotificationEventCatalogue.");
        }
    }

    public function test_a_notification_carries_its_types_label(): void
    {
        $me = User::factory()->create();
        Notification::create(['user_id' => $me->id, 'title' => 'إجازة', 'type' => 'leave.approved']);
        Notification::create(['user_id' => $me->id, 'title' => 'قديم', 'type' => 'something_older']);
        Sanctum::actingAs($me);

        $labels = collect($this->getJson('/api/auth/notifications')->assertOk()->json('data'))
            ->pluck('type_label', 'type');

        $this->assertSame(NotificationEventCatalogue::labelOf('leave.approved'), $labels['leave.approved']);
        $this->assertNull($labels['something_older'], 'an unknown type renders raw, not mislabelled');
    }

    /**
     * Every `type:` named argument of a send call under app/, as a literal or
     * as a catalogue constant.
     *
     * @return array<int, string>
     */
    private function typesSentUnderApp(): array
    {
        $types = [];

        foreach (File::allFiles(app_path()) as $file) {
            $source = $file->getContents();

            preg_match_all('/sendTo\w+\([^;]*?type:\s*\'([^\']+)\'/s', $source, $literal);
            preg_match_all('/sendTo\w+\([^;]*?type:\s*NotificationEventCatalogue::(\w+)/s', $source, $constant);

            array_push($types, ...$literal[1]);
            foreach ($constant[1] as $name) {
                $types[] = constant(NotificationEventCatalogue::class.'::'.$name);
            }
        }

        return array_values(array_unique($types));
    }
}
