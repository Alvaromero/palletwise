<?php

declare(strict_types=1);

namespace Palletwise\Stacker;

use Palletwise\Model\Orientation;
use Palletwise\Partition\Item;

/**
 * @internal
 */
final readonly class PlacedBox
{
    public function __construct(
        public Item $item,
        public Orientation $orientation,
        public int $x,
        public int $y,
        public int $z,
        public int $dx,
        public int $dy,
        public int $dz,
    ) {}
}
