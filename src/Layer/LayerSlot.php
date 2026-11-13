<?php

declare(strict_types=1);

namespace Palletwise\Layer;

use Palletwise\Model\Orientation;
use Palletwise\Partition\Item;

/**
 * A box inside a layer, with its plan position in pallet coordinates.
 *
 * @internal
 */
final readonly class LayerSlot
{
    public function __construct(
        public Item $item,
        public Orientation $orientation,
        public int $x,
        public int $y,
        public int $dx,
        public int $dy,
        public int $dz,
    ) {}
}
