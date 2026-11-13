<?php

declare(strict_types=1);

namespace Palletwise\Planner;

use Palletwise\Model\Warning;
use Palletwise\Stacker\PalletBuilder;

/**
 * @internal
 */
final readonly class ShipmentPlan
{
    /**
     * @param list<PalletBuilder> $pallets
     * @param list<Warning>       $warnings
     */
    public function __construct(
        public array $pallets,
        public array $warnings,
    ) {}
}
