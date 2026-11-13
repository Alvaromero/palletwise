<?php

declare(strict_types=1);

namespace Palletwise\Model;

use Palletwise\Units\LengthUnit;
use Palletwise\Units\WeightUnit;

/**
 * Palletization rule profile, in its own units (integers scaled ×1000;
 * ratios in thousandths). Obtained with ProfileRepository::load().
 *
 * Box rules and mandatory-pallet thresholds are optional: null means the profile does not
 * have that rule.
 */
final readonly class Profile
{
    /**
     * @param list<string> $unconfirmed paths of values not confirmed by an official source; "*" = the whole profile
     */
    public function __construct(
        public string $id,
        public string $version,
        public string $description,
        public LengthUnit $lengthUnit,
        public WeightUnit $weightUnit,
        public int $palletLength,
        public int $palletWidth,
        public int $overhang,
        public int $tareHeight,
        public int $tareWeight,
        public ModeLimits $nonStackable,
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
        public array $unconfirmed,
    ) {}

    public function key(): string
    {
        return $this->id . '@' . $this->version;
    }

    /**
     * Limits of the mode, or null if the profile does not define it.
     */
    public function limits(Mode $mode): ?ModeLimits
    {
        return $mode === Mode::Stackable ? $this->stackable : $this->nonStackable;
    }

    public function isUnconfirmed(string $path): bool
    {
        return \in_array('*', $this->unconfirmed, true) || \in_array($path, $this->unconfirmed, true);
    }

    /**
     * Unconfirmed paths that a calculation in the given mode depends on.
     *
     * @return list<string>
     */
    public function unconfirmedFor(Mode $mode): array
    {
        if (\in_array('*', $this->unconfirmed, true)) {
            return ['*'];
        }
        $prefixOther = 'modes.' . ($mode === Mode::Stackable ? Mode::NonStackable->value : Mode::Stackable->value) . '.';

        return array_values(array_filter(
            $this->unconfirmed,
            static fn(string $path): bool => !str_starts_with($path, $prefixOther),
        ));
    }
}
