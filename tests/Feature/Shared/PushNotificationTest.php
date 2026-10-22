<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Modules\Auth\Enums\ScopeType;
use App\Modules\Auth\Models\DeviceToken;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Services\NotificationService;
use App\Shared\Push\PushMessage;
use App\Shared\Push\PushTransport;
use App\Shared\Support\NotificationEventCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Push delivery — every notification row also goes to the recipient's devices
 * (work order §4.3, mapping §8: "A listener on notification creation pushes
 * to the recipient's device_tokens").
 *
 * The transport is replaced by a recorder: what is under test is WHO gets
 * pushed WHAT and WHEN, not Firebase.
 */
class PushNotificationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{tokens: array<int, string>, message: PushMessage}> */
    private array $sent = [];

    /** @var array<int, string> tokens the fake transport reports as dead */
    private array $dead = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(PushTransport::class, new class($this->sent, $this->dead) implements PushTransport
        {
            public function __construct(private array &$sent, private array &$dead) {}

            public function send(array $tokens, PushMessage $message): array
            {
                $this->sent[] = ['tokens' => $tokens, 'message' => $message];

                return array_values(array_intersect($tokens, $this->dead));
            }
        });
    }

    private function userWithDevices(string ...$tokens): User
    {
        $user = User::factory()->create();

        foreach ($tokens as $token) {
            DeviceToken::create(['user_id' => $user->id, 'token' => $token, 'platform' => 'android', 'last_seen_at' => now()]);
        }

        return $user;
    }

    private function notify(User $user): void
    {
        app(NotificationService::class)->sendToUser(
            $user,
            title: 'تمت الموافقة على الإجازة',
            body: 'إجازتك من 12 إلى 14 تشرين الأول',
            type: NotificationEventCatalogue::LEAVE_APPROVED,
        );
    }

    public function test_a_notification_is_pushed_to_every_device_of_its_recipient(): void
    {
        $user = $this->userWithDevices('phone-token', 'tablet-token');

        $this->notify($user);

        $this->assertCount(1, $this->sent);
        $this->assertEqualsCanonicalizing(['phone-token', 'tablet-token'], $this->sent[0]['tokens']);
        $this->assertSame('تمت الموافقة على الإجازة', $this->sent[0]['message']->title);
        $this->assertSame('leave.approved', $this->sent[0]['message']->data['type']);
    }

    public function test_nobody_elses_device_is_pushed(): void
    {
        $user = $this->userWithDevices('phone-token');
        $this->userWithDevices('someone-elses-token');

        $this->notify($user);

        $this->assertSame(['phone-token'], $this->sent[0]['tokens']);
    }

    public function test_a_recipient_with_no_device_gets_the_row_and_no_push(): void
    {
        $user = $this->userWithDevices();

        $this->notify($user);

        $this->assertSame(1, $user->notifications()->count());
        $this->assertSame([], $this->sent);
    }

    public function test_a_rolled_back_action_pushes_nothing(): void
    {
        $user = $this->userWithDevices('phone-token');

        try {
            DB::transaction(function () use ($user): void {
                $this->notify($user);

                throw new RuntimeException('the approval failed after notifying');
            });
        } catch (RuntimeException) {
        }

        // The row rolled back; the phone must not show a notification for an
        // approval that never happened.
        $this->assertSame([], $this->sent);
    }

    public function test_a_token_firebase_reports_dead_is_removed(): void
    {
        $this->dead = ['uninstalled-token'];
        $user = $this->userWithDevices('phone-token', 'uninstalled-token');

        $this->notify($user);

        $this->assertSame(['phone-token'], $user->deviceTokens()->pluck('token')->all());
    }

    public function test_a_fan_out_pushes_each_recipients_devices(): void
    {
        $first = $this->userWithDevices('first-token');
        $second = $this->userWithDevices('second-token');

        app(NotificationService::class)->sendToRole(
            $this->roleHeldBy($first, $second),
            title: 'إعلان',
        );

        $this->assertEqualsCanonicalizing(['first-token', 'second-token'], collect($this->sent)->flatMap(fn ($s) => $s['tokens'])->all());
    }

    private function roleHeldBy(User ...$users): Role
    {
        $role = Role::create(['name' => 'push_test', 'label' => 'اختبار']);

        foreach ($users as $user) {
            $user->roles()->attach($role->id, ['scope_type' => ScopeType::Company->value]);
        }

        return $role;
    }
}
