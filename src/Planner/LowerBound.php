<?php

declare(strict_types=1);

namespace Palletwise\Planner;

use Palletwise\Model\Mode;
use Palletwise\Partition\ShipmentPartition;
use Palletwise\Profile\ResolvedProfile;
use Palletwise\Units\IntMath;
use Palletwise\Units\Rounding;

/**
 * Lower bound on the number of pallets: the maximum of the volume bound, the weight bound
 * and the number of mandatory units. Volume and weight bounds are summed per shipment
 * (each shipment needs its own pallets), which is still a valid bound.
 *
 * @internal
 */
final class LowerBound
{
    /**
     * @param list<ShipmentPartition> $shipments
     *
     * @return array{int, array{volume: int, weight: int, mandatory: int}}
     */
    public function compute(array $shipments, ResolvedProfile $profile, Mode $mode): array
    {
        $palletVolume = $profile->usableVolume($mode, Rounding::Ceil);
        $payload = $profile->payload($mode);
        $volumeBound = 0;
        $weightBound = 0;
        $mandatory = 0;

        foreach ($shipments as $shipment) {
            $volume = 0;
            $weight = 0;
            foreach ($shipment->allItems() as $item) {
                $volume = IntMath::add($volume, IntMath::volume($item->length, $item->width, $item->height, Rounding::Floor));
                $weight = IntMath::add($weight, $item->weight);
            }
            $volumeBound += IntMath::ceilDiv($volume, $palletVolume);
            $weightBound += IntMath::ceilDiv($weight, $payload);
            $mandatory += \count($shipment->mandatory);
        }

        $bounds = ['volume' => $volumeBound, 'weight' => $weightBound, 'mandatory' => $mandatory];

        return [max($bounds), $bounds];
    }
}
