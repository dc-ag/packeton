# #210226 — [F]-Renovate-Optimizations Implementation Plan

## Scope and delivery model
- This plan covers **only this repository (`packeton`)**.
- Scope is limited to the Packeton API/repository/documentation/test changes required for `current-dependents` behavior.

## Completion checklist (major milestones)
1. [x] Packeton exposes `/api/packages/{name}/current-dependents.json` with latest/current-only semantics.
2. [x] Packeton response includes `dependency_type` (`require`/`require-dev`) with precedence rule (`require` wins).
3. [x] Packeton supports optional `version_tag` substring filter and keeps list/count consistency.
4. [x] Packeton docs/changelog describe the endpoint contract and semantics.
5. [x] Packeton tests cover latest/current filtering, dependency type precedence, `version_tag`, and list/count consistency.

## Phase 1 — Packeton: `getCurrentDependents` endpoint

### 1.1 Repository API design and signatures
- [x] Add in `src/Repository/PackageRepository.php`:
  - `getCurrentDependents(string $name, int $offset = 0, int $limit = 15, ?string $versionTag = null): array`
  - `getCurrentDependentsCount(string $name, ?string $versionTag = null): int`
- [x] Keep existing `getDependents`/`getDependentCount` unchanged for backward compatibility.
- [x] Define deterministic ordering: `ORDER BY p.name ASC`, with stable pagination (`LIMIT/OFFSET`).

### 1.2 SQL strategy for "current" + "latest relevant version"
- [x] Build a subquery/CTE that selects **one version per dependent package** from `package_version`:
  - constrain to current line (`development = true`),
  - apply optional `version_tag` (`version LIKE :versionTag` with `%...%`),
  - resolve ties deterministically (prefer latest by timestamp and/or max `id`).
- [x] Join dependency links against that selected version set (not historical full table).
- [x] Include both dependency sources:
  - `link_require` => `dependency_type = require`
  - `link_require_dev` => `dependency_type = require-dev`
- [x] Deduplicate by dependent package with precedence rule:
  - if package appears in both tables for same target, return once with `dependency_type = require`.
- [x] Count query returns `COUNT(DISTINCT package_id)` equivalent to list query semantics.

### 1.3 Caching updates
- [x] Add dedicated cache keys for new methods and include all filter dimensions:
  - package name,
  - offset/limit (list only),
  - `version_tag` value,
  - current-mode discriminator (to avoid overlap with old dependents cache entries).
- [x] Keep TTL aligned with existing dependents cache unless profiling demands a change.

### 1.4 Controller/API route exposure
- [x] In `src/Controller/PackageController.php`, add new route/action:
  - `GET /api/packages/{name}/current-dependents.json`
  - request params: `page` and optional `version_tag`.
- [x] Reuse existing paginator/metadata response shape where possible, adding `dependency_type` per item.
- [x] Keep existing `/api/packages/{name}/dependents` behavior untouched.

### 1.5 API documentation + changelog
- [x] Update `swagger/packages-api.yaml` with:
  - new endpoint path,
  - optional `version_tag` query parameter,
  - response notes for `dependency_type` and latest/current semantics.
- [x] Add release note/changelog entry clarifying:
  - "current dependents" != historical dependents,
  - `version_tag` is substring match,
  - precedence `require` over `require-dev`.
  - **Note**: changelog added to `UPGRADE.md` (no separate CHANGELOG.md exists in this repo).

### 1.6 Packeton verification
- [x] Add/extend automated tests (repository/functional):
  - historical-only dependent excluded,
  - latest current version included,
  - `require` and `require-dev` both covered,
  - overlap case returns single row with `require`,
  - `version_tag` positive and negative matching,
  - list/count consistency for same filters.
- [x] Run targeted Packeton tests and ensure green before integration handoff.
  - All 52 tests pass (52 tests, 117 assertions). Fixed test fixtures: added missing `autoupdated` and `createdAt` columns, and resolved kernel boot ordering conflict in `setUpBeforeClass`/`tearDownAfterClass`.

## Phase 2 — Packeton rollout and handoff notes

### 2.1 Sequenced delivery in this repository
- [x] Implement repository + controller + swagger + changelog changes together.
- [x] Validate endpoint behavior via Packeton tests before release.
  - All tests green: `php vendor/bin/phpunit tests/Functional/ --no-coverage` → OK (52 tests, 117 assertions).

### 2.2 Handoff notes for downstream consumers
- [x] Document consumer-facing contract clearly:
  - `/api/packages/{name}/current-dependents.json` is current/latest-only,
  - `dependency_type` values are `require` or `require-dev`,
  - overlap precedence is `require`.
- [x] Provide examples for optional `version_tag` filtering semantics.
