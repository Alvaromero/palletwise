<?php

declare(strict_types=1);

namespace Palletwise\Planner;

use Palletwise\Model\Mode;
use Palletwise\Model\ModeResult;
use Palletwise\Model\PlanRequest;
use Palletwise\Model\Warning;
use Palletwise\Model\WarningCode;
use Palletwise\Partition\Partitioner;
use Palletwise\Profile\ResolvedProfile;
use Palletwise\Validation\BoxValidator;

/**
 * Computes the result for one mode, with placements.
 *
 * @internal
 */
final class ModePlanner
{
    public function __construct(
        private readonly ShipmentPlanner $shipments = new ShipmentPlanner(),
        private readonly BoxValidator $validator = new BoxValidator(),
        private readonly Partitioner $partitioner = new Partitioner(),
        private readonly ResultAssembler $assembler = new ResultAssembler(),
        private readonly LowerBound $lowerBound = new LowerBound(),
        private readonly ParcelAdvisor $parcel = new ParcelAdvisor(),
    ) {}

    public function plan(PlanRequest $request, ResolvedProfile $profile, Mode $mode): ModeResult
    {
        $ctx = new PlanningContext($profile, $mode);
        $validation = $this->validator->validate($request, $profile, $mode);
        $partitions = $this->partitioner->partition($request->boxes, $validation->placeableLines, $profile);

        $warnings = $validation->warnings;
        $pallets = [];
        foreach ($partitions as $shipment) {
            $plan = $this->shipments->plan($shipment, $ctx);
            foreach ($plan->pallets as $builder) {
                $pallets[] = $this->assembler->pallet($builder, \count($pallets) + 1, $profile, $mode);
            }
            array_push($warnings, ...$plan->warnings);
        }

        $unconfirmed = $profile->profile->unconfirmedFor($mode);
        if ($unconfirmed !== []) {
            $warnings[] = new Warning(
                WarningCode::UnconfirmedProfileValues,
                'This result depends on profile values that are not confirmed by an official source.',
                details: ['profile' => $profile->profile->key(), 'paths' => $unconfirmed],
            );
        }

        [$bound, $bounds] = $this->lowerBound->compute($partitions, $profile, $mode);

        $recommendation = $this->parcel->advise($pallets, $profile, $mode);

        return new ModeResult($mode, $bound, $bounds, $pallets, $validation->unplaced, $warnings, $recommendation);
    }
}
