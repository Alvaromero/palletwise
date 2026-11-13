<?php

declare(strict_types=1);

namespace Palletwise\Tests\Integration;

use Palletwise\Tests\Support\Requests;
use PHPUnit\Framework\TestCase;

final class ParcelRecommendationTest extends TestCase
{
    public function testNoRecommendationByDefault(): void
    {
        $result = Requests::mode([Requests::box('A', 12, 10, 8, 10, 2)]);
        self::assertNull($result->recommendation);
        self::assertNull($result->toArray()['recommendation']);
    }

    public function testRecommendsParcelBelowTheThreshold(): void
    {
        // 2 boxes of 960 in³ on a 48 × 40 × 67 = 128,640 in³ pallet: 1.49 %.
        $result = Requests::mode([Requests::box('A', 12, 10, 8, 10, 2)], ['overrides' => ['parcelMinFill' => 0.25]]);

        self::assertNotNull($result->recommendation);
        self::assertSame('PARCEL', $result->recommendation->type);
        self::assertSame(149, $result->recommendation->volumeFill);
        self::assertSame(250, $result->recommendation->threshold);
        self::assertSame(
            ['type' => 'PARCEL', 'volumeFill' => 0.0149, 'threshold' => 0.25, 'reason' => 'LOW_PALLET_VOLUME_FILL'],
            $result->recommendation->toArray(),
        );
    }

    public function testNoRecommendationAboveTheThreshold(): void
    {
        $result = Requests::mode([Requests::box('A', 12, 10, 8, 10, 128)], ['overrides' => ['parcelMinFill' => 0.25]]);
        self::assertNull($result->recommendation);
    }

    public function testNoRecommendationWithoutPallets(): void
    {
        $result = Requests::mode([Requests::box('A', 60, 50, 8, 10)], ['overrides' => ['parcelMinFill' => 0.25]]);
        self::assertSame(0, $result->palletCount);
        self::assertNull($result->recommendation);
    }

    public function testRecommendationDoesNotDependOnIncludePlacements(): void
    {
        $with = Requests::mode([Requests::box('A', 12, 10, 8, 10, 2)], ['overrides' => ['parcelMinFill' => 0.25], 'includePlacements' => true]);
        $without = Requests::mode([Requests::box('A', 12, 10, 8, 10, 2)], ['overrides' => ['parcelMinFill' => 0.25]]);
        self::assertEquals($with->recommendation, $without->recommendation);
    }

    public function testMetaIdentifiesTheRun(): void
    {
        $plan = Requests::plan([Requests::box('A', 12, 10, 8, 10, 2)]);
        self::assertSame('1.0.0', $plan->meta->libraryVersion);
        self::assertSame('amazon-us', $plan->meta->profile);
        self::assertSame('2026-11', $plan->meta->profileVersion);
        self::assertSame($plan->request->hash(), $plan->meta->inputHash);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $plan->meta->inputHash);
    }
}
