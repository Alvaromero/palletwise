<?php

declare(strict_types=1);

namespace Palletwise\Tests\Support;

/**
 * Custom linear congruential generator: the same seed produces the same sequence on
 * any PHP version, without touching the global mt_rand() state.
 */
final class SeededRandom
{
    private int $state;

    public function __construct(int $seed)
    {
        $this->state = $seed & 0x7FFFFFFF;
    }

    public function int(int $min, int $max): int
    {
        $this->state = (1_103_515_245 * $this->state + 12_345) & 0x7FFFFFFF;

        return $min + intdiv($this->state, 7) % ($max - $min + 1);
    }

    public function chance(int $percent): bool
    {
        return $this->int(1, 100) <= $percent;
    }

    /**
     * @template T
     *
     * @param non-empty-list<T> $values
     *
     * @return T
     */
    public function pick(array $values): mixed
    {
        return $values[$this->int(0, \count($values) - 1)];
    }

    /**
     * Random request (in in/lb or, sometimes, in cm/kg) with valid, out-of-spec and
     * fragile boxes, sets and several shipments.
     *
     * @return array<string, mixed>
     */
    public function request(): array
    {
        $metric = $this->chance(25);
        $lf = $metric ? 2.54 : 1.0;
        $wf = $metric ? 0.45359237 : 1.0;
        $boxes = [];
        $lines = $this->int(1, 10);
        for ($i = 0; $i < $lines; ++$i) {
            $box = [
                'id' => 'L' . $i,
                'shipmentId' => 'FBA' . $this->int(1, 3),
                'sku' => 'SKU' . $this->int(1, 6),
                'length' => round($this->int(10, 520) / 10 * $lf, 3),
                'width' => round($this->int(10, 360) / 10 * $lf, 3),
                'height' => round($this->int(10, 400) / 10 * $lf, 3),
                'weight' => round($this->int(5, 1300) / 10 * $wf, 3),
                'quantity' => $this->int(1, 30),
            ];
            $rotation = $this->pick([null, null, 'keep_upright', 'any', 'fixed']);
            if ($rotation !== null) {
                $box['rotation'] = $rotation;
            }
            if ($this->chance(10)) {
                $box['fragile'] = true;
            }
            if ($this->chance(15)) {
                $box['setId'] = $this->pick(['KIT-A', 'KIT-B']);
            }
            if ($this->chance(20)) {
                $box['singleUnit'] = true;
            }
            $boxes[] = $box;
        }

        return [
            'profile' => 'amazon-us@2026-11',
            'lengthUnit' => $metric ? 'cm' : 'in',
            'weightUnit' => $metric ? 'kg' : 'lb',
            'modes' => ['non_stackable', 'stackable'],
            'includePlacements' => true,
            'boxes' => $boxes,
        ];
    }
}
