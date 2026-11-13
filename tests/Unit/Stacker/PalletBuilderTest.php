<?php

declare(strict_types=1);

namespace Palletwise\Tests\Unit\Stacker;

use Palletwise\Layer\HomogeneousPatternFinder;
use Palletwise\Layer\HomogeneousSpec;
use Palletwise\Layer\LayerPlan;
use Palletwise\Layer\LayerSlot;
use Palletwise\Layer\Transform;
use Palletwise\Layer\UpOption;
use Palletwise\Model\Orientation;
use Palletwise\Model\PalletType;
use Palletwise\Model\Rotation;
use Palletwise\Partition\Item;
use Palletwise\Stacker\PalletBuilder;
use Palletwise\Tests\Unit\Layer\HomogeneousLayerFactoryTest;
use PHPUnit\Framework\TestCase;

final class PalletBuilderTest extends TestCase
{
    private static function pallet(): PalletBuilder
    {
        return new PalletBuilder('S1', PalletType::Mixed, 48_000, 40_000, 67_000, 1_460_000, 800);
    }

    /**
     * @param list<array{int, int, int, int, int}> $boxes [x, y, dx, dy, dz] in inches
     */
    private static function layer(array $boxes, bool $fragile = false, int $weight = 10): LayerPlan
    {
        $slots = [];
        foreach ($boxes as $i => [$x, $y, $dx, $dy, $dz]) {
            $item = new Item('B' . $i, 'B' . $i, 'S1', 'SKU', $dx * 1000, $dy * 1000, $dz * 1000, $weight * 1000, Rotation::KeepUpright, null, $fragile, $i);
            $slots[] = new LayerSlot($item, Orientation::LWH, $x * 1000, $y * 1000, $dx * 1000, $dy * 1000, $dz * 1000);
        }
        \assert($slots !== []);

        return new LayerPlan($slots, [0, 0, 48_000, 40_000], 0, false);
    }

    public function testSupportThreshold(): void
    {
        $pallet = self::pallet();
        self::assertTrue($pallet->tryPlace(self::layer([[0, 0, 24, 40, 10]])));
        // 20×20 with 16×20 over the box below: exactly 80 %.
        self::assertTrue($pallet->supports(self::layer([[8, 10, 20, 20, 5]]), Transform::Identity));
        self::assertFalse($pallet->supports(self::layer([[9, 10, 20, 20, 5]]), Transform::Identity));
        // Mirrored relative to the pallet it lands over the gap.
        self::assertFalse($pallet->supports(self::layer([[0, 0, 20, 20, 5]]), Transform::MirrorX));
        self::assertTrue($pallet->tryPlace(self::layer([[0, 0, 20, 20, 5]]), [Transform::MirrorX, Transform::Identity]));
        self::assertSame(15_000, $pallet->height());
        self::assertSame([0, 10_000], array_map(static fn($b): int => $b->z, $pallet->boxes()));
        // The top box ended up mirrored over the one below (x = 48 − 0 − 20 = 28 would have no support).
        self::assertSame(0, $pallet->boxes()[1]->x);
    }

    public function testShorterBoxesDoNotSupport(): void
    {
        $pallet = self::pallet();
        self::assertTrue($pallet->tryPlace(self::layer([[0, 0, 24, 40, 10], [24, 0, 24, 40, 9]])));
        self::assertFalse($pallet->tryPlace(self::layer([[20, 0, 10, 40, 10]])));
        self::assertTrue($pallet->tryPlace(self::layer([[0, 0, 24, 40, 10]])));
    }

    public function testHeightWeightAndFragileLimits(): void
    {
        $pallet = self::pallet();
        self::assertFalse($pallet->tryPlace(self::layer([[0, 0, 10, 10, 68]])));
        self::assertFalse($pallet->tryPlace(self::layer([[0, 0, 10, 10, 5]], weight: 1461)));
        self::assertTrue($pallet->tryPlace(self::layer([[0, 0, 48, 40, 10]], fragile: true)));
        self::assertFalse($pallet->tryPlace(self::layer([[0, 0, 10, 10, 5]])), 'nothing goes on top of a fragile layer');
        self::assertSame(1, \count($pallet->layers()));
    }

    public function testAlternatesPatternBetweenLayers(): void
    {
        // 16×12: two-block pattern, asymmetric in y.
        $pattern = (new HomogeneousPatternFinder())->find(48_000, 40_000, 16_000, 12_000, true)->centered(48_000, 40_000);
        $option = UpOption::for(Rotation::KeepUpright, 16_000, 12_000, 10_000)[0];
        $spec = new HomogeneousSpec($option, $pattern, 10, 6, 60, 48_000, 40_000);
        $items = array_map(static fn(int $i): Item => HomogeneousLayerFactoryTest::item(16, 12, 10, 10), range(1, 10));

        $pallet = self::pallet();
        self::assertTrue($pallet->placeAlternating($spec->layer($items, 0)));
        self::assertTrue($pallet->placeAlternating($spec->layer($items, 1)));

        $byLayer = [];
        foreach ($pallet->boxes() as $box) {
            $byLayer[$box->z][] = $box->x . ',' . $box->y . ',' . $box->dx;
        }
        sort($byLayer[0]);
        sort($byLayer[10_000]);
        self::assertNotSame($byLayer[0], $byLayer[10_000]);
    }
}
