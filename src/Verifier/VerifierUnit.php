<?php

declare(strict_types=1);

namespace Palletwise\Verifier;

use Palletwise\Model\Rotation;

/**
 * A physical box as rebuilt by the Verifier from the request.
 *
 * @internal
 */
final readonly class VerifierUnit
{
    public function __construct(
        public string $id,
        public string $shipmentId,
        public ?string $sku,
        public int $length,
        public int $width,
        public int $height,
        public int $weight,
        public Rotation $rotation,
        public ?string $setKey,
        public bool $fragile,
        public bool $mandatory,
    ) {}
}
