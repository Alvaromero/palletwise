# Palletwise

PHP library that estimates how many pallets an inbound shipment needs while following the
palletization rules of its destination. The rules live in versioned **profiles**: the
library ships with profiles for Amazon FBA (US and EU) and Walmart WFS, and you can add your
own for any other marketplace, retailer, 3PL or warehouse.

The goal is a **realistic, physically feasible** count, not a build guide for the warehouse.
Even so, the engine places every box (x, y, z) to guarantee that the plan exists, and it can
return those placements.

- PHP 8.2 or later, no runtime dependencies, no I/O other than reading profile files.
- Deterministic: the same input produces the same JSON, byte for byte.
- Every result goes through an independent verifier before being returned. If a plan
  broke a hard rule, `EngineInvariantViolation` is thrown instead of returning it.

## Installation

```bash
composer require palletwise/palletwise
```

## Quick start

```php
use Palletwise\Model\PlanRequest;
use Palletwise\Planner\Planner;

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

$plan->results[0]->palletCount;   // 3
$plan->toArray();                 // array ready to serialize
$plan->toJson();                  // deterministic JSON
```

The request can also be built with objects:

```php
use Palletwise\Model\Box;
use Palletwise\Model\Mode;
use Palletwise\Model\Overrides;
use Palletwise\Model\PlanRequest;
use Palletwise\Model\Rotation;
use Palletwise\Units\LengthUnit;
use Palletwise\Units\WeightUnit;

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
```

### Input

| Field | Required | Description |
|---|---|---|
| `profile` | yes | Versioned profile, for example `amazon-us@2026-11`. |
| `lengthUnit` | yes | `in`, `cm` or `mm`. |
| `weightUnit` | yes | `lb` or `kg`. |
| `modes` | no | List with `non_stackable` (default) and/or `stackable`. |
| `includePlacements` | no | `true` to return the position of every box. Defaults to `false`. |
| `boxes[]` | yes | At least one line (see below). |
| `overrides` | no | `tareHeight`, `tareWeight`, `minSupport`, `bandTolerance`, `parcelMinFill`. |

Each `boxes[]` line has `id`, `shipmentId`, `length`, `width`, `height`, `weight` and
`quantity`, and optionally `sku`, `rotation` (`keep_upright`, `any` or `fixed`), `setId`,
`fragile` and `singleUnit`.

`sku` does not change how boxes are packed; it groups identical boxes and labels pallets
(`single_sku` or `mixed`). When a line has no `sku`, boxes with the same dimensions and
weight are treated as the same product. Physically identical boxes under different SKUs are
kept in separate groups, which can cost some efficiency.

Validation is strict: numbers must be greater than zero (int, float or decimal string),
`quantity` must be an integer of at least 1, and unknown fields are rejected. Any error
throws `Palletwise\Exception\InvalidPlanRequest` with a message naming the field, for example
`boxes[3].weight must be a number greater than zero.`

A line with `quantity = 1` keeps its `id`. With `quantity = n > 1`, its physical boxes are
`id#1` … `id#n`.

### Output

`Planner::plan()` returns a `PlanResult` with `meta` and one result per requested mode:

```jsonc
{
  "meta": {
    "libraryVersion": "1.0.0",
    "profile": "amazon-us",
    "profileVersion": "2026-11",
    "lengthUnit": "in",
    "weightUnit": "lb",
    "inputHash": "a415a2a2…"            // SHA-256 of the canonical input
  },
  "results": [
    {
      "mode": "non_stackable",
      "palletCount": 3,
      "lowerBound": 2,                  // maximum of the three bounds
      "lowerBounds": { "volume": 2, "weight": 1, "mandatory": 1 },
      "pallets": [
        {
          "index": 2,
          "shipmentId": "FBA15ABC",
          "type": "mixed",              // single_sku | mixed | mandatory
          "height": 69,                 // including the pallet
          "weight": 1095,               // including the pallet
          "boxCount": 45,
          "boxIds": ["CASE-A#1", "CASE-A#2", "…"],
          "volumeUtilization": 0.8805,  // box volume ÷ usable volume
          "layers": [ { "z": 0, "height": 10, "coveredArea": 1728, "boxCount": 8 }, "…" ],
          "placements": [               // only with includePlacements: true
            { "boxId": "CASE-A#1", "x": 0, "y": 0, "z": 0, "length": 18, "width": 12, "height": 10, "orientation": "LWH" }
          ]
        }
      ],
      "unplaced": [ { "boxId": "X#1", "lineId": "X", "shipmentId": "FBA15ABC", "reason": "BOX_DOES_NOT_FIT_BASE" } ],
      "warnings": [ { "code": "MANDATORY_OWN_PALLET", "message": "…", "shipmentId": "FBA15ABC", "boxId": "TABLE", "palletIndex": null, "details": { } } ],
      "recommendation": null            // or { "type": "PARCEL", "volumeFill": 0.0149, "threshold": 0.25, "reason": "LOW_PALLET_VOLUME_FILL" }
    }
  ]
}
```

- All values are in the request units. Areas are in units².
- `placements` origin: the bottom corner of the pallet deck surface; `x` along the pallet
  length (for example 48 in), `y` along its width (40 in), `z` upwards.
- `orientation` tells which declared dimension lies on each axis: `LWH` is the box as
  declared, `WLH` turned 90° in plan, and `LHW`, `HLW`, `WHL` or `HWL` laid on its side (only
  with `rotation: any`).
- Inside the code, value objects hold integers in thousandths of the unit (for example,
  `Pallet::$height === 69000`). `toArray()` and `toJson()` convert them to plain numbers.

## Units

The engine works in the request units and returns the result in those same units.
Internally everything is an integer scaled ×1000, with no floats. Each profile declares its
own units. On load it is converted to the request units with exact factors
(1 in = 25.4 mm, 1 lb = 0.45359237 kg), rounding towards the strict side:

- **down:** pallet dimensions, maximum height and weight, and "more than X" thresholds;
- **up:** pallet height and weight.

For example, with `amazon-us` in cm/kg, the 1,500 lb maximum becomes 680.388 kg and the
40 lb pallet becomes 18.144 kg. Box dimensions and weights with more than 3 decimals are
rounded up.

## Profiles

A profile describes one destination's rules: pallet size and tare, height and weight limits
per mode, which boxes need their own pallet, which boxes are rejected or flagged, and the
engine defaults. Profiles are versioned (`<id>@<YYYY-MM>`), so a plan can always be
reproduced with the rules it was made with; when a destination changes its rules, add a new
version instead of editing the old one.

### Bundled profiles

| | `amazon-us@2026-11` | `amazon-eu@2026-11` | `walmart-us@2026-11` |
|---|---|---|---|
| Units | in / lb | cm / kg | in / lb |
| Pallet | 48 × 40 in | 120 × 100 cm | 48 × 40 in |
| Pallet tare | 5 in, 40 lb | 15 cm, 25 kg | 6 in, 50 lb (**unconfirmed**) |
| `non_stackable` (default) | 72 in, 1,500 lb | 180 cm, 500 kg | under 72 in and 2,100 lb (71.999 in, 2,099.999 lb) |
| `stackable` | 50 in, 750 lb (**unconfirmed**) | not defined | under 50 in, 2,100 lb (**unconfirmed**) |
| Own pallet required | box over 100 lb or over 30 in `width`; set over 100 lb | Amazon US rules in cm/kg | none |
| Box rejected | longer than 80 in; single unit taller than 72 in | Amazon US rules in cm/kg | none beyond the pallet limits |
| `HEAVY_BOX` warning | over 50 lb | over 22.679 kg | over 150 lb |

All limits include the pallet. The whole `amazon-eu` profile is unconfirmed. Every bundled
profile uses the same engine defaults: rotation `keep_upright` (turning about the vertical
axis only), minimum support of 80 % of each box's base, 1 in (2.54 cm) height band tolerance
and no parcel recommendation. A result that depends on an unconfirmed value carries the
`UNCONFIRMED_PROFILE_VALUES` warning.

Sources for `walmart-us`: the WFS
[pallet guide](https://marketplacelearn.walmart.com/guides/Walmart%20Fulfillment%20Services%20(WFS)/Shipping%20to%20WFS/WFS-inbound-orders-Select-&-pack-pallets),
the [Walmart Preferred Carrier program](https://marketplacelearn.walmart.com/guides/Walmart%20Fulfillment%20Services%20(WFS)/Shipping%20to%20WFS/Walmart-Preferred-Carrier-(WPC)-program)
and the WFS master case requirements. See `docs/decisions.md` (D47–D50) for how they were read.

### Your own profiles

Write a JSON file with this shape. Only `units`, `pallet`, `modes.non_stackable` and
`defaults` are required; every rule in `mandatoryPallet` and `boxRules` is optional, and a
missing field or `null` means the destination has no such rule.

```json
{
    "id": "acme-3pl",
    "version": "2027-01",
    "description": "ACME 3PL: 120 x 80 cm pallets.",
    "units": { "length": "cm", "weight": "kg" },
    "pallet": { "length": 120, "width": 80, "overhang": 0, "tareHeight": 14.4, "tareWeight": 25 },
    "modes": {
        "non_stackable": { "maxHeight": 160, "maxWeight": 800 },
        "stackable": { "maxHeight": 120, "maxWeight": 500 }
    },
    "mandatoryPallet": { "boxWeightOver": 60, "boxWidthOver": null, "setWeightOver": null },
    "boxRules": { "unsupportedLengthOver": 200, "heavyBoxWarningOver": 23, "singleUnitHeightOver": null },
    "defaults": { "rotation": "keep_upright", "minSupport": 0.8, "bandTolerance": 2, "parcelMinFill": 0.3 },
    "unconfirmed": ["modes.stackable.maxHeight"]
}
```

| Field | Meaning |
|---|---|
| `id`, `version` | Lowercase letters, digits and dashes; version `YYYY-MM`. Together they form the key used in requests. |
| `units` | Units the profile's numbers are written in. They are converted to each request's units. |
| `pallet` | Base length and width, tare height and weight. `overhang` must be 0 in v1. |
| `modes` | `maxHeight` and `maxWeight` per mode, including the pallet. `stackable` is optional. |
| `mandatoryPallet` | A box heavier than `boxWeightOver` or wider (declared `width`) than `boxWidthOver`, or a set (`setId`) heavier than `setWeightOver`, goes on its own pallet. |
| `boxRules.unsupportedLengthOver` | A box longer than this is not placed (`BOX_UNSUPPORTED_LENGTH`). |
| `boxRules.heavyBoxWarningOver` | A box heavier than this without `singleUnit: true` gets a `HEAVY_BOX` warning. |
| `boxRules.singleUnitHeightOver` | A box that cannot be laid lower than this is not placed (`SINGLE_UNIT_TOO_TALL`). |
| `defaults` | Default rotation, minimum support (0–1], height band tolerance and parcel threshold (0–1] or `null`. |
| `unconfirmed` | Paths of values not confirmed by an official source, or `["*"]` for the whole profile. |

Then give the planner a repository that knows about it:

```php
use Palletwise\Planner\Planner;
use Palletwise\Profile\ProfileRepository;

// Files in /etc/palletwise/profiles/<id>/<version>.json, plus the bundled profiles.
$profiles = (new ProfileRepository())->withDirectory('/etc/palletwise/profiles');

// Or a profile kept elsewhere (database, configuration service…), as JSON or an array.
$profiles = $profiles->withProfile(ProfileRepository::parse($json));

$planner = new Planner($profiles);
$profiles->keys();   // ['acme-3pl@2027-01', 'amazon-eu@2026-11', 'amazon-us@2026-11', 'walmart-us@2026-11']
```

`new ProfileRepository(includeBundled: false)` leaves out the bundled profiles. Every
profile is validated when loaded; an invalid one throws `InvalidProfile`. A key must come
from exactly one source: a custom profile cannot reuse the key of a bundled one (use a new id
or version instead), so `amazon-us@2026-11` always means the same rules.

### Overrides

Per-request adjustments on top of the profile:

| Field | Unit | Effect |
|---|---|---|
| `tareHeight`, `tareWeight` | request units | Pallet height and weight. |
| `minSupport` | ratio (0–1] | Minimum support per box. |
| `bandTolerance` | request units | Band tolerance; it also sets which boxes count as "at the same height" for support (0 = exact contact). |
| `parcelMinFill` | ratio (0–1] | Below this volume fill, shipping as small parcel (Amazon SPD, for example) is recommended. |

## How it works

1. **Validation:** out-of-spec boxes go to `unplaced` with their warning, and planning
   continues with the rest.
2. **Partition:** by `shipmentId` (never mixed), mandatory pallets set aside, identical
   boxes grouped.
3. **Homogeneous layers:** per group, the 2D pattern that fits the most boxes (grid, two
   blocks or a four-block pinwheel), alternating the symmetry between layers when possible.
   Full pallets are emitted directly.
4. **Mixed layers:** the remaining boxes are placed layer by layer with MaxRects on the
   actual surface of each pallet, by height band and respecting the minimum support.
   Fragile boxes only go in the top layer.
5. **Stacking:** height, weight and per-box support; at the end, pallets are merged where
   possible.
6. **Multi-order search:** the mixed stage is repeated sorting by volume, weight, base area
   and height (with and without filling gaps with shorter boxes). The result with the
   fewest pallets wins and, on a tie, the one with the most even weight and height.
7. Independent **verification** of every result.

Decisions about ambiguous rules are in [`docs/decisions.md`](docs/decisions.md).

## Codes

### Warnings (`warnings[].code`) and `unplaced[].reason` values

| Code | Kind | Meaning |
|---|---|---|
| `HEAVY_BOX` | warning | Box over the profile's heavy-box threshold without `singleUnit` (Amazon: valid only if it contains a single unit). It is still planned. |
| `BOX_UNSUPPORTED_LENGTH` | unplaced | Longer than the profile's supported length. |
| `SINGLE_UNIT_TOO_TALL` | unplaced | No allowed orientation brings it within the profile's single-unit height (Amazon: 72 in; Amazon accepts up to 98 in, but v1 does not model it). |
| `BOX_DOES_NOT_FIT_BASE` | unplaced | Does not fit on the base in any allowed orientation. |
| `BOX_EXCEEDS_HEIGHT_LIMIT` | unplaced | Fits on the base, but exceeds the usable height of the mode. |
| `BOX_EXCEEDS_WEIGHT_LIMIT` | unplaced | Heavier than the payload of the mode. |
| `MANDATORY_OWN_PALLET` | warning | The box or set goes on its own pallet (`details.reason`: `box_weight`, `box_width`, `set_weight` or `set_member`). |
| `SET_SPLIT_ACROSS_PALLETS` | warning | A mandatory set does not fit on one pallet; it is split across several that only carry that set. |
| `UNCONFIRMED_PROFILE_VALUES` | warning | The result depends on unconfirmed profile values (`details.paths`). |
| `CENTER_OF_GRAVITY_OFFSET` | soft rule | The center of gravity is off by more than 10 % of a pallet side. |
| `HEAVY_ON_LIGHT` | soft rule | Some boxes rest on lighter ones (`details.pairs`). |

### Verifier

`Verifier::verify(PlanResult, Profile)` returns a list of `Violation`s, each with a stable
code, a severity (`hard` or `soft`), the mode, pallet, box and the values found (integers
scaled ×1000). Hard codes: `PROFILE_MISMATCH`, `MODE_MISMATCH`, `PLACEMENTS_MISSING`,
`BOX_MISSING`, `BOX_DUPLICATED`, `BOX_UNKNOWN`, `PLACEMENT_MISMATCH`, `OVERHANG`, `OVERLAP`,
`INSUFFICIENT_SUPPORT`, `FRAGILE_LOADED`, `ROTATION_NOT_ALLOWED`, `HEIGHT_EXCEEDED`,
`WEIGHT_EXCEEDED`, `HEIGHT_MISMATCH`, `WEIGHT_MISMATCH`, `LAYERS_MISMATCH`, `MIXED_SHIPMENTS`,
`MANDATORY_NOT_ISOLATED`, `MANDATORY_PALLET_MIXED`, `PALLET_INDEX_INVALID` and
`BELOW_LOWER_BOUND`. Soft codes: `CENTER_OF_GRAVITY_OFFSET` and `HEAVY_ON_LIGHT`.

Geometric checks need placements. To verify a result yourself, request it with
`includePlacements: true`.

## Exceptions

All of them implement `Palletwise\Exception\PalletwiseException`.

- `InvalidPlanRequest`: invalid request, unknown profile, or a mode the profile does not define.
- `InvalidProfile`: a profile does not exist, is defined by more than one source or fails
  validation (when using `ProfileRepository` directly).
- `EngineInvariantViolation`: the generated plan failed verification (an engine bug);
  `violations()` returns the details.

## Performance

Time depends mostly on how many distinct box sizes a shipment has, not on how many boxes:
identical boxes are grouped and packed as whole layers. Measured on one core with PHP 8.2,
one mode:

| Boxes | Distinct sizes | Time | With JIT |
|---|---|---|---|
| 500 | 21 | 0.25 s | |
| 2,000 | 21 | 0.8 s | |
| 5,000 | 21 | 1.5 s | 0.75 s |
| 500 | all | 1 s | |
| 1,000 | all | 2.5 s | 1.4 s |

Planning both modes takes roughly 1.6 to 1.7 times as long. Memory stays under 25 MB. Enabling
the OPcache JIT (`opcache.enable_cli=1`, `opcache.jit=tracing`, `opcache.jit_buffer_size=128M`
for CLI workers) cuts the time by 35–45%. The maximum per request is 20,000 boxes.

## Development

```bash
composer install
composer test       # PHPUnit
composer analyse    # PHPStan, level max
composer cs         # PHP-CS-Fixer (dry run); composer cs:fix to apply
composer ci         # all three
```

## Out of scope (v1)

Exact solvers, truck or container loading, a 3D viewer, persistence, adapters for other
systems and the HTTP route. Overhang, double-stacked pallet totals (such as Walmart's 108 in)
and the units up to 98 in that Amazon accepts are not modeled either.

## Contributing and license

Bug reports, new destination profiles and pull requests are welcome: see
[CONTRIBUTING.md](CONTRIBUTING.md). Security issues go through [SECURITY.md](SECURITY.md).
What is planned next is in [ROADMAP.md](ROADMAP.md) and the history in
[CHANGELOG.md](CHANGELOG.md).

Palletwise is released under the [MIT License](LICENSE). It is not affiliated with or
endorsed by Amazon or Walmart; their names are used only to identify the rules a bundled
profile follows. Check each destination's current requirements before relying on a profile.
