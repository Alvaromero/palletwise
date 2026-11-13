<?php

declare(strict_types=1);

namespace Palletwise\Tests\Support;

use Palletwise\Model\Box;
use Palletwise\Model\Layer;
use Palletwise\Model\Meta;
use Palletwise\Model\Mode;
use Palletwise\Model\ModeResult;
use Palletwise\Model\Orientation;
use Palletwise\Model\Pallet;
use Palletwise\Model\PalletType;
use Palletwise\Model\Placement;
use Palletwise\Model\PlanRequest;
use Palletwise\Model\PlanResult;
use Palletwise\Model\Rotation;
use Palletwise\Model\Unplaced;
use Palletwise\Model\WarningCode;
use Palletwise\Units\LengthUnit;
use Palletwise\Units\WeightUnit;

/**
 * Builds plans by hand (in in/lb with the amazon-us profile) to test the Verifier.
 * Height, weight and layers of each pallet are computed from the placements unless
 * they are forced.
 */
final class PlanFixture
{
    public const TARE_HEIGHT = 5_000;
    public const TARE_WEIGHT = 40_000;

    /** @var list<Box> */
    private array $boxes = [];

    /** @var list<Pallet> */
    private array $pallets = [];

    /** @var list<Unplaced> */
    private array $unplaced = [];

    private int $lowerBound = 0;

    /**
     * @param array<string, mixed> $options rotation, setId, fragile, quantity, shipmentId, sku
     */
    public function box(string $id, float $l, float $w, float $h, float $weight, array $options = []): self
    {
        $rotation = $options['rotation'] ?? null;
        $setId = $options['setId'] ?? null;
        $quantity = $options['quantity'] ?? 1;
        \assert($rotation === null || $rotation instanceof Rotation);
        \assert($setId === null || \is_string($setId));
        \assert(\is_int($quantity));
        $shipmentId = $options['shipmentId'] ?? 'S1';
        $sku = $options['sku'] ?? 'SKU-' . $id;
        \assert(\is_string($shipmentId) && \is_string($sku));
        $this->boxes[] = new Box(
            $id,
            $shipmentId,
            $sku,
            $l,
            $w,
            $h,
            $weight,
            $quantity,
            $rotation,
            $setId,
            (bool) ($options['fragile'] ?? false),
        );

        return $this;
    }

    /**
     * @param list<Placement> $placements
     * @param array{height?: int, weight?: int, boxIds?: list<string>, layers?: list<Layer>} $force
     */
    public function pallet(array $placements, string $shipmentId = 'S1', PalletType $type = PalletType::Mixed, array $force = []): self
    {
        $weights = [];
        foreach ($this->boxes as $box) {
            for ($n = 1; $n <= $box->quantity; ++$n) {
                $weights[PlanRequest::unitId($box, $n)] = $box->weight;
            }
        }
        $top = 0;
        $weight = self::TARE_WEIGHT;
        $zs = [];
        foreach ($placements as $p) {
            $top = max($top, $p->z + $p->height);
            $weight += $weights[$p->boxId] ?? 0;
            $zs[$p->z] = ($zs[$p->z] ?? 0) + 1;
        }
        ksort($zs);
        $layers = [];
        $levels = array_keys($zs);
        foreach ($levels as $i => $z) {
            $next = $levels[$i + 1] ?? $top;
            $layers[] = new Layer($z, $next - $z, 0, $zs[$z]);
        }

        $this->pallets[] = new Pallet(
            \count($this->pallets) + 1,
            $shipmentId,
            $type,
            $force['height'] ?? self::TARE_HEIGHT + $top,
            $force['weight'] ?? $weight,
            $force['boxIds'] ?? array_map(static fn(Placement $p): string => $p->boxId, $placements),
            0,
            $force['layers'] ?? $layers,
            $placements,
        );

        return $this;
    }

    public function unplaced(string $boxId, WarningCode $reason = WarningCode::BoxDoesNotFitBase): self
    {
        $this->unplaced[] = new Unplaced($boxId, $boxId, 'S1', $reason);

        return $this;
    }

    public function lowerBound(int $lowerBound): self
    {
        $this->lowerBound = $lowerBound;

        return $this;
    }

    public static function at(string $boxId, float $x, float $y, float $z, float $l, float $w, float $h, Orientation $o = Orientation::LWH): Placement
    {
        return new Placement($boxId, self::s($x), self::s($y), self::s($z), self::s($l), self::s($w), self::s($h), $o);
    }

    public function build(Mode $mode = Mode::NonStackable, bool $stripPlacements = false): PlanResult
    {
        $request = new PlanRequest('amazon-us@2026-11', LengthUnit::Inch, WeightUnit::Pound, $this->boxes, [$mode]);
        $result = new ModeResult($mode, $this->lowerBound, ['volume' => 0, 'weight' => 0, 'mandatory' => 0], $this->pallets, $this->unplaced, [], null);
        if ($stripPlacements) {
            $result = $result->withoutPlacements();
        }

        return new PlanResult(new Meta('test', 'amazon-us', '2026-11', 'in', 'lb', $request->hash()), [$result], $request);
    }

    private static function s(float $v): int
    {
        return (int) round($v * 1000);
    }
}
