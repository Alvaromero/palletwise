<?php

declare(strict_types=1);

namespace Palletwise\Layer;

/**
 * Surface on which the next layer is built (the top face of a pallet).
 *
 * @internal
 */
interface SupportSurface
{
    /** z level of the base of the next layer. */
    public function height(): int;

    public function remainingHeight(): int;

    public function remainingPayload(): int;

    /** Whether anything can be placed on top (there is no fragile layer on top). */
    public function isOpen(): bool;

    /** Whether a dx × dy box at (x, y), resting at the current level, meets the minimum support. */
    public function isSupported(int $x, int $y, int $dx, int $dy): bool;
}
