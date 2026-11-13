<?php

declare(strict_types=1);

namespace Palletwise\Model;

/**
 * Which original box dimension lies on each axis: x (along the pallet length),
 * y (along the pallet width) and z (vertical). LWH is the box as declared.
 */
enum Orientation: string
{
    case LWH = 'LWH';
    case WLH = 'WLH';
    case LHW = 'LHW';
    case HLW = 'HLW';
    case WHL = 'WHL';
    case HWL = 'HWL';

    /**
     * @return array{int, int, int} [dx, dy, dz]
     */
    public function apply(int $length, int $width, int $height): array
    {
        return match ($this) {
            self::LWH => [$length, $width, $height],
            self::WLH => [$width, $length, $height],
            self::LHW => [$length, $height, $width],
            self::HLW => [$height, $length, $width],
            self::WHL => [$width, $height, $length],
            self::HWL => [$height, $width, $length],
        };
    }

    /**
     * The same orientation turned 90° about the vertical axis.
     */
    public function turned(): self
    {
        return match ($this) {
            self::LWH => self::WLH,
            self::WLH => self::LWH,
            self::LHW => self::HLW,
            self::HLW => self::LHW,
            self::WHL => self::HWL,
            self::HWL => self::WHL,
        };
    }
}
