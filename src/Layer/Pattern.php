<?php

declare(strict_types=1);

namespace Palletwise\Layer;

/**
 * 2D pattern of a homogeneous layer. Positions are sorted by (y, x).
 *
 * @internal
 */
final readonly class Pattern
{
    /** @var list<Rect> */
    public array $rects;

    /**
     * @param list<Rect> $rects
     */
    public function __construct(array $rects, public string $kind)
    {
        usort($rects, static fn(Rect $a, Rect $b): int => [$a->y, $a->x] <=> [$b->y, $b->x]);
        $this->rects = $rects;
    }

    public function count(): int
    {
        return \count($this->rects);
    }

    /**
     * The same pattern with its bounding box centered on the pallet (integer offset rounded down).
     */
    public function centered(int $palletLength, int $palletWidth): self
    {
        if ($this->rects === []) {
            return $this;
        }
        [$x0, $y0, $x1, $y1] = $this->bounds();
        $shiftX = intdiv($palletLength - ($x1 - $x0), 2) - $x0;
        $shiftY = intdiv($palletWidth - ($y1 - $y0), 2) - $y0;

        return new self(array_map(
            static fn(Rect $r): Rect => new Rect($r->x + $shiftX, $r->y + $shiftY, $r->dx, $r->dy, $r->turned),
            $this->rects,
        ), $this->kind);
    }

    /**
     * Bounding box [x0, y0, x1, y1].
     *
     * @return array{int, int, int, int}
     */
    public function bounds(): array
    {
        $x0 = $y0 = PHP_INT_MAX;
        $x1 = $y1 = PHP_INT_MIN;
        foreach ($this->rects as $r) {
            $x0 = min($x0, $r->x);
            $y0 = min($y0, $r->y);
            $x1 = max($x1, $r->x + $r->dx);
            $y1 = max($y1, $r->y + $r->dy);
        }

        return [$x0, $y0, $x1, $y1];
    }

    /**
     * Positions sorted from closest to farthest from the pallet center
     * (Manhattan distance between centers; tie-break by y, x). Used for partial layers,
     * so the center of gravity stays as centered as possible.
     *
     * @return list<Rect>
     */
    public function rectsByCenterDistance(int $palletLength, int $palletWidth): array
    {
        $rects = $this->rects;
        $distance = static fn(Rect $r): int => abs(2 * $r->x + $r->dx - $palletLength) + abs(2 * $r->y + $r->dy - $palletWidth);
        usort($rects, static fn(Rect $a, Rect $b): int => [$distance($a), $a->y, $a->x] <=> [$distance($b), $b->y, $b->x]);

        return $rects;
    }
}
