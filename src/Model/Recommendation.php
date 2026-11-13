<?php

declare(strict_types=1);

namespace Palletwise\Model;

/**
 * Recommendation to ship as small parcel (for example, Amazon SPD) instead of on pallets.
 */
final readonly class Recommendation
{
    public const TYPE_PARCEL = 'PARCEL';
    public const REASON_LOW_FILL = 'LOW_PALLET_VOLUME_FILL';

    /**
     * @param int $volumeFill volume fill in ten-thousandths
     * @param int $threshold  threshold in thousandths
     */
    public function __construct(
        public string $type,
        public int $volumeFill,
        public int $threshold,
        public string $reason,
    ) {}

    /**
     * @return array<string, string|float>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'volumeFill' => $this->volumeFill / 10_000,
            'threshold' => $this->threshold / 1_000,
            'reason' => $this->reason,
        ];
    }
}
