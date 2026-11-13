<?php

declare(strict_types=1);

namespace Palletwise\Model;

/**
 * Stable warning codes. Also used as the reason in `unplaced[]`.
 */
enum WarningCode: string
{
    /** Box over 50 lb without `singleUnit`: valid only if it contains a single unit. */
    case HeavyBox = 'HEAVY_BOX';

    /** Box longer than 80 in: unsupported case, not placed. */
    case BoxUnsupportedLength = 'BOX_UNSUPPORTED_LENGTH';

    /** A single unit taller than 72 in: not placed. */
    case SingleUnitTooTall = 'SINGLE_UNIT_TOO_TALL';

    /** Does not fit on the pallet base in any allowed orientation. */
    case BoxDoesNotFitBase = 'BOX_DOES_NOT_FIT_BASE';

    /** Fits on the base, but exceeds the usable height of the mode. */
    case BoxExceedsHeightLimit = 'BOX_EXCEEDS_HEIGHT_LIMIT';

    /** Exceeds the payload of the mode on its own. */
    case BoxExceedsWeightLimit = 'BOX_EXCEEDS_WEIGHT_LIMIT';

    /** The box or set requires its own pallet. */
    case MandatoryOwnPallet = 'MANDATORY_OWN_PALLET';

    /** A mandatory set does not fit on one pallet and is split across several (only boxes from the set). */
    case SetSplitAcrossPallets = 'SET_SPLIT_ACROSS_PALLETS';

    /** The result depends on profile values marked as unconfirmed. */
    case UnconfirmedProfileValues = 'UNCONFIRMED_PROFILE_VALUES';

    /** Soft rule: the center of gravity is off-center. */
    case CenterOfGravityOffset = 'CENTER_OF_GRAVITY_OFFSET';

    /** Soft rule: some boxes rest on lighter boxes. */
    case HeavyOnLight = 'HEAVY_ON_LIGHT';
}
