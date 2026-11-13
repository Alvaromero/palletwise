# Changelog

All notable changes to this project are documented here. The project follows
[Semantic Versioning](https://semver.org/).

## 1.0.0 — first public release

- **Planner:** estimates the pallets an inbound shipment needs in `non_stackable` and/or
  `stackable` mode, placing every box (x, y, z) so the count is physically feasible.
  Homogeneous layers, mixed layers with support checks, fragile boxes on top, sets, and a
  multi-order search that keeps the best plan.
- **Profiles:** versioned JSON profiles (`<id>@<YYYY-MM>`) bundled for Amazon FBA US and EU
  and Walmart WFS US. Add your own from a directory or in memory; every rule is optional.
- **Verifier:** every result is checked by an independent verifier before it is returned.
- **Results:** pallet type (`single_sku`, `mixed`, `mandatory`), height, weight, layers,
  utilization, lower bounds, warnings, unplaced boxes, an optional parcel recommendation and
  optional placements. Deterministic output, byte for byte.
- **Input:** inches/pounds or centimeters/kilograms, exact decimal handling, strict
  validation with field-level messages, per-request overrides; `sku` is optional.
