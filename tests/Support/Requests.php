<?php

declare(strict_types=1);

namespace Palletwise\Tests\Support;

use Palletwise\Model\Mode;
use Palletwise\Model\ModeResult;
use Palletwise\Model\PlanRequest;
use Palletwise\Model\PlanResult;
use Palletwise\Planner\Planner;

/**
 * Shortcuts for building requests in tests.
 */
final class Requests
{
    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    public static function box(string $id, float|int|string $l, float|int|string $w, float|int|string $h, float|int|string $weight, int $quantity = 1, array $extra = []): array
    {
        return array_merge(['id' => $id, 'shipmentId' => 'S1', 'sku' => 'SKU-' . $id, 'length' => $l, 'width' => $w, 'height' => $h, 'weight' => $weight, 'quantity' => $quantity], $extra);
    }

    /**
     * @param list<array<string, mixed>> $boxes
     * @param array<string, mixed>       $options
     */
    public static function request(array $boxes, array $options = []): PlanRequest
    {
        return PlanRequest::fromArray($options + [
            'profile' => 'amazon-us@2026-11',
            'lengthUnit' => 'in',
            'weightUnit' => 'lb',
            'boxes' => $boxes,
        ]);
    }

    /**
     * @param list<array<string, mixed>> $boxes
     * @param array<string, mixed>       $options
     */
    public static function plan(array $boxes, array $options = []): PlanResult
    {
        return (new Planner())->plan(self::request($boxes, $options));
    }

    /**
     * @param list<array<string, mixed>> $boxes
     * @param array<string, mixed>       $options
     */
    public static function mode(array $boxes, array $options = [], Mode $mode = Mode::NonStackable): ModeResult
    {
        $result = self::plan($boxes, $options)->forMode($mode);
        \assert($result !== null);

        return $result;
    }

    /**
     * @return list<string>
     */
    public static function warningCodes(ModeResult $result): array
    {
        return array_map(static fn($w): string => $w->code->value, $result->warnings);
    }
}
