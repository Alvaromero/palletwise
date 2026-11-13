<?php

declare(strict_types=1);

namespace Palletwise\Tests\Unit\Layer;

use Palletwise\Layer\HomogeneousPatternFinder;
use Palletwise\Layer\Pattern;
use Palletwise\Layer\Rect;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HomogeneousPatternFinderTest extends TestCase
{
    private const L = 48_000;
    private const W = 40_000;

    /**
     * @return iterable<string, array{int, int, bool, int, string}>
     */
    public static function cases(): iterable
    {
        yield '12x10 grid' => [12_000, 10_000, true, 16, 'grid'];
        yield '16x12 two blocks reach the area bound' => [16_000, 12_000, true, 10, 'two-block'];
        yield '9x7 four blocks' => [9_000, 7_000, true, 29, 'four-block'];
        yield '14x10 four blocks' => [14_000, 10_000, true, 13, 'four-block'];
        yield '16x12 fixed cannot turn' => [16_000, 12_000, false, 9, 'grid'];
        yield 'exact fit' => [24_000, 20_000, true, 4, 'grid'];
        // 24.001 + 20 ≤ 48: one turned column next to two unturned ones.
        yield 'one thousandth too big' => [24_001, 20_000, true, 3, 'two-block'];
        yield 'one thousandth too big, fixed' => [24_001, 20_000, false, 2, 'grid'];
        yield 'full base' => [48_000, 40_000, true, 1, 'grid'];
        yield 'turned only' => [40_000, 48_000, true, 1, 'grid'];
        yield 'does not fit' => [49_000, 10_000, false, 0, 'grid'];
    }

    #[DataProvider('cases')]
    public function testFindsBestCount(int $a, int $b, bool $canTurn, int $count, string $kind): void
    {
        $pattern = (new HomogeneousPatternFinder())->find(self::L, self::W, $a, $b, $canTurn);

        self::assertSame($count, $pattern->count());
        self::assertSame($kind, $pattern->kind);
        self::assertValidPattern($pattern, $a, $b, $canTurn);
    }

    public function testCenteringAndOrdering(): void
    {
        $pattern = (new HomogeneousPatternFinder())->find(self::L, self::W, 15_000, 15_000, true)->centered(self::L, self::W);

        self::assertSame([1_500, 5_000, 46_500, 35_000], $pattern->bounds());
        self::assertSame([[1_500, 5_000], [16_500, 5_000], [31_500, 5_000], [1_500, 20_000]], array_map(
            static fn(Rect $r): array => [$r->x, $r->y],
            \array_slice($pattern->rects, 0, 4),
        ));
        $center = $pattern->rectsByCenterDistance(self::L, self::W);
        self::assertSame([16_500, 5_000], [$center[0]->x, $center[0]->y]);
        self::assertValidPattern($pattern, 15_000, 15_000, true);
    }

    public function testManySmallBoxes(): void
    {
        $pattern = (new HomogeneousPatternFinder())->find(self::L, self::W, 1_000, 1_000, true);
        self::assertSame(1_920, $pattern->count());
    }

    private static function assertValidPattern(Pattern $pattern, int $a, int $b, bool $canTurn): void
    {
        $rects = $pattern->rects;
        foreach ($rects as $i => $r) {
            self::assertTrue($r->x >= 0 && $r->y >= 0 && $r->x + $r->dx <= self::L && $r->y + $r->dy <= self::W, 'inside pallet');
            self::assertSame($r->turned ? [$b, $a] : [$a, $b], [$r->dx, $r->dy]);
            if (!$canTurn) {
                self::assertFalse($r->turned);
            }
            for ($j = $i + 1; $j < \count($rects); ++$j) {
                $o = $rects[$j];
                $overlap = min($r->x + $r->dx, $o->x + $o->dx) > max($r->x, $o->x) && min($r->y + $r->dy, $o->y + $o->dy) > max($r->y, $o->y);
                self::assertFalse($overlap, 'no overlap');
            }
        }
    }
}
