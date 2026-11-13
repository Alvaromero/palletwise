<?php

declare(strict_types=1);

namespace Palletwise\Tests\Unit\Stacker;

use Palletwise\Layer\LayerPlan;
use Palletwise\Layer\LayerSlot;
use Palletwise\Model\Orientation;
use Palletwise\Model\PalletType;
use Palletwise\Model\Rotation;
use Palletwise\Partition\Item;
use Palletwise\Stacker\PalletBuilder;
use Palletwise\Stacker\Stacker;
use PHPUnit\Framework\TestCase;

final class StackerTest extends TestCase
{
    private int $sequence = 0;

    private static function pallet(): PalletBuilder
    {
        return new PalletBuilder('S1', PalletType::Mixed, 48_000, 40_000, 67_000, 1_460_000, 800);
    }

    /**
     * @param list<array{int, int, int, int}> $boxes [x, y, dx, dy] in inches
     */
    private function layer(array $boxes, int $height, int $weight = 10, bool $fragile = false): LayerPlan
    {
        $slots = [];
        foreach ($boxes as [$x, $y, $dx, $dy]) {
            $id = 'B' . $this->sequence . '-' . \count($slots);
            $item = new Item($id, $id, 'S1', 'SKU', $dx * 1000, $dy * 1000, $height * 1000, $weight * 1000, Rotation::KeepUpright, null, $fragile, \count($slots));
            $slots[] = new LayerSlot($item, Orientation::LWH, $x * 1000, $y * 1000, $dx * 1000, $dy * 1000, $height * 1000);
        }
        \assert($slots !== []);
        $x0 = min(array_map(static fn(LayerSlot $s): int => $s->x, $slots));
        $y0 = min(array_map(static fn(LayerSlot $s): int => $s->y, $slots));
        $x1 = max(array_map(static fn(LayerSlot $s): int => $s->x + $s->dx, $slots));
        $y1 = max(array_map(static fn(LayerSlot $s): int => $s->y + $s->dy, $slots));

        return new LayerPlan($slots, [$x0, $y0, $x1, $y1], $this->sequence++, false);
    }

    /**
     * @param list<LayerPlan> $layers
     *
     * @return list<PalletBuilder>
     */
    private static function stack(array $layers): array
    {
        return (new Stacker())->stack($layers, self::pallet(...), 48_000 * 40_000, 800);
    }

    public function testFullLayersGoBelowPartialOnesAndFragileOnTop(): void
    {
        $partial = $this->layer([[0, 0, 20, 20]], 10, 100);
        $fragile = $this->layer([[0, 0, 48, 40]], 10, 5, fragile: true);
        $full = $this->layer([[0, 0, 48, 40]], 10, 50);

        $pallets = self::stack([$partial, $fragile, $full]);
        self::assertCount(2, $pallets);
        self::assertSame([$full, $partial], $pallets[0]->layerPlans());
        // The fragile layer cannot go on the partial one (no support): it opens another pallet.
        self::assertSame([$fragile], $pallets[1]->layerPlans());
    }

    public function testFillsOpenPalletsFirst(): void
    {
        $open = self::pallet();
        $open->tryPlace($this->layer([[0, 0, 48, 40]], 60));
        $pallets = (new Stacker())->stack([$this->layer([[0, 0, 48, 40]], 7), $this->layer([[0, 0, 48, 40]], 8)], self::pallet(...), 48_000 * 40_000, 800, [$open]);

        self::assertSame(67_000, $open->height());
        self::assertCount(1, $pallets);
    }

    public function testLayersMoveToTheSupportedCorner(): void
    {
        $pallet = self::pallet();
        $pallet->tryPlace($this->layer([[0, 0, 20, 20]], 10));
        // Centered it would have no support; aligned to the bottom-left corner, it does.
        self::assertTrue($pallet->tryPlaceAnywhere($this->layer([[14, 10, 20, 20]], 10)));
        self::assertSame([0, 0], [$pallet->boxes()[1]->x, $pallet->boxes()[1]->y]);
    }

    public function testCompactMergesPallets(): void
    {
        $a = self::pallet();
        $a->tryPlace($this->layer([[0, 0, 20, 20]], 10));
        $b = self::pallet();
        $b->tryPlace($this->layer([[0, 0, 48, 40]], 10));
        $c = self::pallet();
        $c->tryPlace($this->layer([[0, 0, 48, 40]], 60));

        $merged = (new Stacker())->compact([$a, $b, $c], 67_000, 1_460_000);
        self::assertCount(2, $merged);
        // a and b are restacked with the full layer at the bottom; c fits with nobody.
        self::assertSame(20_000, $merged[0]->height());
        self::assertSame(1_920_000_000, $merged[0]->layers()[0]->coveredArea);
        self::assertSame($c, $merged[1]);
    }

    public function testCompactDoesNotMergeUnsupportedStacks(): void
    {
        $a = self::pallet();
        $a->tryPlace($this->layer([[0, 0, 20, 20]], 10));
        $b = self::pallet();
        $b->tryPlace($this->layer([[0, 0, 48, 10], [0, 30, 48, 10]], 10));

        self::assertCount(2, (new Stacker())->compact([$a, $b], 67_000, 1_460_000));
    }
}
