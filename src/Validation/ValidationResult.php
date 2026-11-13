<?php

declare(strict_types=1);

namespace Palletwise\Validation;

use Palletwise\Model\Unplaced;
use Palletwise\Model\Warning;

/**
 * @internal
 */
final readonly class ValidationResult
{
    /**
     * @param array<string, true> $placeableLines ids of the lines that can be placed
     * @param list<Unplaced>      $unplaced
     * @param list<Warning>       $warnings
     */
    public function __construct(
        public array $placeableLines,
        public array $unplaced,
        public array $warnings,
    ) {}
}
