<?php

declare(strict_types=1);

namespace Tests\Unit\Documents;

use App\Modules\Documents\Support\SystemValues;
use Carbon\CarbonImmutable;
use LogicException;
use Tests\TestCase;

/**
 * A creator's values numbered once, at print (fix round 1 of documents to
 * employees, Task 2): the claimed numbers and the print's moment, every other
 * value kept.
 */
class SystemValuesTest extends TestCase
{
    public function test_unnumbered_values_are_numbered_and_dated_once(): void
    {
        $creator = SystemValues::unnumbered('محمد الأحمد', 'إدارة الموارد البشرية', 12);

        $this->assertNull($creator->exportNumber);
        $this->assertNull($creator->reference);
        $this->assertSame(12, $creator->importNumber);

        $printedAt = CarbonImmutable::parse('2026-11-27 23:59:30', 'Asia/Damascus');
        $numbered = $creator->numbered(186, 'EM-2026-0001', $printedAt);

        $this->assertSame(186, $numbered->exportNumber);
        $this->assertSame('EM-2026-0001', $numbered->reference);
        $this->assertSame(12, $numbered->importNumber);
        $this->assertSame('محمد الأحمد', $numbered->creatorName);
        $this->assertSame('إدارة الموارد البشرية', $numbered->creatorUnit);
        $this->assertTrue($numbered->date->equalTo($printedAt));
        $this->assertSame('2026/11/27', $numbered->toArray()['system.date']);

        $this->expectException(LogicException::class);
        $numbered->numbered(187, 'EM-2026-0002', $printedAt);
    }
}
