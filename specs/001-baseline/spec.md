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

- **FR-ORM-005**: `Doctrine\ExactCollationListener` sets a byte-exact NO PAD collation on `username` / `ip_address` on MySQL / MariaDB: `utf8mb4_nopad_bin` on MariaDB, `utf8mb4_0900_bin` on MySQL 8.0+, and `utf8mb4_bin` with a warning on MySQL 5.7 or a short `server_version`. The repository re-checks every row with `===` (REQ-SEC-006, 3.11.0).
- **FR-ORM-009**: `Doctrine\LoginThrottleExactKeyColumns` (3.17.0, REQ-SEC-006) is the bundle's own declaration of its two access-key columns (`username`, `ip_address`) for MigrationsKit's `ExactKeyColumnsInterface`, so `nowo:migrations:check-key-collations` asserts the byte-exact collation instead of trusting it; MigrationsKit tags it by autoconfiguration (a host registers nothing and can drop the `collation_check.ignore_columns` entries it kept by hand). The table name is `doctrine.table_prefix` + `login_attempts`; the columns are declared inactive (skipped, with the condition `nowo_login_throttle.doctrine.register_mapping: the LoginAttempt entity is mapped`) when the entity is not mapped. Stateless and database-free (worker safe). The service is private and simply removed from the container when MigrationsKit is absent.
- **FR-ORM-006**: Entity-free store ports (REQ-ARCH-001): `LoginAttemptViewRepositoryInterface` and the `LoginAttemptView` read model (3.9), `LoginAttemptRecorderInterface` (3.12), `LoginAttemptWithoutUsernameReaderInterface` (3.13.0) and `LoginAttemptStoreInterface` (3.14). The store interface is the twin of the deprecated `LoginAttemptRepositoryInterface`. `AttemptStorePass` points the internal store alias at the store interface first, else at the deprecated repository interface, else at the bundle repository.
- **FR-ORM-007**: `Testing\LoginAttemptStoreContractTestCase` holds the conformance tests a host store extends to check the limiter's contract, including the `clearAttempts()` rule (3.14).
- **FR-ORM-008**: `LoginAttemptLockInterface` + `Transaction\AttemptLockRunner` serialise the units of one IP address: an attempt decision or a reset after a successful login. The lock is a per-IP lock (`GET_LOCK()` on MySQL / MariaDB) inside the bundle's own READ COMMITTED unit on the primary connection, bounded by `attempt_lock_timeout` and with a lock-free deny fast path (3.15, Beacon spec 239).

### Rate limiting

- **FR-LIMIT-001**: `DatabaseRateLimiter`, `DatabaseRateLimiterFactory` — Symfony RateLimiter integration backed by Doctrine.
- **FR-LIMIT-002**: Limiter and factory hold no per-request mutable state (safe under FrankenPHP scenario B).

- **FR-LIMIT-003**: `Throttle\ThrottleRules` is the single place for the limiter rules: limits, per-IP cap, bound without a username, retry time. `ThrottleStatus` is its read-only result, and `UsernameNormalizer` extracts and normalises the submitted identifier the same way for the limiter, the status provider and the info service (3.9).
- **FR-LIMIT-004**: The database limiter dispatches `Event\LoginThrottledEvent` for every refused attempt and `Event\LoginAttemptsClearedEvent` after a successful login clears its IP + username bucket. Both events carry scalars only and are dispatched after the store write (REQ-ARCH-001 rule 4, 3.14).

### Services & i18n

- **FR-SVC-001**: `LoginThrottleInfoService` — remaining attempts / lockout info for UI.
- **FR-SVC-002**: `LoginThrottleStatusProviderInterface` / `LoginThrottleStatusProvider` report the HTTP-free throttling state of a bucket, using the limiter's own `ThrottleRules` (3.9).
- **FR-I18N-001**: 24 locale files for throttle error messages.

### Conflict answer (REQ-RUNTIME-003 rule 6, 3.16)

- **FR-CONC-001**: When the attempt store fails transiently (lock not granted, deadlock, lock wait timeout, serialization failure) the limiter raises `Transaction\LoginThrottleStorageFailure` (a `TransientStorageFailure`, generic message, always `CONFLICT_001`). `StorageFailureSubscriber` answers it for a login on a guarded firewall with 409 (or 503, `nowo_login_throttle.conflict_response.status`) + `Retry-After` + `X-Conflict-Code`, never a 2xx or a redirect (fails closed); `ConflictResponsePass` wires it by `conflict_response.mode` (`bundle` default: transaction-doctrine's policy and responder when registered; `host`: the subscriber is removed and the host maps the failure).
- **FR-CONC-002**: `LoginConflictListener` (priority 128, both modes, stateless) ends the login when the failure comes from the reset after a right password (token cleared, session invalidated, remember-me cookie dropped) and removes transaction-doctrine's commit mark from the request, so the answer is identical whether the account exists, is locked or the password is right (no account-existence oracle).

### Worker / static analysis

- **FR-WORKER-001**: Bundle is viable under FrankenPHP worker with kernel not reset between requests (see [`docs/FRANKENPHP-WORKER-AUDIT.md`](../../docs/FRANKENPHP-WORKER-AUDIT.md)).
- **FR-WORKER-002**: PHPStan includes `nowo-tech/phpstan-frankenphp` classic, worker, and worker-strict rulesets.

---

## Success Criteria

- **SC-001**: **59/59** production files under `src/` mapped in [`code-inventory.md`](code-inventory.md) (audited 2026-11-25 on v3.17.0; `git ls-files src | wc -l`).
- **SC-002**: Config keys match [`docs/CONFIGURATION.md`](../../docs/CONFIGURATION.md).
- **SC-003**: 100% PHPUnit line coverage on `src/` (project standard).

---

## Explicit non-goals

- CAPTCHA or account lockout beyond Symfony login throttling.
- Non-form-login authentication flows (API keys, OAuth) unless firewall uses form login.

---

## Validation

`composer qa`, `make test-coverage-100`, PHPStan, inventory row audit.
