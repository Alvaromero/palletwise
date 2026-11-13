<?php

declare(strict_types=1);

namespace Palletwise\Partition;

/**
 * Identical boxes of a shipment, in input order.
 *
 * @internal
 */
final readonly class ItemGroup
{
    /**
     * @param non-empty-list<Item> $items
     */
    public function __construct(public array $items) {}

    public function sample(): Item
    {
        return $this->items[0];
    }

    /**
     * Groups identical non-fragile boxes in order of first appearance and sets fragile ones aside.
     *
     * @param list<Item> $items
     *
     * @return array{list<self>, list<Item>}
     */
    public static function split(array $items): array
    {
        $groups = [];
        $fragile = [];
        foreach ($items as $item) {
            if ($item->fragile) {
                $fragile[] = $item;
            } else {
                $groups[$item->groupKey()][] = $item;
            }
        }

        return [array_values(array_map(static fn(array $g): self => new self($g), $groups)), $fragile];
    }
}
