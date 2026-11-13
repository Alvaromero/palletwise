<?php

declare(strict_types=1);

namespace Palletwise\Layer;

/**
 * Plan-view rectangle. `turned` tells whether the box is rotated 90° from its base option.
 *
 * @internal
 */
final readonly class Rect
{
    public function __construct(
        public int $x,
        public int $y,
        public int $dx,
        public int $dy,
        public bool $turned = false,
    ) {}

    public function key(): string
    {
        return $this->x . ',' . $this->y . ',' . $this->dx . ',' . $this->dy;
    }
}
