<?php

declare(strict_types=1);

namespace Palletwise\Verifier;

use Palletwise\Model\Mode;
use Palletwise\Model\ModeResult;
use Palletwise\Model\Pallet;
use Palletwise\Model\PalletType;
use Palletwise\Model\PlanResult;
use Palletwise\Model\Profile;
use Palletwise\Model\Rotation;
use Palletwise\Profile\ResolvedProfile;

/**
 * Independent check of a plan. It only uses simple geometry (axis-aligned boxes,
 * rectangle intersection and areas) and shares no placement code with the engine:
 * it rebuilds the physical boxes from the request on its own.
 *
 * Geometric checks need placements; if a result does not include them,
 * PLACEMENTS_MISSING (hard) is reported and the remaining rules are still checked.
 */
final class Verifier implements PlanVerifier
{
    /** Maximum center-of-gravity offset, as a percentage of each pallet side. */
    public const COG_MAX_OFFSET_PERCENT = 10;

    /**
     * @return list<Violation>
     */
    public function verify(PlanResult $result, Profile $profile): array
    {
        $request = $result->request;
        $violations = [];

        if ($profile->key() !== $request->profile) {
            return [new Violation(ViolationCode::ProfileMismatch, null, values: ['expected' => $request->profile, 'found' => $profile->key()])];
        }
        $resultModes = array_map(static fn(ModeResult $r): string => $r->mode->value, $result->results);
        $requestModes = array_map(static fn(Mode $m): string => $m->value, $request->modes);
        if ($resultModes !== $requestModes) {
            $violations[] = new Violation(ViolationCode::ModeMismatch, null, values: [
                'expected' => implode(',', $requestModes),
                'found' => implode(',', $resultModes),
            ]);
        }

        $resolved = ResolvedProfile::resolve($profile, $request->lengthUnit, $request->weightUnit, $request->overrides);
        $units = $this->expandUnits($result, $resolved);

        foreach ($result->results as $modeResult) {
            if (!$resolved->hasMode($modeResult->mode)) {
                $violations[] = new Violation(ViolationCode::ModeMismatch, $modeResult->mode, values: ['expected' => 'mode defined by profile', 'found' => $modeResult->mode->value]);
                continue;
            }
            array_push($violations, ...$this->verifyMode($modeResult, $resolved, $units));
        }

        return $violations;
    }

    /**
     * @return list<Violation>
     */
    public function hardViolations(PlanResult $result, Profile $profile): array
    {
        return array_values(array_filter($this->verify($result, $profile), static fn(Violation $v): bool => $v->isHard()));
    }

    /**
     * Expected physical boxes, indexed by id.
     *
     * @return array<string, VerifierUnit>
     */
    private function expandUnits(PlanResult $result, ResolvedProfile $profile): array
    {
        $setWeights = [];
        $setForced = [];
        foreach ($result->request->boxes as $box) {
            if ($box->setId === null) {
                continue;
            }
            $key = $box->shipmentId . "\0" . $box->setId;
            $setWeights[$key] = ($setWeights[$key] ?? 0) + $box->weight * $box->quantity;
            if (self::over($box->weight, $profile->mandatoryBoxWeightOver) || self::over($box->width, $profile->mandatoryBoxWidthOver)) {
                $setForced[$key] = true;
            }
        }

        $units = [];
        foreach ($result->request->boxes as $box) {
            $setKey = $box->setId === null ? null : $box->shipmentId . "\0" . $box->setId;
            $mandatory = self::over($box->weight, $profile->mandatoryBoxWeightOver)
                || self::over($box->width, $profile->mandatoryBoxWidthOver)
                || ($setKey !== null && (self::over($setWeights[$setKey] ?? 0, $profile->mandatorySetWeightOver) || isset($setForced[$setKey])));
            for ($n = 1; $n <= $box->quantity; ++$n) {
                $id = $box->quantity === 1 ? $box->id : $box->id . '#' . $n;
                $units[$id] = new VerifierUnit(
                    $id,
                    $box->shipmentId,
                    $box->sku,
                    $box->length,
                    $box->width,
                    $box->height,
                    $box->weight,
                    $box->rotation ?? $profile->defaultRotation,
                    $setKey,
                    $box->fragile,
                    $mandatory,
                );
            }
        }

        return $units;
    }

    /**
     * @param array<string, VerifierUnit> $units
     *
     * @return list<Violation>
     */
    private function verifyMode(ModeResult $result, ResolvedProfile $profile, array $units): array
    {
        $mode = $result->mode;
        $violations = [];

        if ($result->palletCount !== \count($result->pallets)) {
            $violations[] = new Violation(ViolationCode::PalletIndexInvalid, $mode, values: ['palletCount' => $result->palletCount, 'pallets' => \count($result->pallets)]);
        }
        if ($result->palletCount < $result->lowerBound) {
            $violations[] = new Violation(ViolationCode::BelowLowerBound, $mode, values: ['palletCount' => $result->palletCount, 'lowerBound' => $result->lowerBound]);
        }

        // Each box exactly once, across pallets and unplaced.
        $seen = [];
        foreach ($result->pallets as $i => $pallet) {
            if ($pallet->index !== $i + 1) {
                $violations[] = new Violation(ViolationCode::PalletIndexInvalid, $mode, $pallet->index, values: ['expected' => $i + 1]);
            }
            foreach ($pallet->boxIds as $id) {
                $seen[$id] = ($seen[$id] ?? 0) + 1;
            }
        }
        foreach ($result->unplaced as $unplaced) {
            $seen[$unplaced->boxId] = ($seen[$unplaced->boxId] ?? 0) + 1;
        }
        foreach ($seen as $id => $count) {
            $id = (string) $id;
            if (!isset($units[$id])) {
                $violations[] = new Violation(ViolationCode::BoxUnknown, $mode, boxId: $id);
            } elseif ($count > 1) {
                $violations[] = new Violation(ViolationCode::BoxDuplicated, $mode, boxId: $id, values: ['count' => $count]);
            }
        }
        foreach ($units as $id => $unit) {
            if (!isset($seen[$id])) {
                $violations[] = new Violation(ViolationCode::BoxMissing, $mode, boxId: $id);
            }
        }

        $missingPlacements = false;
        foreach ($result->pallets as $pallet) {
            if ($pallet->placements === null) {
                $missingPlacements = true;
            }
            array_push($violations, ...$this->verifyPallet($pallet, $mode, $profile, $units));
        }
        if ($missingPlacements) {
            $violations[] = new Violation(ViolationCode::PlacementsMissing, $mode);
        }

        return $violations;
    }

    /**
     * @param array<string, VerifierUnit> $units
     *
     * @return list<Violation>
     */
    private function verifyPallet(Pallet $pallet, Mode $mode, ResolvedProfile $profile, array $units): array
    {
        $index = $pallet->index;
        $limits = $profile->limits($mode);
        $violations = [];

        /** @var list<VerifierUnit> $palletUnits */
        $palletUnits = [];
        foreach ($pallet->boxIds as $id) {
            if (isset($units[$id])) {
                $palletUnits[] = $units[$id];
            }
        }

        // A single shipmentId per pallet, and the declared one.
        foreach ($palletUnits as $unit) {
            if ($unit->shipmentId !== $pallet->shipmentId) {
                $violations[] = new Violation(ViolationCode::MixedShipments, $mode, $index, $unit->id, ['expected' => $pallet->shipmentId, 'found' => $unit->shipmentId]);
            }
        }

        // Mandatory pallets: a single box or a single set. Mandatory boxes: only on mandatory pallets.
        if ($pallet->type === PalletType::Mandatory && \count($palletUnits) > 1) {
            $sets = array_unique(array_map(static fn(VerifierUnit $u): string => $u->setKey ?? ("\0box:" . $u->id), $palletUnits));
            if (\count($sets) > 1 || $palletUnits[0]->setKey === null) {
                $violations[] = new Violation(ViolationCode::MandatoryPalletMixed, $mode, $index, values: ['boxes' => \count($palletUnits)]);
            }
        }
        if ($pallet->type !== PalletType::Mandatory) {
            foreach ($palletUnits as $unit) {
                if ($unit->mandatory) {
                    $violations[] = new Violation(ViolationCode::MandatoryNotIsolated, $mode, $index, $unit->id);
                }
            }
        }

        // Weight.
        $weight = $profile->tareWeight;
        foreach ($palletUnits as $unit) {
            $weight += $unit->weight;
        }
        if ($weight !== $pallet->weight) {
            $violations[] = new Violation(ViolationCode::WeightMismatch, $mode, $index, values: ['reported' => $pallet->weight, 'computed' => $weight]);
        }
        if ($weight > $limits->maxWeight) {
            $violations[] = new Violation(ViolationCode::WeightExceeded, $mode, $index, values: ['weight' => $weight, 'max' => $limits->maxWeight]);
        }

        // Layers: contiguous from the pallet deck and with the pallet's box count.
        $z = 0;
        $layerBoxes = 0;
        foreach ($pallet->layers as $layer) {
            if ($layer->z !== $z || $layer->height <= 0) {
                $violations[] = new Violation(ViolationCode::LayersMismatch, $mode, $index, values: ['expectedZ' => $z, 'foundZ' => $layer->z]);
            }
            $z = $layer->z + $layer->height;
            $layerBoxes += $layer->boxCount;
        }
        if ($layerBoxes !== \count($pallet->boxIds)) {
            $violations[] = new Violation(ViolationCode::LayersMismatch, $mode, $index, values: ['layerBoxes' => $layerBoxes, 'boxes' => \count($pallet->boxIds)]);
        }

        if ($pallet->placements === null) {
            // Without placements only the declared height can be checked.
            if ($pallet->height > $limits->maxHeight) {
                $violations[] = new Violation(ViolationCode::HeightExceeded, $mode, $index, values: ['height' => $pallet->height, 'max' => $limits->maxHeight]);
            }

            return $violations;
        }

        // Placements: the same boxes as boxIds.
        $boxes = [];
        $placedIds = [];
        foreach ($pallet->placements as $p) {
            $placedIds[] = $p->boxId;
            if (!isset($units[$p->boxId])) {
                continue;
            }
            $boxes[] = new VerifierBox($units[$p->boxId], $p->x, $p->y, $p->z, $p->length, $p->width, $p->height, $p->orientation->value);
        }
        $sortedPlaced = $placedIds;
        $sortedIds = $pallet->boxIds;
        sort($sortedPlaced, SORT_STRING);
        sort($sortedIds, SORT_STRING);
        if ($sortedPlaced !== $sortedIds) {
            $violations[] = new Violation(ViolationCode::PlacementMismatch, $mode, $index, values: ['placements' => \count($placedIds), 'boxIds' => \count($pallet->boxIds)]);
        }

        // Height.
        $top = 0;
        foreach ($pallet->placements as $p) {
            $top = max($top, $p->z + $p->height);
        }
        $height = $profile->tareHeight + $top;
        if ($height !== $pallet->height) {
            $violations[] = new Violation(ViolationCode::HeightMismatch, $mode, $index, values: ['reported' => $pallet->height, 'computed' => $height]);
        }
        if ($height > $limits->maxHeight) {
            $violations[] = new Violation(ViolationCode::HeightExceeded, $mode, $index, values: ['height' => $height, 'max' => $limits->maxHeight]);
        }
        if ($z !== $top) {
            $violations[] = new Violation(ViolationCode::LayersMismatch, $mode, $index, values: ['layersTop' => $z, 'boxesTop' => $top]);
        }

        array_push(
            $violations,
            ...$this->checkRotationAndBounds($boxes, $profile, $mode, $index),
            ...$this->checkOverlaps($boxes, $mode, $index),
            ...$this->checkSupportAndFragile($boxes, $profile, $mode, $index),
            ...$this->checkSoftRules($boxes, $profile, $mode, $index),
        );

        return $violations;
    }

    /**
     * @param list<VerifierBox> $boxes
     *
     * @return list<Violation>
     */
    private function checkRotationAndBounds(array $boxes, ResolvedProfile $profile, Mode $mode, int $index): array
    {
        $violations = [];
        foreach ($boxes as $b) {
            $u = $b->unit;
            $expected = self::dimsFor($b->orientation, $u->length, $u->width, $u->height);
            $allowed = match ($u->rotation) {
                Rotation::Fixed => ['LWH'],
                Rotation::KeepUpright => ['LWH', 'WLH'],
                Rotation::Any => ['LWH', 'WLH', 'LHW', 'HLW', 'WHL', 'HWL'],
            };
            if (!\in_array($b->orientation, $allowed, true) || $expected !== [$b->dx, $b->dy, $b->dz]) {
                $violations[] = new Violation(ViolationCode::RotationNotAllowed, $mode, $index, $u->id, [
                    'rotation' => $u->rotation->value,
                    'orientation' => $b->orientation,
                ]);
            }
            if ($b->x < 0 || $b->y < 0 || $b->z < 0
                || $b->x + $b->dx > $profile->palletLength
                || $b->y + $b->dy > $profile->palletWidth) {
                $violations[] = new Violation(ViolationCode::Overhang, $mode, $index, $u->id, [
                    'x' => $b->x, 'y' => $b->y, 'z' => $b->z, 'length' => $b->dx, 'width' => $b->dy,
                ]);
            }
        }

        return $violations;
    }

    /**
     * Sweep along x: only pairs whose x intervals overlap are compared.
     *
     * @param list<VerifierBox> $boxes
     *
     * @return list<Violation>
     */
    private function checkOverlaps(array $boxes, Mode $mode, int $index): array
    {
        usort($boxes, static fn(VerifierBox $a, VerifierBox $b): int => [$a->x, $a->unit->id] <=> [$b->x, $b->unit->id]);
        $violations = [];
        $n = \count($boxes);
        for ($i = 0; $i < $n; ++$i) {
            $a = $boxes[$i];
            for ($j = $i + 1; $j < $n && $boxes[$j]->x < $a->x + $a->dx; ++$j) {
                $b = $boxes[$j];
                if (self::overlap1d($a->y, $a->dy, $b->y, $b->dy) > 0 && self::overlap1d($a->z, $a->dz, $b->z, $b->dz) > 0) {
                    $violations[] = new Violation(ViolationCode::Overlap, $mode, $index, $a->unit->id, ['other' => $b->unit->id]);
                }
            }
        }

        return $violations;
    }

    /**
     * @param list<VerifierBox> $boxes
     *
     * @return list<Violation>
     */
    private function checkSupportAndFragile(array $boxes, ResolvedProfile $profile, Mode $mode, int $index): array
    {
        $violations = [];
        $byTop = [];
        foreach ($boxes as $b) {
            $byTop[$b->z + $b->dz][] = $b;
        }

        foreach ($boxes as $b) {
            if ($b->z === 0) {
                continue;
            }
            $supported = 0;
            foreach ($byTop as $top => $candidates) {
                if ($top > $b->z || $top < $b->z - $profile->bandTolerance) {
                    continue;
                }
                foreach ($candidates as $below) {
                    $supported += self::footprintOverlap($b, $below);
                }
            }
            $area = $b->dx * $b->dy;
            if ($supported * 1000 < $profile->minSupport * $area) {
                $violations[] = new Violation(ViolationCode::InsufficientSupport, $mode, $index, $b->unit->id, [
                    'supportedArea' => $supported,
                    'baseArea' => $area,
                    'minSupport' => $profile->minSupport,
                ]);
            }
        }

        foreach ($boxes as $fragile) {
            if (!$fragile->unit->fragile) {
                continue;
            }
            foreach ($boxes as $other) {
                if ($other !== $fragile && $other->z >= $fragile->z + $fragile->dz && self::footprintOverlap($other, $fragile) > 0) {
                    $violations[] = new Violation(ViolationCode::FragileLoaded, $mode, $index, $fragile->unit->id, ['above' => $other->unit->id]);
                }
            }
        }

        return $violations;
    }

    /**
     * Soft rules: off-center center of gravity and heavy boxes on light ones.
     *
     * @param list<VerifierBox> $boxes
     *
     * @return list<Violation>
     */
    private function checkSoftRules(array $boxes, ResolvedProfile $profile, Mode $mode, int $index): array
    {
        $violations = [];
        $totalWeight = 0;
        $momentX = 0;
        $momentY = 0;
        foreach ($boxes as $b) {
            $totalWeight += $b->unit->weight;
            // Moments about the pallet center, with doubled coordinates to avoid halves.
            $momentX += $b->unit->weight * (2 * $b->x + $b->dx - $profile->palletLength);
            $momentY += $b->unit->weight * (2 * $b->y + $b->dy - $profile->palletWidth);
        }
        if ($totalWeight > 0) {
            // |offset| > p% of the side  ⇔  |moment| · 50 > p · side · weight
            $limitX = self::COG_MAX_OFFSET_PERCENT * $profile->palletLength * $totalWeight;
            $limitY = self::COG_MAX_OFFSET_PERCENT * $profile->palletWidth * $totalWeight;
            if (abs($momentX) * 50 > $limitX || abs($momentY) * 50 > $limitY) {
                $violations[] = new Violation(ViolationCode::CenterOfGravityOffset, $mode, $index, values: [
                    'offsetX' => intdiv($momentX, 2 * $totalWeight),
                    'offsetY' => intdiv($momentY, 2 * $totalWeight),
                ]);
            }
        }

        $byTop = [];
        foreach ($boxes as $b) {
            $byTop[$b->z + $b->dz][] = $b;
        }
        $pairs = 0;
        $example = null;
        foreach ($boxes as $upper) {
            foreach ($byTop[$upper->z] ?? [] as $lower) {
                if ($upper->z > 0 && $upper->unit->weight > $lower->unit->weight && self::footprintOverlap($upper, $lower) > 0) {
                    ++$pairs;
                    $example ??= $upper->unit->id;
                }
            }
        }
        if ($pairs > 0) {
            $violations[] = new Violation(ViolationCode::HeavyOnLight, $mode, $index, $example, ['pairs' => $pairs]);
        }

        return $violations;
    }

    /**
     * @return array{int, int, int}
     */
    private static function dimsFor(string $orientation, int $l, int $w, int $h): array
    {
        $map = ['L' => $l, 'W' => $w, 'H' => $h];
        if (\strlen($orientation) !== 3 || count_chars($orientation, 3) !== 'HLW') {
            return [-1, -1, -1];
        }

        return [$map[$orientation[0]], $map[$orientation[1]], $map[$orientation[2]]];
    }

    private static function overlap1d(int $a, int $da, int $b, int $db): int
    {
        return max(0, min($a + $da, $b + $db) - max($a, $b));
    }

    private static function footprintOverlap(VerifierBox $a, VerifierBox $b): int
    {
        return self::overlap1d($a->x, $a->dx, $b->x, $b->dx) * self::overlap1d($a->y, $a->dy, $b->y, $b->dy);
    }

    /** Value over an optional threshold (null = no such rule). */
    private static function over(int $value, ?int $threshold): bool
    {
        return $threshold !== null && $value > $threshold;
    }
}
