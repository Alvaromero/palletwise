<?php

declare(strict_types=1);

namespace Palletwise\Layer;

use Palletwise\Model\Mode;
use Palletwise\Partition\Item;
use Palletwise\Profile\ResolvedProfile;
use Palletwise\Units\IntMath;

/**
 * Builds mixed layers with MaxRects directly on the surface of a pallet.
 *
 * Each box stands on its lowest possible face. For the next layer, height bands are
 * tried from tallest to shortest (a band is the set of boxes whose height is within the
 * tolerance of the tallest box in the band):
 *  1. the band's boxes are placed in the requested order, with both plan orientations,
 *     and only at positions where the box has the minimum support on the surface;
 *  2. if the band runs out and there is room left, it is optionally filled with shorter boxes.
 * Each band is built with both MaxRects rules (Best Short Side Fit and Bottom-Left).
 * The winning layer is the one that best fills its slab (box volume ÷ pallet area × layer
 * height); on a tie, the tallest and then the first rule. Band search stops when a layer fills at
 * least GOOD_FILL or after MAX_BANDS bands that produced a possible layer.
 *
 * The layer height is that of its tallest box and its weight does not exceed what the
 * pallet has left. The first layer of a pallet is centered on it.
 *
 * @internal
 */
final class MixedLayerBuilder
{
    public const MAX_BANDS = 6;

    /** Fill (in thousandths) above which no more bands are tried. */
    public const GOOD_FILL = 850;

    /**
     * Prepares and sorts the boxes according to the search criterion.
     *
     * @param list<Item> $items
     *
     * @return list<MixedEntry>
     */
    public function entries(array $items, ResolvedProfile $profile, Mode $mode, OrderKey $order): array
    {
        $keyed = [];
        foreach ($items as $item) {
            $option = HomogeneousLayerFactory::lowestOption($item, $profile, $mode);
            if ($option === null) {
                throw new \LogicException('Validated box has no feasible orientation.');
            }
            $metrics = [
                'volume' => IntMath::mul(IntMath::mul($option->dx, $option->dy), $option->dz),
                'weight' => $item->weight,
                'base_area' => IntMath::mul($option->dx, $option->dy),
                'height' => $option->dz,
            ];
            $keyed[] = [new MixedEntry($item, $option), $order->sortKey($metrics, $item->ordinal)];
        }
        usort($keyed, static fn(array $a, array $b): int => $a[1] <=> $b[1]);

        return array_map(static fn(array $k): MixedEntry => $k[0], $keyed);
    }

    /**
     * Best layer on the surface, or null if no box fits.
     *
     * @param list<MixedEntry> $entries
     *
     * @return array{LayerPlan, array<int, true>}|null the layer and the indices of $entries it uses
     */
    public function layerOn(SupportSurface $surface, array $entries, ResolvedProfile $profile, bool $fillWithShorter, int $sequence): ?array
    {
        if (!$surface->isOpen()) {
            return null;
        }
        $maxHeight = $surface->remainingHeight();
        $payload = $surface->remainingPayload();
        $feasible = [];
        foreach ($entries as $i => $entry) {
            if ($entry->option->dz <= $maxHeight && $entry->item->weight <= $payload) {
                $feasible[$i] = $entry;
            }
        }
        if ($feasible === []) {
            return null;
        }

        $heights = array_map(static fn(MixedEntry $e): int => $e->option->dz, $feasible);
        rsort($heights);
        $tops = [];
        foreach ($heights as $h) {
            if ($tops === [] || $h < end($tops) - $profile->bandTolerance) {
                $tops[] = $h;
            }
        }

        $accept = $surface->height() === 0 ? null : $surface->isSupported(...);
        $best = null;
        $bestKey = null;
        $tried = 0;
        foreach ($tops as $top) {
            $tried += $this->tryBand($top, $feasible, $profile, $fillWithShorter, $payload, $accept, $best, $bestKey);
            if ($bestKey !== null && (-$bestKey[0] >= self::GOOD_FILL || $tried >= self::MAX_BANDS)) {
                break;
            }
        }
        if ($best === null) {
            return null;
        }
        [$slots, $placed] = $best;

        $layer = $surface->height() === 0
            ? self::centered($slots, $profile->palletLength, $profile->palletWidth, $sequence)
            : new LayerPlan($slots, self::bounds($slots), $sequence, false);

        return [$layer, $placed];
    }

    /**
     * Builds the band with each rule and updates the best layer. Returns 1 if any rule
     * placed something.
     *
     * @param array<int, MixedEntry>                              $feasible
     * @param (callable(int, int, int, int): bool)|null            $accept
     * @param array{non-empty-list<LayerSlot>, array<int, true>}|null $best
     * @param list<int>|null                                       $bestKey
     *
     * @param-out array{non-empty-list<LayerSlot>, array<int, true>}|null $best
     * @param-out list<int>|null $bestKey
     */
    private function tryBand(int $top, array $feasible, ResolvedProfile $profile, bool $fillWithShorter, int $payload, ?callable $accept, ?array &$best, ?array &$bestKey): int
    {
        $any = 0;
        foreach (MaxRectsRule::cases() as $ruleIndex => $rule) {
            $floor = $top - $profile->bandTolerance;
            $packer = new MaxRectsPacker($profile->palletLength, $profile->palletWidth, $rule);
            $slots = [];
            $weight = 0;
            $placed = [];
            $bandLeft = false;
            foreach ($feasible as $i => $entry) {
                $dz = $entry->option->dz;
                if ($dz > $top || $dz < $floor) {
                    continue;
                }
                if ($this->tryAdd($entry, $packer, $slots, $weight, $payload, $accept)) {
                    $placed[$i] = true;
                } else {
                    $bandLeft = true;
                }
            }
            if ($fillWithShorter && !$bandLeft) {
                foreach ($feasible as $i => $entry) {
                    if ($entry->option->dz < $floor && $packer->freeArea() > 0
                        && $this->tryAdd($entry, $packer, $slots, $weight, $payload, $accept)) {
                        $placed[$i] = true;
                    }
                }
            }
            if ($slots === []) {
                continue;
            }
            $any = 1;
            $height = max(array_map(static fn(LayerSlot $s): int => $s->dz, $slots));
            $volume = 0;
            foreach ($slots as $s) {
                $volume = IntMath::add($volume, IntMath::volume($s->dx, $s->dy, $s->dz));
            }
            $fill = IntMath::ratio($volume, max(1, IntMath::volume($profile->palletLength, $profile->palletWidth, $height)), 3);
            $key = [-$fill, -$height, $ruleIndex];
            if ($bestKey === null || $key < $bestKey) {
                $bestKey = $key;
                $best = [$slots, $placed];
            }
        }

        return $any;
    }

    /**
     * @param list<LayerSlot>                           $slots
     * @param (callable(int, int, int, int): bool)|null $accept
     */
    private function tryAdd(MixedEntry $entry, MaxRectsPacker $packer, array &$slots, int &$weight, int $payload, ?callable $accept): bool
    {
        $item = $entry->item;
        $option = $entry->option;
        if ($weight + $item->weight > $payload) {
            return false;
        }
        $position = $packer->find($option->dx, $option->dy, $option->canTurn, $accept);
        if ($position === null) {
            return false;
        }
        [$x, $y, $turned] = $position;
        [$orientation, $dx, $dy] = $option->placed($turned);
        $packer->place($x, $y, $dx, $dy);
        $slots[] = new LayerSlot($item, $orientation, $x, $y, $dx, $dy, $option->dz);
        $weight += $item->weight;

        return true;
    }

    /**
     * @param non-empty-list<LayerSlot> $slots
     *
     * @return array{int, int, int, int}
     */
    private static function bounds(array $slots): array
    {
        $x0 = $y0 = PHP_INT_MAX;
        $x1 = $y1 = 0;
        foreach ($slots as $s) {
            $x0 = min($x0, $s->x);
            $y0 = min($y0, $s->y);
            $x1 = max($x1, $s->x + $s->dx);
            $y1 = max($y1, $s->y + $s->dy);
        }

        return [$x0, $y0, $x1, $y1];
    }

    /**
     * @param non-empty-list<LayerSlot> $slots
     */
    private static function centered(array $slots, int $palletLength, int $palletWidth, int $sequence): LayerPlan
    {
        [$x0, $y0, $x1, $y1] = self::bounds($slots);
        $shiftX = intdiv($palletLength - ($x1 - $x0), 2) - $x0;
        $shiftY = intdiv($palletWidth - ($y1 - $y0), 2) - $y0;
        $moved = array_map(
            static fn(LayerSlot $s): LayerSlot => new LayerSlot($s->item, $s->orientation, $s->x + $shiftX, $s->y + $shiftY, $s->dx, $s->dy, $s->dz),
            $slots,
        );

        return new LayerPlan($moved, [$x0 + $shiftX, $y0 + $shiftY, $x1 + $shiftX, $y1 + $shiftY], $sequence, false);
    }
}
