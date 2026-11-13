<?php

declare(strict_types=1);

namespace Palletwise\Model;

/**
 * Result for one mode.
 */
final readonly class ModeResult
{
    public int $palletCount;

    /**
     * @param list<Pallet>   $pallets
     * @param list<Unplaced> $unplaced
     * @param list<Warning>  $warnings
     * @param array{volume: int, weight: int, mandatory: int} $lowerBounds
     */
    public function __construct(
        public Mode $mode,
        public int $lowerBound,
        public array $lowerBounds,
        public array $pallets,
        public array $unplaced,
        public array $warnings,
        public ?Recommendation $recommendation,
    ) {
        $this->palletCount = \count($pallets);
    }

    /**
     * @param list<Warning> $warnings
     */
    public function withAddedWarnings(array $warnings): self
    {
        return new self($this->mode, $this->lowerBound, $this->lowerBounds, $this->pallets, $this->unplaced, [...$this->warnings, ...$warnings], $this->recommendation);
    }

    public function withoutPlacements(): self
    {
        return new self(
            $this->mode,
            $this->lowerBound,
            $this->lowerBounds,
            array_map(static fn(Pallet $p): Pallet => $p->withoutPlacements(), $this->pallets),
            $this->unplaced,
            $this->warnings,
            $this->recommendation,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode->value,
            'palletCount' => $this->palletCount,
            'lowerBound' => $this->lowerBound,
            'lowerBounds' => $this->lowerBounds,
            'pallets' => array_map(static fn(Pallet $p): array => $p->toArray(), $this->pallets),
            'unplaced' => array_map(static fn(Unplaced $u): array => $u->toArray(), $this->unplaced),
            'warnings' => array_map(static fn(Warning $w): array => $w->toArray(), $this->warnings),
            'recommendation' => $this->recommendation?->toArray(),
        ];
    }
}
