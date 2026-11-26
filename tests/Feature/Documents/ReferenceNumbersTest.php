<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Modules\Documents\Services\ReferenceNumbers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A template's reference, PREFIX-YEAR-SEQ (documents design §8.4).
 */
class ReferenceNumbersTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function claim(string $prefix): string
    {
        return DB::transaction(fn () => app(ReferenceNumbers::class)->claim($prefix));
    }

    public function test_the_format_and_the_count_per_prefix(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 10:00', 'Asia/Damascus'));

        $this->assertSame('RE-2026-0001', $this->claim('RE'));
        $this->assertSame('RE-2026-0002', $this->claim('RE'));
        $this->assertSame('PR-2026-0001', $this->claim('PR'));
    }

    public function test_the_year_turns_in_damascus_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-31 23:59', 'Asia/Damascus'));
        $this->assertSame('RE-2026-0001', $this->claim('RE'));
        $this->assertSame('RE-2026-0002', $this->claim('RE'));

        Carbon::setTestNow(Carbon::parse('2027-01-01 00:01', 'Asia/Damascus'));
        $this->assertSame('RE-2027-0001', $this->claim('RE'));
    }
}
