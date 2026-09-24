# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/login-throttle-bundle` (`symfony-bundle`) |
| Audited revision | `v3.2.1` (post W-01/W-02 + ORM-3 `createQueryBuilder` fix) |
| Audit date | 2026-09-24 |
| Method | Manual review of every file under `src/` (bundle class, DI extension, configuration, `services.yaml`, database rate limiter and factory, repository, info service, entity; console command skimmed as CLI only) |
| **Verdict** | ✅ **Viable under scenario B (100%)** — rate-limit decisions are stateless and read from the database on every call (no cross-user leak); the database storage detaches every `LoginAttempt` it writes or loads, resets a closed EntityManager through `ManagerRegistry`, and never reuses DoctrineBundle's ORM-3 repository proxy bound to a closed manager |
| Remediation | W-01 resolved in `LoginAttemptRepository` (detach + closed-EM reset + live `createQueryBuilder`); W-02 resolved in `NowoLoginThrottleBundle::boot()` / `Configuration::generateConfigFile()`. Regression tests: `tests/Repository/LoginAttemptRepositoryWorkerModeTest.php`, `tests/NowoLoginThrottleBundleBootTest.php` |

## Execution model assumed

FrankenPHP worker mode boots the Symfony kernel once per worker and serves many requests with the same container. This audit assumes the **strict** variant: the kernel is **not** rebooted between requests (`reset_kernel` / services resetter off), so every shared service, static property and PHP global survives from one request to the next. Two scenarios are evaluated:

- **A — kernel not rebooted, `services_resetter` still runs:** services tagged `kernel.reset` (or implementing `ResetInterface`) are reset between requests.
- **B — no reset at all:** nothing is reset; any per-request state kept in a service leaks into the next request.

A bundle that is safe under **B** is safe under **A** and under classic mode / PHP-FPM.

## Summary

| Area | Status | Notes |
|------|--------|-------|
| Mutable state in shared services | ✅ | `DatabaseRateLimiter` and its factory are `final` with `readonly` properties; `LoginThrottleInfoService` has two setter-injected properties that are set once at container build |
| Static properties / `static` locals | ✅ | None; `Configuration::secondsToInterval()` is a pure static helper used at compile time |
| `ResetInterface` / `kernel.reset` coverage | ✅ N/A | No bundle service holds per-request state; the repository does not rely on DoctrineBundle's resetter for its own entities |
| Request / user / locale captured in services | ✅ | IP and username are read from the `Request` argument on each call; nothing is captured in a constructor |
| Superglobals, `$_ENV`, `putenv`, `ini_set`, `setlocale`, timezone | ✅ | None used |
| Doctrine / EntityManager | ✅ | All writes and DQL go through `getEntityManager()` / overridden `createQueryBuilder()` (registry resolve + closed-EM reset); `recordAttempt()` / `getAttempts()` detach entities (W-01). Clearing application entities between requests stays the application's responsibility |
| Output, headers, `exit`, shutdown functions | ✅ | None |
| Resources (files, sockets, cURL) held open | ✅ | None; `Bundle::boot()` reads/writes config files once per kernel boot without keeping handles |
| Memory growth across requests | ✅ | `LoginAttempt` entities are detached after write/read (W-01) |
| Blocking I/O and timeouts | ✅ | Only database queries on the login path; no HTTP or process calls |
| Third-party static state | ✅ | Only Symfony Security / RateLimiter contracts and Doctrine ORM |
| PHPStan FrankenPHP rulesets | ✅ | `extension.neon`, `ruleset-classic.neon`, `ruleset-worker.neon` and `ruleset-worker-strict.neon` included in `phpstan.neon.dist` |

Worker demo: `demo/demo-symfony8/docker/frankenphp/Caddyfile` declares a `worker` block inside `php_server` (line 15). `Caddyfile.dev` runs in classic mode.

## Services reviewed

| Service | Shared | Mutable state | Scenario A | Scenario B |
|---------|--------|---------------|------------|------------|
| `nowo_login_throttle.database_rate_limiter[.*]` (`RateLimiter\DatabaseRateLimiter`, registered in the extension, public) | yes | none (`readonly` repository, max attempts, timeout) | ✅ | ✅ |
| `RateLimiter\DatabaseRateLimiterFactory` | yes | none (`readonly`); `create()` returns a new limiter | ✅ | ✅ |
| `Repository\LoginAttemptRepository` (+ `LoginAttemptRepositoryInterface` alias) | yes | `readonly` `ManagerRegistry` reference only (no cache) | ✅ | ✅ (W-01 resolved) |
| `Service\LoginThrottleInfoService` | yes | `repository`, `firewallsConfig` (set once by `#[Required]` / `calls`, never per request) | ✅ | ✅ |
| `Command\ConfigureSecurityCommand` | CLI only | not used by the HTTP worker | N/A | N/A |
| `NowoLoginThrottleBundle::boot()` | once per kernel boot | none | ✅ (W-02 resolved) | ✅ (W-02 resolved) |

`Entity\LoginAttempt` is created per attempt; its `createdAt` is set in the entity constructor, not in a service. No `RateLimit` or `LoginAttempt` object is stored in a service property.

## Findings

### W-01 — Login attempts go through the shared EntityManager and are never detached (Medium, scenario B only)

- **Where:** `src/Repository/LoginAttemptRepository.php` (`persist()` + `flush()` in `recordAttempt()`, `getAttempts()` hydrates `LoginAttempt` entities); called on every login attempt from `src/RateLimiter/DatabaseRateLimiter.php` (`consume()`) and from `src/Service/LoginThrottleInfoService.php`.
- **Worker impact:** under A, DoctrineBundle's `kernel.reset` clears the EntityManager (and resets it if it was closed), so nothing survives. Under B:
  - every `LoginAttempt` created or loaded stays managed in the identity map, so memory grows with every login POST. An attacker sending many failed logins controls this growth.
  - if a `flush()` fails (database error, lost connection), the EntityManager is closed and without recovery every later `consume()` on that worker throws "EntityManager is closed".
  - with Doctrine ORM 3 + `ServiceEntityRepository` proxy, `createQueryBuilder()` caches an inner `EntityRepository` bound to the manager from the first call; after `ManagerRegistry::resetManager()` that proxy would keep querying the closed instance unless `createQueryBuilder()` is overridden.
  - Throttling decisions stay correct when counts hit the database: `COUNT` / `DELETE` DQL decide blocks, not the identity map.
- **Recommendation:** keep `kernel.reset` enabled (scenario A). To be safe under B, detach after flush/load, recover closed managers with `ManagerRegistry::resetManager()`, and resolve DQL through a live manager (override `createQueryBuilder()` / `getEntityManager()`).
- **Status:** Resolved — `recordAttempt()` resolves/resets the manager and detaches in `finally`; `getAttempts()` detaches; `getEntityManager()` always reads from `ManagerRegistry` and resets when closed; `createQueryBuilder()` builds via that live manager so ORM-3 proxy cache cannot pin a closed EM. Residual (accepted): `flush()` still writes the whole unit of work of that manager; under scenario B clearing application entities between requests remains the application's responsibility. Map `LoginAttempt` to a dedicated entity manager to isolate it.

### W-02 — `Bundle::boot()` scans and may write `config/packages` at runtime (Low)

- **Where:** `src/NowoLoginThrottleBundle.php` (`glob()` + `file_get_contents()` over every `config/packages/*.yaml|*.yml` in `isConfigurationDefined()`) and `src/DependencyInjection/Configuration.php` (`mkdir()` / `file_put_contents()` of `nowo_login_throttle.yaml`).
- **Worker impact:** in worker mode `boot()` runs once per worker boot instead of once per request, so the cost is lower than under PHP-FPM. However, several workers booting at the same time can race to write the same file, and on a read-only production image `file_put_contents()` fails with a PHP warning, which Symfony's debug error handler turns into an exception during kernel boot. The generated file has no effect until the container is rebuilt. No state leaks between requests.
- **Recommendation:** ship `config/packages/nowo_login_throttle.yaml` in the application (or via a Flex recipe) so the write path is never reached in production, and consider limiting the generation to `PHP_SAPI === 'cli'` / `kernel.debug`.
- **Status:** Resolved — `boot()` skips the generation when the target directory (or the project dir if `config/packages` is missing) is not writable, and `Configuration::generateConfigFile()` writes with `LOCK_EX`. Shipping the config file remains the recommended setup.

### Info

- `DatabaseRateLimiter::consume()` does a read (`isBlocked()`), then an insert, then a count. This is not atomic, so concurrent attempts on different worker threads can exceed `max_count_attempts` by a small margin. This is the same under PHP-FPM and is not caused by worker mode.
- IP detection uses `Request::getClientIp()`. Behind Caddy / a load balancer, `framework.trusted_proxies` must be configured, otherwise all users share the proxy IP and IP-based throttling blocks everyone together. This is an application setting, not bundle state.
- With `storage: cache` the bundle only configures Symfony's native `login_throttling`; the rate limiter state lives in the cache pool (`cache.rate_limiter` by default), which is shared between workers and safe in worker mode.

## Usage recommendations in worker mode

- `storage: database` is safe under scenario B (no kernel / services reset) since the W-01 remediation. Keeping `services_resetter` enabled is still recommended because the application's own Doctrine state is framework-owned. With `storage: cache` no special handling is needed.
- Commit the bundle config file (W-02) and do not rely on runtime generation in containers.
- Configure `framework.trusted_proxies` / `trusted_headers` for the FrankenPHP / Caddy setup so `getClientIp()` returns the real client IP.
- Run the cleanup of old rows (`LoginAttemptRepository::cleanup()`) from a scheduled CLI job, not from an HTTP request.
- Custom `LoginAttemptRepositoryInterface` implementations or decorators of `DatabaseRateLimiter` must not cache counts or attempts in properties (that would mix users between requests) unless they implement `ResetInterface` and the cache is keyed by IP + username with a short TTL.
- If the application cannot guarantee scenario A for *its own* services, set FrankenPHP `max_requests` (or `FRANKENPHP_LOOP_MAX` with the Symfony runtime) so workers are recycled.

## Re-audit triggers

Re-run this audit when a change adds: properties to `DatabaseRateLimiter`, `DatabaseRateLimiterFactory` or `LoginThrottleInfoService` that change per request; an in-memory cache of attempts or counts; an event listener or subscriber on security events; any use of `$_SERVER` / `$_ENV` / `getenv()` at runtime; or new filesystem access in `Bundle::boot()`.
