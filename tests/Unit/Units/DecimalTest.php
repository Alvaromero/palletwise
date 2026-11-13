<?php

declare(strict_types=1);

namespace Palletwise\Tests\Unit\Units;

use Palletwise\Units\Decimal;
use Palletwise\Units\Rounding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DecimalTest extends TestCase
{
    /**
     * @return iterable<string, array{int|float|string, Rounding, int}>
     */
    public static function conversions(): iterable
    {
        yield 'int' => [12, Rounding::Ceil, 12_000];
        yield 'float exact' => [12.5, Rounding::Ceil, 12_500];
        yield 'float 0.1' => [0.1, Rounding::Ceil, 100];
        yield 'float 0.3 (binary noise)' => [0.3, Rounding::Floor, 300];
        yield 'string' => ['24.001', Rounding::Floor, 24_001];
        yield 'extra decimals ceil' => ['24.0005', Rounding::Ceil, 24_001];
        yield 'extra decimals floor' => ['24.0005', Rounding::Floor, 24_000];
        yield 'float extra decimals ceil' => [24.0005, Rounding::Ceil, 24_001];
        yield 'exponent' => ['1.5e2', Rounding::Ceil, 150_000];
        yield 'negative exponent' => ['15e-1', Rounding::Ceil, 1_500];
        yield 'tiny positive ceil' => ['1e-9', Rounding::Ceil, 1];
        yield 'tiny positive floor' => ['1e-9', Rounding::Floor, 0];
        yield 'negative ceil' => ['-1.0005', Rounding::Ceil, -1_000];
        yield 'negative floor' => ['-1.0005', Rounding::Floor, -1_001];
        yield 'leading dot' => ['.25', Rounding::Ceil, 250];
        yield 'trailing dot' => ['7.', Rounding::Ceil, 7_000];
        yield 'zero' => ['0.000', Rounding::Ceil, 0];
        yield 'negative zero' => ['-0', Rounding::Floor, 0];
    }

    #[DataProvider('conversions')]
    public function testToScaled(int|float|string $input, Rounding $rounding, int $expected): void
    {
        self::assertSame($expected, Decimal::toScaled($input, $rounding));
    }

    /**
     * @return iterable<string, array{int|float|string}>
     */
    public static function invalid(): iterable
    {
        yield 'empty' => [''];
        yield 'dot' => ['.'];
        yield 'text' => ['abc'];
        yield 'comma' => ['1,5'];
        yield 'spaces' => [' 1'];
        yield 'nan' => [NAN];
        yield 'inf' => [INF];
        yield 'too large' => ['1e20'];
        yield 'too large int' => [PHP_INT_MAX];
    }

    #[DataProvider('invalid')]
    public function testRejectsInvalid(int|float|string $input): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Decimal::toScaled($input, Rounding::Ceil);
    }

    public function testIsNumeric(): void
    {
        self::assertTrue(Decimal::isNumeric(3));
        self::assertTrue(Decimal::isNumeric(3.5));
        self::assertTrue(Decimal::isNumeric('3.5'));
        self::assertFalse(Decimal::isNumeric('3,5'));
        self::assertFalse(Decimal::isNumeric(true));
        self::assertFalse(Decimal::isNumeric(null));
        self::assertFalse(Decimal::isNumeric(NAN));
        self::assertFalse(Decimal::isNumeric('.'));
    }

    public function testFormat(): void
    {
        self::assertSame('12.5', Decimal::format(12_500));
        self::assertSame('12', Decimal::format(12_000));
        self::assertSame('0.001', Decimal::format(1));
        self::assertSame('-3.25', Decimal::format(-3_250));
        self::assertSame('0', Decimal::format(0));
    }

    public function testRoundTrip(): void
    {
        foreach (['0.001', '48', '121.92', '680.388'] as $value) {
            self::assertSame($value, Decimal::format(Decimal::toScaled($value, Rounding::Ceil)));
        }
        self::assertSame(12.5, Decimal::toFloat(12_500));
    }
}
