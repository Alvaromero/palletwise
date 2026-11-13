<?php

declare(strict_types=1);

namespace Palletwise\Units;

/**
 * Conversion between decimal numbers and integers scaled ×1000 (thousandths of the unit).
 *
 * Floats are read with 9 decimals (sprintf '%.9F', independent of php.ini) and strings
 * as exact decimals. If the value has more than 3 significant decimals it is
 * rounded in the given direction.
 */
final class Decimal
{
    public const SCALE = 1000;

    /** Maximum accepted absolute value, already scaled (1e15 → 1e12 units). */
    public const MAX_ABS_SCALED = 1_000_000_000_000_000;

    private const PATTERN = '/^([+-]?)(\d*)(?:\.(\d*))?(?:[eE]([+-]?\d{1,4}))?$/D';

    private function __construct() {}

    /**
     * @throws \InvalidArgumentException if the value is not a representable finite number
     */
    public static function toScaled(int|float|string $value, Rounding $rounding): int
    {
        if (\is_int($value)) {
            if ($value > intdiv(self::MAX_ABS_SCALED, self::SCALE) || $value < -intdiv(self::MAX_ABS_SCALED, self::SCALE)) {
                throw new \InvalidArgumentException('Number out of range.');
            }

            return $value * self::SCALE;
        }

        if (\is_float($value)) {
            if (!is_finite($value)) {
                throw new \InvalidArgumentException('Number must be finite.');
            }
            $value = \sprintf('%.9F', $value);
        }

        return self::parseString($value, $rounding);
    }

    public static function isNumeric(mixed $value): bool
    {
        if (\is_int($value)) {
            return true;
        }
        if (\is_float($value)) {
            return is_finite($value);
        }

        return \is_string($value) && preg_match(self::PATTERN, $value) === 1 && preg_match('/\d/', $value) === 1;
    }

    /**
     * Canonical decimal representation: no superfluous zeros ("12.5", "3", "0.001").
     */
    public static function format(int $scaled): string
    {
        $negative = $scaled < 0;
        $abs = $negative ? -$scaled : $scaled;
        $int = intdiv($abs, self::SCALE);
        $frac = rtrim(str_pad((string) ($abs % self::SCALE), 3, '0', STR_PAD_LEFT), '0');

        return ($negative ? '-' : '') . $int . ($frac === '' ? '' : '.' . $frac);
    }

    public static function toFloat(int $scaled): float
    {
        return $scaled / self::SCALE;
    }

    private static function parseString(string $value, Rounding $rounding): int
    {
        if (preg_match(self::PATTERN, $value, $m) !== 1) {
            throw new \InvalidArgumentException('Not a decimal number.');
        }
        $sign = $m[1];
        $intDigits = $m[2];
        $fracDigits = $m[3] ?? '';
        if ($intDigits === '' && $fracDigits === '') {
            throw new \InvalidArgumentException('Not a decimal number.');
        }
        $exponent = isset($m[4]) ? (int) $m[4] : 0;

        $digits = ltrim($intDigits . $fracDigits, '0');
        // Position of the decimal point counted from the end of $digits.
        $fracLength = \strlen($fracDigits) - $exponent;

        if ($digits === '') {
            return 0;
        }
        $point = \strlen($digits) - $fracLength;
        if ($point > 15) {
            throw new \InvalidArgumentException('Number out of range.');
        }

        if ($point >= 0) {
            $intPart = substr($digits, 0, $point);
            $fracPart = substr($digits, $point);
            if ($fracLength < 0) {
                $intPart = $digits . str_repeat('0', -$fracLength);
                $fracPart = '';
            }
        } else {
            $intPart = '';
            $fracPart = -$point > 40 ? str_repeat('0', 3) . '1' : str_repeat('0', -$point) . $digits;
        }

        $magnitude = ((int) ($intPart === '' ? '0' : $intPart)) * self::SCALE
            + (int) str_pad(substr($fracPart, 0, 3), 3, '0');
        $hasRemainder = trim(substr($fracPart, 3), '0') !== '';

        $negative = $sign === '-' && ($magnitude > 0 || $hasRemainder);
        if ($hasRemainder) {
            $awayFromZero = $negative ? $rounding === Rounding::Floor : $rounding === Rounding::Ceil;
            if ($awayFromZero) {
                ++$magnitude;
            }
        }
        if ($magnitude > self::MAX_ABS_SCALED) {
            throw new \InvalidArgumentException('Number out of range.');
        }

        return $negative ? -$magnitude : $magnitude;
    }
}
