<?php

declare(strict_types=1);

namespace Palletwise\Model;

/**
 * Warning with a stable code. `details` holds values already expressed in the
 * request units.
 */
final readonly class Warning
{
    /**
     * @param array<string, int|float|string|bool|list<string>|null> $details
     */
    public function __construct(
        public WarningCode $code,
        public string $message,
        public ?string $shipmentId = null,
        public ?string $boxId = null,
        public ?int $palletIndex = null,
        public array $details = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code->value,
            'message' => $this->message,
            'shipmentId' => $this->shipmentId,
            'boxId' => $this->boxId,
            'palletIndex' => $this->palletIndex,
            'details' => $this->details,
        ];
    }
}
