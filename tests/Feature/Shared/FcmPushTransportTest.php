<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Shared\Push\FcmPushTransport;
use App\Shared\Push\LogPushTransport;
use App\Shared\Push\PushMessage;
use App\Shared\Push\PushTransport;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\InvalidArgument;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Exception\Messaging\ServerUnavailable;
use Kreait\Firebase\Messaging\Message;
use Kreait\Firebase\Messaging\MessageTarget;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Kreait\Firebase\Messaging\SendReport;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * The FCM transport (work order §4.3, D8) — through kreait/firebase-php, as
 * the legacy backend does.
 *
 * Firebase itself is the one thing mocked here: the Messaging contract is the
 * boundary with Google, and what is under test is what this class sends and
 * how it reads the answer.
 */
class FcmPushTransportTest extends TestCase
{
    /** @var array<int, array{message: Message, tokens: array<int, string>}> */
    private array $calls = [];

    /**
     * @param  callable(string): ?\Throwable  $outcome  null = delivered
     */
    private function transport(callable $outcome): FcmPushTransport
    {
        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldReceive('sendMulticast')->andReturnUsing(function (Message $message, array $tokens) use ($outcome) {
            $this->calls[] = ['message' => $message, 'tokens' => $tokens];

            return MulticastSendReport::withItems(array_map(function (string $token) use ($outcome): SendReport {
                $target = MessageTarget::with(MessageTarget::TOKEN, $token);
                $error = $outcome($token);

                return $error === null ? SendReport::success($target, ['name' => 'ok']) : SendReport::failure($target, $error);
            }, $tokens));
        });

        return new FcmPushTransport($messaging);
    }

    private function message(): PushMessage
    {
        return new PushMessage('تمت الموافقة على الإجازة', 'من 12 إلى 14 تشرين الأول', [
            'type' => 'leave.approved', 'reference_type' => 'leave_request', 'reference_id' => '42',
        ]);
    }

    public function test_it_sends_the_title_body_and_data_to_every_token(): void
    {
        $this->transport(fn () => null)->send(['phone', 'tablet'], $this->message());

        $this->assertSame(['phone', 'tablet'], $this->calls[0]['tokens']);
        $sent = $this->calls[0]['message']->jsonSerialize();
        $this->assertSame('تمت الموافقة على الإجازة', $sent['notification']['title']);
        $this->assertSame('من 12 إلى 14 تشرين الأول', $sent['notification']['body']);
        $this->assertSame('leave.approved', $sent['data']['type']);
        $this->assertSame('42', $sent['data']['reference_id']);
        // The legacy Flutter handler routes a tap on this key; kept so the
        // re-pointed app needs no change to open a notification.
        $this->assertSame('FLUTTER_NOTIFICATION_CLICK', $sent['data']['click_action']);
    }

    public function test_unknown_and_invalid_tokens_are_reported_dead(): void
    {
        $dead = $this->transport(fn (string $token) => match ($token) {
            'uninstalled' => new NotFound('Requested entity was not found.'),
            'garbage'     => new InvalidArgument('The registration token is not a valid FCM registration token'),
            default       => null,
        })->send(['phone', 'uninstalled', 'garbage'], $this->message());

        $this->assertEqualsCanonicalizing(['uninstalled', 'garbage'], $dead);
    }

    public function test_a_send_that_reached_no_device_is_retried(): void
    {
        $transport = $this->transport(fn () => new ServerUnavailable('The service is currently unavailable.'));

        // Thrown, so the queue's backoff retries the job — nothing was
        // delivered, so a retry duplicates nothing.
        $this->expectException(RuntimeException::class);

        $transport->send(['phone', 'tablet'], $this->message());
    }

    public function test_a_partial_outage_is_not_retried(): void
    {
        $dead = $this->transport(fn (string $token) => $token === 'tablet' ? new ServerUnavailable('unavailable') : null)
            ->send(['phone', 'tablet'], $this->message());

        // The phone already has it; retrying the job would push it twice.
        $this->assertSame([], $dead);
    }

    public function test_a_message_without_a_body_is_sent_with_a_title_only(): void
    {
        $this->transport(fn () => null)->send(['phone'], new PushMessage('إعلان'));

        $this->assertSame('إعلان', $this->calls[0]['message']->jsonSerialize()['notification']['title']);
    }

    // ── Which transport is bound ─────────────────────────────────────────

    public function test_without_firebase_credentials_pushes_are_logged(): void
    {
        config(['firebase.projects.app.credentials' => null]);

        $this->assertInstanceOf(LogPushTransport::class, app(PushTransport::class));
    }

    public function test_with_firebase_credentials_pushes_go_to_fcm(): void
    {
        config(['firebase.projects.app.credentials' => '/srv/secrets/firebase.json']);
        $this->app->instance(Messaging::class, Mockery::mock(Messaging::class));

        $this->assertInstanceOf(FcmPushTransport::class, app(PushTransport::class));
    }
}
