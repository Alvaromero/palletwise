<?php

declare(strict_types=1);

namespace Palletwise\Tests\Integration;

use Palletwise\Model\Mode;
use Palletwise\Model\PlanRequest;
use Palletwise\Planner\Planner;
use Palletwise\Profile\ProfileRepository;
use Palletwise\Tests\Support\Requests;
use PHPUnit\Framework\TestCase;

/**
 * The same shipment planned with different destination profiles.
 */
final class ProfilesTest extends TestCase
{
    private const WALMART = ['profile' => 'walmart-us@2026-11'];

    public function testWalmartHasNoMandatoryOwnPallet(): void
    {
        // 120 lb and 36 in wide: Amazon requires one pallet per box, Walmart stacks both.
        $boxes = [Requests::box('BIG', 40, 36, 10, 120, 2)];

        $amazon = Requests::mode($boxes);
        $walmart = Requests::mode($boxes, self::WALMART);

        self::assertSame(2, $amazon->palletCount);
        self::assertContains('MANDATORY_OWN_PALLET', Requests::warningCodes($amazon));
        self::assertSame(1, $walmart->palletCount);
        self::assertNotContains('MANDATORY_OWN_PALLET', Requests::warningCodes($walmart));
    }

    public function testWalmartHeightLimitIsStrictlyUnder72Inches(): void
    {
        // 11 in boxes: Amazon fits 6 layers (67 in usable), Walmart 5 (71.999 − 6 = 65.999 in).
        $boxes = [Requests::box('A', 12, 10, 11, 10, 96)];

        self::assertSame(1, Requests::mode($boxes)->palletCount);
        $walmart = Requests::mode($boxes, self::WALMART);
        self::assertSame(2, $walmart->palletCount);
        foreach ($walmart->pallets as $pallet) {
            self::assertLessThan(72_000, $pallet->height);
        }
    }

    public function testWalmartHeavyBoxWarningAt150Pounds(): void
    {
        $result = Requests::mode([
            Requests::box('OK', 20, 20, 20, 140),
            Requests::box('HEAVY', 20, 20, 20, 160),
        ], self::WALMART);

        $heavy = array_values(array_filter($result->warnings, static fn($w): bool => $w->code->value === 'HEAVY_BOX'));
        self::assertCount(1, $heavy);
        self::assertSame('HEAVY', $heavy[0]->boxId);
        self::assertContains('UNCONFIRMED_PROFILE_VALUES', Requests::warningCodes($result));
    }

    public function testCustomProfileRegisteredInMemory(): void
    {
        $profile = ProfileRepository::parse([
            'id' => 'acme-3pl',
            'version' => '2027-01',
            'description' => 'A 3PL with 120 x 80 cm pallets and no box rules.',
            'units' => ['length' => 'cm', 'weight' => 'kg'],
            'pallet' => ['length' => 120, 'width' => 80, 'overhang' => 0, 'tareHeight' => 14.4, 'tareWeight' => 25],
            'modes' => ['non_stackable' => ['maxHeight' => 160, 'maxWeight' => 800]],
            'defaults' => ['rotation' => 'keep_upright', 'minSupport' => 0.8, 'bandTolerance' => 2, 'parcelMinFill' => 0.3],
        ]);
        $planner = new Planner((new ProfileRepository())->withProfile($profile));

        $plan = $planner->plan(PlanRequest::fromArray([
            'profile' => 'acme-3pl@2027-01',
            'lengthUnit' => 'cm',
            'weightUnit' => 'kg',
            'boxes' => [Requests::box('A', 40, 30, 30, 8, 32)],
        ]));
        $result = $plan->forMode(Mode::NonStackable);

        self::assertNotNull($result);
        // 8 per layer × 4 layers (145.6 cm usable) = 32 boxes, 256 kg.
        self::assertSame(1, $result->palletCount);
        self::assertSame('acme-3pl', $plan->meta->profile);
        self::assertSame([], Requests::warningCodes($result));
    }
}
