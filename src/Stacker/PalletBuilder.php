<?php

declare(strict_types=1);

namespace Palletwise\Stacker;

use Palletwise\Layer\LayerPlan;
use Palletwise\Layer\SupportSurface;
use Palletwise\Layer\Transform;
use Palletwise\Model\Layer;
use Palletwise\Model\PalletType;
use Palletwise\Units\IntMath;

/**
 * Pallet under construction: stacks layers checking height, weight, fragile boxes and
 * minimum support per box. Support counts the boxes whose top face is at the base of the
 * new layer or at most `supportTolerance` below it (the band tolerance).
 *
 * @internal
 */
final class PalletBuilder implements SupportSurface
{
    /** @var list<PlacedBox> */
    private array $boxes = [];

    /** @var list<Layer> */
    private array $layers = [];

    /** @var list<LayerPlan> placed layers, bottom to top */
    private array $plans = [];

    /** @var array<int, list<PlacedBox>> boxes indexed by the level of their top face */
    private array $byTop = [];

    private int $height = 0;
    private int $weight = 0;
    private bool $closed = false;

    /** Symmetry used in the last placed layer. */
    private Transform $lastTransform = Transform::Identity;

    private ?string $lastSignature = null;

    /** @var list<PlacedBox>|null */
    private ?array $surfaceCache = null;

    public function __construct(
        public readonly string $shipmentId,
        public readonly PalletType $kind,
        private readonly int $palletLength,
        private readonly int $palletWidth,
        private readonly int $usableHeight,
        private readonly int $payload,
        private readonly int $minSupport,
        private readonly int $supportTolerance = 0,
    ) {}

    public function isEmpty(): bool
    {
        return $this->layers === [];
    }

    public function height(): int
    {
        return $this->height;
    }

    public function weight(): int
    {
        return $this->weight;
    }

    public function remainingHeight(): int
    {
        return $this->usableHeight - $this->height;
    }

    public function remainingPayload(): int
    {
        return $this->payload - $this->weight;
    }

    /**
     * @return list<PlacedBox>
     */
    public function boxes(): array
    {
        return $this->boxes;
    }

    /**
     * @return list<Layer>
     */
    public function layers(): array
    {
        return $this->layers;
    }

    /**
     * @return list<LayerPlan>
     */
    public function layerPlans(): array
    {
        return $this->plans;
    }

    /**
     * Empty pallet with the same configuration.
     */
    public function emptyCopy(): self
    {
        return new self($this->shipmentId, $this->kind, $this->palletLength, $this->palletWidth, $this->usableHeight, $this->payload, $this->minSupport, $this->supportTolerance);
    }

    /**
     * Height, weight and fragile boxes; does not check support.
     */
    public function fits(LayerPlan $layer): bool
    {
        return !$this->closed
            && $this->height + $layer->height <= $this->usableHeight
            && $this->weight + $layer->weight <= $this->payload;
    }

    /**
     * Tries to place the layer, trying the symmetries in the given order.
     *
     * @param list<Transform> $transforms
     */
    public function tryPlace(LayerPlan $layer, array $transforms = [Transform::Identity]): bool
    {
        if (!$this->fits($layer)) {
            return false;
        }
        foreach ($transforms as $transform) {
            if ($this->supports($layer, $transform)) {
                $this->place($layer, $transform);

                return true;
            }
        }

        return false;
    }

    /**
     * Like tryPlace() with every symmetry, also trying to shift the layer to each
     * corner and edge of the pallet (LayerPlan::offsets()).
     */
    public function tryPlaceAnywhere(LayerPlan $layer): bool
    {
        if (!$this->fits($layer)) {
            return false;
        }
        foreach ($layer->offsets($this->palletLength, $this->palletWidth) as [$ox, $oy]) {
            foreach (Transform::cases() as $transform) {
                if ($this->supports($layer, $transform, $ox, $oy)) {
                    $this->place($layer, $transform, $ox, $oy);

                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Places the layer alternating the pattern relative to the previous one when possible:
     * first tries the symmetries that change the geometry and keep support; if none works,
     * repeats the previous layer's one.
     */
    public function placeAlternating(LayerPlan $layer): bool
    {
        if (!$this->fits($layer)) {
            return false;
        }
        if ($this->isEmpty()) {
            $this->place($layer, Transform::Identity);

            return true;
        }
        $previous = $this->lastSignature;
        foreach (Transform::cases() as $transform) {
            if ($transform === $this->lastTransform) {
                continue;
            }
            if ($layer->signature($transform) !== $previous && $this->supports($layer, $transform)) {
                $this->place($layer, $transform);

                return true;
            }
        }

        return $this->tryPlace($layer, [$this->lastTransform, ...Transform::cases()]);
    }

    public function supports(LayerPlan $layer, Transform $transform, int $offsetX = 0, int $offsetY = 0): bool
    {
        if ($this->height === 0) {
            return true;
        }
        foreach ($layer->positions($transform, $offsetX, $offsetY) as $i => [$x, $y]) {
            $slot = $layer->slots[$i];
            if (!$this->isSupported($x, $y, $slot->dx, $slot->dy)) {
                return false;
            }
        }

        return true;
    }

    public function isOpen(): bool
    {
        return !$this->closed;
    }

    public function isSupported(int $x, int $y, int $dx, int $dy): bool
    {
        if ($this->height === 0) {
            return true;
        }
        $supported = 0;
        foreach ($this->surface() as $b) {
            $ox = min($x + $dx, $b->x + $b->dx) - max($x, $b->x);
            $oy = min($y + $dy, $b->y + $b->dy) - max($y, $b->y);
            if ($ox > 0 && $oy > 0) {
                $supported += $ox * $oy;
            }
        }

        return IntMath::mul($supported, 1000) >= IntMath::mul($this->minSupport, IntMath::mul($dx, $dy));
    }

    /**
     * Boxes that give support at the current level (top face within the tolerance).
     *
     * @return list<PlacedBox>
     */
    private function surface(): array
    {
        if ($this->surfaceCache !== null) {
            return $this->surfaceCache;
        }
        $below = [];
        foreach ($this->byTop as $top => $boxes) {
            if ($top <= $this->height && $top >= $this->height - $this->supportTolerance) {
                array_push($below, ...$boxes);
            }
        }

        return $this->surfaceCache = $below;
    }

    private function place(LayerPlan $layer, Transform $transform, int $offsetX = 0, int $offsetY = 0): void
    {
        $z = $this->height;
        foreach ($layer->positions($transform, $offsetX, $offsetY) as $i => [$x, $y]) {
            $slot = $layer->slots[$i];
            $box = new PlacedBox($slot->item, $slot->orientation, $x, $y, $z, $slot->dx, $slot->dy, $slot->dz);
            $this->boxes[] = $box;
            $this->byTop[$z + $slot->dz][] = $box;
        }
        $this->layers[] = new Layer($z, $layer->height, $layer->coveredArea, \count($layer->slots));
        $this->plans[] = $layer;
        $this->height += $layer->height;
        $this->weight += $layer->weight;
        $this->closed = $this->closed || $layer->fragile;
        $this->surfaceCache = null;
        $this->lastTransform = $transform;
        $this->lastSignature = $layer->signature($transform, $offsetX, $offsetY);
    }
}
