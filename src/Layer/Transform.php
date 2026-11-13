<?php

declare(strict_types=1);

namespace Palletwise\Layer;

/**
 * Symmetries of a layer within its frame (the pattern's bounding box for homogeneous
 * layers, the whole pallet for mixed ones). They do not change box orientation.
 *
 * @internal
 */
enum Transform
{
    case Identity;
    case MirrorX;
    case MirrorY;
    case Rotate180;

    /**
     * @param array{int, int, int, int} $frame [x0, y0, x1, y1]
     *
     * @return array{int, int}
     */
    public function apply(int $x, int $y, int $dx, int $dy, array $frame): array
    {
        [$x0, $y0, $x1, $y1] = $frame;
        $mx = $x0 + $x1 - $x - $dx;
        $my = $y0 + $y1 - $y - $dy;

        return match ($this) {
            self::Identity => [$x, $y],
            self::MirrorX => [$mx, $y],
            self::MirrorY => [$x, $my],
            self::Rotate180 => [$mx, $my],
        };
    }
}
