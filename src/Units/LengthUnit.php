<?php

declare(strict_types=1);

namespace Palletwise\Units;

enum LengthUnit: string
{
    case Inch = 'in';
    case Centimeter = 'cm';
    case Millimeter = 'mm';

    /**
     * Exact size of the unit in micrometers (1 in = exactly 25.4 mm).
     */
    public function micrometers(): int
    {
        return match ($this) {
            self::Inch => 25_400,
            self::Centimeter => 10_000,
            self::Millimeter => 1_000,
        };
    }
}
