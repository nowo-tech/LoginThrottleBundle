# Feature Specification: LoginThrottleBundle baseline (100% code coverage)

**Feature Branch**: `001-baseline`  
**Status**: Active  

**Package**: `nowo-tech/login-throttle-bundle`  
**Configuration root**: `nowo_login_throttle`  
**Code inventory**: [`code-inventory.md`](code-inventory.md)

---

## Summary

Drop-in Symfony bundle wrapping native **`login_throttling`**: sensible defaults compatible with deprecated `anyx/login-gate-bundle`, single- or multi-firewall YAML, **cache** or **database** storage for attempts, auto-generated config file, `ConfigureSecurityCommand` for `security.yaml` scaffolding, and i18n for throttle messages.

---

## User Scenarios

### US-01 — Enable throttling (P1)

**Given** `nowo_login_throttle.enabled: true` with `max_count_attempts` and `timeout`, **When** bundle boots, **Then** `NowoLoginThrottleExtension` wires Symfony `login_throttling` on the configured firewall(s).

### US-02 — Database storage & audit (P1)

**Given** `storage: database`, **When** failed logins occur, **Then** `DatabaseRateLimiter` persists attempts via `LoginAttempt` entity and `LoginAttemptRepository` for auditing and cleanup.

### US-03 — Multi-firewall config (P2)

**Given** `nowo_login_throttle.firewalls` map (e.g. `main`, `api`), **When** each firewall has independent limits, **Then** separate rate limiter services/factories apply per firewall entry.

### US-04 — Security scaffolding (P2)

**Given** new integrator, **When** `nowo:login-throttle:configure-security` runs, **Then** `ConfigureSecurityCommand` emits documented `security.yaml` snippets with `login_throttling` keys.

### US-05 — Throttle feedback (P3)

**Given** a throttled login, **When** the login form renders, **Then** `LoginThrottleInfoService` and translation files supply locale-aware user messages.

### US-06 — FrankenPHP worker without kernel reset (P1)

**Given** FrankenPHP worker mode with no kernel / `services_resetter` between requests and `storage: database`, **When** many failed logins (and occasional closed EntityManagers) occur on the same worker, **Then** throttling stays correct per IP/username, memory does not grow via managed `LoginAttempt` entities, and the next request can still read/write attempts.

---

## Requirements

### Bundle & config

- **FR-BUNDLE-001**: `NowoLoginThrottleBundle` alias `nowo_login_throttle`.
- **FR-CFG-001**: `Configuration` — simple single-firewall keys and advanced `firewalls` tree; `enabled`, `max_count_attempts`, `timeout`, `watch_period`, `storage`, `cache_pool`, `rate_limiter`, `lock_factory`.
- **FR-CFG-002**: `NowoLoginThrottleExtension` — generates default YAML, registers limiters and Doctrine mapping when storage is database.
- **FR-CFG-003**: Default config generation on boot is skipped when the target directory is not writable; writes use `LOCK_EX`.

### DI

- **FR-DI-001**: `Resources/config/services.yaml` wires limiters, repository, info service, command.

### CLI

- **FR-CLI-001**: `ConfigureSecurityCommand` outputs firewall recipe.
- **FR-CLI-002**: `CleanupLoginAttemptsCommand` (`nowo:login-throttle:cleanup`) deletes stored login attempts older than the configured watch period when storage is `database`.

### Persistence

- **FR-ORM-001**: `LoginAttempt` entity for stored attempts.
- **FR-ORM-002**: `LoginAttemptRepository` — queries and expired-attempt cleanup; attempt windows use the optional injected `Psr\Clock\ClockInterface` (also used by `DatabaseRateLimiter`).
- **FR-ORM-004**: `Doctrine\TablePrefixListener` prepends `doctrine.table_prefix` to the `LoginAttempt` table and its index names only; registered only when the prefix is not empty (REQ-ORM-002).
- **FR-ORM-003**: Under long-lived workers, `LoginAttemptRepository` detaches written/loaded attempts, resets a closed EntityManager via `ManagerRegistry`, and builds DQL through a live manager (ORM 3 `ServiceEntityRepository` proxy safe).

### Rate limiting

- **FR-LIMIT-001**: `DatabaseRateLimiter`, `DatabaseRateLimiterFactory` — Symfony RateLimiter integration backed by Doctrine.
- **FR-LIMIT-002**: Limiter and factory hold no per-request mutable state (safe under FrankenPHP scenario B).

### Services & i18n

- **FR-SVC-001**: `LoginThrottleInfoService` — remaining attempts / lockout info for UI.
- **FR-I18N-001**: 24 locale files for throttle error messages.

### Worker / static analysis

- **FR-WORKER-001**: Bundle is viable under FrankenPHP worker with kernel not reset between requests (see [`docs/FRANKENPHP-WORKER-AUDIT.md`](../../docs/FRANKENPHP-WORKER-AUDIT.md)).
- **FR-WORKER-002**: PHPStan includes `nowo-tech/phpstan-frankenphp` classic, worker, and worker-strict rulesets.

---

## Success Criteria

- **SC-001**: **37/37** production files under `src/` mapped in [`code-inventory.md`](code-inventory.md) (audited 2026-10-21; `git ls-files src | wc -l`).
- **SC-002**: Config keys match [`docs/CONFIGURATION.md`](../../docs/CONFIGURATION.md).
- **SC-003**: 100% PHPUnit line coverage on `src/` (project standard).

---

## Explicit non-goals

- CAPTCHA or account lockout beyond Symfony login throttling.
- Non-form-login authentication flows (API keys, OAuth) unless firewall uses form login.

---

## Validation

`composer qa`, `make test-coverage-100`, PHPStan, inventory row audit.
