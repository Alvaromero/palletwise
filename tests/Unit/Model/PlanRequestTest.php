<?php

declare(strict_types=1);

namespace Palletwise\Tests\Unit\Model;

use Palletwise\Exception\InvalidPlanRequest;
use Palletwise\Model\Box;
use Palletwise\Model\Mode;
use Palletwise\Model\Orientation;
use Palletwise\Model\PlanRequest;
use Palletwise\Model\Rotation;
use Palletwise\Tests\Support\ArrayPath;
use Palletwise\Units\LengthUnit;
use Palletwise\Units\WeightUnit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlanRequestTest extends TestCase
{
    public function testFromArray(): void
    {
        $request = PlanRequest::fromArray(self::valid());

        self::assertSame('amazon-us@2026-11', $request->profile);
        self::assertSame(LengthUnit::Inch, $request->lengthUnit);
        self::assertSame(WeightUnit::Pound, $request->weightUnit);
        self::assertSame([Mode::NonStackable, Mode::Stackable], $request->modes);
        self::assertFalse($request->includePlacements);
        self::assertCount(2, $request->boxes);
        $box = $request->boxes[0];
        self::assertSame(24_000, $box->length);
        self::assertSame(16_500, $box->width);
        self::assertSame(12_001, $box->height); // '12.0001' rounded up
        self::assertSame(35_250, $box->weight);
        self::assertSame(3, $box->quantity);
        self::assertNull($box->rotation);
        self::assertSame(Rotation::Any, $request->boxes[1]->rotation);
        self::assertSame('S1', $request->boxes[1]->setId);
        self::assertTrue($request->boxes[1]->fragile);
        self::assertTrue($request->boxes[1]->singleUnit);
        self::assertSame(1_000, $request->overrides->bandTolerance);
    }

    public function testDefaults(): void
    {
        $data = self::valid();
        unset($data['modes'], $data['overrides']);
        $request = PlanRequest::fromArray($data);
        self::assertSame([Mode::NonStackable], $request->modes);
        self::assertNull($request->overrides->tareHeight);
    }

    public function testSkuIsOptional(): void
    {
        $data = ArrayPath::set(ArrayPath::remove(self::valid(), 'boxes.0.sku'), 'boxes.1.sku', null);
        $request = PlanRequest::fromArray($data);

        self::assertNull($request->boxes[0]->sku);
        self::assertNull($request->boxes[1]->sku);
        self::assertStringContainsString('"sku":null', (string) json_encode($request->toCanonicalArray()));
    }

    public function testDuplicateModesAreCollapsed(): void
    {
        $data = self::valid();
        $data['modes'] = ['stackable', 'non_stackable', 'stackable'];
        self::assertSame([Mode::Stackable, Mode::NonStackable], PlanRequest::fromArray($data)->modes);
    }

    public function testUnitIds(): void
    {
        $request = PlanRequest::fromArray(self::valid());
        self::assertSame('A#2', PlanRequest::unitId($request->boxes[0], 2));
        self::assertSame('B', PlanRequest::unitId($request->boxes[1], 1));
    }

    public function testHashIsStableAndCanonical(): void
    {
        $a = PlanRequest::fromArray(self::valid());
        $data = ArrayPath::set(self::valid(), 'boxes.0.weight', '35.250');
        $b = PlanRequest::fromArray(array_reverse($data, true));

        self::assertSame(64, \strlen($a->hash()));
        self::assertSame($a->hash(), $b->hash());

        self::assertNotSame($a->hash(), PlanRequest::fromArray(ArrayPath::set($data, 'boxes.0.weight', 35.251))->hash());
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function invalidRequests(): iterable
    {
        $v = self::valid();
        $set = static fn(string $path, mixed $value): array => ArrayPath::set($v, $path, $value);

        yield 'zero length' => [$set('boxes.0.length', 0), 'boxes[0].length must be a number greater than zero'];
        yield 'negative weight' => [$set('boxes.0.weight', -1), 'boxes[0].weight must be a number greater than zero'];
        yield 'tiny negative' => [$set('boxes.0.weight', '-0.0001'), 'boxes[0].weight must be a number greater than zero'];
        yield 'non numeric' => [$set('boxes.0.width', 'wide'), 'boxes[0].width must be a number'];
        yield 'bool number' => [$set('boxes.0.height', true), 'boxes[0].height must be a number'];
        yield 'nan' => [$set('boxes.0.height', NAN), 'boxes[0].height must be a number'];
        yield 'too big' => [$set('boxes.0.height', 1e7), 'must not exceed'];
        yield 'quantity zero' => [$set('boxes.0.quantity', 0), 'quantity must be an integer'];
        yield 'quantity float' => [$set('boxes.0.quantity', 2.0), 'quantity must be an integer'];
        yield 'quantity string' => [$set('boxes.0.quantity', '2'), 'quantity must be an integer'];
        yield 'too many units' => [$set('boxes.0.quantity', PlanRequest::MAX_UNITS + 1), 'quantity must be an integer between'];
        yield 'missing weight' => [ArrayPath::remove($v, 'boxes.0.weight'), 'boxes[0].weight is required'];
        yield 'empty sku' => [$set('boxes.0.sku', ' '), 'boxes[0].sku must be a non-empty string'];
        yield 'bad rotation' => [$set('boxes.0.rotation', 'sideways'), 'rotation must be one of'];
        yield 'bad fragile' => [$set('boxes.0.fragile', 'yes'), 'fragile must be a boolean'];
        yield 'unknown box field' => [$set('boxes.0.colour', 'red'), 'unknown field "colour"'];
        yield 'box not object' => [$set('boxes.0', 'A'), 'boxes[0] must be an object'];
        yield 'duplicate id' => [$set('boxes.0.id', 'B'), 'Box id "B" is duplicated'];
        yield 'colliding id' => [$set('boxes.1.id', 'A#2'), 'Box id "A#2" collides with the expanded ids of box "A"'];
        yield 'unknown field' => [$set('speed', 1), 'unknown field "speed"'];
        yield 'missing profile' => [ArrayPath::remove($v, 'profile'), 'profile is required'];
        yield 'bad profile key' => [$set('profile', 'amazon us'), 'profile must look like'];
        yield 'bad length unit' => [$set('lengthUnit', 'ft'), 'lengthUnit must be one of'];
        yield 'bad weight unit' => [$set('weightUnit', 'g'), 'weightUnit must be one of'];
        yield 'bad mode' => [$set('modes', ['floor']), 'modes[0] must be one of'];
        yield 'empty modes' => [$set('modes', []), 'modes must contain'];
        yield 'empty boxes' => [$set('boxes', []), 'boxes must contain'];
        yield 'boxes not list' => [$set('boxes', ['a' => []]), 'boxes must be a list'];
        yield 'bad placements flag' => [$set('includePlacements', 1), 'includePlacements must be a boolean'];
        yield 'bad override' => [$set('overrides', ['minSupport' => 1.2]), 'overrides.minSupport must not exceed 1'];
        yield 'negative override' => [$set('overrides', ['tareWeight' => -1]), 'overrides.tareWeight must be a number greater than or equal to zero'];
        yield 'unknown override' => [$set('overrides', ['speed' => 1]), 'overrides: unknown field "speed"'];
    }

    /**
     * @param array<mixed> $data
     */
    #[DataProvider('invalidRequests')]
    public function testValidation(array $data, string $message): void
    {
        $this->expectException(InvalidPlanRequest::class);
        $this->expectExceptionMessage($message);
        PlanRequest::fromArray($data);
    }

    public function testTotalUnitsLimit(): void
    {
        $this->expectException(InvalidPlanRequest::class);
        $this->expectExceptionMessage('maximum');
        new PlanRequest('amazon-us@2026-11', LengthUnit::Inch, WeightUnit::Pound, [
            new Box('A', 'S', 'X', 1, 1, 1, 1, Box::MAX_QUANTITY),
            new Box('B', 'S', 'X', 1, 1, 1, 1, 1),
        ]);
    }

    public function testOrientationDims(): void
    {
        self::assertSame([3, 2, 1], Orientation::HWL->apply(1, 2, 3));
        self::assertSame(Orientation::WLH, Orientation::LWH->turned());
        self::assertTrue(Rotation::KeepUpright->allows(Orientation::WLH));
        self::assertFalse(Rotation::KeepUpright->allows(Orientation::LHW));
        self::assertSame([Orientation::LWH], Rotation::Fixed->orientations());
        self::assertCount(6, Rotation::Any->orientations());
    }

    /**
     * @return array<string, mixed>
     */
    private static function valid(): array
    {
        return [
            'profile' => 'amazon-us@2026-11',
            'lengthUnit' => 'in',
            'weightUnit' => 'lb',
            'modes' => ['non_stackable', 'stackable'],
            'boxes' => [
                ['id' => 'A', 'shipmentId' => 'FBA1', 'sku' => 'SKU-1', 'length' => 24, 'width' => 16.5, 'height' => '12.0001', 'weight' => 35.25, 'quantity' => 3],
                ['id' => 'B', 'shipmentId' => 'FBA1', 'sku' => 'SKU-2', 'length' => 10, 'width' => 10, 'height' => 10, 'weight' => 5, 'quantity' => 1, 'rotation' => 'any', 'setId' => 'S1', 'fragile' => true, 'singleUnit' => true],
            ],
            'overrides' => ['bandTolerance' => 1],
        ];
    }
}
