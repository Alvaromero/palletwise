<?php

declare(strict_types=1);

namespace Palletwise\Stacker;

/**
 * Monotonic counter that numbers layers in creation order (deterministic tie-breaks).
 *
 * @internal
 */
final class Sequence
{
    private int $value = 0;

    public function next(): int
    {
        return $this->value++;
    }
}
