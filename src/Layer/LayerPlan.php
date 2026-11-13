<?php

declare(strict_types=1);

namespace Palletwise\Layer;

use Palletwise\Units\IntMath;

/**
 * A layer laid out in plan view, not yet assigned a z level.
 *
 * @internal
 */
final readonly class LayerPlan
{
    public int $height;
    public int $weight;
    public bool $fragile;
    public int $coveredArea;

    /** Area of the boxes that reach the layer height (usable surface to support boxes above). */
    public int $flatArea;

    /**
     * @param non-empty-list<LayerSlot> $slots
     * @param array{int, int, int, int} $frame symmetry frame [x0, y0, x1, y1]
     * @param int                       $sequence creation order (tie-breaker)
     */
    public function __construct(
        public array $slots,
        public array $frame,
        public int $sequence,
        public bool $homogeneous,
    ) {
        $height = 0;
        $weight = 0;
        $fragile = false;
        $covered = 0;
        foreach ($slots as $slot) {
            $height = max($height, $slot->dz);
            $weight += $slot->item->weight;
            $fragile = $fragile || $slot->item->fragile;
            $covered = IntMath::add($covered, IntMath::mul($slot->dx, $slot->dy));
        }
        $flat = 0;
        foreach ($slots as $slot) {
            if ($slot->dz === $height) {
                $flat += $slot->dx * $slot->dy;
            }
        }
        $this->height = $height;
        $this->weight = $weight;
        $this->fragile = $fragile;
        $this->coveredArea = $covered;
        $this->flatArea = $flat;
    }

    /**
     * Plan positions after applying a symmetry, in the same order as $slots.
     *
     * @return list<array{int, int}>
     */
    public function positions(Transform $transform, int $offsetX = 0, int $offsetY = 0): array
    {
        return array_map(
            function (LayerSlot $s) use ($transform, $offsetX, $offsetY): array {
                [$x, $y] = $transform->apply($s->x, $s->y, $s->dx, $s->dy, $this->frame);

                return [$x + $offsetX, $y + $offsetY];
            },
            $this->slots,
        );
    }

    /**
     * Candidate offsets for placing the layer: as is, and with its frame aligned to each
     * corner and each edge of the pallet. No duplicates, in a fixed order.
     *
     * @return list<array{int, int}>
     */
    public function offsets(int $palletLength, int $palletWidth): array
    {
        [$x0, $y0, $x1, $y1] = $this->frame;
        $left = -$x0;
        $right = $palletLength - $x1;
        $bottom = -$y0;
        $top = $palletWidth - $y1;
        $candidates = [[0, 0], [$left, $bottom], [$right, $bottom], [$left, $top], [$right, $top], [0, $bottom], [0, $top], [$left, 0], [$right, 0]];
        $out = [];
        foreach ($candidates as $c) {
            $out[$c[0] . ':' . $c[1]] = $c;
        }

        return array_values($out);
    }

    /**
     * Geometric signature of the transformed layer (independent of order and of which box goes where).
     */
    public function signature(Transform $transform, int $offsetX = 0, int $offsetY = 0): string
    {
        $keys = [];
        foreach ($this->positions($transform, $offsetX, $offsetY) as $i => [$x, $y]) {
            $s = $this->slots[$i];
            $keys[] = $x . ',' . $y . ',' . $s->dx . ',' . $s->dy . ',' . $s->dz;
        }
        sort($keys, SORT_STRING);

        return implode(';', $keys);
    }
}
