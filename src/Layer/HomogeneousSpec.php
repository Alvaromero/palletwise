<?php

declare(strict_types=1);

namespace Palletwise\Layer;

use Palletwise\Partition\Item;

/**
 * Layer plan for a group of identical boxes: pattern per layer, layers per pallet and
 * boxes per pallet (the minimum of the height limit and the weight limit).
 *
 * @internal
 */
final readonly class HomogeneousSpec
{
    /**
     * @param positive-int $perLayer
     * @param positive-int $capacity
     */
    public function __construct(
        public UpOption $option,
        public Pattern $pattern,
        public int $perLayer,
        public int $layersPerPallet,
        public int $capacity,
        private int $palletLength,
        private int $palletWidth,
    ) {}

    /**
     * Layer with the given boxes (at most perLayer). A partial layer takes the positions
     * closest to the pallet center.
     *
     * @param non-empty-list<Item> $items
     */
    public function layer(array $items, int $sequence): LayerPlan
    {
        \assert(\count($items) <= $this->perLayer);
        $rects = \count($items) === $this->perLayer
            ? $this->pattern->rects
            : $this->pattern->rectsByCenterDistance($this->palletLength, $this->palletWidth);
        $slots = [];
        foreach ($items as $i => $item) {
            $rect = $rects[$i];
            [$orientation, $dx, $dy] = $this->option->placed($rect->turned);
            $slots[] = new LayerSlot($item, $orientation, $rect->x, $rect->y, $dx, $dy, $this->option->dz);
        }

        return new LayerPlan($slots, $this->pattern->bounds(), $sequence, true);
    }
}
