<?php

declare(strict_types=1);

namespace Palletwise\Units;

/**
 * Converts values scaled ×1000 between units with exact integer arithmetic
 * and an explicit rounding direction.
 */
final class UnitConverter
{
    private function __construct() {}

    public static function length(int $scaled, LengthUnit $from, LengthUnit $to, Rounding $rounding): int
    {
        return self::convert($scaled, $from->micrometers(), $to->micrometers(), $rounding);
    }

    public static function weight(int $scaled, WeightUnit $from, WeightUnit $to, Rounding $rounding): int
    {
        return self::convert($scaled, $from->factor(), $to->factor(), $rounding);
    }

    private static function convert(int $value, int $fromFactor, int $toFactor, Rounding $rounding): int
    {
        if ($fromFactor === $toFactor) {
            return $value;
        }

        return IntMath::div(IntMath::mul($value, $fromFactor), $toFactor, $rounding);
    }
}
