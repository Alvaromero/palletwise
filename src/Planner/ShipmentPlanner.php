<?php

declare(strict_types=1);

namespace Palletwise\Planner;

use Palletwise\Layer\HomogeneousLayerFactory;
use Palletwise\Layer\LayerPlan;
use Palletwise\Layer\LayerSlot;
use Palletwise\Layer\MixedLayerBuilder;
use Palletwise\Model\PalletType;
use Palletwise\Model\Warning;
use Palletwise\Model\WarningCode;
use Palletwise\Partition\Item;
use Palletwise\Partition\ItemGroup;
use Palletwise\Partition\MandatoryUnit;
use Palletwise\Partition\ShipmentPartition;
use Palletwise\Stacker\HomogeneousStacker;
use Palletwise\Stacker\MixedFiller;
use Palletwise\Stacker\PalletBuilder;
use Palletwise\Stacker\Stacker;
use Palletwise\Units\Decimal;

/**
 * Plans the pallets of one shipment.
 *
 *  1. Each mandatory unit (box or set) goes on its own pallets.
 *  2. Each group of identical boxes fills full pallets with its homogeneous pattern; the
 *     leftover full layers and loose boxes move on to the mixed stage.
 *  3. Mixed stage: leftover homogeneous layers are stacked (Stacker) on top of the full
 *     homogeneous pallets if they have room left (full by height or weight need not mean
 *     filled), or on new pallets; then the loose boxes are
 *     placed layer by layer on those pallets (MixedFiller) and, at the end, the fragile ones;
 *     finally, pallets are merged where possible (Stacker::compact()).
 *  4. The mixed stage is repeated with each MixedStrategy; the winner is the result with the
 *     fewest pallets and, on a tie, the most even weight and height (smallest difference
 *     between the heaviest and lightest pallet, then between the tallest and shortest) and,
 *     finally, the first strategy tried.
 *
 * @internal
 */
final class ShipmentPlanner
{
    /** @var list<MixedStrategy> */
    private readonly array $strategies;

    /**
     * @param list<MixedStrategy>|null $strategies
     */
    public function __construct(
        ?array $strategies = null,
        private readonly HomogeneousLayerFactory $homogeneous = new HomogeneousLayerFactory(),
        private readonly HomogeneousStacker $homogeneousStacker = new HomogeneousStacker(),
        private readonly MixedLayerBuilder $layers = new MixedLayerBuilder(),
        private readonly MixedFiller $filler = new MixedFiller(),
        private readonly Stacker $stacker = new Stacker(),
    ) {
        $this->strategies = $strategies ?? MixedStrategy::all();
    }

    public function plan(ShipmentPartition $shipment, PlanningContext $ctx): ShipmentPlan
    {
        $pallets = [];
        $warnings = [];

        foreach ($shipment->mandatory as $unit) {
            if (\count($unit->items) === 1) {
                $unitPallets = [$this->single($unit->items[0], $shipment->shipmentId, $ctx)];
            } else {
                [$groups, $fragile] = ItemGroup::split($unit->items);
                $unitPallets = $this->planItems($groups, $fragile, $shipment->shipmentId, PalletType::Mandatory, $ctx);
            }
            array_push($pallets, ...$unitPallets);
            $warnings[] = self::mandatoryWarning($unit, $shipment->shipmentId, \count($unitPallets));
            if (\count($unitPallets) > 1) {
                $warnings[] = new Warning(
                    WarningCode::SetSplitAcrossPallets,
                    'Set does not fit on a single pallet; it is split across pallets that contain only this set.',
                    $shipment->shipmentId,
                    details: ['setId' => (string) $unit->setId, 'pallets' => \count($unitPallets)],
                );
            }
        }

        array_push($pallets, ...$this->planItems($shipment->groups, $shipment->fragile, $shipment->shipmentId, PalletType::Mixed, $ctx));

        return new ShipmentPlan($pallets, $warnings);
    }

    /**
     * @param list<ItemGroup> $groups
     * @param list<Item>      $fragile
     *
     * @return list<PalletBuilder>
     */
    private function planItems(array $groups, array $fragile, string $shipmentId, PalletType $kind, PlanningContext $ctx): array
    {
        $full = [];
        $leftoverLayers = [];
        $loose = [];

        foreach ($groups as $group) {
            $spec = $this->homogeneous->spec($group->sample(), $ctx->profile, $ctx->mode);
            if ($spec === null) {
                throw new \LogicException('Validated box has no homogeneous layer.');
            }
            [$chunks, $rest] = HomogeneousStacker::split($group->items, $spec);
            foreach ($chunks as $chunk) {
                \assert($chunk !== []);
                $full[] = $this->homogeneousStacker->pallet($chunk, $spec, $ctx->newPallet($shipmentId, $kind), $ctx->sequence);
            }
            $layerCount = $spec->capacity >= $spec->perLayer ? intdiv(\count($rest), $spec->perLayer) : 0;
            for ($i = 0; $i < $layerCount; ++$i) {
                $chunk = \array_slice($rest, $i * $spec->perLayer, $spec->perLayer);
                \assert($chunk !== []);
                $leftoverLayers[] = $spec->layer($chunk, $ctx->sequence->next());
            }
            array_push($loose, ...\array_slice($rest, $layerCount * $spec->perLayer));
        }

        if ($leftoverLayers === [] && $loose === [] && $fragile === []) {
            return $full;
        }

        return $this->searchMixed($full, $leftoverLayers, $loose, $fragile, $shipmentId, $kind, $ctx);
    }

    /**
     * @param list<PalletBuilder> $full     full homogeneous pallets
     * @param list<LayerPlan>     $prebuilt leftover homogeneous layers
     * @param list<Item>          $loose
     * @param list<Item>          $fragile
     *
     * @return list<PalletBuilder>
     */
    private function searchMixed(array $full, array $prebuilt, array $loose, array $fragile, string $shipmentId, PalletType $kind, PlanningContext $ctx): array
    {
        $profile = $ctx->profile;
        $newPallet = static fn(): PalletBuilder => $ctx->newPallet($shipmentId, $kind);
        $best = [];
        $bestScore = null;
        foreach ($this->strategies as $index => $strategy) {
            $open = array_map(static fn(PalletBuilder $p): PalletBuilder => clone $p, $full);
            $pallets = [...$open, ...$this->stacker->stack($prebuilt, $newPallet, $profile->palletArea(), $profile->minSupport, $open)];
            foreach ([$loose, $fragile] as $items) {
                $entries = $this->layers->entries($items, $profile, $ctx->mode, $strategy->order);
                $pallets = $this->filler->fill($pallets, $entries, $newPallet, $profile, $strategy->fillWithShorter, $ctx->sequence);
            }
            $pallets = $this->stacker->compact($pallets, $profile->usableHeight($ctx->mode), $profile->payload($ctx->mode));

            $score = [\count($pallets), self::spread($pallets, 'weight'), self::spread($pallets, 'height'), $index];
            if ($bestScore === null || $score < $bestScore) {
                $bestScore = $score;
                $best = $pallets;
            }
        }

        return $best;
    }

    /**
     * @param list<PalletBuilder> $pallets
     */
    private static function spread(array $pallets, string $what): int
    {
        $values = array_map(static fn(PalletBuilder $p): int => $what === 'weight' ? $p->weight() : $p->height(), $pallets);

        return $values === [] ? 0 : max($values) - min($values);
    }

    private function single(Item $item, string $shipmentId, PlanningContext $ctx): PalletBuilder
    {
        $profile = $ctx->profile;
        $option = HomogeneousLayerFactory::lowestOption($item, $profile, $ctx->mode);
        if ($option === null) {
            throw new \LogicException('Validated box has no feasible orientation.');
        }
        $turned = !($option->dx <= $profile->palletLength && $option->dy <= $profile->palletWidth);
        [$orientation, $dx, $dy] = $option->placed($turned);
        $x = intdiv($profile->palletLength - $dx, 2);
        $y = intdiv($profile->palletWidth - $dy, 2);
        $layer = new LayerPlan([new LayerSlot($item, $orientation, $x, $y, $dx, $dy, $option->dz)], [$x, $y, $x + $dx, $y + $dy], $ctx->sequence->next(), true);
        $pallet = $ctx->newPallet($shipmentId, PalletType::Mandatory);
        if (!$pallet->tryPlace($layer)) {
            throw new \LogicException('Validated box does not fit on an empty pallet.');
        }

        return $pallet;
    }

    private static function mandatoryWarning(MandatoryUnit $unit, string $shipmentId, int $pallets): Warning
    {
        $details = ['reason' => $unit->reason, 'weight' => Decimal::toFloat($unit->declaredWeight), 'pallets' => $pallets];
        if ($unit->setId !== null) {
            $details['setId'] = $unit->setId;
        }

        return new Warning(
            WarningCode::MandatoryOwnPallet,
            $unit->setId === null ? 'Box requires its own pallet.' : 'Set requires its own pallet.',
            $shipmentId,
            $unit->setId === null ? $unit->items[0]->id : null,
            details: $details,
        );
    }
}
