<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Modules\Auth\Models\User;
use App\Shared\Traits\ApiRespond;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * `paginated()`'s `$extra` (2026-10-18): a list that carries facts about the
 * whole result — the year it is for, a register's window and summary — puts
 * them beside `meta`, still inside the one envelope (C16). The envelope's own
 * keys cannot be overwritten through it.
 */
class PaginatedExtraTest extends TestCase
{
    use ApiRespond;
    use RefreshDatabase;

    private function page(): AnonymousResourceCollection
    {
        User::factory()->count(2)->create();

        return JsonResource::collection(User::query()->paginate(1));
    }

    public function test_extra_keys_sit_beside_meta(): void
    {
        Route::get('/api/_test/paginated', fn () => $this->paginated($this->page(), extra: ['from' => '2026-06-01', 'summary' => ['days' => 3]]));

        $this->getJson('/api/_test/paginated')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('from', '2026-06-01')
            ->assertJsonPath('summary.days', 3)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonCount(1, 'data');
    }

    public function test_the_envelopes_own_keys_cannot_be_replaced(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->paginated($this->page(), extra: ['meta' => 1]);
    }
}
