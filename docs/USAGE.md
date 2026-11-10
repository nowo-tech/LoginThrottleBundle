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
- With `storage: database`, treat `login_attempts` as infrastructure (like a cache table). Prune it with
  `nowo:login-throttle:cleanup` and use `doctrine.table_prefix` to keep it apart from your tables.

**Ports and events to use**

| Need | Use |
| --- | --- |
| Your own throttling store or rules | Implement `Symfony\Component\HttpFoundation\RateLimiter\RequestRateLimiterInterface` and set `rate_limiter: <service id>` (or `firewalls.<name>.rate_limiter`). The bundle then registers no database limiter for that firewall. |
| React to a lockout (audit, notification) | Listen to Symfony's `LoginFailureEvent` and check `getException() instanceof TooManyLoginAttemptsAuthenticationException`. The bundle dispatches no events of its own. |
| Show remaining attempts on the login page | `LoginThrottleStatusProviderInterface::status(string $firewall, string $ipAddress, ?string $username = null): ThrottleStatus` (since 3.9; HTTP-free, typed, same rules as the database limiter: normalised username, IP + username bucket, per-IP cap). `LoginThrottleInfoService::getAttemptInfo()` returns the same figures as an array. |
| Read the attempt store without the Doctrine entity | `LoginAttemptViewRepositoryInterface` (since 3.9, implemented by `LoginAttemptRepository`): `getAttemptViews()` returns readonly `LoginAttemptView` DTOs, `findOldestAttemptAt()` a `DateTimeImmutable`. |
| `json_login` / custom authenticators with `storage: database` | Set `username_from_passport: true` (since 3.9): without `_username` / `username` / `email` POST fields, attempts are keyed on the identifier the visitor submitted (the passport `UserBadge` identifier, never the resolved user), like `form_login`. |
| Testable time | Register a `Psr\Clock\ClockInterface` service. The database limiter and the repository use it when present. |

Example adapter that turns a lockout into an application command:

```php
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

#[AsEventListener]
final class LoginLockoutListener
{
    public function __construct(private readonly RecordLoginLockout $recordLoginLockout) {} // your application port

    public function __invoke(LoginFailureEvent $event): void
    {
        if (!$event->getException() instanceof TooManyLoginAttemptsAuthenticationException) {
            return;
        }

        ($this->recordLoginLockout)(
            $event->getFirewallName(),
            $event->getRequest()->getClientIp() ?? 'unknown',
        );
    }
}
```

**Known limitations**

- The database limiter is wired to the concrete `LoginAttemptRepository`. Overriding
  `LoginAttemptRepositoryInterface` changes only `LoginThrottleInfoService` and the cleanup command, not the limiter,
  and `storage: database` requires `register_mapping` `true` or `auto`. To use your own persistence, provide your own
  `rate_limiter` instead.
- `LoginAttemptRepositoryInterface` still declares `recordAttempt(): LoginAttempt` and `getAttempts(): list<LoginAttempt>`
  (kept for BC); the bundle itself reads through `LoginAttemptViewRepositoryInterface` when your store implements it.
- `username_from_passport` is `false` by default (3.x keeps today's keys): with `json_login` and no matching POST
  fields, attempts stay keyed by IP only until you enable it.
- With `storage: cache` the bundle cannot read Symfony's limiter state: `ThrottleStatus::$tracked` is `false` and the
  figures are unknown.
- `doctrine/orm` and `doctrine/doctrine-bundle` are required even when every firewall uses `storage: cache`.
