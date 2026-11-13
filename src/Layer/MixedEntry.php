<?php

declare(strict_types=1);

namespace Palletwise\Layer;

use Palletwise\Partition\Item;

/**
 * A loose box in the mixed stage with its chosen standing face.
 *
 * @internal
 */
final readonly class MixedEntry
{
    public function __construct(
        public Item $item,
        public UpOption $option,
    ) {}
}
