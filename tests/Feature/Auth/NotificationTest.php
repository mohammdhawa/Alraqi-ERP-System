<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Modules\Auth\Models\Notification;
use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Notifications coverage (Package E).
 *
 * The endpoints are not permission-guarded — authorization is ownership, so a
 * plain authenticated user manages only their own notifications. These tests
 * verify listing/counting/marking, and that one user cannot touch another's
 * notification (404, not a cross-user update).
 */
class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private function makeNotification(User $user, array $overrides = []): Notification
    {
        return Notification::create(array_merge([
            'user_id' => $user->id,
            'title'   => 'Welcome',
            'body'    => 'Your account is ready.',
            'type'    => 'system',
            'is_read' => false,
        ], $overrides));
    }

    public function test_index_returns_only_my_notifications(): void
    {
        $me    = User::factory()->create();
        $other = User::factory()->create();
        $this->makeNotification($me, ['title' => 'Mine']);
        $this->makeNotification($other, ['title' => 'Theirs']);

        Sanctum::actingAs($me);

        $this->getJson('/api/auth/notifications')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Mine');
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/auth/notifications')->assertUnauthorized();
    }

    public function test_unread_count_counts_only_unread(): void
    {
        $me = User::factory()->create();
        $this->makeNotification($me, ['is_read' => false]);
        $this->makeNotification($me, ['is_read' => false]);
        $this->makeNotification($me, ['is_read' => true]);

        Sanctum::actingAs($me);

        $this->getJson('/api/auth/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 2);
    }

    public function test_mark_read_marks_my_notification(): void
    {
        $me = User::factory()->create();
        $notification = $this->makeNotification($me);

        Sanctum::actingAs($me);

        $this->postJson("/api/auth/notifications/{$notification->id}/read")
            ->assertOk()
            ->assertJsonPath('data.is_read', true);

        $this->assertDatabaseHas('notifications', [
            'id'      => $notification->id,
            'is_read' => true,
        ]);
    }

    /**
     * C13: every state-changing endpoint is audited.
     *
     * The notifications group was excluded from the `audit` middleware wholesale,
     * because its two READ endpoints are polled constantly and would flood the
     * trail. That exclusion silently swept up this route, which is a write — and
     * the Notification model carries no HasAuditLog either (deliberately, to keep
     * the bulk fan-out insert event-free), so marking a notification read left no
     * trace anywhere at all. The exclusion is now per-route.
     */
    public function test_marking_a_notification_read_is_audited(): void
    {
        $user = $this->actingAsRolelessUser();
        $notification = $user->notifications()->create(['title' => 'عنوان']);

        $this->postJson("/api/auth/notifications/{$notification->id}/read")->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'api_request',
        ]);
        $this->assertStringContainsString(
            'notifications',
            (string) \DB::table('audit_logs')->where('event', 'api_request')->value('description'),
        );
    }

    public function test_polled_read_endpoints_stay_unaudited(): void
    {
        $this->actingAsRolelessUser();

        $this->getJson('/api/auth/notifications')->assertOk();
        $this->getJson('/api/auth/notifications/unread-count')->assertOk();

        // Still deliberately excluded — clients poll these and the trail would
        // carry nothing but noise.
        $this->assertDatabaseMissing('audit_logs', ['event' => 'api_request']);
    }

    public function test_cannot_mark_another_users_notification(): void
    {
        $me    = User::factory()->create();
        $other = User::factory()->create();
        $theirs = $this->makeNotification($other);

        Sanctum::actingAs($me);

        // Scoped lookup -> 404, and the notification stays unread.
        $this->postJson("/api/auth/notifications/{$theirs->id}/read")
            ->assertNotFound();

        $this->assertDatabaseHas('notifications', [
            'id'      => $theirs->id,
            'is_read' => false,
        ]);
    }
}
