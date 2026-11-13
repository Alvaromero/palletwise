<?php

declare(strict_types=1);

namespace Palletwise\Tests\Unit\Profile;

use Palletwise\Exception\InvalidPlanRequest;
use Palletwise\Model\Mode;
use Palletwise\Model\Overrides;
use Palletwise\Profile\ProfileRepository;
use Palletwise\Profile\ResolvedProfile;
use Palletwise\Units\LengthUnit;
use Palletwise\Units\Rounding;
use Palletwise\Units\WeightUnit;
use PHPUnit\Framework\TestCase;

final class ResolvedProfileTest extends TestCase
{
    public function testSameUnitsKeepValues(): void
    {
        $p = ResolvedProfile::resolve((new ProfileRepository())->load('amazon-us@2026-11'), LengthUnit::Inch, WeightUnit::Pound);

        self::assertSame(48_000, $p->palletLength);
        self::assertSame(67_000, $p->usableHeight(Mode::NonStackable));
        self::assertSame(1_460_000, $p->payload(Mode::NonStackable));
        self::assertSame(45_000, $p->usableHeight(Mode::Stackable));
        self::assertSame(710_000, $p->payload(Mode::Stackable));
        self::assertSame(48_000 * 40_000, $p->palletArea());
        self::assertSame(48 * 40 * 67 * 1000, $p->usableVolume(Mode::NonStackable, Rounding::Floor));
    }

    public function testUsProfileInMetricRoundsConservatively(): void
    {
        $p = ResolvedProfile::resolve((new ProfileRepository())->load('amazon-us@2026-11'), LengthUnit::Centimeter, WeightUnit::Kilogram);

        // Exact: 48 in = 121.92 cm, 40 in = 101.6 cm, 72 in = 182.88 cm, 5 in = 12.7 cm.
        self::assertSame(121_920, $p->palletLength);
        self::assertSame(101_600, $p->palletWidth);
        self::assertSame(182_880, $p->limits(Mode::NonStackable)->maxHeight);
        self::assertSame(12_700, $p->tareHeight);
        // Inexact: maximums rounded down and the pallet rounded up.
        self::assertSame(680_388, $p->limits(Mode::NonStackable)->maxWeight); // 680.38855...
        self::assertSame(18_144, $p->tareWeight); // 18.1436948
        self::assertSame(45_359, $p->mandatoryBoxWeightOver); // 45.359237
        self::assertSame(22_679, $p->heavyBoxWarningOver); // 22.6796185
        self::assertSame(76_200, $p->mandatoryBoxWidthOver);
        self::assertSame(203_200, $p->unsupportedLengthOver);
        self::assertSame(2_540, $p->bandTolerance);
        self::assertSame(800, $p->minSupport);
    }

    public function testUsProfileInMillimeters(): void
    {
        $p = ResolvedProfile::resolve((new ProfileRepository())->load('amazon-us@2026-11'), LengthUnit::Millimeter, WeightUnit::Pound);

        self::assertSame(1_219_200, $p->palletLength);
        self::assertSame(1_701_800, $p->usableHeight(Mode::NonStackable));
    }

    public function testEuProfileInImperialRoundsConservatively(): void
    {
        $p = ResolvedProfile::resolve((new ProfileRepository())->load('amazon-eu@2026-11'), LengthUnit::Inch, WeightUnit::Pound);

        // 120 cm = 47.244094 in, 100 cm = 39.370078 in, 180 cm = 70.866141 in, 15 cm = 5.905511 in.
        self::assertSame(47_244, $p->palletLength);
        self::assertSame(39_370, $p->palletWidth);
        self::assertSame(70_866, $p->limits(Mode::NonStackable)->maxHeight);
        self::assertSame(5_906, $p->tareHeight);
        // 500 kg = 1102.311311 lb, 25 kg = 55.115566 lb.
        self::assertSame(1_102_311, $p->limits(Mode::NonStackable)->maxWeight);
        self::assertSame(55_116, $p->tareWeight);
        self::assertFalse($p->hasMode(Mode::Stackable));
    }

    public function testOverridesAreInRequestUnits(): void
    {
        $p = ResolvedProfile::resolve(
            (new ProfileRepository())->load('amazon-us@2026-11'),
            LengthUnit::Centimeter,
            WeightUnit::Kilogram,
            new Overrides(tareHeight: 14, tareWeight: '20.5', minSupport: 0.9, bandTolerance: 3, parcelMinFill: 0.4),
        );

        self::assertSame(14_000, $p->tareHeight);
        self::assertSame(20_500, $p->tareWeight);
        self::assertSame(900, $p->minSupport);
        self::assertSame(3_000, $p->bandTolerance);
        self::assertSame(400, $p->parcelMinFill);
        self::assertSame(182_880 - 14_000, $p->usableHeight(Mode::NonStackable));
    }

    public function testUnknownModeIsARequestError(): void
    {
        $p = ResolvedProfile::resolve((new ProfileRepository())->load('amazon-eu@2026-11'), LengthUnit::Centimeter, WeightUnit::Kilogram);
        $this->expectException(InvalidPlanRequest::class);
        $p->limits(Mode::Stackable);
    }

    public function testTareOverrideMustLeaveRoom(): void
    {
        $p = ResolvedProfile::resolve(
            (new ProfileRepository())->load('amazon-us@2026-11'),
            LengthUnit::Inch,
            WeightUnit::Pound,
            new Overrides(tareHeight: 72),
        );
        $this->expectException(InvalidPlanRequest::class);
        $p->assertUsable(Mode::NonStackable);
    }
}
