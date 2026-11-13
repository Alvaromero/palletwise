<?php

declare(strict_types=1);

namespace Palletwise\Partition;

use Palletwise\Model\Box;
use Palletwise\Model\PlanRequest;
use Palletwise\Profile\ResolvedProfile;

/**
 * Splits by shipmentId, sets aside mandatory pallets and groups identical boxes,
 * expanding `quantity`.
 *
 * Fixed order: shipments by shipmentId (binary string comparison); within each one,
 * mandatory units and groups by first appearance in the input.
 *
 * @internal
 */
final class Partitioner
{
    /**
     * @param list<Box>            $lines       all request lines (to weigh complete sets)
     * @param array<string, true>  $placeable   ids of the lines that passed validation
     *
     * @return list<ShipmentPartition>
     */
    public function partition(array $lines, array $placeable, ResolvedProfile $profile): array
    {
        $setWeight = [];
        $setForced = [];
        foreach ($lines as $line) {
            if ($line->setId === null) {
                continue;
            }
            $key = self::setKey($line);
            $setWeight[$key] = ($setWeight[$key] ?? 0) + $line->weight * $line->quantity;
            if (self::boxReason($line, $profile) !== null) {
                $setForced[$key] = true;
            }
        }

        /** @var array<string, array{mandatory: array<string, array{setId: ?string, items: list<Item>, reason: string, weight: int}>, items: list<Item>}> $shipments */
        $shipments = [];
        $ordinal = 0;
        foreach ($lines as $line) {
            $lineOrdinal = $ordinal;
            $ordinal += $line->quantity;
            if (!isset($placeable[$line->id])) {
                continue;
            }
            $shipments[$line->shipmentId] ??= ['mandatory' => [], 'items' => []];
            $bucket = &$shipments[$line->shipmentId];

            $items = [];
            for ($n = 1; $n <= $line->quantity; ++$n) {
                $items[] = new Item(
                    PlanRequest::unitId($line, $n),
                    $line->id,
                    $line->shipmentId,
                    $line->sku ?? self::physicalSku($line),
                    $line->length,
                    $line->width,
                    $line->height,
                    $line->weight,
                    $line->rotation ?? $profile->defaultRotation,
                    $line->setId,
                    $line->fragile,
                    $lineOrdinal + $n - 1,
                );
            }

            $setKey = $line->setId === null ? null : self::setKey($line);
            if ($setKey !== null && (ResolvedProfile::over($setWeight[$setKey] ?? 0, $profile->mandatorySetWeightOver) || isset($setForced[$setKey]))) {
                $reason = ResolvedProfile::over($setWeight[$setKey] ?? 0, $profile->mandatorySetWeightOver) ? MandatoryUnit::REASON_SET_WEIGHT : MandatoryUnit::REASON_SET_MEMBER;
                $unitKey = 'set:' . $setKey;
                $bucket['mandatory'][$unitKey] ??= ['setId' => $line->setId, 'items' => [], 'reason' => $reason, 'weight' => $setWeight[$setKey] ?? 0];
                array_push($bucket['mandatory'][$unitKey]['items'], ...$items);
                unset($bucket);
                continue;
            }

            $boxReason = self::boxReason($line, $profile);
            if ($boxReason !== null) {
                foreach ($items as $item) {
                    $bucket['mandatory']['box:' . $item->id] = ['setId' => null, 'items' => [$item], 'reason' => $boxReason, 'weight' => $item->weight];
                }
                unset($bucket);
                continue;
            }

            array_push($bucket['items'], ...$items);
            unset($bucket);
        }

        ksort($shipments, SORT_STRING);
        $result = [];
        foreach ($shipments as $shipmentId => $bucket) {
            $mandatory = [];
            foreach ($bucket['mandatory'] as $unit) {
                $items = $unit['items'];
                \assert($items !== []);
                $mandatory[] = new MandatoryUnit($unit['setId'], $items, $unit['reason'], $unit['weight']);
            }
            [$groups, $fragile] = ItemGroup::split($bucket['items']);
            $result[] = new ShipmentPartition((string) $shipmentId, $mandatory, $groups, $fragile);
        }

        return $result;
    }

    /**
     * Stand-in SKU for a line without one: boxes with the same dimensions and weight
     * count as the same product. The leading NUL keeps it apart from any real SKU.
     */
    private static function physicalSku(Box $line): string
    {
        return "\0" . implode('x', [$line->length, $line->width, $line->height, $line->weight]);
    }

    private static function setKey(Box $line): string
    {
        return $line->shipmentId . "\0" . $line->setId;
    }

    private static function boxReason(Box $line, ResolvedProfile $profile): ?string
    {
        if (ResolvedProfile::over($line->weight, $profile->mandatoryBoxWeightOver)) {
            return MandatoryUnit::REASON_BOX_WEIGHT;
        }
        if (ResolvedProfile::over($line->width, $profile->mandatoryBoxWidthOver)) {
            return MandatoryUnit::REASON_BOX_WIDTH;
        }

        return null;
    }
}
