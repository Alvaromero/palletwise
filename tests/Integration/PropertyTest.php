<?php

declare(strict_types=1);

namespace Palletwise\Tests\Integration;

use Palletwise\Model\PlanRequest;
use Palletwise\Planner\Planner;
use Palletwise\Profile\ProfileRepository;
use Palletwise\Tests\Support\SeededRandom;
use Palletwise\Verifier\Verifier;
use Palletwise\Verifier\Violation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Properties over inputs generated with a fixed seed.
 */
final class PropertyTest extends TestCase
{
    private const CASES = 60;

    /**
     * @return iterable<string, array{int}>
     */
    public static function seeds(): iterable
    {
        for ($seed = 1; $seed <= self::CASES; ++$seed) {
            yield 'seed ' . $seed => [$seed * 7_919];
        }
    }

    #[DataProvider('seeds')]
    public function testEveryPlanPassesTheVerifierAndRespectsTheLowerBound(int $seed): void
    {
        $request = PlanRequest::fromArray((new SeededRandom($seed))->request());
        $plan = (new Planner())->plan($request);

        $hard = (new Verifier())->hardViolations($plan, (new ProfileRepository())->load($request->profile));
        self::assertSame([], array_map(static fn(Violation $v): string => (string) $v, $hard));

        $units = array_sum(array_map(static fn($b): int => $b->quantity, $request->boxes));
        foreach ($plan->results as $result) {
            self::assertGreaterThanOrEqual($result->lowerBound, $result->palletCount);
            $placed = array_sum(array_map(static fn($p): int => $p->boxCount(), $result->pallets));
            self::assertSame($units, $placed + \count($result->unplaced));
            foreach ($result->pallets as $pallet) {
                self::assertNotNull($pallet->placements);
                self::assertGreaterThan(0, $pallet->boxCount());
            }
        }
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function determinismSeeds(): iterable
    {
        for ($seed = 1; $seed <= 10; ++$seed) {
            yield 'seed ' . $seed => [$seed * 104_729];
        }
    }

    #[DataProvider('determinismSeeds')]
    public function testDeterministicOutput(int $seed): void
    {
        $data = (new SeededRandom($seed))->request();
        $first = (new Planner())->plan(PlanRequest::fromArray($data))->toJson();
        $second = (new Planner())->plan(PlanRequest::fromArray($data))->toJson();

        self::assertSame($first, $second);
    }
}
