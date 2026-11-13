<?php

declare(strict_types=1);

namespace Palletwise\Model;

use Palletwise\Units\Decimal;

/**
 * A pallet layer: level of its base above the pallet deck, height (that of its tallest box)
 * and covered area (scaled ×10^6, i.e. in millionths of unit²).
 */
final readonly class Layer
{
    public function __construct(
        public int $z,
        public int $height,
        public int $coveredArea,
        public int $boxCount,
    ) {}

    /**
     * @return array<string, int|float>
     */
    public function toArray(): array
    {
        return [
            'z' => Decimal::toFloat($this->z),
            'height' => Decimal::toFloat($this->height),
            'coveredArea' => $this->coveredArea / 1_000_000,
            'boxCount' => $this->boxCount,
        ];
    }
}
