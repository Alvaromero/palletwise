<?php

declare(strict_types=1);

namespace Palletwise\Stacker;

use Palletwise\Layer\HomogeneousSpec;
use Palletwise\Partition\Item;

/**
 * Builds pallets of identical boxes: pattern layers, alternating the symmetry between
 * layers when possible, and a partial top layer if weight limits before height.
 *
 * @internal
 */
final class HomogeneousStacker
{
    /**
     * @param non-empty-list<Item> $items at most spec->capacity
     */
    public function pallet(array $items, HomogeneousSpec $spec, PalletBuilder $pallet, Sequence $sequence): PalletBuilder
    {
        \assert(\count($items) <= $spec->capacity);
        foreach (array_chunk($items, $spec->perLayer) as $chunk) {
            if (!$pallet->placeAlternating($spec->layer($chunk, $sequence->next()))) {
                throw new \LogicException('Homogeneous layer could not be stacked.');
            }
        }

        return $pallet;
    }

    /**
     * Full pallets (capacity boxes each) and the leftover boxes.
     *
     * @param list<Item> $items
     *
     * @return array{list<list<Item>>, list<Item>}
     */
    public static function split(array $items, HomogeneousSpec $spec): array
    {
        $full = intdiv(\count($items), $spec->capacity);
        $chunks = [];
        for ($i = 0; $i < $full; ++$i) {
            $chunks[] = \array_slice($items, $i * $spec->capacity, $spec->capacity);
        }

        return [$chunks, \array_slice($items, $full * $spec->capacity)];
    }
}
