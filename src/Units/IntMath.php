<?php

declare(strict_types=1);

namespace Palletwise\Units;

/**
 * Integer arithmetic with overflow checks. The engine does not use floats.
 */
final class IntMath
{
    private function __construct() {}

    public static function mul(int $a, int $b): int
    {
        if ($a !== 0 && $b !== 0) {
            $absA = $a < 0 ? -$a : $a;
            $absB = $b < 0 ? -$b : $b;
            if ($absB > intdiv(PHP_INT_MAX, $absA)) {
                throw new \OverflowException('Integer overflow in multiplication.');
            }
        }

        return $a * $b;
    }

    public static function add(int $a, int $b): int
    {
        if (($b > 0 && $a > PHP_INT_MAX - $b) || ($b < 0 && $a < PHP_INT_MIN - $b)) {
            throw new \OverflowException('Integer overflow in addition.');
        }

        return $a + $b;
    }

    public static function floorDiv(int $a, int $b): int
    {
        self::assertPositive($b);
        $q = intdiv($a, $b);
        if ($a % $b !== 0 && $a < 0) {
            --$q;
        }

        return $q;
    }

    public static function ceilDiv(int $a, int $b): int
    {
        self::assertPositive($b);
        $q = intdiv($a, $b);
        if ($a % $b !== 0 && $a > 0) {
            ++$q;
        }

        return $q;
    }

    public static function div(int $a, int $b, Rounding $rounding): int
    {
        return $rounding === Rounding::Ceil ? self::ceilDiv($a, $b) : self::floorDiv($a, $b);
    }

    /**
     * floor(num / den × 10^digits) without overflow, for num >= 0 and den > 0.
     */
    public static function ratio(int $num, int $den, int $digits): int
    {
        self::assertPositive($den);
        if ($num < 0) {
            throw new \InvalidArgumentException('Ratio numerator must be >= 0.');
        }
        if ($den > intdiv(PHP_INT_MAX, 10)) {
            throw new \OverflowException('Ratio denominator too large.');
        }
        $result = intdiv($num, $den);
        $rest = $num % $den;
        for ($i = 0; $i < $digits; ++$i) {
            $rest *= 10;
            $result = self::add(self::mul($result, 10), intdiv($rest, $den));
            $rest %= $den;
        }

        return $result;
    }

    /**
     * Volume in thousandths of a cubic unit from dimensions scaled ×1000.
     */
    public static function volume(int $dx, int $dy, int $dz, Rounding $rounding = Rounding::Floor): int
    {
        return self::div(self::mul(self::mul($dx, $dy), $dz), 1_000_000, $rounding);
    }

    private static function assertPositive(int $b): void
    {
        if ($b <= 0) {
            throw new \InvalidArgumentException('Divisor must be > 0.');
        }
    }
}
