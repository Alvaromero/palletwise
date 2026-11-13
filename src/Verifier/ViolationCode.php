<?php

declare(strict_types=1);

namespace Palletwise\Verifier;

/**
 * Stable codes of the Verifier checks.
 */
enum ViolationCode: string
{
    case ProfileMismatch = 'PROFILE_MISMATCH';
    case ModeMismatch = 'MODE_MISMATCH';
    case PlacementsMissing = 'PLACEMENTS_MISSING';
    case BoxMissing = 'BOX_MISSING';
    case BoxDuplicated = 'BOX_DUPLICATED';
    case BoxUnknown = 'BOX_UNKNOWN';
    case PlacementMismatch = 'PLACEMENT_MISMATCH';
    case Overhang = 'OVERHANG';
    case Overlap = 'OVERLAP';
    case InsufficientSupport = 'INSUFFICIENT_SUPPORT';
    case FragileLoaded = 'FRAGILE_LOADED';
    case RotationNotAllowed = 'ROTATION_NOT_ALLOWED';
    case HeightExceeded = 'HEIGHT_EXCEEDED';
    case WeightExceeded = 'WEIGHT_EXCEEDED';
    case HeightMismatch = 'HEIGHT_MISMATCH';
    case WeightMismatch = 'WEIGHT_MISMATCH';
    case LayersMismatch = 'LAYERS_MISMATCH';
    case MixedShipments = 'MIXED_SHIPMENTS';
    case MandatoryNotIsolated = 'MANDATORY_NOT_ISOLATED';
    case MandatoryPalletMixed = 'MANDATORY_PALLET_MIXED';
    case PalletIndexInvalid = 'PALLET_INDEX_INVALID';
    case BelowLowerBound = 'BELOW_LOWER_BOUND';
    case CenterOfGravityOffset = 'CENTER_OF_GRAVITY_OFFSET';
    case HeavyOnLight = 'HEAVY_ON_LIGHT';

    public function severity(): Severity
    {
        return match ($this) {
            self::CenterOfGravityOffset, self::HeavyOnLight => Severity::Soft,
            default => Severity::Hard,
        };
    }
}
