<?php

declare(strict_types=1);

namespace Palletwise\Tests\Unit\Verifier;

use Palletwise\Model\Mode;
use Palletwise\Model\Orientation;
use Palletwise\Model\PalletType;
use Palletwise\Model\PlanResult;
use Palletwise\Model\Profile;
use Palletwise\Model\Rotation;
use Palletwise\Profile\ProfileRepository;
use Palletwise\Tests\Support\PlanFixture;
use Palletwise\Verifier\Severity;
use Palletwise\Verifier\Verifier;
use Palletwise\Verifier\Violation;
use Palletwise\Verifier\ViolationCode;
use PHPUnit\Framework\TestCase;

final class VerifierTest extends TestCase
{
    private static function profile(string $key = 'amazon-us@2026-11'): Profile
    {
        return (new ProfileRepository())->load($key);
    }

    /**
     * @return list<string>
     */
    private static function codes(PlanResult $plan): array
    {
        $codes = array_map(static fn(Violation $v): string => $v->code->value, (new Verifier())->verify($plan, self::profile()));
        sort($codes);

        return array_values(array_unique($codes));
    }

    /**
     * Two layers of two 48x20 boxes: a valid, centered plan.
     */
    private static function validFixture(): PlanFixture
    {
        return (new PlanFixture())
            ->box('A', 48, 20, 10, 30)
            ->box('B', 48, 20, 10, 30)
            ->box('C', 48, 20, 10, 20)
            ->box('D', 48, 20, 10, 20)
            ->pallet([
                PlanFixture::at('A', 0, 0, 0, 48, 20, 10),
                PlanFixture::at('B', 0, 20, 0, 48, 20, 10),
                PlanFixture::at('C', 0, 0, 10, 48, 20, 10),
                PlanFixture::at('D', 0, 20, 10, 48, 20, 10),
            ])
            ->lowerBound(1);
    }

    public function testValidPlanHasNoViolations(): void
    {
        self::assertSame([], self::codes(self::validFixture()->build()));
    }

    public function testWithoutPlacementsReportsMissingPlacementsOnly(): void
    {
        self::assertSame(['PLACEMENTS_MISSING'], self::codes(self::validFixture()->build(stripPlacements: true)));
    }

    public function testMissingDuplicatedAndUnknownBoxes(): void
    {
        $plan = (new PlanFixture())
            ->box('A', 10, 10, 10, 5)
            ->box('B', 10, 10, 10, 5)
            ->pallet([PlanFixture::at('A', 19, 15, 0, 10, 10, 10)])
            ->pallet([PlanFixture::at('X', 19, 15, 0, 10, 10, 10)])
            ->unplaced('A')
            ->lowerBound(1)
            ->build();

        self::assertSame(['BOX_DUPLICATED', 'BOX_MISSING', 'BOX_UNKNOWN'], self::codes($plan));
    }

    public function testOverhang(): void
    {
        $plan = (new PlanFixture())
            ->box('A', 30, 20, 10, 5)
            ->pallet([PlanFixture::at('A', 18.001, 10, 0, 30, 20, 10)])
            ->build();

        self::assertContains('OVERHANG', self::codes($plan));
    }

    public function testExactFitIsNotOverhang(): void
    {
        $plan = (new PlanFixture())
            ->box('A', 48, 40, 10, 5)
            ->pallet([PlanFixture::at('A', 0, 0, 0, 48, 40, 10)], type: PalletType::Mandatory)
            ->build();

        self::assertSame([], self::codes($plan));
    }

    public function testOverlap(): void
    {
        $plan = (new PlanFixture())
            ->box('A', 24, 40, 10, 5)
            ->box('B', 24, 40, 10, 5)
            ->pallet([
                PlanFixture::at('A', 0, 0, 0, 24, 40, 10),
                PlanFixture::at('B', 23.999, 0, 0, 24, 40, 10),
            ])
            ->build();

        self::assertContains('OVERLAP', self::codes($plan));
    }

    public function testTouchingBoxesDoNotOverlap(): void
    {
        $plan = (new PlanFixture())
            ->box('A', 24, 40, 10, 5)
            ->box('B', 24, 40, 10, 5)
            ->pallet([
                PlanFixture::at('A', 0, 0, 0, 24, 40, 10),
                PlanFixture::at('B', 24, 0, 0, 24, 40, 10),
            ])
            ->build();

        self::assertNotContains('OVERLAP', self::codes($plan));
    }

    public function testInsufficientSupport(): void
    {
        // C (20x20) rests 16x20 = 80 % on A: exactly at the limit. D rests 15x20 = 75 %.
        $base = (new PlanFixture())
            ->box('A', 24, 40, 10, 30)
            ->box('C', 20, 20, 10, 5);
        $ok = (clone $base)->pallet([
            PlanFixture::at('A', 0, 0, 0, 24, 40, 10),
            PlanFixture::at('C', 8, 10, 10, 20, 20, 10),
        ])->build();
        $bad = (clone $base)->pallet([
            PlanFixture::at('A', 0, 0, 0, 24, 40, 10),
            PlanFixture::at('C', 9, 10, 10, 20, 20, 10),
        ])->build();

        self::assertNotContains('INSUFFICIENT_SUPPORT', self::codes($ok));
        self::assertContains('INSUFFICIENT_SUPPORT', self::codes($bad));
    }

    public function testSupportCountsBoxesWithinTheBandTolerance(): void
    {
        $plan = static fn(float $heightB): PlanResult => (new PlanFixture())
            ->box('A', 48, 20, 10, 30)
            ->box('B', 48, 20, $heightB, 30)
            ->box('C', 48, 30, 10, 5)
            ->pallet([
                PlanFixture::at('A', 0, 0, 0, 48, 20, 10),
                PlanFixture::at('B', 0, 20, 0, 48, 20, $heightB),
                PlanFixture::at('C', 0, 5, 10, 48, 30, 10),
            ])
            ->build();

        // C rests 15/30 on A and 15/30 on B. B at 1 in below (the tolerance) counts;
        // at 1.001 in, it does not.
        self::assertNotContains('INSUFFICIENT_SUPPORT', self::codes($plan(9)));
        self::assertContains('INSUFFICIENT_SUPPORT', self::codes($plan(8.999)));
    }

    public function testFragileMustNotBeLoaded(): void
    {
        $plan = (new PlanFixture())
            ->box('A', 48, 40, 10, 30, ['fragile' => true])
            ->box('B', 48, 40, 10, 5)
            ->pallet([
                PlanFixture::at('A', 0, 0, 0, 48, 40, 10),
                PlanFixture::at('B', 0, 0, 10, 48, 40, 10),
            ])
            ->build();

        self::assertContains('FRAGILE_LOADED', self::codes($plan));
    }

    public function testRotation(): void
    {
        $plan = (new PlanFixture())
            ->box('A', 30, 20, 10, 5)
            ->box('B', 30, 20, 10, 5, ['rotation' => Rotation::Any])
            ->box('C', 30, 20, 10, 5, ['rotation' => Rotation::Fixed])
            ->pallet([
                PlanFixture::at('A', 0, 0, 0, 30, 10, 20, Orientation::LHW),
                PlanFixture::at('B', 0, 20, 0, 30, 10, 20, Orientation::LHW),
            ])
            ->pallet([PlanFixture::at('C', 14, 5, 0, 20, 30, 10, Orientation::WLH)])
            ->build();

        $violations = (new Verifier())->verify($plan, self::profile());
        $rotation = array_values(array_filter($violations, static fn(Violation $v): bool => $v->code === ViolationCode::RotationNotAllowed));
        self::assertSame(['A', 'C'], array_map(static fn(Violation $v): ?string => $v->boxId, $rotation));
    }

    public function testDimensionsMustMatchOrientation(): void
    {
        $plan = (new PlanFixture())
            ->box('A', 30, 20, 10, 5)
            ->pallet([PlanFixture::at('A', 9, 10, 0, 30, 20, 9)])
            ->build();

        self::assertContains('ROTATION_NOT_ALLOWED', self::codes($plan));
    }

    public function testHeightAndWeightLimits(): void
    {
        // 7 layers of 10 in = 70 in + 5 of pallet = 75 > 72; 7 × 210 + 40 = 1510 > 1500.
        $fixture = new PlanFixture();
        $placements = [];
        for ($i = 0; $i < 7; ++$i) {
            $fixture->box('L' . $i, 48, 40, 10, 210);
            $placements[] = PlanFixture::at('L' . $i, 0, 0, 10 * $i, 48, 40, 10);
        }
        $codes = self::codes($fixture->pallet($placements)->build());

        self::assertContains('HEIGHT_EXCEEDED', $codes);
        self::assertContains('WEIGHT_EXCEEDED', $codes);
    }

    public function testStackableModeIsStricter(): void
    {
        // 50 in tall with pallet in stackable: 46 in of boxes do not fit.
        $plan = (new PlanFixture())
            ->box('A', 48, 40, 46, 100)
            ->pallet([PlanFixture::at('A', 0, 0, 0, 48, 40, 46)])
            ->build(Mode::Stackable);

        self::assertContains('HEIGHT_EXCEEDED', self::codes($plan));
    }

    public function testReportedTotalsMustMatch(): void
    {
        $plan = (new PlanFixture())
            ->box('A', 48, 40, 10, 5)
            ->pallet([PlanFixture::at('A', 0, 0, 0, 48, 40, 10)], type: PalletType::Mandatory, force: ['height' => 14_000, 'weight' => 46_000])
            ->build();

        self::assertSame(['HEIGHT_MISMATCH', 'WEIGHT_MISMATCH'], self::codes($plan));
    }

    public function testLayersMustBeConsistent(): void
    {
        $plan = (new PlanFixture())
            ->box('A', 48, 40, 10, 5)
            ->pallet([PlanFixture::at('A', 0, 0, 0, 48, 40, 10)], type: PalletType::Mandatory, force: ['layers' => []])
            ->build();

        self::assertSame(['LAYERS_MISMATCH'], self::codes($plan));
    }

    public function testOneShipmentPerPallet(): void
    {
        $plan = (new PlanFixture())
            ->box('A', 48, 20, 10, 5)
            ->box('B', 48, 20, 10, 5, ['shipmentId' => 'S2'])
            ->pallet([
                PlanFixture::at('A', 0, 0, 0, 48, 20, 10),
                PlanFixture::at('B', 0, 20, 0, 48, 20, 10),
            ])
            ->build();

        self::assertSame(['MIXED_SHIPMENTS'], self::codes($plan));
    }

    public function testMandatoryBoxesNeedTheirOwnPallet(): void
    {
        $plan = (new PlanFixture())
            ->box('HEAVY', 24, 40, 10, 100.001)
            ->box('WIDE', 20, 30.001, 10, 5)
            ->pallet([
                PlanFixture::at('HEAVY', 0, 0, 0, 24, 40, 10),
                PlanFixture::at('WIDE', 24, 0, 0, 20, 30.001, 10),
            ])
            ->build();

        self::assertContains('MANDATORY_NOT_ISOLATED', self::codes($plan));
    }

    public function testMandatoryPalletHoldsOneBoxOrOneSet(): void
    {
        $set = (new PlanFixture())
            ->box('S-A', 24, 40, 10, 60, ['setId' => 'KIT'])
            ->box('S-B', 24, 40, 10, 60, ['setId' => 'KIT'])
            ->pallet([
                PlanFixture::at('S-A', 0, 0, 0, 24, 40, 10),
                PlanFixture::at('S-B', 24, 0, 0, 24, 40, 10),
            ], type: PalletType::Mandatory)
            ->build();
        self::assertSame([], self::codes($set));

        $setOnMixed = (new PlanFixture())
            ->box('S-A', 24, 40, 10, 60, ['setId' => 'KIT'])
            ->box('S-B', 24, 40, 10, 60, ['setId' => 'KIT'])
            ->pallet([
                PlanFixture::at('S-A', 0, 0, 0, 24, 40, 10),
                PlanFixture::at('S-B', 24, 0, 0, 24, 40, 10),
            ])
            ->build();
        self::assertSame(['MANDATORY_NOT_ISOLATED'], self::codes($setOnMixed));

        $mixed = (new PlanFixture())
            ->box('A', 24, 40, 10, 5)
            ->box('B', 24, 40, 10, 5)
            ->pallet([
                PlanFixture::at('A', 0, 0, 0, 24, 40, 10),
                PlanFixture::at('B', 24, 0, 0, 24, 40, 10),
            ], type: PalletType::Mandatory)
            ->build();
        self::assertSame(['MANDATORY_PALLET_MIXED'], self::codes($mixed));
    }

    public function testLowerBoundAndIndices(): void
    {
        $plan = (new PlanFixture())
            ->box('A', 48, 40, 10, 5)
            ->pallet([PlanFixture::at('A', 0, 0, 0, 48, 40, 10)], type: PalletType::Mandatory)
            ->lowerBound(2)
            ->build();

        self::assertSame(['BELOW_LOWER_BOUND'], self::codes($plan));
    }

    public function testSoftRules(): void
    {
        $plan = (new PlanFixture())
            ->box('LIGHT', 12, 12, 10, 5)
            ->box('HEAVY', 12, 12, 10, 50)
            ->pallet([
                PlanFixture::at('LIGHT', 0, 0, 0, 12, 12, 10),
                PlanFixture::at('HEAVY', 0, 0, 10, 12, 12, 10),
            ])
            ->build();

        $violations = (new Verifier())->verify($plan, self::profile());
        $codes = array_map(static fn(Violation $v): string => $v->code->value, $violations);
        sort($codes);
        self::assertSame(['CENTER_OF_GRAVITY_OFFSET', 'HEAVY_ON_LIGHT'], $codes);
        foreach ($violations as $v) {
            self::assertSame(Severity::Soft, $v->severity);
            self::assertFalse($v->isHard());
        }
        self::assertSame([], (new Verifier())->hardViolations($plan, self::profile()));
    }

    public function testProfileMismatch(): void
    {
        $codes = array_map(
            static fn(Violation $v): string => $v->code->value,
            (new Verifier())->verify(self::validFixture()->build(), self::profile('amazon-eu@2026-11')),
        );
        self::assertSame(['PROFILE_MISMATCH'], $codes);
    }

    public function testViolationToString(): void
    {
        $plan = (new PlanFixture())
            ->box('A', 30, 20, 10, 5)
            ->pallet([PlanFixture::at('A', 18.001, 10, 0, 30, 20, 10)])
            ->build();
        $violation = (new Verifier())->hardViolations($plan, self::profile())[0];
        self::assertSame('OVERHANG mode=non_stackable pallet=1 box=A x=18001 y=10000 z=0 length=30000 width=20000', (string) $violation);
    }
}
