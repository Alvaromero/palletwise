<?php

declare(strict_types=1);

namespace Palletwise\Tests\Unit\Layer;

use Palletwise\Layer\MaxRectsPacker;
use PHPUnit\Framework\TestCase;

final class MaxRectsPackerTest extends TestCase
{
    public function testPacksExactly(): void
    {
        $packer = new MaxRectsPacker(48, 40);
        $placed = [];
        for ($i = 0; $i < 4; ++$i) {
            $position = $packer->find(24, 20, false);
            self::assertNotNull($position);
            [$x, $y] = $position;
            $packer->place($x, $y, 24, 20);
            $placed[] = [$x, $y];
        }
        sort($placed);
        self::assertSame([[0, 0], [0, 20], [24, 0], [24, 20]], $placed);
        self::assertSame(0, $packer->freeArea());
        self::assertNull($packer->find(1, 1, true));
    }

    public function testTurnsWhenNeeded(): void
    {
        $packer = new MaxRectsPacker(48, 40);
        $packer->place(0, 0, 38, 40);
        self::assertSame([38, 0, true], $packer->find(40, 10, true));
        self::assertNull($packer->find(40, 10, false));
    }

    public function testBestShortSideFitAndTieBreak(): void
    {
        $packer = new MaxRectsPacker(48, 40);
        $packer->place(0, 0, 30, 40);
        // Free: x 30..48 (18 wide). An 18 × 10 box fits the width exactly.
        self::assertSame([30, 0, false], $packer->find(18, 10, true));
    }

    public function testAcceptCallbackRestrictsPositionsAndTriesCorners(): void
    {
        $packer = new MaxRectsPacker(48, 40);
        // Only the top-right corner is accepted.
        $accept = static fn(int $x, int $y, int $w, int $h): bool => $x + $w === 48 && $y + $h === 40;
        self::assertSame([38, 30, false], $packer->find(10, 10, false, $accept));
        self::assertNull($packer->find(10, 10, false, static fn(): bool => false));
    }
}
