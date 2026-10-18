<?php

namespace App\Providers;

use App\Modules\Approvals\Models\Approval;
use App\Modules\Approvals\Models\ApprovalReassignment;
use App\Modules\Approvals\Models\ApprovalStep;
use App\Modules\Attendance\Contracts\ApprovedLeave;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Support\NoApprovedLeave;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Departments\Models\Department;
use App\Modules\HR\Import\OpenSpoutWorkbookReader;
use App\Modules\HR\Import\WorkbookReader;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeAssignment;
use App\Modules\HR\Models\EmployeeDocument;
use App\Modules\HR\Models\EmployeeWarning;
use App\Modules\HR\Models\JobTitle;
use App\Shared\Push\FcmPushTransport;
use App\Shared\Push\LogPushTransport;
use App\Shared\Push\PushTransport;
use App\Shared\Support\RequestContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Kreait\Firebase\Contract\Messaging;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Push delivery (work order §4.3). FCM when Firebase credentials are
        // configured (FIREBASE_CREDENTIALS — the legacy project's service
        // account, D8); otherwise every push is a log line, and the
        // notification row still reaches the app's list. Decided at resolve
        // time, so a server without credentials never builds a Firebase client.
        $this->app->bind(PushTransport::class, function ($app): PushTransport {
            $project = config('firebase.default', 'app');

            return filled(config("firebase.projects.{$project}.credentials"))
                ? new FcmPushTransport($app->make(Messaging::class))
                : new LogPushTransport;
        });

        // The employee importer reads HR's workbooks through this (§4.4).
        $this->app->bind(WorkbookReader::class, OpenSpoutWorkbookReader::class);

        // Attendance asks "is this person on approved leave today?" through a
        // contract it owns (§4.5). Until the Leave module exists nobody is;
        // the Leave module replaces this binding with its own (§4.7), so the
        // dependency runs Leave → Attendance only.
        $this->app->bind(ApprovedLeave::class, NoApprovedLeave::class);

        // One context per request OR per queued job, shared by everything that
        // needs to know who is calling and under which correlation id (GAP-5).
        //
        // SCOPED, NOT SINGLETON. A queue worker is one long-lived process
        // handling many jobs; between jobs it calls forgetScopedInstances() and
        // nothing else. A singleton therefore SURVIVES that reset, so every job
        // a worker ever ran would share one correlation id — and the audit rows
        // of unrelated jobs would be indistinguishable from each other, which is
        // the precise failure this context was introduced to fix. Verified: the
        // same instance and id came back across forgetScopedInstances().
        //
        // The deliberate answer for non-HTTP work: a job gets its OWN generated
        // request id (not null), so its audit rows group together and can be
        // told apart from another job's. SetRequestContext replaces that id with
        // the real one on an HTTP request.
        $this->app->scoped(RequestContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // MORPH MAP — the public name of every polymorphic type (C12).
        //
        // Without this, getMorphClass() returns the fully-qualified class name,
        // and two places published it: NotificationResource emitted
        // `reference.type: "App\Modules\HR\Models\Employee"` to API clients —
        // which API_DOCUMENTATION.md showed as the contract — and every
        // audit_logs.auditable_type row stored the same. C12 forbids exposing a
        // fully-qualified model name to a client in any environment, and a
        // resource docblock claiming it did "without leaking the internal
        // Eloquent morph map" did not make it so.
        //
        // ENFORCED, not merely mapped: an unmapped model raises
        // ClassMorphViolationException rather than quietly falling back to the
        // class name. A silent fallback is how the leak survived in the first
        // place, and P3 says a forgotten registration must fail loudly.
        //
        // ⚠️ ADD EVERY NEW MORPHABLE OR AUDITED MODEL HERE, in the same commit.
        // MorphMapTest fails if a model using HasAuditLog is missing.
        //
        // These aliases are now a PUBLIC contract (clients deep-link on
        // `reference.type`) and a stored value. Renaming one is a data
        // migration, not an edit. They are stable strings deliberately
        // decoupled from namespaces, so moving a class between modules — which
        // the legacy merge will do — no longer rewrites history.
        Relation::enforceMorphMap([
            'employee'            => Employee::class,
            'employee_assignment' => EmployeeAssignment::class,
            'employee_document'   => EmployeeDocument::class,
            'employee_warning'    => EmployeeWarning::class,
            'job_title'           => JobTitle::class,
            'department'          => Department::class,
            'user'                => User::class,
            'role'                => Role::class,
            'permission'          => Permission::class,
            'attendance_record'   => AttendanceRecord::class,
            'approval'              => Approval::class,
            'approval_step'         => ApprovalStep::class,
            'approval_reassignment' => ApprovalReassignment::class,
        ]);

        // Super-admin bypass: a super admin passes EVERY Gate/policy check
        // without holding the matching permission. Returning null (not false)
        // for everyone else lets the normal permission checks decide — false
        // here would short-circuit and deny. This is the architecture's mandated
        // alternative to syncing every permission onto one role: new permissions
        // are instantly available to super admins with no re-seed. The
        // CheckPermission middleware honours the same rule via hasPermission().
        Gate::before(fn (User $user) => $user->isSuperAdmin() ? true : null);

        // The baseline limit for every API route ($middleware->throttleApi() in
        // bootstrap/app.php applies it). Keyed on the authenticated user where
        // there is one, so a shared office IP does not throttle a whole floor,
        // and on the IP otherwise.
        //
        // The guard is named EXPLICITLY. The default guard is `web`, and this
        // limiter now runs BEFORE auth:sanctum (see the priority list in
        // bootstrap/app.php), so a bare $request->user() would resolve nothing
        // and silently key every authenticated caller by IP instead — putting a
        // whole office behind one 60/min budget. Naming the guard makes the key
        // independent of middleware ordering.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by(
                $request->user('sanctum')?->getAuthIdentifier() ?: $request->ip(),
            );
        });

        // Brute-force / credential-stuffing protection on the auth endpoints.
        //
        // Keyed on IP **and** email, not IP alone. Keying on IP alone means one
        // attacker behind a NAT can lock out every legitimate user sharing that
        // address — the throttle becomes the denial of service. Pairing the two
        // limits attempts per account per origin, which is the thing actually
        // worth rationing. The email is lower-cased so casing variants do not
        // buy an attacker five more attempts each.
        RateLimiter::for('auth-login', function (Request $request) {
            // ⚙️ KEYED ON `login`, NOT `email` (account model, 2026-09-22). The
            // field was renamed when the employee number became the identifier,
            // and a limiter still reading `email` would have keyed every attempt
            // on the same empty string — collapsing all logins into one bucket
            // and locking the whole company out after five failures anywhere.
            // The protection would have looked present and behaved as an
            // outage, which is the same failure this key was widened to avoid
            // when it was IP-only.
            $identifier = Str::lower(trim((string) $request->input('login')));

            return Limit::perMinute(5)->by($request->ip().'|'.$identifier);
        });

        RateLimiter::for('auth-refresh', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });
    }
}
