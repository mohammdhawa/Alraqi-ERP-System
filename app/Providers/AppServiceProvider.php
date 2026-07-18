<?php

namespace App\Providers;

use App\Modules\Auth\Models\User;
use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Super-admin bypass: a super admin passes EVERY Gate/policy check
        // without holding the matching permission. Returning null (not false)
        // for everyone else lets the normal permission checks decide — false
        // here would short-circuit and deny. This is the architecture's mandated
        // alternative to syncing every permission onto one role: new permissions
        // are instantly available to super admins with no re-seed. The
        // CheckPermission middleware honours the same rule via hasPermission().
        Gate::before(fn (User $user) => $user->isSuperAdmin() ? true : null);

        // Brute-force / credential-stuffing protection on the auth endpoints.
        RateLimiter::for('auth-login', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        RateLimiter::for('auth-refresh', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });
    }
}
