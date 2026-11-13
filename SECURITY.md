# Security

## Reporting a vulnerability

Please do **not** open a public issue. Report it privately through GitHub:
**Security → Report a vulnerability** on this repository
(<https://github.com/Alvaromero/palletwise/security/advisories/new>).

Include the version, how to reproduce it and what an attacker could do. You should get an
answer within a week. Fixes are released as a new version and credited in the advisory
unless you prefer otherwise.

## Supported versions

Only the latest release gets security fixes.

## Scope

Palletwise is a library with no network access and no I/O other than reading profile
files. What it is meant to guarantee:

- Requests are validated strictly; invalid input throws `InvalidPlanRequest` instead of
  being guessed at. Request size is bounded (20,000 boxes per request).
- Profiles are only read from the bundled directory, from directories you pass to
  `ProfileRepository::withDirectory()`, or from memory.

Reports about input that makes the planner hang, exhaust memory or read files it should
not are in scope.
