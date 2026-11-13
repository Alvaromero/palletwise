<?php

declare(strict_types=1);

namespace Palletwise\Model;

use Palletwise\Exception\InvalidPlanRequest;
use Palletwise\Support\Input;
use Palletwise\Support\Json;
use Palletwise\Units\Decimal;
use Palletwise\Units\LengthUnit;
use Palletwise\Units\WeightUnit;

/**
 * Input of Planner::plan().
 */
final readonly class PlanRequest
{
    /** Maximum number of physical boxes (summing quantity) per request. */
    public const MAX_UNITS = 20_000;

    private const FIELDS = ['profile', 'lengthUnit', 'weightUnit', 'modes', 'includePlacements', 'boxes', 'overrides'];
    private const PROFILE_PATTERN = '/^[a-z0-9][a-z0-9-]{0,62}@\d{4}-\d{2}$/D';

    /** @var list<Box> */
    public array $boxes;

    /** @var list<Mode> */
    public array $modes;

    /**
     * @param list<Box>  $boxes
     * @param list<Mode> $modes modes to compute; duplicates are dropped, keeping order
     */
    public function __construct(
        public string $profile,
        public LengthUnit $lengthUnit,
        public WeightUnit $weightUnit,
        array $boxes,
        array $modes = [Mode::NonStackable],
        public bool $includePlacements = false,
        public Overrides $overrides = new Overrides(),
    ) {
        if (preg_match(self::PROFILE_PATTERN, $profile) !== 1) {
            throw InvalidPlanRequest::because('profile must look like "<id>@<YYYY-MM>", for example "amazon-us@2026-11".');
        }
        if ($boxes === []) {
            throw InvalidPlanRequest::because('boxes must contain at least one box.');
        }
        if ($modes === []) {
            throw InvalidPlanRequest::because('modes must contain at least one mode.');
        }
        $uniqueModes = [];
        foreach ($modes as $mode) {
            if (!\in_array($mode, $uniqueModes, true)) {
                $uniqueModes[] = $mode;
            }
        }

        $ids = [];
        $units = 0;
        foreach ($boxes as $box) {
            if (isset($ids[$box->id])) {
                throw InvalidPlanRequest::because(\sprintf('Box id "%s" is duplicated.', $box->id));
            }
            $ids[$box->id] = true;
            $units += $box->quantity;
        }
        if ($units > self::MAX_UNITS) {
            throw InvalidPlanRequest::because(\sprintf('The request contains %d boxes; the maximum is %d.', $units, self::MAX_UNITS));
        }
        foreach ($boxes as $box) {
            if ($box->quantity === 1) {
                continue;
            }
            for ($n = 1; $n <= $box->quantity; ++$n) {
                if (isset($ids[self::unitId($box, $n)])) {
                    throw InvalidPlanRequest::because(\sprintf('Box id "%s" collides with the expanded ids of box "%s".', self::unitId($box, $n), $box->id));
                }
            }
        }

        $this->boxes = $boxes;
        $this->modes = $uniqueModes;
    }

    /**
     * Builds the request from an array (for example, decoded JSON) with strict validation.
     *
     * @param array<mixed> $data
     *
     * @throws InvalidPlanRequest
     */
    public static function fromArray(array $data): self
    {
        Input::assertKeys($data, self::FIELDS, 'request');
        foreach (['profile', 'lengthUnit', 'weightUnit', 'boxes'] as $required) {
            if (!\array_key_exists($required, $data)) {
                throw InvalidPlanRequest::because(\sprintf('%s is required.', $required));
            }
        }
        $profile = Input::string($data['profile'], 'profile');
        $lengthUnit = \is_string($data['lengthUnit']) ? LengthUnit::tryFrom($data['lengthUnit']) : null;
        if ($lengthUnit === null) {
            throw InvalidPlanRequest::because('lengthUnit must be one of: in, cm, mm.');
        }
        $weightUnit = \is_string($data['weightUnit']) ? WeightUnit::tryFrom($data['weightUnit']) : null;
        if ($weightUnit === null) {
            throw InvalidPlanRequest::because('weightUnit must be one of: lb, kg.');
        }

        $modes = [Mode::NonStackable];
        if (\array_key_exists('modes', $data)) {
            if (!\is_array($data['modes']) || !array_is_list($data['modes'])) {
                throw InvalidPlanRequest::because('modes must be a list.');
            }
            $modes = [];
            foreach ($data['modes'] as $i => $value) {
                $mode = \is_string($value) ? Mode::tryFrom($value) : null;
                if ($mode === null) {
                    throw InvalidPlanRequest::because(\sprintf('modes[%d] must be one of: non_stackable, stackable.', $i));
                }
                $modes[] = $mode;
            }
        }

        $includePlacements = \array_key_exists('includePlacements', $data)
            ? Input::bool($data['includePlacements'], 'includePlacements')
            : false;

        if (!\is_array($data['boxes']) || !array_is_list($data['boxes'])) {
            throw InvalidPlanRequest::because('boxes must be a list.');
        }
        $boxes = [];
        foreach ($data['boxes'] as $i => $boxData) {
            if (!\is_array($boxData)) {
                throw InvalidPlanRequest::because(\sprintf('boxes[%d] must be an object.', $i));
            }
            $boxes[] = Box::fromArray($boxData, $i);
        }

        $overrides = new Overrides();
        if (\array_key_exists('overrides', $data) && $data['overrides'] !== null) {
            if (!\is_array($data['overrides']) || (array_is_list($data['overrides']) && $data['overrides'] !== [])) {
                throw InvalidPlanRequest::because('overrides must be an object.');
            }
            $overrides = Overrides::fromArray($data['overrides']);
        }

        return new self($profile, $lengthUnit, $weightUnit, $boxes, $modes, $includePlacements, $overrides);
    }

    /**
     * Id of unit n (1..quantity) of a line: the id itself if quantity is 1, "id#n" otherwise.
     */
    public static function unitId(Box $box, int $n): string
    {
        return $box->quantity === 1 ? $box->id : $box->id . '#' . $n;
    }

    /**
     * Canonical representation (sorted keys, numbers as exact decimals).
     *
     * @return array<mixed>
     */
    public function toCanonicalArray(): array
    {
        $boxes = [];
        foreach ($this->boxes as $box) {
            $boxes[] = [
                'id' => $box->id,
                'shipmentId' => $box->shipmentId,
                'sku' => $box->sku,
                'length' => Decimal::format($box->length),
                'width' => Decimal::format($box->width),
                'height' => Decimal::format($box->height),
                'weight' => Decimal::format($box->weight),
                'quantity' => $box->quantity,
                'rotation' => $box->rotation?->value,
                'setId' => $box->setId,
                'fragile' => $box->fragile,
                'singleUnit' => $box->singleUnit,
            ];
        }

        return Json::sortKeys([
            'profile' => $this->profile,
            'lengthUnit' => $this->lengthUnit->value,
            'weightUnit' => $this->weightUnit->value,
            'modes' => array_map(static fn(Mode $m): string => $m->value, $this->modes),
            'includePlacements' => $this->includePlacements,
            'overrides' => $this->overrides->toCanonicalArray(),
            'boxes' => $boxes,
        ]);
    }

    /**
     * SHA-256 of the canonical input.
     */
    public function hash(): string
    {
        return hash('sha256', Json::encode($this->toCanonicalArray()));
    }
}
