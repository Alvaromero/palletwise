<?php

declare(strict_types=1);

namespace Palletwise\Model;

/**
 * Limits of a mode. Height and weight always include the pallet.
 */
final readonly class ModeLimits
{
    public function __construct(
        public int $maxHeight,
        public int $maxWeight,
    ) {}
}
