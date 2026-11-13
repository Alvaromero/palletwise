<?php

declare(strict_types=1);

namespace Palletwise\Tests\Unit\Units;

use Palletwise\Units\IntMath;
use Palletwise\Units\LengthUnit;
use Palletwise\Units\Rounding;
use Palletwise\Units\UnitConverter;
use Palletwise\Units\WeightUnit;
use PHPUnit\Framework\TestCase;

final class UnitConverterTest extends TestCase
{
    public function testExactLengthConversions(): void
    {
        self::assertSame(121_920, UnitConverter::length(48_000, LengthUnit::Inch, LengthUnit::Centimeter, Rounding::Floor));
        self::assertSame(1_219_200, UnitConverter::length(48_000, LengthUnit::Inch, LengthUnit::Millimeter, Rounding::Floor));
        self::assertSame(48_000, UnitConverter::length(1_219_200, LengthUnit::Millimeter, LengthUnit::Inch, Rounding::Floor));
        self::assertSame(12_000, UnitConverter::length(12_000, LengthUnit::Inch, LengthUnit::Inch, Rounding::Ceil));
    }

    public function testInexactConversionsRoundInRequestedDirection(): void
    {
        // 100 cm = 39.37007874 in
        self::assertSame(39_370, UnitConverter::length(100_000, LengthUnit::Centimeter, LengthUnit::Inch, Rounding::Floor));
        self::assertSame(39_371, UnitConverter::length(100_000, LengthUnit::Centimeter, LengthUnit::Inch, Rounding::Ceil));
        // 1500 lb = 680.38855 kg; 40 lb = 18.1436948 kg
        self::assertSame(680_388, UnitConverter::weight(1_500_000, WeightUnit::Pound, WeightUnit::Kilogram, Rounding::Floor));
        self::assertSame(18_144, UnitConverter::weight(40_000, WeightUnit::Pound, WeightUnit::Kilogram, Rounding::Ceil));
        // 500 kg = 1102.31131 lb
        self::assertSame(1_102_311, UnitConverter::weight(500_000, WeightUnit::Kilogram, WeightUnit::Pound, Rounding::Floor));
    }

    public function testIntMath(): void
    {
        self::assertSame(-2, IntMath::floorDiv(-3, 2));
        self::assertSame(2, IntMath::ceilDiv(3, 2));
        self::assertSame(-1, IntMath::ceilDiv(-3, 2));
        self::assertSame(3_333, IntMath::ratio(1, 3, 4));
        self::assertSame(10_000, IntMath::ratio(7, 7, 4));
        self::assertSame(1_000, IntMath::volume(1_000, 1_000, 1_000));
        $this->expectException(\OverflowException::class);
        IntMath::mul(PHP_INT_MAX, 2);
    }
}
