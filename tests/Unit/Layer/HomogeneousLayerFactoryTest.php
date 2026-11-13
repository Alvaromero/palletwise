<?php

declare(strict_types=1);

namespace Palletwise\Tests\Unit\Layer;

use Palletwise\Layer\HomogeneousLayerFactory;
use Palletwise\Layer\HomogeneousSpec;
use Palletwise\Model\Mode;
use Palletwise\Model\Orientation;
use Palletwise\Model\Rotation;
use Palletwise\Partition\Item;
use Palletwise\Profile\ProfileRepository;
use Palletwise\Profile\ResolvedProfile;
use Palletwise\Units\LengthUnit;
use Palletwise\Units\WeightUnit;
use PHPUnit\Framework\TestCase;

final class HomogeneousLayerFactoryTest extends TestCase
{
    public static function item(int $l, int $w, int $h, int $weight, Rotation $rotation = Rotation::KeepUpright, bool $fragile = false): Item
    {
        return new Item('A', 'A', 'S1', 'SKU', $l * 1000, $w * 1000, $h * 1000, $weight * 1000, $rotation, null, $fragile, 0);
    }

    private static function spec(Item $item, Mode $mode = Mode::NonStackable): HomogeneousSpec
    {
        $profile = ResolvedProfile::resolve((new ProfileRepository())->load('amazon-us@2026-11'), LengthUnit::Inch, WeightUnit::Pound);
        $spec = (new HomogeneousLayerFactory())->spec($item, $profile, $mode);
        self::assertNotNull($spec);

        return $spec;
    }

    public function testHeightLimited(): void
    {
        $spec = self::spec(self::item(12, 10, 8, 10));
        self::assertSame([16, 8, 128], [$spec->perLayer, $spec->layersPerPallet, $spec->capacity]);
    }

    public function testWeightLimitedLeavesAPartialTopLayer(): void
    {
        $spec = self::spec(self::item(12, 10, 8, 50));
        self::assertSame([16, 2, 29], [$spec->perLayer, $spec->layersPerPallet, $spec->capacity]);
    }

    public function testStackableMode(): void
    {
        // 45 usable in: 5 layers of 8 in; 710 lb: 71 boxes of 10 lb.
        $spec = self::spec(self::item(12, 10, 8, 10), Mode::Stackable);
        self::assertSame([16, 5, 71], [$spec->perLayer, $spec->layersPerPallet, $spec->capacity]);
    }

    public function testFragileGetsASingleLayer(): void
    {
        $spec = self::spec(self::item(12, 10, 8, 10, fragile: true));
        self::assertSame([16, 1, 16], [$spec->perLayer, $spec->layersPerPallet, $spec->capacity]);
    }

    public function testAnyRotationChoosesTheBestSupportFace(): void
    {
        // Upright: 1 per layer × 13 layers. On its edge (40×5 in plan, 30 tall): 9 × 2 = 18.
        self::assertSame(13, self::spec(self::item(40, 30, 5, 1))->capacity);
        $spec = self::spec(self::item(40, 30, 5, 1, Rotation::Any));
        self::assertSame(18, $spec->capacity);
        self::assertSame(Orientation::LHW, $spec->option->orientation);
    }

    public function testPartialLayerUsesCentralPositions(): void
    {
        $spec = self::spec(self::item(12, 10, 8, 10));
        $layer = $spec->layer([self::item(12, 10, 8, 10), self::item(12, 10, 8, 10)], 0);
        $positions = array_map(static fn($s): array => [$s->x, $s->y], $layer->slots);
        self::assertSame([[12_000, 10_000], [24_000, 10_000]], $positions);
        self::assertSame([0, 0, 48_000, 40_000], $layer->frame);
    }
}
