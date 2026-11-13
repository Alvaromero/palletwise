<?php

declare(strict_types=1);

namespace Palletwise\Layer;

/**
 * Finds the 2D pattern that fits the most identical boxes (a × b) on the L × W base,
 * combining both horizontal orientations.
 *
 * Candidates, in this fixed order (a later candidate only wins if it fits strictly
 * more boxes):
 *  1. single-orientation grid;
 *  2. two blocks: one vertical or horizontal cut, each side with its best grid;
 *  3. four-block pinwheel (classic first-order heuristic), if the number of cut
 *     positions per axis does not exceed MAX_CUTS.
 *
 * Cuts are tried at multiples of a and b. Each block is filled with the grid that fits
 * the most boxes (on a tie, unturned).
 *
 * @internal
 */
final class HomogeneousPatternFinder
{
    public const MAX_CUTS = 16;

    /** @var array<string, Pattern> */
    private array $cache = [];

    public function find(int $length, int $width, int $a, int $b, bool $canTurn): Pattern
    {
        $key = implode(':', [$length, $width, $a, $b, $canTurn ? 1 : 0]);

        return $this->cache[$key] ??= $this->search($length, $width, $a, $b, $canTurn);
    }

    private function search(int $L, int $W, int $a, int $b, bool $canTurn): Pattern
    {
        $grid = [];
        $count = function (int $w, int $h) use (&$grid, $a, $b, $canTurn): int {
            if ($w <= 0 || $h <= 0) {
                return 0;
            }
            $key = $w . ':' . $h;
            if (!isset($grid[$key])) {
                $straight = intdiv($w, $a) * intdiv($h, $b);
                $turned = $canTurn ? intdiv($w, $b) * intdiv($h, $a) : 0;
                $grid[$key] = max($straight, $turned);
            }

            return $grid[$key];
        };

        $best = [[0, 0, $L, $W]];
        $bestCount = $count($L, $W);
        $bestKind = 'grid';

        $cutsX = self::cuts($L, $a, $canTurn ? $b : null);
        $cutsY = self::cuts($W, $b, $canTurn ? $a : null);

        foreach ($cutsX as $x1) {
            $c = $count($x1, $W) + $count($L - $x1, $W);
            if ($c > $bestCount) {
                [$best, $bestCount, $bestKind] = [[[0, 0, $x1, $W], [$x1, 0, $L - $x1, $W]], $c, 'two-block'];
            }
        }
        foreach ($cutsY as $y1) {
            $c = $count($L, $y1) + $count($L, $W - $y1);
            if ($c > $bestCount) {
                [$best, $bestCount, $bestKind] = [[[0, 0, $L, $y1], [0, $y1, $L, $W - $y1]], $c, 'two-block'];
            }
        }

        if ($canTurn && \count($cutsX) <= self::MAX_CUTS && \count($cutsY) <= self::MAX_CUTS) {
            // B1 = [0,x1]×[0,y1], B2 = [x1,L]×[0,y2], B3 = [x3,L]×[y2,W], B4 = [0,x3]×[y1,W]
            foreach ($cutsX as $x1) {
                foreach ($cutsX as $x3) {
                    foreach ($cutsY as $y1) {
                        foreach ($cutsY as $y2) {
                            if (($x3 < $x1 && $y2 < $y1) || ($x3 > $x1 && $y2 > $y1)) {
                                continue;
                            }
                            $c = $count($x1, $y1) + $count($L - $x1, $y2) + $count($L - $x3, $W - $y2) + $count($x3, $W - $y1);
                            if ($c > $bestCount) {
                                $best = [[0, 0, $x1, $y1], [$x1, 0, $L - $x1, $y2], [$x3, $y2, $L - $x3, $W - $y2], [0, $y1, $x3, $W - $y1]];
                                $bestCount = $c;
                                $bestKind = 'four-block';
                            }
                        }
                    }
                }
            }
        }

        $rects = [];
        foreach ($best as [$bx, $by, $bw, $bh]) {
            array_push($rects, ...$this->fill($bx, $by, $bw, $bh, $a, $b, $canTurn));
        }

        return new Pattern($rects, $bestKind);
    }

    /**
     * @return list<Rect>
     */
    private function fill(int $bx, int $by, int $bw, int $bh, int $a, int $b, bool $canTurn): array
    {
        if ($bw <= 0 || $bh <= 0) {
            return [];
        }
        $straight = intdiv($bw, $a) * intdiv($bh, $b);
        $turnedCount = $canTurn ? intdiv($bw, $b) * intdiv($bh, $a) : 0;
        $turned = $turnedCount > $straight;
        [$dx, $dy] = $turned ? [$b, $a] : [$a, $b];
        $rects = [];
        for ($j = 0; $j < intdiv($bh, $dy); ++$j) {
            for ($i = 0; $i < intdiv($bw, $dx); ++$i) {
                $rects[] = new Rect($bx + $i * $dx, $by + $j * $dy, $dx, $dy, $turned);
            }
        }

        return $rects;
    }

    /**
     * Cut positions strictly inside (0, size): multiples of p and q.
     *
     * @return list<int>
     */
    private static function cuts(int $size, int $p, ?int $q): array
    {
        $cuts = [];
        foreach ([$p, $q] as $step) {
            if ($step === null) {
                continue;
            }
            for ($v = $step; $v < $size; $v += $step) {
                $cuts[$v] = true;
                if (\count($cuts) > 4 * self::MAX_CUTS) {
                    break;
                }
            }
        }
        $cuts = array_keys($cuts);
        sort($cuts);

        return $cuts;
    }
}
