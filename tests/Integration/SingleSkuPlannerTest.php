<?php

declare(strict_types=1);

namespace Palletwise\Tests\Integration;

use Palletwise\Exception\EngineInvariantViolation;
use Palletwise\Exception\InvalidPlanRequest;
use Palletwise\Model\Mode;
use Palletwise\Model\Pallet;
use Palletwise\Model\PlanResult;
use Palletwise\Model\Profile;
use Palletwise\Planner\Planner;
use Palletwise\Tests\Support\Requests;
use Palletwise\Verifier\PlanVerifier;
use Palletwise\Verifier\Violation;
use Palletwise\Verifier\ViolationCode;
use PHPUnit\Framework\TestCase;

final class SingleSkuPlannerTest extends TestCase
{
    /**
     * @return list<int>
     */
    private static function boxCounts(PlanResult $plan, Mode $mode = Mode::NonStackable): array
    {
        $result = $plan->forMode($mode);
        self::assertNotNull($result);

        return array_map(static fn(Pallet $p): int => $p->boxCount(), $result->pallets);
    }

    public function testHundredsOfIdenticalBoxes(): void
    {
        // 12×10×8: 16 per layer × 8 layers = 128 per pallet.
        $result = Requests::mode([Requests::box('A', 12, 10, 8, 10, 1000)]);

        self::assertSame(8, $result->palletCount);
        self::assertSame([128, 128, 128, 128, 128, 128, 128, 104], array_map(static fn(Pallet $p): int => $p->boxCount(), $result->pallets));
        self::assertSame(8, $result->lowerBound);
        $first = $result->pallets[0];
        self::assertSame('single_sku', $first->type->value);
        self::assertSame(69_000, $first->height); // 8 × 8 + 5
        self::assertSame(1_320_000, $first->weight); // 128 × 10 + 40
        self::assertCount(8, $first->layers);
        self::assertSame(9_552, $first->volumeUtilization); // 122880 / 128640
        self::assertNull($first->placements);
    }

    public function testWeightLimitedPallets(): void
    {
        // 6×5×4 at 10 lb: 1024 fit by height, 146 by weight.
        self::assertSame([146], self::boxCounts(Requests::plan([Requests::box('A', 6, 5, 4, 10, 146)])));
        self::assertSame([146, 1], self::boxCounts(Requests::plan([Requests::box('A', 6, 5, 4, 10, 147)])));
        self::assertSame([145, 1], self::boxCounts(Requests::plan([Requests::box('A', 6, 5, 4, 10.001, 146)])));
    }

    public function testBoxesThatFitByOneThousandth(): void
    {
        // 24 × 20: 4 per layer. 24.001 × 20: 3 (two blocks). 48.001: does not fit.
        $exact = Requests::mode([Requests::box('A', 24, 20, 67, 10, 8)], ['includePlacements' => true]);
        self::assertSame([4, 4], array_map(static fn(Pallet $p): int => $p->boxCount(), $exact->pallets));
        self::assertSame(72_000, $exact->pallets[0]->height);

        $over = Requests::mode([Requests::box('A', 24.001, 20, 67, 10, 8)]);
        self::assertSame([3, 3, 2], array_map(static fn(Pallet $p): int => $p->boxCount(), $over->pallets));

        $tooTall = Requests::mode([Requests::box('A', 24, 20, 67.001, 10, 1)]);
        self::assertSame(0, $tooTall->palletCount);
        self::assertSame('BOX_EXCEEDS_HEIGHT_LIMIT', $tooTall->unplaced[0]->reason->value);
    }

    public function testMetricRequestAgainstImperialProfile(): void
    {
        $imperial = Requests::mode([Requests::box('A', 12, 10, 8, 10, 300)]);
        $metric = Requests::mode([Requests::box('A', 30.48, 25.4, 20.32, 4.5359237, 300)], ['lengthUnit' => 'cm', 'weightUnit' => 'kg']);

        self::assertSame(3, $imperial->palletCount);
        self::assertSame($imperial->palletCount, $metric->palletCount);
        self::assertSame(
            array_map(static fn(Pallet $p): int => $p->boxCount(), $imperial->pallets),
            array_map(static fn(Pallet $p): int => $p->boxCount(), $metric->pallets),
        );
        // 8 layers of 20.32 cm + 12.7 of pallet; 128 × 4.536 + 18.144 kg.
        self::assertSame(175_260, $metric->pallets[0]->height);
        self::assertSame(598_752, $metric->pallets[0]->weight);
    }

    public function testMandatoryPallets(): void
    {
        $result = Requests::mode([
            Requests::box('H', 20, 20, 20, 120, 2),
            Requests::box('W', 30, 31, 10, 20),
            Requests::box('A', 12, 10, 8, 10, 10),
        ], ['includePlacements' => true]);

        self::assertSame(['mandatory', 'mandatory', 'mandatory', 'single_sku'], array_map(static fn(Pallet $p): string => $p->type->value, $result->pallets));
        self::assertSame([['H#1'], ['H#2'], ['W']], array_map(static fn(Pallet $p): array => $p->boxIds, \array_slice($result->pallets, 0, 3)));
        self::assertSame(3, $result->lowerBounds['mandatory']);
        self::assertSame(4, $result->palletCount);
        // The box is centered.
        $placement = $result->pallets[0]->placements[0] ?? null;
        self::assertNotNull($placement);
        self::assertSame([14_000, 10_000, 0], [$placement->x, $placement->y, $placement->z]);
        self::assertSame(3, \count(array_filter(Requests::warningCodes($result), static fn(string $c): bool => $c === 'MANDATORY_OWN_PALLET')));
    }

    public function testHeavySetGetsItsOwnPallet(): void
    {
        $result = Requests::mode([
            Requests::box('K1', 12, 10, 8, 40, 2, ['setId' => 'KIT']),
            Requests::box('K2', 12, 10, 8, 30, 1, ['setId' => 'KIT']),
            Requests::box('A', 12, 10, 8, 10, 2),
        ]);

        self::assertSame('mandatory', $result->pallets[0]->type->value);
        $setIds = [];
        foreach ($result->pallets as $pallet) {
            if ($pallet->type->value === 'mandatory') {
                array_push($setIds, ...$pallet->boxIds);
            }
        }
        sort($setIds);
        self::assertSame(['K1#1', 'K1#2', 'K2'], $setIds);
    }

    public function testShipmentsNeverShareAPallet(): void
    {
        $result = Requests::mode([
            Requests::box('A', 12, 10, 8, 10, 5, ['shipmentId' => 'FBA-B']),
            Requests::box('B', 12, 10, 8, 10, 5, ['shipmentId' => 'FBA-A', 'sku' => 'SKU-A']),
        ]);

        self::assertSame(['FBA-A', 'FBA-B'], array_map(static fn(Pallet $p): string => $p->shipmentId, $result->pallets));
        self::assertSame(2, $result->lowerBound);
    }

    public function testUnplacedBoxesAreReportedAndTheRestIsPlanned(): void
    {
        $result = Requests::mode([
            Requests::box('BIG', 49, 41, 10, 10, 2),
            Requests::box('LONG', 81, 10, 10, 10),
            Requests::box('OK', 12, 10, 8, 10, 3),
        ]);

        self::assertSame(1, $result->palletCount);
        self::assertSame(['BIG#1', 'BIG#2', 'LONG'], array_map(static fn($u): string => $u->boxId, $result->unplaced));
        self::assertSame(['BOX_DOES_NOT_FIT_BASE', 'BOX_UNSUPPORTED_LENGTH'], Requests::warningCodes($result));
    }

    public function testBothModes(): void
    {
        $plan = Requests::plan([Requests::box('A', 12, 10, 8, 10, 300)], ['modes' => ['non_stackable', 'stackable']]);

        self::assertSame([128, 128, 44], self::boxCounts($plan));
        // Stackable: 5 layers (45 in) and 71 boxes by weight (710 lb).
        self::assertSame([71, 71, 71, 71, 16], self::boxCounts($plan, Mode::Stackable));
        $stackable = $plan->forMode(Mode::Stackable);
        self::assertNotNull($stackable);
        self::assertContains('UNCONFIRMED_PROFILE_VALUES', Requests::warningCodes($stackable));
    }

    public function testPlacementsOnlyWhenRequested(): void
    {
        $with = Requests::mode([Requests::box('A', 12, 10, 8, 10, 20)], ['includePlacements' => true]);
        $placements = $with->pallets[0]->placements;
        self::assertNotNull($placements);
        self::assertCount(20, $placements);
        self::assertSame(['A#1', 'A#2'], [$placements[0]->boxId, $placements[1]->boxId]);

        $without = Requests::mode([Requests::box('A', 12, 10, 8, 10, 20)]);
        self::assertNull($without->pallets[0]->placements);
        self::assertArrayNotHasKey('placements', $without->pallets[0]->toArray());
    }

    public function testUnknownProfileOrModeIsARequestError(): void
    {
        try {
            Requests::plan([Requests::box('A', 1, 1, 1, 1)], ['profile' => 'amazon-mx@2026-11']);
            self::fail('Expected InvalidPlanRequest');
        } catch (InvalidPlanRequest $e) {
            self::assertSame('Unknown or invalid profile "amazon-mx@2026-11".', $e->getMessage());
        }

        $this->expectException(InvalidPlanRequest::class);
        $this->expectExceptionMessage('does not define mode "stackable"');
        Requests::plan([Requests::box('A', 1, 1, 1, 1)], ['profile' => 'amazon-eu@2026-11', 'lengthUnit' => 'cm', 'weightUnit' => 'kg', 'modes' => ['stackable']]);
    }

    public function testTareOverridesMustLeaveRoom(): void
    {
        $this->expectException(InvalidPlanRequest::class);
        Requests::plan([Requests::box('A', 1, 1, 1, 1)], ['overrides' => ['tareWeight' => 1500]]);
    }

    public function testHardViolationsThrow(): void
    {
        $verifier = new class implements PlanVerifier {
            public function verify(PlanResult $result, Profile $profile): array
            {
                return [new Violation(ViolationCode::Overlap, Mode::NonStackable, 1, 'A')];
            }
        };

        try {
            (new Planner(verifier: $verifier))->plan(Requests::request([Requests::box('A', 1, 1, 1, 1)]));
            self::fail('Expected EngineInvariantViolation');
        } catch (EngineInvariantViolation $e) {
            self::assertSame('Internal planning error: the generated plan failed verification (OVERLAP).', $e->getMessage());
            self::assertCount(1, $e->violations());
        }
    }

    public function testDeterministicJson(): void
    {
        $boxes = [Requests::box('A', 16, 12, 9.5, 12.25, 77), Requests::box('B', 20, 20, 20, 150)];
        $a = Requests::plan($boxes, ['includePlacements' => true, 'modes' => ['non_stackable', 'stackable']])->toJson();
        $b = Requests::plan($boxes, ['includePlacements' => true, 'modes' => ['non_stackable', 'stackable']])->toJson();

        self::assertSame($a, $b);
        self::assertSame(hash('sha256', $a), hash('sha256', $b));
    }
}
