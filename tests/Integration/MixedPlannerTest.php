<?php

declare(strict_types=1);

namespace Palletwise\Tests\Integration;

use Palletwise\Layer\OrderKey;
use Palletwise\Model\Mode;
use Palletwise\Model\ModeResult;
use Palletwise\Model\Pallet;
use Palletwise\Model\Placement;
use Palletwise\Planner\MixedStrategy;
use Palletwise\Planner\Planner;
use Palletwise\Tests\Support\Requests;
use PHPUnit\Framework\TestCase;

final class MixedPlannerTest extends TestCase
{
    public function testDifferentSkusShareAPallet(): void
    {
        $result = Requests::mode([
            Requests::box('A', 12, 10, 8, 10, 5),
            Requests::box('B', 16, 12, 8, 8, 4),
            Requests::box('C', 10, 10, 6, 5, 6),
        ]);

        self::assertSame(1, $result->palletCount);
        self::assertSame('mixed', $result->pallets[0]->type->value);
        self::assertSame(15, $result->pallets[0]->boxCount());
    }

    public function testLeftoversTopUpHomogeneousPallets(): void
    {
        // 12×10×16: 16 per layer × 4 layers = 64 per pallet, 64 in tall (3 in left).
        // The 2 in flat boxes fit on top of the full pallet.
        $result = Requests::mode([
            Requests::box('TALL', 12, 10, 16, 5, 64),
            Requests::box('FLAT', 12, 10, 2, 1, 16),
        ], ['includePlacements' => true]);

        self::assertSame(1, $result->palletCount);
        self::assertSame(71_000, $result->pallets[0]->height);
        self::assertSame('mixed', $result->pallets[0]->type->value);
    }

    public function testPalletTypeWithoutSku(): void
    {
        // 16 per layer × 6 layers = 96 boxes per pallet: the first is one kind of box only.
        $result = Requests::mode([
            Requests::box('A', 12, 10, 11, 10, 96, ['sku' => null]),
            Requests::box('B', 12, 10, 8, 10, 5, ['sku' => null]),
            Requests::box('C', 16, 12, 8, 8, 4, ['sku' => null]),
        ]);

        self::assertSame(['single_sku', 'mixed'], array_map(static fn(Pallet $p): string => $p->type->value, $result->pallets));
    }

    public function testFragileBoxesEndUpOnTop(): void
    {
        $result = Requests::mode([
            Requests::box('BASE', 24, 20, 10, 20, 8),
            Requests::box('GLASS', 12, 10, 6, 4, 10, ['fragile' => true]),
            Requests::box('MORE', 12, 10, 6, 4, 10),
        ], ['includePlacements' => true]);

        foreach ($result->pallets as $pallet) {
            $placements = $pallet->placements ?? [];
            foreach ($placements as $f) {
                if (!str_starts_with($f->boxId, 'GLASS')) {
                    continue;
                }
                foreach ($placements as $other) {
                    self::assertFalse(
                        $other->z >= $f->z + $f->height && self::overlaps($f, $other),
                        \sprintf('%s is above fragile %s', $other->boxId, $f->boxId),
                    );
                }
            }
        }
        self::assertSame(1, $result->palletCount);
    }

    public function testManyFragileBoxesNeedOneTopLayerPerPallet(): void
    {
        // 30 fragile 12×10 boxes: 16 per layer → 2 pallets (one layer each).
        $result = Requests::mode([Requests::box('GLASS', 12, 10, 8, 4, 30, ['fragile' => true])]);
        self::assertSame(2, $result->palletCount);
        self::assertSame([1, 1], array_map(static fn(Pallet $p): int => \count($p->layers), $result->pallets));
    }

    public function testStackableModeNeedsMorePallets(): void
    {
        $plan = Requests::plan([
            Requests::box('A', 12, 10, 8, 10, 100),
            Requests::box('B', 16, 12, 9, 12, 40),
        ], ['modes' => ['non_stackable', 'stackable']]);

        $non = $plan->forMode(Mode::NonStackable);
        $stack = $plan->forMode(Mode::Stackable);
        self::assertNotNull($non);
        self::assertNotNull($stack);
        self::assertGreaterThan($non->palletCount, $stack->palletCount);
        foreach ($stack->pallets as $pallet) {
            self::assertLessThanOrEqual(50_000, $pallet->height);
            self::assertLessThanOrEqual(750_000, $pallet->weight);
        }
    }

    public function testHeavySetSplitAcrossPallets(): void
    {
        // A set of 20 boxes of 80 lb (1600 lb) does not fit in a 1460 lb payload.
        $result = Requests::mode([
            Requests::box('KIT', 12, 10, 8, 80, 20, ['setId' => 'BIG', 'singleUnit' => true]),
            Requests::box('OTHER', 12, 10, 8, 10, 3),
        ]);

        $mandatory = array_values(array_filter($result->pallets, static fn(Pallet $p): bool => $p->type->value === 'mandatory'));
        self::assertCount(2, $mandatory);
        foreach ($mandatory as $pallet) {
            foreach ($pallet->boxIds as $id) {
                self::assertStringStartsWith('KIT#', $id);
            }
        }
        self::assertContains('SET_SPLIT_ACROSS_PALLETS', Requests::warningCodes($result));
        self::assertNotContains('HEAVY_BOX', Requests::warningCodes($result));
    }

    public function testStrictContactWithZeroBandTolerance(): void
    {
        $boxes = [Requests::box('A', 24, 20, 10, 10, 2), Requests::box('B', 24, 20, 9.5, 10, 2), Requests::box('C', 48, 40, 5, 10, 1, ['width' => 30, 'length' => 40])];
        $tolerant = Requests::mode($boxes);
        $strict = Requests::mode($boxes, ['overrides' => ['bandTolerance' => 0]]);

        self::assertLessThanOrEqual($strict->palletCount, $tolerant->palletCount);
    }

    public function testSearchIsAtLeastAsGoodAsEachStrategy(): void
    {
        $request = Requests::request([
            Requests::box('A', 17.1, 14.1, 8.6, 13.2, 23),
            Requests::box('B', 16.6, 15.2, 11.9, 17.4, 19),
            Requests::box('C', 11.5, 12.8, 5.8, 6.9, 45),
            Requests::box('D', 8.1, 15.1, 4.3, 1.5, 33),
            Requests::box('E', 23, 9.6, 9.1, 12.8, 7),
        ]);
        $best = self::first((new Planner())->plan($request)->results)->palletCount;
        foreach ([false, true] as $fill) {
            foreach (OrderKey::cases() as $order) {
                $single = self::first((new Planner(strategies: [new MixedStrategy($order, $fill)]))->plan($request)->results);
                self::assertLessThanOrEqual($single->palletCount, $best);
            }
        }
    }

    /**
     * @param list<ModeResult> $results
     */
    private static function first(array $results): ModeResult
    {
        self::assertNotEmpty($results);

        return $results[0];
    }

    private static function overlaps(Placement $a, Placement $b): bool
    {
        return min($a->x + $a->length, $b->x + $b->length) > max($a->x, $b->x)
            && min($a->y + $a->width, $b->y + $b->width) > max($a->y, $b->y);
    }
}
