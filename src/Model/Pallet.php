<?php

declare(strict_types=1);

namespace Palletwise\Model;

use Palletwise\Units\Decimal;

/**
 * A pallet in the result. Height and weight include the pallet itself.
 */
final readonly class Pallet
{
    /**
     * @param list<string>         $boxIds
     * @param list<Layer>          $layers
     * @param list<Placement>|null $placements null if they were not requested
     * @param int                  $volumeUtilization box volume ÷ usable volume, in ten-thousandths
     */
    public function __construct(
        public int $index,
        public string $shipmentId,
        public PalletType $type,
        public int $height,
        public int $weight,
        public array $boxIds,
        public int $volumeUtilization,
        public array $layers,
        public ?array $placements = null,
    ) {}

    public function boxCount(): int
    {
        return \count($this->boxIds);
    }

    public function withoutPlacements(): self
    {
        return new self($this->index, $this->shipmentId, $this->type, $this->height, $this->weight, $this->boxIds, $this->volumeUtilization, $this->layers);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'index' => $this->index,
            'shipmentId' => $this->shipmentId,
            'type' => $this->type->value,
            'height' => Decimal::toFloat($this->height),
            'weight' => Decimal::toFloat($this->weight),
            'boxCount' => $this->boxCount(),
            'boxIds' => $this->boxIds,
            'volumeUtilization' => $this->volumeUtilization / 10_000,
            'layers' => array_map(static fn(Layer $l): array => $l->toArray(), $this->layers),
        ];
        if ($this->placements !== null) {
            $out['placements'] = array_map(static fn(Placement $p): array => $p->toArray(), $this->placements);
        }

        return $out;
    }
}
