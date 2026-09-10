<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Shared\Support\RequestContext;
use Tests\TestCase;

/**
 * RequestContext must be SCOPED, not a singleton.
 *
 * WHY A TEST AND NOT JUST THE BINDING: the difference between `scoped()` and
 * `singleton()` is invisible under HTTP — both hand back one instance per
 * request — so the whole suite stayed green with the wrong one. It only shows up
 * in a long-lived worker: a queue worker calls forgetScopedInstances() between
 * jobs and nothing else, so a singleton SURVIVES the reset and every job that
 * worker ever runs shares one correlation id. The audit rows of unrelated jobs
 * would then be indistinguishable, which is the exact confusion this context was
 * introduced to remove.
 *
 * These tests simulate that reset directly, so reverting the binding to
 * singleton() fails here instead of silently in production months later.
 */
class RequestContextLifecycleTest extends TestCase
{
    public function test_the_context_is_rebuilt_between_queue_jobs(): void
    {
        $first   = app(RequestContext::class);
        $firstId = $first->requestId();

        // Exactly what Illuminate\Queue\Worker does between jobs.
        $this->app->forgetScopedInstances();

        $second = app(RequestContext::class);

        $this->assertNotSame(
            $first,
            $second,
            'RequestContext survived forgetScopedInstances() — it is bound as a singleton, '
                .'so every job in a worker process would share one correlation id',
        );
        $this->assertNotSame(
            $firstId,
            $second->requestId(),
            'a second job reused the first job\'s correlation id',
        );
    }

    public function test_the_same_instance_is_reused_within_one_scope(): void
    {
        // Scoped must still mean ONE per request — an audit row written by a
        // model hook and one written by a service must agree on the id.
        $this->assertSame(app(RequestContext::class), app(RequestContext::class));
    }

    public function test_work_outside_http_gets_its_own_correlation_id_not_null(): void
    {
        // The deliberate decision recorded in AppServiceProvider: a console
        // command or a queued job gets a generated id, so its audit rows group
        // together and can be told apart from another job's. Null would make
        // every non-HTTP row anonymous and mutually indistinguishable.
        $context = app(RequestContext::class);

        $this->assertNotSame('', $context->requestId());
        $this->assertNull($context->ip(), 'there is no client ip outside HTTP');
        $this->assertNull($context->userAgent(), 'there is no user agent outside HTTP');
        $this->assertNull($context->userId(), 'there is no authenticated user outside HTTP');
    }
}
