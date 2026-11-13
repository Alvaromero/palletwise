<?php

declare(strict_types=1);

namespace Palletwise\Partition;

/**
 * @internal
 */
final readonly class ShipmentPartition
{
    /**
     * @param list<MandatoryUnit> $mandatory
     * @param list<ItemGroup>     $groups    non-fragile boxes grouped by identity
     * @param list<Item>          $fragile   fragile boxes (can only go in the top layer)
     */
    public function __construct(
        public string $shipmentId,
        public array $mandatory,
        public array $groups,
        public array $fragile,
    ) {}

    /**
     * @return list<Item>
     */
    public function allItems(): array
    {
        $items = [];
        foreach ($this->mandatory as $unit) {
            array_push($items, ...$unit->items);
        }
        foreach ($this->groups as $group) {
            array_push($items, ...$group->items);
        }
        array_push($items, ...$this->fragile);

        return $items;
    }
}
