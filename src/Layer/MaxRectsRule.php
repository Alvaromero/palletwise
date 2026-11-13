<?php

declare(strict_types=1);

namespace Palletwise\Layer;

/**
 * MaxRects position selection rule.
 *
 * @internal
 */
enum MaxRectsRule
{
    /** Smallest leftover on the short side, then on the long side, then y, x. */
    case BestShortSideFit;

    /** Lowest top edge (y + height), then x. */
    case BottomLeft;
}
