<?php

declare(strict_types=1);

namespace Palletwise\Verifier;

/**
 * A placed box (axis-aligned prism) inside the Verifier.
 *
 * @internal
 */
final readonly class VerifierBox
{
    public function __construct(
        public VerifierUnit $unit,
        public int $x,
        public int $y,
        public int $z,
        public int $dx,
        public int $dy,
        public int $dz,
        public string $orientation,
    ) {}
}
