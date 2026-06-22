<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

/**
 * ModuleServiceProvider
 *
 * Auto-discovers and registers all ERP modules from app/Modules/.
 * Each module must have a routes.php file to be loaded.
 *
 * WHY this design:
 * - Zero-config module registration: drop a folder in Modules/ and it works.
 * - Each module owns its routes, keeping concerns isolated.
 * - Future modules (HR, Finance, Inventory) follow the exact same pattern.
 * - Migrations are organized per-module under database/migrations/{Module}/.
 *
 * HOW to add a new module:
 * 1. Create app/Modules/{ModuleName}/ with Controllers/, Services/, etc.
 * 2. Add a routes.php inside the module folder.
 * 3. Add database/migrations/{ModuleName}/ for module-specific migrations.
 * 4. That's it — this provider handles the rest.
 */
class ModuleServiceProvider extends ServiceProvider
{
    /**
     * Register module-level bindings.
     *
     * This is where you'd bind module-specific interfaces to implementations
     * if needed. For now, modules use concrete service classes directly
     * via dependency injection, which is simpler and sufficient.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap all discovered modules.
     */
    public function boot(): void
    {
        $this->loadModuleRoutes();
        $this->loadModuleMigrations();
    }

    /**
     * Auto-discover and load routes.php from each module directory.
     *
     * Routes are loaded with:
     * - 'api' middleware group (throttle, json responses)
     * - Prefix: /api/{module} (lowercase)
     * - e.g., Auth module → /api/auth/login
     *
     * WHY prefix per module:
     * - Clean URL namespacing prevents route collisions.
     * - Each module can define its own route names without conflicts.
     * - Makes API versioning per-module trivial in the future.
     */
    private function loadModuleRoutes(): void
    {
        $modulesPath = app_path('Modules');

        if (! File::isDirectory($modulesPath)) {
            return;
        }

        $modules = File::directories($modulesPath);

        foreach ($modules as $modulePath) {
            $routesFile = $modulePath . '/routes.php';

            if (File::exists($routesFile)) {
                $moduleName = strtolower(basename($modulePath));

                Route::prefix("api/{$moduleName}")
                    ->middleware('api')
                    ->group($routesFile);
            }
        }
    }

    /**
     * Load migrations from per-module directories.
     *
     * Scans database/migrations/ for subdirectories matching module names.
     * This keeps migration files organized by domain while still using
     * Laravel's standard migration runner (`php artisan migrate`).
     *
     * WHY per-module migration directories:
     * - 50+ migrations in a flat folder becomes unmanageable in an ERP.
     * - Developers can reason about a module's schema in isolation.
     * - Module removal is cleaner (drop the directory).
     */
    private function loadModuleMigrations(): void
    {
        $migrationsPath = database_path('migrations');

        if (! File::isDirectory($migrationsPath)) {
            return;
        }

        $directories = File::directories($migrationsPath);

        foreach ($directories as $directory) {
            $this->loadMigrationsFrom($directory);
        }
    }
}