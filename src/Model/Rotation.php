<?php

declare(strict_types=1);

namespace Palletwise\Model;

enum Rotation: string
{
    /** Turning about the vertical axis only (the top face stays up). */
    case KeepUpright = 'keep_upright';

    /** Any of the 6 orthogonal orientations. */
    case Any = 'any';

    /** Exactly as declared: length on x, width on y, height on z. */
    case Fixed = 'fixed';

    /**
     * Allowed orientations, in the engine's fixed order of preference.
     *
     * @return list<Orientation>
     */
    public function orientations(): array
    {
        return match ($this) {
            self::Fixed => [Orientation::LWH],
            self::KeepUpright => [Orientation::LWH, Orientation::WLH],
            self::Any => Orientation::cases(),
        };
    }

    public function allows(Orientation $orientation): bool
    {
        return \in_array($orientation, $this->orientations(), true);
    }
}
