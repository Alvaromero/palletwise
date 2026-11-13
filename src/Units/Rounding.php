<?php

declare(strict_types=1);

namespace Palletwise\Units;

/**
 * Rounding direction when a value has more precision than the internal scale
 * or when converting between units.
 */
enum Rounding
{
    /** Towards +infinity. */
    case Ceil;

    /** Towards -infinity. */
    case Floor;
}
