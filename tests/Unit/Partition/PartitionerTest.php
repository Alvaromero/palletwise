<?php

declare(strict_types=1);

namespace Palletwise\Tests\Unit\Partition;

use Palletwise\Model\PlanRequest;
use Palletwise\Partition\Item;
use Palletwise\Partition\MandatoryUnit;
use Palletwise\Partition\Partitioner;
use Palletwise\Partition\ShipmentPartition;
use Palletwise\Profile\ProfileRepository;
use Palletwise\Profile\ResolvedProfile;
use Palletwise\Tests\Support\Requests;
use PHPUnit\Framework\TestCase;

final class PartitionerTest extends TestCase
{
    /**
     * @param array<string, true>|null $placeable
     *
     * @return list<ShipmentPartition>
     */
    private static function partition(PlanRequest $request, ?array $placeable = null): array
    {
        $profile = ResolvedProfile::resolve((new ProfileRepository())->load($request->profile), $request->lengthUnit, $request->weightUnit);
        $placeable ??= array_fill_keys(array_map(static fn($b): string => $b->id, $request->boxes), true);

        return (new Partitioner())->partition($request->boxes, $placeable, $profile);
    }

    /**
     * @param list<Item> $items
     *
     * @return list<string>
     */
    private static function ids(array $items): array
    {
        return array_map(static fn(Item $i): string => $i->id, $items);
    }

    public function testSplitsByShipmentSortedById(): void
    {
        $parts = self::partition(Requests::request([
            Requests::box('A', 10, 10, 10, 5, extra: ['shipmentId' => 'S2']),
            Requests::box('B', 10, 10, 10, 5, extra: ['shipmentId' => 'S10']),
            Requests::box('C', 10, 10, 10, 5, extra: ['shipmentId' => 'S1']),
        ]));

        self::assertSame(['S1', 'S10', 'S2'], array_map(static fn(ShipmentPartition $p): string => $p->shipmentId, $parts));
    }

    public function testGroupsIdenticalBoxesAndExpandsQuantity(): void
    {
        $parts = self::partition(Requests::request([
            Requests::box('A', 10, 10, 10, 5, 3, ['sku' => 'X']),
            Requests::box('B', 10, 10, 10, 5, 2, ['sku' => 'X']),
            Requests::box('C', 10, 10, 10, 5, 1, ['sku' => 'Y']),
            Requests::box('D', 10, 10, 10, 6, 1, ['sku' => 'X']),
            Requests::box('E', 10, 10, 10, 5, 2, ['sku' => 'X', 'fragile' => true]),
        ]));

        self::assertCount(1, $parts);
        $groups = array_map(static fn($g): array => self::ids($g->items), $parts[0]->groups);
        self::assertSame([['A#1', 'A#2', 'A#3', 'B#1', 'B#2'], ['C'], ['D']], $groups);
        self::assertSame(['E#1', 'E#2'], self::ids($parts[0]->fragile));
        self::assertSame([0, 1, 2, 3, 4], array_map(static fn(Item $i): int => $i->ordinal, $parts[0]->groups[0]->items));
    }

    public function testBoxesWithoutSkuAreGroupedByDimensionsAndWeight(): void
    {
        $parts = self::partition(Requests::request([
            Requests::box('A', 10, 10, 10, 5, 2, ['sku' => null]),
            Requests::box('B', 10, 10, 10, 5, 1, ['sku' => null]),
            Requests::box('C', 10, 10, 10, 6, 1, ['sku' => null]),
            Requests::box('D', 10, 10, 10, 5, 1, ['sku' => 'X']),
        ]));

        $groups = array_map(static fn($g): array => self::ids($g->items), $parts[0]->groups);
        self::assertSame([['A#1', 'A#2', 'B'], ['C'], ['D']], $groups);
    }

    public function testMandatoryBoxesAndSets(): void
    {
        $parts = self::partition(Requests::request([
            Requests::box('HEAVY', 10, 10, 10, 100.001, 2),
            Requests::box('LIMIT', 10, 10, 10, 100),
            Requests::box('WIDE', 20, 30.001, 10, 5),
            Requests::box('K1', 10, 10, 10, 40, 2, ['setId' => 'KIT']),
            Requests::box('K2', 10, 10, 10, 20.001, 1, ['setId' => 'KIT']),
            Requests::box('L1', 10, 10, 10, 50, 2, ['setId' => 'LIGHT']),
            Requests::box('M1', 10, 10, 10, 5, 1, ['setId' => 'MEMBER']),
            Requests::box('M2', 10, 31, 10, 5, 1, ['setId' => 'MEMBER']),
        ]));

        $units = array_map(static fn(MandatoryUnit $u): array => [$u->setId, self::ids($u->items), $u->reason], $parts[0]->mandatory);
        self::assertSame([
            [null, ['HEAVY#1'], 'box_weight'],
            [null, ['HEAVY#2'], 'box_weight'],
            [null, ['WIDE'], 'box_width'],
            ['KIT', ['K1#1', 'K1#2', 'K2'], 'set_weight'],
            ['MEMBER', ['M1', 'M2'], 'set_member'],
        ], $units);
        // The LIGHT set weighs exactly 100 lb: it is not mandatory.
        $grouped = array_merge(...array_map(static fn($g): array => self::ids($g->items), $parts[0]->groups));
        self::assertSame(['LIMIT', 'L1#1', 'L1#2'], $grouped);
    }

    public function testSetWeightCountsUnplaceableMembers(): void
    {
        $request = Requests::request([
            Requests::box('K1', 10, 10, 10, 60, 1, ['setId' => 'KIT']),
            Requests::box('K2', 90, 10, 10, 41, 1, ['setId' => 'KIT']),
        ]);
        $parts = self::partition($request, ['K1' => true]);

        self::assertCount(1, $parts[0]->mandatory);
        self::assertSame(['K1'], self::ids($parts[0]->mandatory[0]->items));
    }

    public function testSetsAreScopedByShipment(): void
    {
        $parts = self::partition(Requests::request([
            Requests::box('A', 10, 10, 10, 60, 1, ['setId' => 'KIT', 'shipmentId' => 'S1']),
            Requests::box('B', 10, 10, 10, 60, 1, ['setId' => 'KIT', 'shipmentId' => 'S2']),
        ]));

        self::assertSame([], $parts[0]->mandatory);
        self::assertSame([], $parts[1]->mandatory);
    }
}
