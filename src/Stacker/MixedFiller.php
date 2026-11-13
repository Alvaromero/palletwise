<?php

declare(strict_types=1);

namespace Palletwise\Stacker;

use Palletwise\Layer\MixedEntry;
use Palletwise\Layer\MixedLayerBuilder;
use Palletwise\Profile\ResolvedProfile;

/**
 * Places the loose boxes layer by layer on the existing pallets, in order, and opens new
 * pallets when no layer fits on the current one. Each layer is built on the actual
 * surface of the pallet, so it always meets the minimum support.
 *
 * @internal
 */
final class MixedFiller
{
    public function __construct(private readonly MixedLayerBuilder $builder = new MixedLayerBuilder()) {}

    /**
     * @param list<PalletBuilder>       $pallets   pallets that may be topped up (they are modified)
     * @param list<MixedEntry>          $entries
     * @param callable(): PalletBuilder $newPallet
     *
     * @return list<PalletBuilder> the same pallets plus the new ones, in order
     */
    public function fill(array $pallets, array $entries, callable $newPallet, ResolvedProfile $profile, bool $fillWithShorter, Sequence $sequence): array
    {
        $index = 0;
        while ($entries !== []) {
            if ($index >= \count($pallets)) {
                $pallets[] = $newPallet();
            }
            $pallet = $pallets[$index];
            $result = $this->builder->layerOn($pallet, $entries, $profile, $fillWithShorter, $sequence->next());
            if ($result === null) {
                if ($pallet->isEmpty()) {
                    throw new \LogicException('No box fits on an empty pallet.');
                }
                ++$index;
                continue;
            }
            [$layer, $used] = $result;
            if (!$pallet->tryPlace($layer)) {
                throw new \LogicException('A layer built on the pallet surface could not be placed.');
            }
            $entries = array_values(array_diff_key($entries, $used));
        }

        return $pallets;
    }
}
