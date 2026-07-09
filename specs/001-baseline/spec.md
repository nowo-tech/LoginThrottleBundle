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

---

## Requirements

### Bundle & config

- **FR-BUNDLE-001**: `NowoLoginThrottleBundle` alias `nowo_login_throttle`.
- **FR-CFG-001**: `Configuration` — simple single-firewall keys and advanced `firewalls` tree; `enabled`, `max_count_attempts`, `timeout`, `watch_period`, `storage`, `cache_pool`, `rate_limiter`, `lock_factory`.
- **FR-CFG-002**: `NowoLoginThrottleExtension` — generates default YAML, registers limiters and Doctrine mapping when storage is database.

### DI

- **FR-DI-001**: `Resources/config/services.yaml` wires limiters, repository, info service, command.

### CLI

- **FR-CLI-001**: `ConfigureSecurityCommand` outputs firewall recipe.

### Persistence

- **FR-ORM-001**: `LoginAttempt` entity for stored attempts.
- **FR-ORM-002**: `LoginAttemptRepository` — queries and expired-attempt cleanup.

### Rate limiting

- **FR-LIMIT-001**: `DatabaseRateLimiter`, `DatabaseRateLimiterFactory` — Symfony RateLimiter integration backed by Doctrine.

### Services & i18n

- **FR-SVC-001**: `LoginThrottleInfoService` — remaining attempts / lockout info for UI.
- **FR-I18N-001**: 24 locale files for throttle error messages.

---

## Success Criteria

- **SC-001**: **34/34** files mapped in inventory.
- **SC-002**: Config keys match [`docs/CONFIGURATION.md`](../../docs/CONFIGURATION.md).
- **SC-003**: 100% PHPUnit line coverage on `src/` (project standard).

---

## Explicit non-goals

- CAPTCHA or account lockout beyond Symfony login throttling.
- Non-form-login authentication flows (API keys, OAuth) unless firewall uses form login.

---

## Validation

`composer qa`, `make test-coverage-100`, PHPStan, inventory row audit.
