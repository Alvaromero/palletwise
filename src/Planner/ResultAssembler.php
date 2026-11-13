<?php

declare(strict_types=1);

namespace Palletwise\Planner;

use Palletwise\Model\Mode;
use Palletwise\Model\Pallet;
use Palletwise\Model\PalletType;
use Palletwise\Model\Placement;
use Palletwise\Profile\ResolvedProfile;
use Palletwise\Stacker\PalletBuilder;
use Palletwise\Stacker\PlacedBox;
use Palletwise\Units\IntMath;
use Palletwise\Units\Rounding;

/**
 * Converts pallets under construction into result pallets.
 *
 * @internal
 */
final class ResultAssembler
{
    public function pallet(PalletBuilder $builder, int $index, ResolvedProfile $profile, Mode $mode): Pallet
    {
        $boxes = $builder->boxes();
        usort($boxes, static fn(PlacedBox $a, PlacedBox $b): int => [$a->z, $a->y, $a->x, $a->item->ordinal] <=> [$b->z, $b->y, $b->x, $b->item->ordinal]);

        $volume = 0;
        $skus = [];
        $placements = [];
        foreach ($boxes as $box) {
            $volume = IntMath::add($volume, IntMath::volume($box->dx, $box->dy, $box->dz));
            $skus[$box->item->sku] = true;
            $placements[] = new Placement($box->item->id, $box->x, $box->y, $box->z, $box->dx, $box->dy, $box->dz, $box->orientation);
        }

        $type = match (true) {
            $builder->kind === PalletType::Mandatory => PalletType::Mandatory,
            \count($skus) === 1 => PalletType::SingleSku,
            default => PalletType::Mixed,
        };

        return new Pallet(
            $index,
            $builder->shipmentId,
            $type,
            $profile->tareHeight + $builder->height(),
            $profile->tareWeight + $builder->weight(),
            array_map(static fn(Placement $p): string => $p->boxId, $placements),
            IntMath::ratio($volume, $profile->usableVolume($mode, Rounding::Floor), 4),
            $builder->layers(),
            $placements,
        );
    }
}
