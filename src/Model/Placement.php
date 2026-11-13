<?php

declare(strict_types=1);

namespace Palletwise\Model;

use Palletwise\Units\Decimal;

/**
 * Position of a box on the pallet. Origin at the bottom corner of the pallet deck
 * surface: x along the pallet length, y along the pallet width, z upwards.
 * Values are integers scaled ×1000 in the request unit.
 */
final readonly class Placement
{
    public function __construct(
        public string $boxId,
        public int $x,
        public int $y,
        public int $z,
        public int $length,
        public int $width,
        public int $height,
        public Orientation $orientation,
    ) {}

    /**
     * @return array<string, string|float>
     */
    public function toArray(): array
    {
        return [
            'boxId' => $this->boxId,
            'x' => Decimal::toFloat($this->x),
            'y' => Decimal::toFloat($this->y),
            'z' => Decimal::toFloat($this->z),
            'length' => Decimal::toFloat($this->length),
            'width' => Decimal::toFloat($this->width),
            'height' => Decimal::toFloat($this->height),
            'orientation' => $this->orientation->value,
        ];
    }
}
