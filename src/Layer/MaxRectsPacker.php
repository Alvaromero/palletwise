<?php

declare(strict_types=1);

namespace Palletwise\Layer;

/**
 * MaxRects 2D packing with the Best Short Side Fit or Bottom-Left rule.
 *
 * Fixed tie-break for a placement: the rule's key, then lower y, lower x, unturned
 * before turned and, when there is an accept check, the corner index.
 *
 * @internal
 */
final class MaxRectsPacker
{
    /** @var list<array{int, int, int, int}> free rectangles [x, y, w, h] */
    private array $free;

    private int $freeArea;

    public function __construct(int $width, int $height, private readonly MaxRectsRule $rule = MaxRectsRule::BestShortSideFit)
    {
        $this->free = [[0, 0, $width, $height]];
        $this->freeArea = $width * $height;
    }

    /**
     * Area not yet occupied (upper bound on what fits).
     */
    public function freeArea(): int
    {
        return $this->freeArea;
    }

    /**
     * Best position for a dx × dy box, or null if it does not fit.
     *
     * Without `$accept`, each free rectangle offers its bottom-left corner. With `$accept`
     * (for example, a support check) its four corners are offered and only accepted
     * positions count; the tie-break adds the corner index.
     *
     * @param (callable(int, int, int, int): bool)|null $accept
     *
     * @return array{int, int, bool}|null [x, y, turned]
     */
    public function find(int $dx, int $dy, bool $canTurn, ?callable $accept = null): ?array
    {
        if ($dx * $dy > $this->freeArea) {
            return null;
        }
        $best = null;
        $bestKey = null;
        $variants = $canTurn && $dx !== $dy ? [[$dx, $dy, false], [$dy, $dx, true]] : [[$dx, $dy, false]];
        foreach ($this->free as [$fx, $fy, $fw, $fh]) {
            foreach ($variants as [$w, $h, $turned]) {
                if ($w > $fw || $h > $fh) {
                    continue;
                }
                $leftW = $fw - $w;
                $leftH = $fh - $h;
                $corners = $accept === null
                    ? [[$fx, $fy]]
                    : [[$fx, $fy], [$fx + $leftW, $fy], [$fx, $fy + $leftH], [$fx + $leftW, $fy + $leftH]];
                foreach ($corners as $c => [$x, $y]) {
                    $key = $this->rule === MaxRectsRule::BottomLeft
                        ? [$y + $h, $x, 0, 0, $turned ? 1 : 0, $c]
                        : [min($leftW, $leftH), max($leftW, $leftH), $y, $x, $turned ? 1 : 0, $c];
                    if ($bestKey !== null && $key >= $bestKey) {
                        continue;
                    }
                    if ($accept !== null && !$accept($x, $y, $w, $h)) {
                        continue;
                    }
                    $bestKey = $key;
                    $best = [$x, $y, $turned];
                }
            }
        }

        return $best;
    }

    public function place(int $x, int $y, int $w, int $h): void
    {
        $next = [];
        $created = [];
        foreach ($this->free as $f) {
            [$fx, $fy, $fw, $fh] = $f;
            if ($x >= $fx + $fw || $x + $w <= $fx || $y >= $fy + $fh || $y + $h <= $fy) {
                $next[] = $f;
                continue;
            }
            if ($x > $fx) {
                $created[] = [$fx, $fy, $x - $fx, $fh];
            }
            if ($x + $w < $fx + $fw) {
                $created[] = [$x + $w, $fy, $fx + $fw - ($x + $w), $fh];
            }
            if ($y > $fy) {
                $created[] = [$fx, $fy, $fw, $y - $fy];
            }
            if ($y + $h < $fy + $fh) {
                $created[] = [$fx, $y + $h, $fw, $fy + $fh - ($y + $h)];
            }
        }
        $this->free = self::prune([...$next, ...$created]);
        $this->freeArea -= $w * $h;
    }

    /**
     * Removes rectangles contained in another one (of two equal ones, the first is kept).
     *
     * @param list<array{int, int, int, int}> $rects
     *
     * @return list<array{int, int, int, int}>
     */
    private static function prune(array $rects): array
    {
        $n = \count($rects);
        $keep = array_fill(0, $n, true);
        for ($i = 0; $i < $n; ++$i) {
            if (!$keep[$i]) {
                continue;
            }
            [$ax, $ay, $aw, $ah] = $rects[$i];
            for ($j = 0; $j < $n; ++$j) {
                if ($i === $j || !$keep[$j]) {
                    continue;
                }
                [$bx, $by, $bw, $bh] = $rects[$j];
                if ($ax >= $bx && $ay >= $by && $ax + $aw <= $bx + $bw && $ay + $ah <= $by + $bh) {
                    // A is inside B; if they are equal, the one with the lower index survives.
                    if ($aw === $bw && $ah === $bh && $ax === $bx && $ay === $by && $i < $j) {
                        $keep[$j] = false;
                        continue;
                    }
                    $keep[$i] = false;
                    break;
                }
            }
        }
        $out = [];
        foreach ($rects as $i => $r) {
            if ($keep[$i]) {
                $out[] = $r;
            }
        }

        return $out;
    }
}
