<?php

declare(strict_types=1);

namespace Palletwise\Support;

use Palletwise\Exception\InvalidPlanRequest;
use Palletwise\Units\Decimal;
use Palletwise\Units\Rounding;

/**
 * Strict input reading. Every error is an InvalidPlanRequest whose message names the
 * input field and nothing else.
 *
 * @internal
 */
final class Input
{
    public const MAX_STRING_LENGTH = 200;

    private function __construct() {}

    /**
     * @param array<mixed>  $data
     * @param list<string>  $allowed
     */
    public static function assertKeys(array $data, array $allowed, string $where): void
    {
        foreach (array_keys($data) as $key) {
            if (!\in_array($key, $allowed, true)) {
                throw InvalidPlanRequest::because(\sprintf('%s: unknown field "%s".', $where, (string) $key));
            }
        }
    }

    public static function string(mixed $value, string $field): string
    {
        if (!\is_string($value) || $value === '' || trim($value) === '') {
            throw InvalidPlanRequest::because(\sprintf('%s must be a non-empty string.', $field));
        }
        if (\strlen($value) > self::MAX_STRING_LENGTH) {
            throw InvalidPlanRequest::because(\sprintf('%s must be at most %d characters long.', $field, self::MAX_STRING_LENGTH));
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw InvalidPlanRequest::because(\sprintf('%s must not contain control characters.', $field));
        }

        return $value;
    }

    public static function bool(mixed $value, string $field): bool
    {
        if (!\is_bool($value)) {
            throw InvalidPlanRequest::because(\sprintf('%s must be a boolean.', $field));
        }

        return $value;
    }

    /**
     * Strictly positive number converted to an integer scaled ×1000.
     */
    public static function positive(mixed $value, string $field, Rounding $rounding, int $maxScaled): int
    {
        $scaled = self::scaled($value, $field, $rounding);
        if ($scaled <= 0) {
            throw InvalidPlanRequest::because(\sprintf('%s must be a number greater than zero.', $field));
        }
        if ($scaled > $maxScaled) {
            throw InvalidPlanRequest::because(\sprintf('%s must not exceed %s.', $field, Decimal::format($maxScaled)));
        }

        return $scaled;
    }

    /**
     * Number greater than or equal to zero converted to an integer scaled ×1000.
     */
    public static function nonNegative(mixed $value, string $field, Rounding $rounding, int $maxScaled): int
    {
        $scaled = self::scaled($value, $field, $rounding);
        if ($scaled < 0) {
            throw InvalidPlanRequest::because(\sprintf('%s must be a number greater than or equal to zero.', $field));
        }
        if ($scaled > $maxScaled) {
            throw InvalidPlanRequest::because(\sprintf('%s must not exceed %s.', $field, Decimal::format($maxScaled)));
        }

        return $scaled;
    }

    private static function scaled(mixed $value, string $field, Rounding $rounding): int
    {
        if (!Decimal::isNumeric($value)) {
            throw InvalidPlanRequest::because(\sprintf('%s must be a number.', $field));
        }
        \assert(\is_int($value) || \is_float($value) || \is_string($value));
        try {
            $scaled = Decimal::toScaled($value, $rounding);
        } catch (\InvalidArgumentException) {
            throw InvalidPlanRequest::because(\sprintf('%s must be a finite number within range.', $field));
        }
        return $scaled;
    }
}
