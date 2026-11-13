<?php

declare(strict_types=1);

namespace Palletwise\Support;

/**
 * Deterministic JSON encoder. Floats are written with at most 6 decimals using
 * number_format, so the output does not depend on `serialize_precision`.
 *
 * @internal
 */
final class Json
{
    private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    private function __construct() {}

    public static function encode(mixed $value): string
    {
        if ($value === null || \is_bool($value) || \is_int($value) || \is_string($value)) {
            return json_encode($value, self::FLAGS);
        }
        if (\is_float($value)) {
            return self::formatFloat($value);
        }
        if (\is_array($value)) {
            if (array_is_list($value)) {
                return '[' . implode(',', array_map(self::encode(...), $value)) . ']';
            }
            $parts = [];
            foreach ($value as $key => $item) {
                $parts[] = json_encode((string) $key, self::FLAGS) . ':' . self::encode($item);
            }

            return '{' . implode(',', $parts) . '}';
        }

        throw new \InvalidArgumentException('Unsupported value in JSON output.');
    }

    /**
     * Recursively sorts map keys (lists keep their order).
     *
     * @param array<mixed> $value
     *
     * @return array<mixed>
     */
    public static function sortKeys(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            if (\is_array($item)) {
                $value[$key] = self::sortKeys($item);
            }
        }

        return $value;
    }

    private static function formatFloat(float $value): string
    {
        if (!is_finite($value)) {
            throw new \InvalidArgumentException('Non-finite float in JSON output.');
        }
        $text = number_format($value, 6, '.', '');
        if (str_contains($text, '.')) {
            $text = rtrim(rtrim($text, '0'), '.');
        }

        return $text === '-0' ? '0' : $text;
    }
}
