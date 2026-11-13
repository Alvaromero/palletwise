<?php

declare(strict_types=1);

namespace Palletwise\Stacker;

use Palletwise\Layer\LayerPlan;

/**
 * Stacks prebuilt layers (the leftover homogeneous ones) onto pallets and compacts the result.
 *
 * Layer order: fragile ones last (they can only go on top); before them, the "full" ones
 * (flat surface of at least the minimum support of the base), then the heaviest, with most
 * coverage, tallest and, finally, in creation order. The given open pallets are topped up
 * first and then new pallets are opened, one at a time: for each pallet the remaining
 * layers are walked in that order and every one that fits is placed (height, weight and
 * support, trying symmetries and offsets).
 *
 * @internal
 */
final class Stacker
{
    /**
     * @param list<LayerPlan>           $layers
     * @param callable(): PalletBuilder $newPallet
     * @param list<PalletBuilder>       $open      pallets already started that are topped up first (they are modified)
     *
     * @return list<PalletBuilder> only the new pallets
     */
    public function stack(array $layers, callable $newPallet, int $palletArea, int $minSupport, array $open = []): array
    {
        $keyed = [];
        foreach ($layers as $layer) {
            $full = $layer->flatArea * 1000 >= $minSupport * $palletArea;
            $keyed[] = [$layer, [$layer->fragile ? 1 : 0, $full ? 0 : 1, -$layer->weight, -$layer->coveredArea, -$layer->height, $layer->sequence]];
        }
        usort($keyed, static fn(array $a, array $b): int => $a[1] <=> $b[1]);
        $remaining = array_map(static fn(array $k): LayerPlan => $k[0], $keyed);

        foreach ($open as $pallet) {
            $left = [];
            foreach ($remaining as $layer) {
                if (!$pallet->tryPlaceAnywhere($layer)) {
                    $left[] = $layer;
                }
            }
            $remaining = $left;
        }

        $pallets = [];
        while ($remaining !== []) {
            $pallet = $newPallet();
            $left = [];
            foreach ($remaining as $layer) {
                if (!$pallet->tryPlaceAnywhere($layer)) {
                    $left[] = $layer;
                }
            }
            if ($pallet->isEmpty()) {
                throw new \LogicException('A layer does not fit on an empty pallet.');
            }
            $pallets[] = $pallet;
            $remaining = $left;
        }

        return $pallets;
    }

    /**
     * Tries to merge pairs of pallets by restacking their layers onto one. Pairs whose
     * combined height and weight fit are tried, from lowest to highest combined height
     * (tie-break by position), and this repeats while some merge succeeds. The merged
     * pallet takes the place of the first one in the pair.
     *
     * @param list<PalletBuilder> $pallets
     *
     * @return list<PalletBuilder>
     */
    public function compact(array $pallets, int $usableHeight, int $payload): array
    {
        do {
            $merged = false;
            $pairs = [];
            $n = \count($pallets);
            for ($i = 0; $i < $n; ++$i) {
                for ($j = $i + 1; $j < $n; ++$j) {
                    $a = $pallets[$i];
                    $b = $pallets[$j];
                    if ($a->height() + $b->height() <= $usableHeight && $a->weight() + $b->weight() <= $payload) {
                        $pairs[] = [$a->height() + $b->height(), $i, $j];
                    }
                }
            }
            sort($pairs);
            foreach ($pairs as [, $i, $j]) {
                $combined = $this->restack($pallets[$i], $pallets[$j]);
                if ($combined !== null) {
                    $pallets[$i] = $combined;
                    unset($pallets[$j]);
                    $pallets = array_values($pallets);
                    $merged = true;
                    break;
                }
            }
        } while ($merged);

        return $pallets;
    }

    private function restack(PalletBuilder $a, PalletBuilder $b): ?PalletBuilder
    {
        $layers = [...$a->layerPlans(), ...$b->layerPlans()];
        $orders = [
            static fn(LayerPlan $l): array => [$l->fragile ? 1 : 0, -$l->flatArea, -$l->weight, $l->sequence],
            static fn(LayerPlan $l): array => [$l->fragile ? 1 : 0, -$l->coveredArea, -$l->weight, $l->sequence],
            static fn(LayerPlan $l): array => [$l->fragile ? 1 : 0, -$l->weight, -$l->flatArea, $l->sequence],
        ];
        foreach ($orders as $key) {
            usort($layers, static fn(LayerPlan $x, LayerPlan $y): int => $key($x) <=> $key($y));
            $pallet = $a->emptyCopy();
            $ok = true;
            foreach ($layers as $layer) {
                if (!$pallet->tryPlaceAnywhere($layer)) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return $pallet;
            }
        }

        return null;
    }
}
