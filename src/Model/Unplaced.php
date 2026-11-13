<?php

declare(strict_types=1);

namespace Palletwise\Model;

/**
 * A physical box that is not placed on any pallet.
 */
final readonly class Unplaced
{
    public function __construct(
        public string $boxId,
        public string $lineId,
        public string $shipmentId,
        public WarningCode $reason,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'boxId' => $this->boxId,
            'lineId' => $this->lineId,
            'shipmentId' => $this->shipmentId,
            'reason' => $this->reason->value,
        ];
    }
}
