# Contributing

Thanks for your interest. Bug reports, new destination profiles and pull requests are welcome.

## Reporting a problem

Open an issue with:

- The Palletwise version and the PHP version.
- The request that shows the problem (profile, modes and boxes), as JSON or PHP. Remove
  anything you do not want to make public; dimensions and weights are usually enough.
- What the planner returned and what you expected (for example, a pallet layout you know
  is possible with fewer pallets).

Security problems go through [SECURITY.md](SECURITY.md), not public issues.

## Development

PHP 8.2 or later and Composer.

```bash
composer install
composer test       # PHPUnit
composer analyse    # PHPStan, level max
composer cs         # PHP-CS-Fixer (dry run); composer cs:fix to apply
composer ci         # all three
```

[docs/decisions.md](docs/decisions.md) explains every design decision, and the README's
"How it works" section gives the overview.

## Pull requests

- One topic per pull request, with tests for new behavior and for fixed bugs.
- `composer ci` must pass; CI runs it on PHP 8.2 to 8.5.
- Keep the output deterministic: no randomness, no time limits, no dependence on hash or
  iteration order.
- No runtime dependencies.
- Code, comments and documentation in English.
- **Profiles:** cite the public source of every rule in the profile's description or in
  `docs/decisions.md`, and list values you could not confirm under `unconfirmed`. A rule
  that changes gets a new profile version; published versions are not edited.

By contributing you agree that your contributions are released under the [MIT License](LICENSE).
