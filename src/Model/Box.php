<?php

declare(strict_types=1);

namespace Palletwise\Model;

use Palletwise\Exception\InvalidPlanRequest;
use Palletwise\Support\Input;
use Palletwise\Units\Rounding;

/**
 * A box line in the request.
 *
 * The constructor takes measurements in the request units (int, float or decimal
 * string). Properties hold integers scaled ×1000 (thousandths of that unit), rounded
 * up when they carry more than 3 decimals: a box is never treated as smaller or
 * lighter than declared.
 */
final readonly class Box
{
    /** Maximum value of a dimension or weight: 1,000,000 units. */
    public const MAX_VALUE_SCALED = 1_000_000_000;

    public const MAX_QUANTITY = 20_000;

    private const FIELDS = ['id', 'shipmentId', 'sku', 'length', 'width', 'height', 'weight', 'quantity', 'rotation', 'setId', 'fragile', 'singleUnit'];

    public string $id;
    public string $shipmentId;
    /** Null when the line has no SKU: identical boxes are then grouped by dimensions and weight. */
    public ?string $sku;
    public int $length;
    public int $width;
    public int $height;
    public int $weight;
    public int $quantity;
    public ?string $setId;

    public function __construct(
        string $id,
        string $shipmentId,
        ?string $sku,
        int|float|string $length,
        int|float|string $width,
        int|float|string $height,
        int|float|string $weight,
        int $quantity = 1,
        public ?Rotation $rotation = null,
        ?string $setId = null,
        public bool $fragile = false,
        public bool $singleUnit = false,
    ) {
        $where = \sprintf('Box "%s"', $id);
        $this->id = Input::string($id, 'Box id');
        $this->shipmentId = Input::string($shipmentId, $where . ': shipmentId');
        $this->sku = $sku === null ? null : Input::string($sku, $where . ': sku');
        $this->length = Input::positive($length, $where . ': length', Rounding::Ceil, self::MAX_VALUE_SCALED);
        $this->width = Input::positive($width, $where . ': width', Rounding::Ceil, self::MAX_VALUE_SCALED);
        $this->height = Input::positive($height, $where . ': height', Rounding::Ceil, self::MAX_VALUE_SCALED);
        $this->weight = Input::positive($weight, $where . ': weight', Rounding::Ceil, self::MAX_VALUE_SCALED);
        if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
            throw InvalidPlanRequest::because(\sprintf('%s: quantity must be an integer between 1 and %d.', $where, self::MAX_QUANTITY));
        }
        $this->quantity = $quantity;
        $this->setId = $setId === null ? null : Input::string($setId, $where . ': setId');
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data, int $index = 0): self
    {
        $where = \sprintf('boxes[%d]', $index);
        Input::assertKeys($data, self::FIELDS, $where);
        foreach (['id', 'shipmentId', 'length', 'width', 'height', 'weight', 'quantity'] as $required) {
            if (!\array_key_exists($required, $data)) {
                throw InvalidPlanRequest::because(\sprintf('%s.%s is required.', $where, $required));
            }
        }
        $id = Input::string($data['id'], $where . '.id');
        Input::string($data['shipmentId'], $where . '.shipmentId');
        $sku = isset($data['sku']) ? Input::string($data['sku'], $where . '.sku') : null;
        foreach (['length', 'width', 'height', 'weight'] as $field) {
            Input::positive($data[$field], $where . '.' . $field, Rounding::Ceil, self::MAX_VALUE_SCALED);
        }
        if (!\is_int($data['quantity']) || $data['quantity'] < 1) {
            throw InvalidPlanRequest::because(\sprintf('%s.quantity must be an integer greater than or equal to 1.', $where));
        }

        $rotation = null;
        if (isset($data['rotation'])) {
            $rotation = \is_string($data['rotation']) ? Rotation::tryFrom($data['rotation']) : null;
            if ($rotation === null) {
                throw InvalidPlanRequest::because(\sprintf('%s.rotation must be one of: keep_upright, any, fixed.', $where));
            }
        }
        $setId = isset($data['setId']) ? Input::string($data['setId'], $where . '.setId') : null;
        $fragile = isset($data['fragile']) ? Input::bool($data['fragile'], $where . '.fragile') : false;
        $singleUnit = isset($data['singleUnit']) ? Input::bool($data['singleUnit'], $where . '.singleUnit') : false;

        /** @var int|float|string $length */
        $length = $data['length'];
        /** @var int|float|string $width */
        $width = $data['width'];
        /** @var int|float|string $height */
        $height = $data['height'];
        /** @var int|float|string $weight */
        $weight = $data['weight'];
        /** @var string $shipmentId */
        $shipmentId = $data['shipmentId'];

        return new self($id, $shipmentId, $sku, $length, $width, $height, $weight, $data['quantity'], $rotation, $setId, $fragile, $singleUnit);
    }
}
