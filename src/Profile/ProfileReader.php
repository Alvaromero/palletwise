<?php

declare(strict_types=1);

namespace Palletwise\Profile;

use Palletwise\Exception\InvalidProfile;
use Palletwise\Units\Decimal;
use Palletwise\Units\Rounding;

/**
 * Typed reading of profile data by "a.b.c" paths.
 *
 * @internal
 */
final class ProfileReader
{
    /**
     * @param array<mixed> $data
     */
    public function __construct(
        private readonly string $key,
        private readonly array $data,
    ) {}

    public function has(string $path): bool
    {
        return $this->lookup($path, $found) !== null || $found;
    }

    public function isNull(string $path): bool
    {
        $value = $this->lookup($path, $found);
        if (!$found) {
            throw InvalidProfile::invalid($this->key, \sprintf('%s is required.', $path));
        }

        return $value === null;
    }

    /**
     * @param list<string> $allowed
     */
    public function keys(string $path, array $allowed): void
    {
        $value = $path === '' ? $this->data : $this->lookup($path, $found);
        if (!\is_array($value) || ($value !== [] && array_is_list($value))) {
            throw InvalidProfile::invalid($this->key, \sprintf('%s must be an object.', $path === '' ? 'root' : $path));
        }
        foreach (array_keys($value) as $k) {
            if (!\in_array($k, $allowed, true)) {
                throw InvalidProfile::invalid($this->key, \sprintf('unknown field "%s".', ltrim($path . '.' . $k, '.')));
            }
        }
    }

    public function string(string $path): string
    {
        $value = $this->lookup($path, $found);
        if (!\is_string($value) || $value === '') {
            throw InvalidProfile::invalid($this->key, \sprintf('%s must be a non-empty string.', $path));
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    public function stringList(string $path): array
    {
        $value = $this->lookup($path, $found);
        if (!\is_array($value) || !array_is_list($value)) {
            throw InvalidProfile::invalid($this->key, \sprintf('%s must be a list of strings.', $path));
        }
        $out = [];
        foreach ($value as $item) {
            if (!\is_string($item)) {
                throw InvalidProfile::invalid($this->key, \sprintf('%s must be a list of strings.', $path));
            }
            $out[] = $item;
        }

        return $out;
    }

    public function positive(string $path, Rounding $rounding): int
    {
        $value = $this->number($path, $rounding);
        if ($value <= 0) {
            throw InvalidProfile::invalid($this->key, \sprintf('%s must be greater than zero.', $path));
        }

        return $value;
    }

    public function nonNegative(string $path, Rounding $rounding): int
    {
        $value = $this->number($path, $rounding);
        if ($value < 0) {
            throw InvalidProfile::invalid($this->key, \sprintf('%s must be zero or greater.', $path));
        }

        return $value;
    }

    private function number(string $path, Rounding $rounding): int
    {
        $value = $this->lookup($path, $found);
        if (!\is_int($value) && !\is_float($value)) {
            throw InvalidProfile::invalid($this->key, \sprintf('%s must be a number.', $path));
        }
        try {
            $scaled = Decimal::toScaled($value, $rounding);
        } catch (\InvalidArgumentException) {
            throw InvalidProfile::invalid($this->key, \sprintf('%s is out of range.', $path));
        }
        if ($scaled > 1_000_000_000) {
            throw InvalidProfile::invalid($this->key, \sprintf('%s is out of range.', $path));
        }

        return $scaled;
    }

    /**
     * @param-out bool $found
     */
    private function lookup(string $path, ?bool &$found = null): mixed
    {
        $found = false;
        $current = $this->data;
        foreach (explode('.', $path) as $segment) {
            if (!\is_array($current) || !\array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }
        $found = true;

        return $current;
    }
}
