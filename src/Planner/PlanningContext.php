<?php

declare(strict_types=1);

namespace Palletwise\Planner;

use Palletwise\Model\Mode;
use Palletwise\Model\PalletType;
use Palletwise\Profile\ResolvedProfile;
use Palletwise\Stacker\PalletBuilder;
use Palletwise\Stacker\Sequence;

/**
 * Resolved profile, mode and layer counter for one calculation.
 *
 * @internal
 */
final class PlanningContext
{
    public readonly Sequence $sequence;

    public function __construct(
        public readonly ResolvedProfile $profile,
        public readonly Mode $mode,
    ) {
        $this->sequence = new Sequence();
    }

    public function newPallet(string $shipmentId, PalletType $kind = PalletType::Mixed): PalletBuilder
    {
        return new PalletBuilder(
            $shipmentId,
            $kind,
            $this->profile->palletLength,
            $this->profile->palletWidth,
            $this->profile->usableHeight($this->mode),
            $this->profile->payload($this->mode),
            $this->profile->minSupport,
            $this->profile->bandTolerance,
        );
    }
}
