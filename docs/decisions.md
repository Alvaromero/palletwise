# Decisions

Log of the ambiguous rules and how they were resolved. The general criterion is the one the
specification asks for: when in doubt, the most conservative option (the one that never
underestimates pallets or lets a questionable load through).

## Units and numbers

- **D1. Internal scale.** Everything is stored as an integer in thousandths of the request
  unit (×1000). Ratios (`minSupport`, `parcelMinFill`) in thousandths (0.8 → 800), and the
  returned utilizations in ten-thousandths.
- **D2. More than 3 decimals in the input.** Box dimensions and weights are rounded up
  (`24.0005` → `24.001`): a box is never treated as smaller or lighter than declared. Floats
  are read with `sprintf('%.9F')`, which does not depend on `php.ini`.
- **D3. Profile conversion.** Exact factors (1 in = 25.4 mm, 1 lb = 0.45359237 kg) with
  integer arithmetic. Rounding direction per field:
  - down: pallet dimensions, maximum height and weight, "more than X" thresholds
    (mandatory-pallet weight and width, unsupported length, 50 lb warning, 72 in unit) and
    band tolerance (stricter bands);
  - up: pallet height and weight.
- **D4. Volumes.** Computed as `floor(dx·dy·dz / 10^6)` (thousandths of unit³). For the lower
  bound, box volume is rounded down and pallet volume up, so the bound never exceeds the
  true optimum.
- **D5. Range.** Dimensions and weights up to 1,000,000 units; up to 20,000 physical boxes
  per request. Out of range → `InvalidPlanRequest`.
- **D6. Deterministic JSON.** `PlanResult::toJson()` uses its own encoder: floats with
  `number_format(…, 6)` without trailing zeros, independent of `serialize_precision`.

## Profiles

- **D7. Overhang.** The loader requires `overhang = 0`; v1 does not model overhang.
- **D8. Limits include the pallet.** Each mode's `maxHeight` and `maxWeight` always include
  the pallet. Usable height = maximum − pallet height; payload = maximum − pallet weight.
- **D9. `amazon-us` stackable mode.** 50 in is treated as the maximum height (not as an
  "ideal") and 750 lb includes the pallet. Both values are listed in `unconfirmed`.
- **D10. `amazon-eu@2026-11`.** It only defines the `non_stackable` mode (we have no figures
  for stackable; requesting it gives `InvalidPlanRequest`). The box rules, which the
  specification does not give, are those of `amazon-us` converted to cm/kg, rounding towards
  the strict side. The whole profile is marked as unconfirmed (`"unconfirmed": ["*"]`).
- **D11. Unconfirmed values.** If a mode depends on unconfirmed values, its result carries
  the `UNCONFIRMED_PROFILE_VALUES` warning with the affected paths.

## API

- **D12. One result per mode with `plan(): PlanResult`.** The specification asks both for
  `Planner::plan(PlanRequest): PlanResult` and for "one PlanResult per mode". `PlanResult` is
  the container: shared `meta` plus one `ModeResult` per requested mode, in request order
  (without duplicates). `ModeResult` has the fields described for the per-mode result.
- **D13. Physical box ids.** A line with `quantity = 1` keeps its `id`; with
  `quantity = n > 1` it expands into `id#1` … `id#n`. An id that collides with an expansion
  is an input error.
- **D14. Strict input.** Unknown fields, booleans or non-decimal strings where a number is
  expected, and a non-integer `quantity` are `InvalidPlanRequest`. Numbers are accepted as
  int, float or decimal string (`"12.5"`, `"1e2"`).

## Verifier

- **D15. Independence.** The Verifier rebuilds the physical boxes from the request with its
  own code and only uses simple geometry. It shares the model and profile conversion
  (`ResolvedProfile`) with the engine, not the placement logic.
- **D16. Support.** A box at `z = 0` rests fully on the pallet. Otherwise, the boxes whose
  top face is between `z − bandTolerance` and `z` count; the contact area must be at least
  `minSupport` of the base area. In other words, boxes in the same height band are treated
  as being at the same height for support too, which is what the band defines. With
  `overrides.bandTolerance = 0` exact contact is required. See D32.
- **D17. Fragile boxes.** No box may be above a fragile one (at any height) if their
  footprints overlap.
- **D18. Mandatory pallets.** A box is mandatory if it weighs more than the threshold or if
  its declared `width` exceeds the width threshold. The declared width is used as is: this
  is the most conservative choice, because `width` ≥ min(length, width). A set is mandatory
  if the sum of its boxes (counting `quantity`, including those that are not placed) exceeds
  the threshold, or if any of its boxes is mandatory on its own. A set is identified by
  (`shipmentId`, `setId`). A mandatory box on a pallet that is not `mandatory` is a hard
  violation.
- **D19. No placements.** If a result does not include `placements`, the Verifier reports
  `PLACEMENTS_MISSING` (hard) and checks the rest. The Planner always verifies the full plan
  before stripping placements.
- **D20. Soft rules.** Center of gravity off by more than 10 % of either side from the pallet
  center, and boxes resting on lighter ones (one warning per pallet, with the number of
  pairs).

## Validation and partition

- **D21. Order of a box's checks.** The first one that fails wins: length over 80 in
  (`BOX_UNSUPPORTED_LENGTH`, on the larger of `length` and `width`) → no allowed orientation
  brings the box to 72 in or less (`SINGLE_UNIT_TOO_TALL`) → does not fit on the base
  (`BOX_DOES_NOT_FIT_BASE`) → fits on the base but not in the mode's usable height
  (`BOX_EXCEEDS_HEIGHT_LIMIT`) → heavier than the payload (`BOX_EXCEEDS_WEIGHT_LIMIT`). The
  last four are not named by the specification; they are physical consequences of the
  profile. Every unit of the line goes to `unplaced` and one warning is emitted per line.
- **D22. 72 in "for a single unit".** It applies to any box, with or without `singleUnit`,
  using the lowest orientation its rotation allows.
- **D23. `HEAVY_BOX`.** One warning per line (not per unit), with `quantity` in the
  details. It is also emitted if the line ends up in `unplaced` for another reason.
- **D24. Identical boxes.** Same SKU, dimensions, weight, rotation and fragility (the last
  two change how they can be stacked). `sku` is optional (D51).
- **D25. Fixed order.** Shipments by `shipmentId` (binary comparison); within each one,
  mandatory pallets, then homogeneous ones and then the rest; groups and boxes in input
  order. The final tie-breaker of any sort is the box's position in the expanded input.

## Homogeneous layers

- **D26. Pattern.** Grid, two blocks and a four-block pinwheel with cuts at multiples of the
  box's two dimensions. A later candidate only wins if it fits strictly more boxes. The
  pinwheel is skipped if there are more than 16 cut positions per axis (small boxes, where
  the grid already wastes little).
- **D27. Standing face.** With `keep_upright` and `fixed` the declared height is vertical.
  With `any` all three faces are tried and the one fitting the most boxes per pallet wins;
  on a tie, the lowest stack height; on a tie, the fixed order (height, width, length as
  vertical).
- **D28. Boxes per pallet.** The minimum of layers by height × boxes per layer and
  `floor(payload / weight)`. If weight is the limit, the top layer is partial and uses the
  positions closest to the pallet center.
- **D29. Alternation.** Each layer tries a symmetry (mirror in x, in y or a 180° turn within
  the pattern's bounding box) that changes the geometry relative to the previous one and
  keeps the minimum support. If none works, it repeats the previous layer's one.
- **D30. Centered pattern.** The pattern's bounding box is centered on the pallet (integer
  offset rounded down). A single mandatory box is also centered.
- **D31. Fragile boxes.** They can only go in the top layer, so a pallet carries at most one
  layer with fragile boxes and nothing above it.

## Mixed layers, stacking and search

- **D32. Why the tolerance counts for support.** With exact contact, a box 0.3 in shorter
  than its layer neighbours supports nothing, and almost no mixed layer can take another
  one on top. In testing, that doubled the pallet count for shipments with many SKUs (2.2×
  the lower bound versus 1.6×). The specification groups boxes into bands "by tolerance",
  so the band is treated as a common height. **This decision is less conservative than
  exact contact; it should be confirmed.**
- **D33. Mixed layers on the surface.** Instead of building all mixed layers separately and
  stacking them afterwards, each layer is built with MaxRects directly on the pallet,
  accepting only positions with the minimum support. Bands are tried from tallest to
  shortest with both MaxRects rules (Best Short Side Fit and Bottom-Left); the layer that
  best fills its height slab wins. Leftover homogeneous layers are still stacked as
  prebuilt layers (Stacker).
- **D34. Standing face in the mixed stage.** With `any` rotation, a loose box stands on its
  lowest possible face.
- **D35. "Full" pallets with room left.** A homogeneous pallet that is full by height or
  weight is emitted directly, but if it has height and payload left it can receive layers
  from the mixed stage on top (it becomes `mixed`). This never increases the pallet count.
- **D36. Compaction.** At the end of the mixed stage, pairs of pallets whose combined height
  and weight fit in one are merged by restacking their layers (more flat surface at the
  bottom). It is only accepted if every layer meets the support rule.
- **D37. Placing a prebuilt layer.** The four symmetries are tried and, in addition,
  shifting the layer to align it with each corner and each edge of the pallet.
- **D38. Multi-order search.** Eight variants in a fixed order: volume, weight, base area and
  height, first without filling a layer's gap with shorter boxes and then with filling. The
  one with the fewest pallets wins; on a tie, the smallest weight difference between the
  heaviest and lightest pallet, then the smallest height difference and, finally, the first
  one tried. Other variants were measured (sorting stacking by coverage, breaking up
  leftover homogeneous layers, filling their gaps) and were not worth their cost.
- **D39. Performance.** Time grows with the number of distinct box sizes far more than with
  the number of boxes. Measured on one core, one mode: 500 boxes of 21 sizes, 0.25 s; 5,000
  of 21 sizes, 1.5 s; 1,000 all different, 2.5 s (README, "Performance"). There is no
  configurable time limit in v1.

## Parcel recommendation and metadata

- **D40. Parcel (Amazon SPD).** Evaluated per mode over the whole result: volume of placed
  boxes ÷ (pallets × length × width × usable height). If it falls below `parcelMinFill`,
  shipping as small parcel is recommended (type `PARCEL`) with reason
  `LOW_PALLET_VOLUME_FILL`. With `parcelMinFill = null` (the default) or no pallets,
  `recommendation` is `null`. Other parcel conditions (weight or size per box) are
  not considered; the specification only asks for fill.
- **D41. `meta`.** Library version, profile, profile version, request units and the SHA-256
  of the request's canonical JSON: sorted keys, numbers as exact already-scaled decimals
  (`"12.5"`), boxes in input order and overrides only if given. Two equivalent requests
  (`12.5` and `"12.500"`) have the same hash.
- **D42. `quantity` is required.** The specification lists it among each box's required
  fields; a default of 1 is not assumed.

## Generic profiles

- **D43. Destination rules are data.** The engine has no retailer-specific logic: everything
  that differs between destinations lives in the profile. The rules that not every
  destination has (`mandatoryPallet.*` and `boxRules.*`) are optional; a missing field or
  `null` means "no such rule", and every check that uses them is skipped. Pallet size, mode
  limits and engine defaults stay required, because without them there is nothing to plan.
- **D44. Neutral codes.** `BOX_OVER_50LB` became `HEAVY_BOX` and `SINGLE_UNIT_OVER_72IN`
  became `SINGLE_UNIT_TOO_TALL`: the threshold comes from the profile, so the code cannot
  carry Amazon's numbers. The threshold is still reported in `details`.
- **D45. Parcel instead of SPD.** `spdMinFill` became `parcelMinFill` and the recommendation
  type `SPD` became `PARCEL`; SPD is Amazon's name for small parcel delivery.
- **D46. Profile sources.** `ProfileRepository` combines the bundled profiles (optional),
  extra directories with the same `<id>/<version>.json` layout and profiles registered in
  memory (`withProfile(ProfileRepository::parse(...))`, for profiles kept in a database or a
  configuration service). It is immutable. A key must be defined by exactly one source;
  otherwise `load()` throws `InvalidProfile`. Letting a custom profile silently replace
  `amazon-us@2026-11` would make the same key mean different rules on different systems, and
  `meta` would no longer identify the rules a plan was made with. To change rules, use a new
  id or version.

## `walmart-us@2026-11`

- **D47. Limits.** From the WFS pallet guide: 48 × 40 in GMA pallets, each pallet "under
  72 in, including the pallet" and "under 2,100 lb". The engine treats limits as "at most",
  so "under" is modeled as 0.001 below the figure (71.999 in, 2,099.999 lb). No overhang.
- **D48. Stackable mode.** The Walmart Preferred Carrier page says stackable pallets must be
  under 50 in; the WFS guide only caps double-stacked pallets at 108 in in total (54 in each).
  The stricter 50 in is used (49.999 in), with the same 2,100 lb per pallet because no source
  gives a separate figure. Both values are marked unconfirmed. The 108 in total of a
  double stack is not modeled (pallet pairs are out of scope).
- **D49. Pallet tare.** Walmart does not publish a tare for the pallet itself. A GMA
  48 × 40 pallet is about 5.5 in tall and 35–50 lb, so 6 in and 50 lb are used (the high end,
  which leaves less room for boxes). Both are marked unconfirmed.
- **D50. Box rules.** WFS has no rule that forces a box onto its own pallet, so
  `mandatoryPallet` is empty. A master case may weigh up to 150 lb unless it holds a single
  unit over 50 lb; this maps to `heavyBoxWarningOver = 150`, flagged unless the line has
  `singleUnit: true`. The recommended master case size (31.5 × 23.25 × 16 in, 50 lb) is a
  receiving recommendation, not a limit, and is not modeled. There is no length or
  single-unit height rule beyond what fits on the pallet.

## Optional SKU

- **D51. `sku` is optional.** Packing only depends on dimensions, weight, rotation and
  fragility; the SKU groups identical boxes (D24) and labels pallets as `single_sku` or
  `mixed`. Many shipping exports carry no SKU, so a line may omit it (or send `null`).
  Such a line gets a stand-in SKU built from its scaled dimensions and weight, prefixed with
  NUL so it never matches a real SKU: physically identical boxes are grouped together, and a
  pallet holding one kind of box is labeled `single_sku`. The request echo keeps `sku: null`.
