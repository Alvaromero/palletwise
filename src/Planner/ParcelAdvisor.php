<?php

declare(strict_types=1);

namespace Palletwise\Planner;

use Palletwise\Model\Mode;
use Palletwise\Model\Pallet;
use Palletwise\Model\Recommendation;
use Palletwise\Profile\ResolvedProfile;
use Palletwise\Units\IntMath;
use Palletwise\Units\Rounding;

/**
 * Recommends shipping as small parcel (for example, Amazon SPD) when pallets are poorly filled.
 *
 * Fill = volume of placed boxes ÷ (pallets × length × width × usable height).
 * If `parcelMinFill` is null (the profile default), nothing is recommended.
 *
 * @internal
 */
final class ParcelAdvisor
{
    /**
     * @param list<Pallet> $pallets with placements
     */
    public function advise(array $pallets, ResolvedProfile $profile, Mode $mode): ?Recommendation
    {
        if ($profile->parcelMinFill === null || $pallets === []) {
            return null;
        }
        $fill = self::fill($pallets, $profile, $mode);
        if ($fill >= $profile->parcelMinFill * 10) {
            return null;
        }

        return new Recommendation(Recommendation::TYPE_PARCEL, $fill, $profile->parcelMinFill, Recommendation::REASON_LOW_FILL);
    }

    /**
     * Volume fill in ten-thousandths.
     *
     * @param list<Pallet> $pallets
     */
    public static function fill(array $pallets, ResolvedProfile $profile, Mode $mode): int
    {
        $volume = 0;
        foreach ($pallets as $pallet) {
            foreach ($pallet->placements ?? [] as $p) {
                $volume = IntMath::add($volume, IntMath::volume($p->length, $p->width, $p->height));
            }
        }

        return $pallets === [] ? 0 : IntMath::ratio($volume, IntMath::mul(\count($pallets), $profile->usableVolume($mode, Rounding::Floor)), 4);
    }
}
