<?php

declare(strict_types=1);

namespace Palletwise\Tests\Integration;

use Palletwise\Model\Mode;
use Palletwise\Model\Pallet;
use Palletwise\Model\Warning;
use Palletwise\Tests\Support\Requests;
use PHPUnit\Framework\TestCase;

final class EdgeCasesTest extends TestCase
{
    public function testHugeBoxFillsAPalletExactly(): void
    {
        $result = Requests::mode([Requests::box('HUGE', 48, 40, 67, 1460)]);

        self::assertSame(1, $result->palletCount);
        self::assertSame('mandatory', $result->pallets[0]->type->value);
        self::assertSame(72_000, $result->pallets[0]->height);
        self::assertSame(1_500_000, $result->pallets[0]->weight);
        self::assertSame(10_000, $result->pallets[0]->volumeUtilization);
    }

    public function testHugeBoxOverTheLimits(): void
    {
        $result = Requests::mode([
            Requests::box('HEAVY', 48, 40, 67, 1460.001),
            Requests::box('TALL', 48, 40, 67.001, 10),
            Requests::box('WIDE', 48.001, 40, 10, 10),
        ]);

        self::assertSame(0, $result->palletCount);
        self::assertSame(0, $result->lowerBound);
        self::assertSame(
            ['BOX_EXCEEDS_WEIGHT_LIMIT', 'BOX_EXCEEDS_HEIGHT_LIMIT', 'BOX_DOES_NOT_FIT_BASE'],
            array_map(static fn($u): string => $u->reason->value, $result->unplaced),
        );
    }

    public function testHeavyBoxWarnings(): void
    {
        $result = Requests::mode([
            Requests::box('A', 12, 10, 8, 50),
            Requests::box('B', 12, 10, 8, 50.001, 2),
            Requests::box('C', 12, 10, 8, 80, 1, ['singleUnit' => true]),
        ]);

        $warnings = array_values(array_filter($result->warnings, static fn(Warning $w): bool => $w->code->value === 'HEAVY_BOX'));
        self::assertSame(['B'], array_map(static fn(Warning $w): ?string => $w->boxId, $warnings));
        self::assertSame(2, $warnings[0]->details['quantity']);
        self::assertSame(1, $result->palletCount);
    }

    public function testSetAtExactlyTheThresholdIsNotMandatory(): void
    {
        $atLimit = Requests::mode([Requests::box('K', 12, 10, 8, 25, 4, ['setId' => 'KIT'])]);
        self::assertSame('single_sku', $atLimit->pallets[0]->type->value);

        $over = Requests::mode([Requests::box('K', 12, 10, 8, 25.001, 4, ['setId' => 'KIT'])]);
        self::assertSame('mandatory', $over->pallets[0]->type->value);
        $ids = $over->pallets[0]->boxIds;
        sort($ids);
        self::assertSame(['K#1', 'K#2', 'K#3', 'K#4'], $ids);
    }

    public function testMillimetersAndEuProfile(): void
    {
        $mm = Requests::mode([Requests::box('A', 304.8, 254, 203.2, 10, 300)], ['lengthUnit' => 'mm']);
        self::assertSame([128, 128, 44], array_map(static fn(Pallet $p): int => $p->boxCount(), $mm->pallets));

        $eu = Requests::mode([Requests::box('A', 30, 25, 20, 5, 300)], ['profile' => 'amazon-eu@2026-11', 'lengthUnit' => 'cm', 'weightUnit' => 'kg']);
        // 120 × 100: 16 per layer; (180 − 15) / 20 = 8 layers → 128; (500 − 25) / 5 = 95 by weight.
        self::assertSame([95, 95, 95, 15], array_map(static fn(Pallet $p): int => $p->boxCount(), $eu->pallets));
        self::assertContains('UNCONFIRMED_PROFILE_VALUES', Requests::warningCodes($eu));
    }

    public function testResultJsonShape(): void
    {
        $plan = Requests::plan([Requests::box('A', 12, 10, 8, 10, 3)], ['includePlacements' => true]);
        $data = json_decode($plan->toJson(), true);
        self::assertIsArray($data);
        self::assertSame(['meta', 'results'], array_keys($data));
        self::assertIsArray($data['results']);
        $result = $data['results'][0];
        self::assertIsArray($result);
        self::assertSame(['mode', 'palletCount', 'lowerBound', 'lowerBounds', 'pallets', 'unplaced', 'warnings', 'recommendation'], array_keys($result));
        self::assertIsArray($result['pallets']);
        $pallet = $result['pallets'][0];
        self::assertIsArray($pallet);
        self::assertSame(['index', 'shipmentId', 'type', 'height', 'weight', 'boxCount', 'boxIds', 'volumeUtilization', 'layers', 'placements'], array_keys($pallet));
        self::assertSame(13, $pallet['height']);
        self::assertSame(Mode::NonStackable->value, $result['mode']);
    }
}
