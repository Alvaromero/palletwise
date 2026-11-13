<?php

declare(strict_types=1);

namespace Palletwise\Tests\Unit\Validation;

use Palletwise\Model\Mode;
use Palletwise\Model\PlanRequest;
use Palletwise\Model\Unplaced;
use Palletwise\Model\Warning;
use Palletwise\Profile\ProfileRepository;
use Palletwise\Profile\ResolvedProfile;
use Palletwise\Tests\Support\Requests;
use Palletwise\Validation\BoxValidator;
use Palletwise\Validation\ValidationResult;
use PHPUnit\Framework\TestCase;

final class BoxValidatorTest extends TestCase
{
    private static function validate(PlanRequest $request, Mode $mode = Mode::NonStackable): ValidationResult
    {
        $profile = ResolvedProfile::resolve((new ProfileRepository())->load($request->profile), $request->lengthUnit, $request->weightUnit, $request->overrides);

        return (new BoxValidator())->validate($request, $profile, $mode);
    }

    /**
     * @return array<string, string>
     */
    private static function reasons(ValidationResult $result): array
    {
        $out = [];
        foreach ($result->unplaced as $u) {
            $out[$u->boxId] = $u->reason->value;
        }

        return $out;
    }

    public function testReasons(): void
    {
        $result = self::validate(Requests::request([
            Requests::box('OK', 48, 40, 67, 1460),
            Requests::box('LONG', 80.001, 10, 10, 10),
            Requests::box('LONG-OK', 80, 10, 10, 10, extra: ['rotation' => 'any']),
            Requests::box('TALL', 10, 10, 72.001, 10),
            Requests::box('TALL-ANY', 10, 10, 72.001, 10, extra: ['rotation' => 'any']),
            Requests::box('WIDE', 48.001, 10, 10, 10),
            Requests::box('TURN', 40, 48, 10, 10),
            Requests::box('FIXED', 40, 48, 10, 10, extra: ['rotation' => 'fixed']),
            Requests::box('HIGH', 10, 10, 67.001, 10),
            Requests::box('HEAVY', 10, 10, 10, 1460.001),
            Requests::box('LAY', 60, 10, 30, 10, extra: ['rotation' => 'any']),
        ]));

        self::assertSame([
            'LONG' => 'BOX_UNSUPPORTED_LENGTH',
            // 80 in is the exact length limit, but upright it exceeds the usable height.
            'LONG-OK' => 'BOX_EXCEEDS_HEIGHT_LIMIT',
            'TALL' => 'SINGLE_UNIT_TOO_TALL',
            // Lying down it no longer exceeds 72 in, but it does not fit in plan; upright it fits in plan but not in height.
            'TALL-ANY' => 'BOX_EXCEEDS_HEIGHT_LIMIT',
            'WIDE' => 'BOX_DOES_NOT_FIT_BASE',
            'FIXED' => 'BOX_DOES_NOT_FIT_BASE',
            'HIGH' => 'BOX_EXCEEDS_HEIGHT_LIMIT',
            'HEAVY' => 'BOX_EXCEEDS_WEIGHT_LIMIT',
        ], self::reasons($result));
        self::assertArrayHasKey('TURN', $result->placeableLines);
        // 60 long does not fit in plan, but with free rotation it stands upright (60 ≤ 67).
        self::assertArrayHasKey('LAY', $result->placeableLines);
        self::assertArrayHasKey('OK', $result->placeableLines);
    }

    public function testStackableModeLimits(): void
    {
        $result = self::validate(Requests::request([
            Requests::box('H', 10, 10, 45.001, 10),
            Requests::box('W', 10, 10, 10, 710.001),
            Requests::box('OK', 10, 10, 45, 710),
        ], ['modes' => ['stackable']]), Mode::Stackable);

        self::assertSame(['H' => 'BOX_EXCEEDS_HEIGHT_LIMIT', 'W' => 'BOX_EXCEEDS_WEIGHT_LIMIT'], self::reasons($result));
    }

    public function testEveryUnitOfAnUnplacedLineIsReported(): void
    {
        $result = self::validate(Requests::request([Requests::box('X', 50, 41, 10, 10, 3)]));

        self::assertSame(['X#1', 'X#2', 'X#3'], array_map(static fn(Unplaced $u): string => $u->boxId, $result->unplaced));
        self::assertCount(1, $result->warnings);
        self::assertSame('BOX_DOES_NOT_FIT_BASE', $result->warnings[0]->code->value);
        self::assertSame(3, $result->warnings[0]->details['quantity']);
    }

    public function testHeavyBoxWarning(): void
    {
        $result = self::validate(Requests::request([
            Requests::box('A', 10, 10, 10, 50),
            Requests::box('B', 10, 10, 10, 50.001),
            Requests::box('C', 10, 10, 10, 60, extra: ['singleUnit' => true]),
            Requests::box('D', 10, 10, 10, 60, extra: ['singleUnit' => false]),
        ]));

        $codes = array_map(static fn(Warning $w): string => $w->code->value . ':' . $w->boxId, $result->warnings);
        self::assertSame(['HEAVY_BOX:B', 'HEAVY_BOX:D'], $codes);
        self::assertSame([], $result->unplaced);
    }

    public function testMetricRequestAgainstImperialProfile(): void
    {
        // 50 lb = 22.6796 kg → threshold 22.679 kg; 48 in = 121.92 cm.
        $result = self::validate(Requests::request([
            Requests::box('A', 121.92, 101.6, 10, 22.679),
            Requests::box('B', 10, 10, 10, 22.68),
            Requests::box('C', 121.93, 10, 10, 1),
        ], ['lengthUnit' => 'cm', 'weightUnit' => 'kg']));

        self::assertSame(['C' => 'BOX_DOES_NOT_FIT_BASE'], self::reasons($result));
        self::assertSame(['B'], array_map(static fn(Warning $w): ?string => $w->boxId, array_values(array_filter($result->warnings, static fn(Warning $w): bool => $w->code->value === 'HEAVY_BOX'))));
    }
}
