<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Shared\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The morph map is enforced, and it is complete.
 *
 * WHY THIS MATTERS BEYOND TIDINESS: `getMorphClass()` feeds two things — the
 * `reference.type` a client reads, and every `audit_logs.auditable_type` row.
 * Before the map was enforced both carried the fully-qualified class name, so a
 * C12 violation was published in `API_DOCUMENTATION.md` as the contract and
 * written into an append-only table that cannot be rewritten afterwards.
 *
 * With `enforceMorphMap`, an UNMAPPED model throws instead of falling back to
 * the class name — but `HasAuditLog` catches and reports its own failures, so an
 * unmapped audited model would lose audit rows *quietly*. That is exactly the
 * silent-degradation this test exists to prevent: it fails at build time
 * instead, when adding the model is one line.
 */
class MorphMapTest extends TestCase
{
    public function test_the_morph_map_is_enforced(): void
    {
        $this->assertTrue(
            Relation::requiresMorphMap(),
            'the morph map is not enforced, so an unmapped model silently leaks its class name',
        );
    }

    public function test_aliases_are_stable_public_names_not_class_names(): void
    {
        foreach (Relation::morphMap() as $alias => $class) {
            $this->assertStringNotContainsString('\\', $alias, "alias '{$alias}' looks like a class name");
            $this->assertSame(strtolower($alias), $alias, "alias '{$alias}' should be lower-case");
            $this->assertTrue(class_exists($class), "alias '{$alias}' maps to a missing class");
        }
    }

    /**
     * Every model that can appear in a morph column must be registered.
     *
     * Scans the module tree rather than taking a hard-coded list, so a model
     * added in a future module is covered without anyone remembering to extend
     * this test.
     */
    public function test_every_audited_model_is_registered(): void
    {
        $mapped = array_values(Relation::morphMap());
        $missing = [];
        $audited = 0;

        foreach (File::allFiles(app_path('Modules')) as $file) {
            if ($file->getExtension() !== 'php' || ! str_contains($file->getPath(), 'Models')) {
                continue;
            }

            $class = 'App\\Modules\\'.str_replace(
                ['/', '.php'],
                ['\\', ''],
                ltrim(str_replace(app_path('Modules'), '', $file->getPathname()), '\\/'),
            );
            $class = str_replace('/', '\\', $class);

            if (! class_exists($class)) {
                continue;
            }

            if (! in_array(HasAuditLog::class, class_uses_recursive($class), true)) {
                continue;
            }

            $audited++;

            if (! in_array($class, $mapped, true)) {
                $missing[] = $class;
            }
        }

        // Without this the test would pass vacuously if the scan ever stopped
        // resolving classes — a green test that checks nothing being strictly
        // worse than no test.
        $this->assertGreaterThanOrEqual(
            5,
            $audited,
            'the scan found only '.$audited.' audited models; it is not looking where it thinks it is',
        );

        $this->assertSame(
            [],
            $missing,
            "these audited models are not in the morph map, so their audit rows would be lost:\n  "
                .implode("\n  ", $missing)
                ."\nAdd them to Relation::enforceMorphMap() in AppServiceProvider.",
        );
    }
}
