<?php

declare(strict_types=1);

namespace Palletwise\Partition;

use Palletwise\Model\Rotation;

/**
 * A physical box (one unit of an expanded line) with its rotation resolved.
 *
 * @internal
 */
final readonly class Item
{
    public function __construct(
        public string $id,
        public string $lineId,
        public string $shipmentId,
        /** The line's SKU, or a stand-in derived from dimensions and weight when it has none. */
        public string $sku,
        public int $length,
        public int $width,
        public int $height,
        public int $weight,
        public Rotation $rotation,
        public ?string $setId,
        public bool $fragile,
        /** Global position in the input expansion; the engine's final tie-breaker. */
        public int $ordinal,
    ) {}

    /**
     * Identical boxes: same SKU, dimensions, weight, rotation and fragility.
     */
    public function groupKey(): string
    {
        return implode("\0", [$this->sku, $this->length, $this->width, $this->height, $this->weight, $this->rotation->value, $this->fragile ? '1' : '0']);
    }
}
