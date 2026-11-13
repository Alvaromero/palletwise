<?php

declare(strict_types=1);

namespace Palletwise\Model;

use Palletwise\Exception\InvalidPlanRequest;
use Palletwise\Support\Input;
use Palletwise\Units\Decimal;
use Palletwise\Units\Rounding;

/**
 * Optional adjustments to the profile, in the request units.
 * All values are stored as integers scaled ×1000; ratios
 * (minSupport, parcelMinFill) in thousandths (0.8 → 800).
 */
final readonly class Overrides
{
    private const FIELDS = ['tareHeight', 'tareWeight', 'minSupport', 'bandTolerance', 'parcelMinFill'];

    public ?int $tareHeight;
    public ?int $tareWeight;
    public ?int $minSupport;
    public ?int $bandTolerance;
    public ?int $parcelMinFill;

    public function __construct(
        int|float|string|null $tareHeight = null,
        int|float|string|null $tareWeight = null,
        int|float|string|null $minSupport = null,
        int|float|string|null $bandTolerance = null,
        int|float|string|null $parcelMinFill = null,
    ) {
        $this->tareHeight = $tareHeight === null ? null : Input::nonNegative($tareHeight, 'overrides.tareHeight', Rounding::Ceil, Box::MAX_VALUE_SCALED);
        $this->tareWeight = $tareWeight === null ? null : Input::nonNegative($tareWeight, 'overrides.tareWeight', Rounding::Ceil, Box::MAX_VALUE_SCALED);
        $this->minSupport = $minSupport === null ? null : Input::positive($minSupport, 'overrides.minSupport', Rounding::Ceil, Decimal::SCALE);
        $this->bandTolerance = $bandTolerance === null ? null : Input::nonNegative($bandTolerance, 'overrides.bandTolerance', Rounding::Floor, Box::MAX_VALUE_SCALED);
        $this->parcelMinFill = $parcelMinFill === null ? null : Input::positive($parcelMinFill, 'overrides.parcelMinFill', Rounding::Ceil, Decimal::SCALE);
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        Input::assertKeys($data, self::FIELDS, 'overrides');

        return new self(
            self::number($data, 'tareHeight'),
            self::number($data, 'tareWeight'),
            self::number($data, 'minSupport'),
            self::number($data, 'bandTolerance'),
            self::number($data, 'parcelMinFill'),
        );
    }

    /**
     * @param array<mixed> $data
     */
    private static function number(array $data, string $field): int|float|string|null
    {
        $value = $data[$field] ?? null;
        if ($value !== null && !\is_int($value) && !\is_float($value) && !\is_string($value)) {
            throw InvalidPlanRequest::because(\sprintf('overrides.%s must be a number.', $field));
        }

        return $value;
    }

    /**
     * @return array<string, string>
     */
    public function toCanonicalArray(): array
    {
        $values = [
            'tareHeight' => $this->tareHeight,
            'tareWeight' => $this->tareWeight,
            'minSupport' => $this->minSupport,
            'bandTolerance' => $this->bandTolerance,
            'parcelMinFill' => $this->parcelMinFill,
        ];
        $out = [];
        foreach ($values as $field => $value) {
            if ($value !== null) {
                $out[$field] = Decimal::format($value);
            }
        }

        return $out;
    }
}
