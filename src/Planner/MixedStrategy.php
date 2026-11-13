<?php

declare(strict_types=1);

namespace Palletwise\Planner;

use Palletwise\Layer\OrderKey;

/**
 * One variant of the mixed stage within the multi-order search.
 *
 * @internal
 */
final readonly class MixedStrategy
{
    public function __construct(
        public OrderKey $order,
        /** Fill the gap in a layer with shorter boxes when its band runs out. */
        public bool $fillWithShorter,
    ) {}

    /**
     * Variants the Planner tries, in a fixed order: the four orders without filling with
     * shorter boxes, then with it.
     *
     * @return list<self>
     */
    public static function all(): array
    {
        $out = [];
        foreach ([false, true] as $fill) {
            foreach (OrderKey::cases() as $order) {
                $out[] = new self($order, $fill);
            }
        }

        return $out;
    }
}
