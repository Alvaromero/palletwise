<?php

declare(strict_types=1);

namespace Palletwise\Tests\Support;

/**
 * Modifies nested arrays by "a.b.0.c" path to prepare test cases.
 */
final class ArrayPath
{
    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    public static function set(array $data, string $path, mixed $value): array
    {
        return self::apply($data, explode('.', $path), $value, false);
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    public static function remove(array $data, string $path): array
    {
        return self::apply($data, explode('.', $path), null, true);
    }

    /**
     * @param array<mixed> $data
     * @param list<string> $segments
     *
     * @return array<mixed>
     */
    private static function apply(array $data, array $segments, mixed $value, bool $remove): array
    {
        $key = array_shift($segments);
        \assert($key !== null);
        $key = ctype_digit($key) ? (int) $key : $key;
        if ($segments === []) {
            if ($remove) {
                unset($data[$key]);
            } else {
                $data[$key] = $value;
            }

            return $data;
        }
        $child = $data[$key] ?? [];
        \assert(\is_array($child));
        $data[$key] = self::apply($child, $segments, $value, $remove);

        return $data;
    }
}
