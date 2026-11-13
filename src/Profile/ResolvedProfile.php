<?php

declare(strict_types=1);

namespace Palletwise\Profile;

use Palletwise\Exception\InvalidPlanRequest;
use Palletwise\Model\Mode;
use Palletwise\Model\ModeLimits;
use Palletwise\Model\Overrides;
use Palletwise\Model\Profile;
use Palletwise\Model\Rotation;
use Palletwise\Units\IntMath;
use Palletwise\Units\LengthUnit;
use Palletwise\Units\Rounding;
use Palletwise\Units\UnitConverter;
use Palletwise\Units\WeightUnit;

/**
 * Profile expressed in the request units (integers scaled ×1000) with the overrides
 * applied. Each value is rounded in the direction that makes the limits stricter:
 * dimensions and maximums down, pallet tare up, "more than X" thresholds down
 * (more boxes fall under the rule).
 */
final readonly class ResolvedProfile
{
    public function __construct(
        public Profile $profile,
        public LengthUnit $lengthUnit,
        public WeightUnit $weightUnit,
        public int $palletLength,
        public int $palletWidth,
        public int $tareHeight,
        public int $tareWeight,
        public ?ModeLimits $nonStackable,
        public ?ModeLimits $stackable,
        public ?int $mandatoryBoxWeightOver,
        public ?int $mandatoryBoxWidthOver,
        public ?int $mandatorySetWeightOver,
        public ?int $unsupportedLengthOver,
        public ?int $heavyBoxWarningOver,
        public ?int $singleUnitHeightOver,
        public Rotation $defaultRotation,
        public int $minSupport,
        public int $bandTolerance,
        public ?int $parcelMinFill,
    ) {}

    public static function resolve(Profile $profile, LengthUnit $lengthUnit, WeightUnit $weightUnit, ?Overrides $overrides = null): self
    {
        $overrides ??= new Overrides();
        $length = static fn(int $v, Rounding $r): int => UnitConverter::length($v, $profile->lengthUnit, $lengthUnit, $r);
        $weight = static fn(int $v, Rounding $r): int => UnitConverter::weight($v, $profile->weightUnit, $weightUnit, $r);
        $limits = static fn(?ModeLimits $m): ?ModeLimits => $m === null ? null : new ModeLimits(
            $length($m->maxHeight, Rounding::Floor),
            $weight($m->maxWeight, Rounding::Floor),
        );

        return new self(
            profile: $profile,
            lengthUnit: $lengthUnit,
            weightUnit: $weightUnit,
            palletLength: $length($profile->palletLength, Rounding::Floor),
            palletWidth: $length($profile->palletWidth, Rounding::Floor),
            tareHeight: $overrides->tareHeight ?? $length($profile->tareHeight, Rounding::Ceil),
            tareWeight: $overrides->tareWeight ?? $weight($profile->tareWeight, Rounding::Ceil),
            nonStackable: $limits($profile->nonStackable),
            stackable: $limits($profile->stackable),
            mandatoryBoxWeightOver: $profile->mandatoryBoxWeightOver === null ? null : $weight($profile->mandatoryBoxWeightOver, Rounding::Floor),
            mandatoryBoxWidthOver: $profile->mandatoryBoxWidthOver === null ? null : $length($profile->mandatoryBoxWidthOver, Rounding::Floor),
            mandatorySetWeightOver: $profile->mandatorySetWeightOver === null ? null : $weight($profile->mandatorySetWeightOver, Rounding::Floor),
            unsupportedLengthOver: $profile->unsupportedLengthOver === null ? null : $length($profile->unsupportedLengthOver, Rounding::Floor),
            heavyBoxWarningOver: $profile->heavyBoxWarningOver === null ? null : $weight($profile->heavyBoxWarningOver, Rounding::Floor),
            singleUnitHeightOver: $profile->singleUnitHeightOver === null ? null : $length($profile->singleUnitHeightOver, Rounding::Floor),
            defaultRotation: $profile->defaultRotation,
            minSupport: $overrides->minSupport ?? $profile->minSupport,
            bandTolerance: $overrides->bandTolerance ?? $length($profile->bandTolerance, Rounding::Floor),
            parcelMinFill: $overrides->parcelMinFill ?? $profile->parcelMinFill,
        );
    }

    /**
     * Whether a value is over an optional threshold (null = the profile has no such rule).
     */
    public static function over(int $value, ?int $threshold): bool
    {
        return $threshold !== null && $value > $threshold;
    }

    public function hasMode(Mode $mode): bool
    {
        return ($mode === Mode::Stackable ? $this->stackable : $this->nonStackable) !== null;
    }

    public function limits(Mode $mode): ModeLimits
    {
        $limits = $mode === Mode::Stackable ? $this->stackable : $this->nonStackable;
        if ($limits === null) {
            throw InvalidPlanRequest::because(\sprintf('Profile "%s" does not define mode "%s".', $this->profile->key(), $mode->value));
        }

        return $limits;
    }

    /**
     * Checks that, with the overrides applied, the mode leaves usable height and weight.
     *
     * @throws InvalidPlanRequest
     */
    public function assertUsable(Mode $mode): void
    {
        if ($this->usableHeight($mode) <= 0) {
            throw InvalidPlanRequest::because(\sprintf('The pallet tare height leaves no usable height in mode "%s".', $mode->value));
        }
        if ($this->payload($mode) <= 0) {
            throw InvalidPlanRequest::because(\sprintf('The pallet tare weight leaves no payload in mode "%s".', $mode->value));
        }
    }

    /** Height available for boxes: the mode maximum minus the pallet. */
    public function usableHeight(Mode $mode): int
    {
        return $this->limits($mode)->maxHeight - $this->tareHeight;
    }

    /** Weight available for boxes: the mode maximum minus the pallet. */
    public function payload(Mode $mode): int
    {
        return $this->limits($mode)->maxWeight - $this->tareWeight;
    }

    /** Base area in millionths of unit². */
    public function palletArea(): int
    {
        return IntMath::mul($this->palletLength, $this->palletWidth);
    }

    /** Usable volume of a pallet in thousandths of unit³. */
    public function usableVolume(Mode $mode, Rounding $rounding): int
    {
        return IntMath::volume($this->palletLength, $this->palletWidth, $this->usableHeight($mode), $rounding);
    }
}
