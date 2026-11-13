<?php

declare(strict_types=1);

namespace Palletwise\Layer;

/**
 * Sort criteria of the multi-order search, in the fixed order in which they are tried.
 * All sort from largest to smallest; the remaining criteria, in this same order, act as
 * tie-breakers, followed finally by the box's position in the input.
 *
 * @internal
 */
enum OrderKey: string
{
    case Volume = 'volume';
    case Weight = 'weight';
    case BaseArea = 'base_area';
    case Height = 'height';

    /**
     * @param array{volume: int, weight: int, base_area: int, height: int} $metrics
     *
     * @return list<int>
     */
    public function sortKey(array $metrics, int $ordinal): array
    {
        $key = [-$metrics[$this->value]];
        foreach (self::cases() as $other) {
            if ($other !== $this) {
                $key[] = -$metrics[$other->value];
            }
        }
        $key[] = $ordinal;

        return $key;
    }
}
