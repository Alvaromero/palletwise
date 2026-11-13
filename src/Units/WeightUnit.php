<?php

declare(strict_types=1);

namespace Palletwise\Units;

enum WeightUnit: string
{
    case Pound = 'lb';
    case Kilogram = 'kg';

    /**
     * Exact size of the unit in multiples of 1e-8 kg (1 lb = exactly 0.45359237 kg).
     */
    public function factor(): int
    {
        return match ($this) {
            self::Pound => 45_359_237,
            self::Kilogram => 100_000_000,
        };
    }
}
