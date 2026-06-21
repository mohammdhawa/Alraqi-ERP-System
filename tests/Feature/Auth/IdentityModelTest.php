<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Modules\Auth\Models\User;
use Tests\TestCase;

/**
 * Identity-model wiring coverage.
 *
 * Guards against the User-model duplication regressing: the Auth module's User
 * must be the single identity model, and the legacy App\Models\User must stay
 * gone so factories, seeders, and the auth guard cannot drift back to it.
 */
class IdentityModelTest extends TestCase
{
    public function test_auth_provider_is_bound_to_the_module_user_model(): void
    {
        $this->assertSame(
            User::class,
            config('auth.providers.users.model'),
        );
    }

    public function test_legacy_app_models_user_no_longer_exists(): void
    {
        $this->assertFalse(
            class_exists(\App\Models\User::class),
            'The legacy App\\Models\\User must not exist; App\\Modules\\Auth\\Models\\User is the single identity model.',
        );
    }

    public function test_user_factory_builds_the_module_user(): void
    {
        $this->assertInstanceOf(User::class, User::factory()->make());
    }
}
