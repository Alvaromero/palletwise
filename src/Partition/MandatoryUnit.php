<?php

declare(strict_types=1);

namespace Palletwise\Partition;

/**
 * A box or set that needs its own pallet.
 *
 * @internal
 */
final readonly class MandatoryUnit
{
    public const REASON_BOX_WEIGHT = 'box_weight';
    public const REASON_BOX_WIDTH = 'box_width';
    public const REASON_SET_WEIGHT = 'set_weight';
    public const REASON_SET_MEMBER = 'set_member';

    /**
     * @param non-empty-list<Item> $items
     */
    public function __construct(
        public ?string $setId,
        public array $items,
        public string $reason,
        public int $declaredWeight,
    ) {}
}
