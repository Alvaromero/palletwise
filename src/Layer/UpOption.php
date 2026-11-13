<?php

declare(strict_types=1);

namespace Palletwise\Layer;

use Palletwise\Model\Orientation;
use Palletwise\Model\Rotation;

/**
 * A way to stand the box: which dimension is vertical. `orientation` is the "unturned"
 * variant (dx along x); if `canTurn`, turning it 90° in plan is also allowed.
 *
 * @internal
 */
final readonly class UpOption
{
    public function __construct(
        public Orientation $orientation,
        public int $dx,
        public int $dy,
        public int $dz,
        public bool $canTurn,
    ) {}

    /**
     * Options allowed by the rotation, in a fixed order (declared height vertical first)
     * and without geometric duplicates.
     *
     * @return non-empty-list<self>
     */
    public static function for(Rotation $rotation, int $length, int $width, int $height): array
    {
        $candidates = match ($rotation) {
            Rotation::Fixed => [Orientation::LWH],
            Rotation::KeepUpright => [Orientation::LWH],
            Rotation::Any => [Orientation::LWH, Orientation::LHW, Orientation::WHL],
        };
        $options = [];
        $seen = [];
        foreach ($candidates as $orientation) {
            [$dx, $dy, $dz] = $orientation->apply($length, $width, $height);
            $key = $dz . ':' . min($dx, $dy) . ':' . max($dx, $dy);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $options[] = new self($orientation, $dx, $dy, $dz, $rotation !== Rotation::Fixed);
        }

        return $options;
    }

    public function fitsBase(int $palletLength, int $palletWidth): bool
    {
        return ($this->dx <= $palletLength && $this->dy <= $palletWidth)
            || ($this->canTurn && $this->dy <= $palletLength && $this->dx <= $palletWidth);
    }

    /**
     * Concrete orientation and plan dimensions for placing the box turned or not.
     *
     * @return array{Orientation, int, int}
     */
    public function placed(bool $turned): array
    {
        return $turned ? [$this->orientation->turned(), $this->dy, $this->dx] : [$this->orientation, $this->dx, $this->dy];
    }
}
