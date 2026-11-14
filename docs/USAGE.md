# Usage

This document describes common usage patterns for `nowo-tech/login-throttle-bundle`.

## Table of contents

- [Basic usage](#basic-usage)
- [Typical workflow](#typical-workflow)
- [Multi-firewall usage](#multi-firewall-usage)
- [Storage selection](#storage-selection)
- [Translation overrides](#translation-overrides)
- [Using it from a DDD / hexagonal application](#using-it-from-a-ddd--hexagonal-application)

## Basic usage

1. Install and enable the bundle.
2. Configure `nowo_login_throttle` in `config/packages/nowo_login_throttle.yaml`.
3. Run:

```bash
php bin/console nowo:login-throttle:configure-security --force
```

4. Verify `login_throttling` was generated in the expected firewall(s).

## Typical workflow

- Start from conservative limits in development.
- Validate behavior with failed login attempts.
- Adjust limits by firewall according to your risk profile.

## Multi-firewall usage

Use the `firewalls` key to define independent policies for each firewall (for example `main`, `admin`, and `api`).

## Storage selection

- `cache`: lightweight and suitable for most single-node deployments.
- `database`: preferred when you need persistence/auditing or shared state.

## Translation overrides

UI strings use the `NowoLoginThrottleBundle` translation domain. To override them from the host application, add YAML files under `translations/NowoLoginThrottleBundle.<locale>.yaml`. Host translations take precedence over the catalogues shipped in this bundle.

For database details, see [DATABASE_STORAGE.md](DATABASE_STORAGE.md).

## Using it from a DDD / hexagonal application

**Layer:** infrastructure (security adapter). The bundle plugs into Symfony Security's native `login_throttling`;
your domain and application layers never call it. With `storage: database` it keeps its own technical table
(`login_attempts`, entity `Nowo\LoginThrottleBundle\Entity\LoginAttempt`). The user is a plain username string, so
there is no relation to your `User` entity, no `resolve_target_entities` and nothing to extend.

**Keeping the domain free of the bundle**

- Configure it only in `config/packages/nowo_login_throttle.yaml` and `security.yaml`. No domain class needs to import
  `Nowo\LoginThrottleBundle\*`.
- Cache-only hosts can set `doctrine.register_mapping: auto` (or `false`) so the `login_attempts` table never enters
  your schema or migrations.
- Since 3.14, `storage: database` hosts can do the same: implement `LoginAttemptStoreInterface` (entity-free) on your
  own store, alias it, and set `doctrine.register_mapping: false`.
- With `storage: database`, treat `login_attempts` as infrastructure (like a cache table). Prune it with
  `nowo:login-throttle:cleanup` and use `doctrine.table_prefix` to keep it apart from your tables.

**Ports and events to use**

| Need | Use |
| --- | --- |
| Your own attempt store, bundle rules | Implement `Nowo\LoginThrottleBundle\Repository\LoginAttemptStoreInterface` (since 3.14; scalars, `DateTimeImmutable` and the readonly `LoginAttemptView` only, no Doctrine type) and alias it. The database limiter, status provider, info service and cleanup command use it; `doctrine.register_mapping: false` is then allowed with `storage: database`. It wins over the deprecated `LoginAttemptRepositoryInterface` when both are aliased. |
| Your own throttling rules | Implement `Symfony\Component\HttpFoundation\RateLimiter\RequestRateLimiterInterface` and set `rate_limiter: <service id>` (or `firewalls.<name>.rate_limiter`). The bundle then registers no database limiter for that firewall. This is Symfony's own seam (it takes a `Request`); prefer the store port above when the bundle's rules suit you. |
| React to a lockout (audit, notification) | Listen to `Nowo\LoginThrottleBundle\Event\LoginThrottledEvent` (since 3.14, `storage: database`): `ipAddress`, normalised `username`, `scope` (`username` / `ip` / `without_username`), `limit`, `retryAfter`, `lockoutStarted` (true once per lockout: the attempt that reached the limit), `firewallName`. Dispatched after the attempt was written. `LoginAttemptsClearedEvent` follows a successful login that cleared the bucket. With `storage: cache`, listen to Symfony's `LoginFailureEvent` and check `TooManyLoginAttemptsAuthenticationException`. |
| Show remaining attempts on the login page | `LoginThrottleStatusProviderInterface::status(string $firewall, string $ipAddress, ?string $username = null): ThrottleStatus` (since 3.9; HTTP-free, typed, same rules as the database limiter: normalised username, IP + username bucket, per-IP cap). `LoginThrottleInfoService::getAttemptInfo()` returns the same figures as an array. |
| Read the attempt store without the Doctrine entity | `LoginAttemptViewRepositoryInterface` (since 3.9, implemented by `LoginAttemptRepository`): `getAttemptViews()` returns readonly `LoginAttemptView` DTOs, `findOldestAttemptAt()` a `DateTimeImmutable`. |
| Record an attempt without the Doctrine entity | `LoginAttemptRecorderInterface` (since 3.12, implemented by `LoginAttemptRepository`): `recordAttemptView()` returns the readonly `LoginAttemptView` of the stored row. A store that implements it is the one the database limiter records through. |
| `json_login` / custom authenticators with `storage: database` | Set `username_from_passport: true` (since 3.9): without `_username` / `username` / `email` POST fields, attempts are keyed on the identifier the visitor submitted (the passport `UserBadge` identifier, never the resolved user), like `form_login`. |
| Testable time | Register a `Psr\Clock\ClockInterface` service. The database limiter and the repository use it when present. |

Example adapter that turns a lockout into an application command (no Symfony type reaches your application layer):

```php
use Nowo\LoginThrottleBundle\Event\LoginThrottledEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final class LoginLockoutListener
{
    public function __construct(private readonly RecordLoginLockout $recordLoginLockout) {} // your application port

    public function __invoke(LoginThrottledEvent $event): void
    {
        if (!$event->lockoutStarted) {
            return; // one notification per lockout, not per refused attempt
        }

        ($this->recordLoginLockout)($event->firewallName, $event->ipAddress, $event->username, $event->retryAfter);
    }
}
```

Example host store wiring (`storage: database`, no bundle table):

```yaml
# config/packages/nowo_login_throttle.yaml
nowo_login_throttle:
    storage: database
    doctrine:
        register_mapping: false

# config/services.yaml
services:
    Nowo\LoginThrottleBundle\Repository\LoginAttemptStoreInterface: '@App\Infrastructure\Security\RedisLoginAttemptStore'
```

Standard Symfony hosts need none of this: the bundle's Doctrine repository is the default store.

**Known limitations**

- The bundle's repository never flushes your EntityManager: attempts are written with a DBAL insert on its connection.
  If your own store writes inside a transaction, `LoginThrottledEvent` is dispatched when your store returns, which
  may be before your commit.
- `LoginAttemptRepositoryInterface` (with `recordAttempt(): LoginAttempt` and `getAttempts(): list<LoginAttempt>`) is
  deprecated since 3.14 and removed in 4.0; existing stores on it keep working. New stores implement
  `LoginAttemptStoreInterface` only.
- `username_from_passport` is `false` by default (3.x keeps today's keys): with `json_login` and no matching POST
  fields, attempts stay keyed by IP only until you enable it.
- With `storage: cache` the bundle cannot read Symfony's limiter state: `ThrottleStatus::$tracked` is `false` and the
  figures are unknown.
- `doctrine/orm` and `doctrine/doctrine-bundle` are required even when every firewall uses `storage: cache`.
