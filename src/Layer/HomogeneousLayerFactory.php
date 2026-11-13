<?php

declare(strict_types=1);

namespace Palletwise\Layer;

use Palletwise\Model\Mode;
use Palletwise\Partition\Item;
use Palletwise\Profile\ResolvedProfile;

/**
 * Chooses, for a group of identical boxes, the way to stand them and the layer pattern
 * that fits the most boxes per pallet.
 *
 * Criteria: most boxes per pallet; on a tie, lowest stack height; on a tie, the first
 * option in the fixed order of UpOption::for().
 *
 * @internal
 */
final class HomogeneousLayerFactory
{
    public function __construct(private readonly HomogeneousPatternFinder $finder = new HomogeneousPatternFinder()) {}

    public function spec(Item $sample, ResolvedProfile $profile, Mode $mode): ?HomogeneousSpec
    {
        $usableHeight = $profile->usableHeight($mode);
        $byWeight = intdiv($profile->payload($mode), $sample->weight);
        $best = null;
        $bestKey = null;

        foreach (UpOption::for($sample->rotation, $sample->length, $sample->width, $sample->height) as $option) {
            if ($option->dz > $usableHeight || !$option->fitsBase($profile->palletLength, $profile->palletWidth)) {
                continue;
            }
            $pattern = $this->finder->find($profile->palletLength, $profile->palletWidth, $option->dx, $option->dy, $option->canTurn);
            $perLayer = $pattern->count();
            if ($perLayer < 1) {
                continue;
            }
            // Fragile boxes can only go in the top layer: one layer per pallet.
            $layers = $sample->fragile ? 1 : intdiv($usableHeight, $option->dz);
            $capacity = min($perLayer * $layers, $byWeight);
            if ($capacity < 1) {
                continue;
            }
            $stackHeight = intdiv($capacity + $perLayer - 1, $perLayer) * $option->dz;
            $key = [-$capacity, $stackHeight];
            if ($bestKey === null || $key < $bestKey) {
                $bestKey = $key;
                $best = new HomogeneousSpec(
                    $option,
                    $pattern->centered($profile->palletLength, $profile->palletWidth),
                    $perLayer,
                    intdiv($capacity + $perLayer - 1, $perLayer),
                    $capacity,
                    $profile->palletLength,
                    $profile->palletWidth,
                );
            }
        }

        return $best;
    }

    /**
     * Standing option for a single box: the lowest one that fits; on a tie, the first.
     */
    public static function lowestOption(Item $item, ResolvedProfile $profile, Mode $mode): ?UpOption
    {
        $best = null;
        foreach (UpOption::for($item->rotation, $item->length, $item->width, $item->height) as $option) {
            if ($option->dz > $profile->usableHeight($mode) || !$option->fitsBase($profile->palletLength, $profile->palletWidth)) {
                continue;
            }
            if ($best === null || $option->dz < $best->dz) {
                $best = $option;
            }
        }

        return $best;
    }
}
