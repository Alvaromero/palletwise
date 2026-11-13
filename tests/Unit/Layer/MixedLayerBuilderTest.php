<?php

declare(strict_types=1);

namespace Palletwise\Tests\Unit\Layer;

use Palletwise\Layer\LayerSlot;
use Palletwise\Layer\MixedEntry;
use Palletwise\Layer\MixedLayerBuilder;
use Palletwise\Layer\OrderKey;
use Palletwise\Layer\SupportSurface;
use Palletwise\Model\Mode;
use Palletwise\Model\Rotation;
use Palletwise\Partition\Item;
use Palletwise\Profile\ProfileRepository;
use Palletwise\Profile\ResolvedProfile;
use Palletwise\Units\LengthUnit;
use Palletwise\Units\WeightUnit;
use PHPUnit\Framework\TestCase;

final class MixedLayerBuilderTest extends TestCase
{
    private static function profile(): ResolvedProfile
    {
        return ResolvedProfile::resolve((new ProfileRepository())->load('amazon-us@2026-11'), LengthUnit::Inch, WeightUnit::Pound);
    }

    private static function item(string $id, float $l, float $w, float $h, float $weight, int $ordinal, Rotation $rotation = Rotation::KeepUpright): Item
    {
        return new Item($id, $id, 'S1', 'SKU-' . $id, (int) ($l * 1000), (int) ($w * 1000), (int) ($h * 1000), (int) ($weight * 1000), $rotation, null, false, $ordinal);
    }

    private static function surface(int $height = 0, ?callable $supported = null, int $remainingHeight = 67_000, int $payload = 1_460_000): SupportSurface
    {
        return new class ($height, $supported, $remainingHeight, $payload) implements SupportSurface {
            /** @var (callable(int, int, int, int): bool)|null */
            private $supported;

            public function __construct(private readonly int $z, ?callable $supported, private readonly int $remaining, private readonly int $payload)
            {
                $this->supported = $supported;
            }

            public function height(): int
            {
                return $this->z;
            }

            public function remainingHeight(): int
            {
                return $this->remaining;
            }

            public function remainingPayload(): int
            {
                return $this->payload;
            }

            public function isOpen(): bool
            {
                return true;
            }

            public function isSupported(int $x, int $y, int $dx, int $dy): bool
            {
                return $this->supported === null || ($this->supported)($x, $y, $dx, $dy);
            }
        };
    }

    /**
     * @param list<MixedEntry> $entries
     *
     * @return list<string>
     */
    private static function ids(array $entries): array
    {
        return array_map(static fn(MixedEntry $e): string => $e->item->id, $entries);
    }

    public function testEntriesAreSortedByTheOrderKeyThenTheOthers(): void
    {
        $items = [
            self::item('small-heavy', 10, 10, 10, 40, 0),
            self::item('big-light', 20, 20, 20, 5, 1),
            self::item('flat-wide', 30, 20, 4, 10, 2),
            self::item('tie', 10, 10, 10, 40, 3),
        ];
        $builder = new MixedLayerBuilder();
        $profile = self::profile();

        self::assertSame(['big-light', 'flat-wide', 'small-heavy', 'tie'], self::ids($builder->entries($items, $profile, Mode::NonStackable, OrderKey::Volume)));
        self::assertSame(['small-heavy', 'tie', 'flat-wide', 'big-light'], self::ids($builder->entries($items, $profile, Mode::NonStackable, OrderKey::Weight)));
        self::assertSame(['flat-wide', 'big-light', 'small-heavy', 'tie'], self::ids($builder->entries($items, $profile, Mode::NonStackable, OrderKey::BaseArea)));
        self::assertSame(['big-light', 'small-heavy', 'tie', 'flat-wide'], self::ids($builder->entries($items, $profile, Mode::NonStackable, OrderKey::Height)));
    }

    public function testAnyRotationLiesOnTheLowestFace(): void
    {
        $entries = (new MixedLayerBuilder())->entries([self::item('A', 30, 20, 40, 5, 0, Rotation::Any)], self::profile(), Mode::NonStackable, OrderKey::Volume);
        self::assertSame(20_000, $entries[0]->option->dz);
    }

    public function testPicksTheBandThatFillsItsSlabBest(): void
    {
        $items = [self::item('TALL', 10, 10, 20, 5, 0)];
        for ($i = 1; $i <= 4; ++$i) {
            $items[] = self::item('FLAT' . $i, 24, 20, 10, 5, $i);
        }
        $builder = new MixedLayerBuilder();
        $profile = self::profile();
        $entries = $builder->entries($items, $profile, Mode::NonStackable, OrderKey::Height);

        $result = $builder->layerOn(self::surface(), $entries, $profile, false, 0);
        self::assertNotNull($result);
        [$layer, $used] = $result;
        // A single tall box fills 5 % of its slab; the four flat ones, 100 %.
        self::assertSame(10_000, $layer->height);
        self::assertCount(4, $layer->slots);
        self::assertSame([1, 2, 3, 4], array_keys($used));
        self::assertSame([0, 0, 48_000, 40_000], $layer->frame);
    }

    public function testFillWithShorterBoxesAndCentering(): void
    {
        $items = [self::item('TALL', 20, 20, 20, 5, 0), self::item('SHORT', 20, 20, 10, 5, 1)];
        $builder = new MixedLayerBuilder();
        $profile = self::profile();
        $entries = $builder->entries($items, $profile, Mode::NonStackable, OrderKey::Height);

        $withFill = $builder->layerOn(self::surface(), $entries, $profile, true, 0);
        self::assertNotNull($withFill);
        self::assertCount(2, $withFill[0]->slots);
        self::assertSame(20_000, $withFill[0]->height);
        // Both boxes end up in a row along y (20 × 40) and the group is centered on x.
        self::assertSame([14_000, 0, 34_000, 40_000], $withFill[0]->frame);

        $withoutFill = $builder->layerOn(self::surface(), $entries, $profile, false, 0);
        self::assertNotNull($withoutFill);
        // Without filling, the low band's layer (one box of 10) fills its slab as well as the tall one.
        self::assertCount(1, $withoutFill[0]->slots);
    }

    public function testOnlySupportedPositionsAreUsed(): void
    {
        $profile = self::profile();
        $builder = new MixedLayerBuilder();
        $entries = $builder->entries([self::item('A', 10, 10, 10, 5, 0), self::item('B', 10, 10, 10, 5, 1)], $profile, Mode::NonStackable, OrderKey::Volume);
        // There is support only on the right half of the pallet.
        $surface = self::surface(10_000, static fn(int $x): bool => $x >= 24_000);

        $result = $builder->layerOn($surface, $entries, $profile, false, 0);
        self::assertNotNull($result);
        foreach ($result[0]->slots as $slot) {
            self::assertGreaterThanOrEqual(24_000, $slot->x);
        }
        self::assertNull($builder->layerOn(self::surface(10_000, static fn(): bool => false), $entries, $profile, false, 0));
    }

    public function testRespectsRemainingHeightAndPayload(): void
    {
        $profile = self::profile();
        $builder = new MixedLayerBuilder();
        $entries = $builder->entries([self::item('A', 10, 10, 10, 30, 0), self::item('B', 10, 10, 10, 30, 1)], $profile, Mode::NonStackable, OrderKey::Volume);

        self::assertNull($builder->layerOn(self::surface(remainingHeight: 9_999), $entries, $profile, false, 0));
        $result = $builder->layerOn(self::surface(payload: 59_999), $entries, $profile, false, 0);
        self::assertNotNull($result);
        self::assertSame(['A'], array_map(static fn(LayerSlot $s): string => $s->item->id, $result[0]->slots));
    }
}
