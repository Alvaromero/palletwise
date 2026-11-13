<?php

declare(strict_types=1);

namespace Palletwise\Tests\Integration;

use Palletwise\Model\Box;
use Palletwise\Model\Mode;
use Palletwise\Model\Overrides;
use Palletwise\Model\PlanRequest;
use Palletwise\Model\Rotation;
use Palletwise\Planner\Planner;
use Palletwise\Profile\ProfileRepository;
use Palletwise\Units\LengthUnit;
use Palletwise\Units\WeightUnit;
use PHPUnit\Framework\TestCase;

/**
 * The README examples must work as written.
 */
final class ReadmeExamplesTest extends TestCase
{
    public function testQuickStart(): void
    {
        $request = PlanRequest::fromArray([
            'profile' => 'amazon-us@2026-11',
            'lengthUnit' => 'in',
            'weightUnit' => 'lb',
            'boxes' => [
                ['id' => 'CASE-A', 'shipmentId' => 'FBA15ABC', 'sku' => 'MUG-01', 'length' => 18, 'width' => 12, 'height' => 10, 'weight' => 22.5, 'quantity' => 40],
                ['id' => 'CASE-B', 'shipmentId' => 'FBA15ABC', 'sku' => 'LAMP-02', 'length' => 24, 'width' => 16, 'height' => 14, 'weight' => 31, 'quantity' => 6, 'fragile' => true],
                ['id' => 'TABLE', 'shipmentId' => 'FBA15ABC', 'sku' => 'TBL-03', 'length' => 40, 'width' => 32, 'height' => 8, 'weight' => 120, 'quantity' => 1],
            ],
        ]);

        $plan = (new Planner())->plan($request);

        self::assertSame(3, $plan->results[0]->palletCount);
        self::assertSame(2, $plan->results[0]->lowerBound);
        self::assertStringStartsWith('{"meta":{"libraryVersion":"1.0.0"', $plan->toJson());
    }

    public function testObjectApi(): void
    {
        $request = new PlanRequest(
            profile: 'amazon-us@2026-11',
            lengthUnit: LengthUnit::Centimeter,
            weightUnit: WeightUnit::Kilogram,
            boxes: [
                new Box('A', 'FBA15ABC', 'SKU-1', 45.7, 30.5, 25.4, 10.2, quantity: 24),
                new Box('B', 'FBA15ABC', 'SKU-2', '60', '40', '30', '18.5', rotation: Rotation::Any),
            ],
            modes: [Mode::NonStackable, Mode::Stackable],
            includePlacements: true,
            overrides: new Overrides(tareHeight: 14.4, minSupport: 0.85),
        );

        $plan = (new Planner())->plan($request);

        self::assertCount(2, $plan->results);
        $pallet = $plan->results[0]->pallets[0];
        self::assertNotNull($pallet->placements);
        // The height includes the override pallet (14.4 cm) plus the layers.
        $layers = array_sum(array_map(static fn($l): int => $l->height, $pallet->layers));
        self::assertSame(14_400 + $layers, $pallet->height);
    }

    public function testCustomProfileExampleIsValid(): void
    {
        \preg_match('/### Your own profiles.*?```json\n(.*?)```/s', (string) file_get_contents(__DIR__ . '/../../README.md'), $m);
        self::assertArrayHasKey(1, $m);

        $profiles = (new ProfileRepository())->withProfile(ProfileRepository::parse($m[1]));

        self::assertSame(['acme-3pl@2027-01', 'amazon-eu@2026-11', 'amazon-us@2026-11', 'walmart-us@2026-11'], $profiles->keys());
        self::assertNull($profiles->load('acme-3pl@2027-01')->mandatoryBoxWidthOver);
    }
}
