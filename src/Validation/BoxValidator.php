<?php

declare(strict_types=1);

namespace Palletwise\Validation;

use Palletwise\Layer\UpOption;
use Palletwise\Model\Box;
use Palletwise\Model\Mode;
use Palletwise\Model\PlanRequest;
use Palletwise\Model\Unplaced;
use Palletwise\Model\Warning;
use Palletwise\Model\WarningCode;
use Palletwise\Profile\ResolvedProfile;
use Palletwise\Units\Decimal;

/**
 * Flags out-of-spec boxes without stopping the calculation. Those that cannot be placed
 * go to `unplaced` (every unit of the line) with one warning per line.
 *
 * Order of the checks (the first one that fails wins):
 * unsupported length → single unit too tall → does not fit on the base → exceeds usable
 * height → exceeds payload. The heavy-box warning is independent. Rules the profile does
 * not define are skipped.
 *
 * @internal
 */
final class BoxValidator
{
    public function validate(PlanRequest $request, ResolvedProfile $profile, Mode $mode): ValidationResult
    {
        $placeable = [];
        $unplaced = [];
        $warnings = [];
        $usableHeight = $profile->usableHeight($mode);
        $payload = $profile->payload($mode);

        foreach ($request->boxes as $box) {
            if (ResolvedProfile::over($box->weight, $profile->heavyBoxWarningOver) && !$box->singleUnit) {
                $warnings[] = new Warning(
                    WarningCode::HeavyBox,
                    'Box exceeds the heavy-box threshold; it is valid only if it contains a single unit.',
                    $box->shipmentId,
                    $box->id,
                    details: ['weight' => Decimal::toFloat($box->weight), 'threshold' => Decimal::toFloat((int) $profile->heavyBoxWarningOver), 'quantity' => $box->quantity],
                );
            }

            $failure = $this->failure($box, $profile, $usableHeight, $payload);
            if ($failure === null) {
                $placeable[$box->id] = true;
                continue;
            }
            [$code, $message, $details] = $failure;
            $warnings[] = new Warning($code, $message, $box->shipmentId, $box->id, details: $details + ['quantity' => $box->quantity]);
            for ($n = 1; $n <= $box->quantity; ++$n) {
                $unplaced[] = new Unplaced(PlanRequest::unitId($box, $n), $box->id, $box->shipmentId, $code);
            }
        }

        return new ValidationResult($placeable, $unplaced, $warnings);
    }

    /**
     * @return array{WarningCode, string, array<string, float>}|null
     */
    private function failure(Box $box, ResolvedProfile $profile, int $usableHeight, int $payload): ?array
    {
        $f = Decimal::toFloat(...);
        $longest = max($box->length, $box->width);
        if (ResolvedProfile::over($longest, $profile->unsupportedLengthOver)) {
            return [WarningCode::BoxUnsupportedLength, 'Box length exceeds the supported maximum; it is not placed.', ['length' => $f($longest), 'limit' => $f((int) $profile->unsupportedLengthOver)]];
        }

        $options = UpOption::for($box->rotation ?? $profile->defaultRotation, $box->length, $box->width, $box->height);
        $lowest = min(array_map(static fn(UpOption $o): int => $o->dz, $options));
        if (ResolvedProfile::over($lowest, $profile->singleUnitHeightOver)) {
            return [WarningCode::SingleUnitTooTall, 'Single unit taller than the pallet height limit; it is not placed.', ['height' => $f($lowest), 'limit' => $f((int) $profile->singleUnitHeightOver)]];
        }

        $fitting = array_values(array_filter($options, static fn(UpOption $o): bool => $o->fitsBase($profile->palletLength, $profile->palletWidth)));
        if ($fitting === []) {
            return [WarningCode::BoxDoesNotFitBase, 'Box does not fit on the pallet base in any allowed orientation.', [
                'length' => $f($box->length), 'width' => $f($box->width), 'palletLength' => $f($profile->palletLength), 'palletWidth' => $f($profile->palletWidth),
            ]];
        }

        $lowestFitting = min(array_map(static fn(UpOption $o): int => $o->dz, $fitting));
        if ($lowestFitting > $usableHeight) {
            return [WarningCode::BoxExceedsHeightLimit, 'Box is taller than the usable pallet height for this mode.', ['height' => $f($lowestFitting), 'usableHeight' => $f($usableHeight)]];
        }

        if ($box->weight > $payload) {
            return [WarningCode::BoxExceedsWeightLimit, 'Box is heavier than the pallet payload for this mode.', ['weight' => $f($box->weight), 'payload' => $f($payload)]];
        }

        return null;
    }
}
