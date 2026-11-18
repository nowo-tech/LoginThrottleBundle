# Concurrency

REQ-RUNTIME-003. How the login throttle writes under contention, what it answers when a lock is lost, and what stays
yours. The mechanics of the lock are in [HIGH-LOAD](HIGH-LOAD.md).

## Table of contents

- [Write paths](#write-paths)
- [Codes](#codes)
- [The answer on the login](#the-answer-on-the-login)
- [Host options](#host-options)
- [Residual risks](#residual-risks)
- [Tests](#tests)

## Write paths

Only `storage: database` writes. Every write is DBAL on the connection of the entity manager that maps `LoginAttempt`
(never `persist()` / `flush()` / `clear()` / close / reset of an entity manager), safe in FrankenPHP worker mode with the
kernel not reset, in standard and DDD hosts alike.

| Path | Transaction | Control | Conflict answer |
|------|-------------|---------|-----------------|
| Failed login: count, insert one row, count again (`DatabaseRateLimiter::consume()`) | One unit, `READ COMMITTED`, opened by transaction-doctrine's DBAL runner; in a transaction the host left open: an explicit savepoint of its own | Per-IP lock (`GET_LOCK` / advisory transaction lock / SQLite write lock) taken first; locking reads on MySQL / MariaDB | 409 + `Retry-After` + `CONFLICT_001` |
| Successful login: clear the bucket (`reset()`) | One unit, same lock | Same | 409 + `Retry-After` + `CONFLICT_001`; the bundle ends that login first (token cleared, session invalidated, no remember-me cookie), so the next request is anonymous |
| `nowo:login-throttle:cleanup` (expired rows, in batches) | One short transaction per batch | Batched deletes by id; no lock, a lost race deletes nothing twice | Console error, run it again |
| An attempt a limit already refuses | None (plain reads) | Refusing is the safe direction, nothing is recorded | Not a conflict |

Not conflicts, and not mapped to 409: a unique-constraint violation (the table has none that the bundle can hit) and a
lost connection. They stay real errors.

## Codes

| Code | Meaning | `Retry-After` |
|------|---------|---------------|
| `CONFLICT_001` | Nothing was committed, retrying is safe | Yes (1 s) |
| `CONFLICT_002` | Part of the request may be committed; read again | No |

A login attempt never commits something the client could repeat by mistake: an attempt either records one row and
decides, or keeps nothing. So the bundle answers `CONFLICT_001` only (`LoginThrottleStorageFailure::conflictCode()`).

## The answer on the login

When a `LoginThrottleStorageFailure` leaves the limiter on a firewall listed in `nowo_login_throttle`, the bundle
answers (`EventSubscriber\StorageFailureSubscriber`, `kernel.exception`, priority -64):

- **409** with `Retry-After: 1`, `X-Conflict-Code: CONFLICT_001` (also at 503), `Cache-Control: no-store, private` and `CONFLICT_001`: JSON
  `{"error": "concurrency_conflict", "code": "CONFLICT_001", "message": "..."}` for JSON, XHR and `/api/` requests, else
  the platform's "try again" page with `data-conflict-code` (nowo-tech/transaction-doctrine's responder).
- **Fails closed.** The limiter raises before the password check, so no credential is verified while it cannot count,
  and the response is never a 2xx or a redirect. The failure is a refusal, never an allow.
- **Not an oracle.** The answer is built from a fresh failure with a fixed message: the same status, headers and body
  whether the account exists, is unknown, is locked, has a wrong or the right password, or no username was sent.
  Nothing of the request, the username, the IP address or the storage error reaches the response or the responder's log.

- **Only logins.** Only a failure the limiter raised while authenticating (it marks the request) is answered. A
  `LoginThrottleStorageFailure` that code of yours raises through the store's public API on a guarded firewall, a failure
  on a route outside the guarded firewalls and every other exception are left to your application. The plain reads that
  refuse an attempt are covered like the locked unit.
- **A conflict after a right password ends the login.** Symfony has already stored the token when the reset runs, so the
  bundle clears the token storage, invalidates the session and drops the remember-me cookie before it answers: the next
  request is anonymous (kernel test `ConflictAnswerKernelTest`).

### Deliberate exemption from the request-level code (rule 6)

transaction-doctrine 1.6 answers `CONFLICT_002` when the request already committed something (a commit mark on the
request). This bundle does **not** follow that detection: the responder gets a copy of the request without the mark, a
fresh failure, and the code and header are fixed to `CONFLICT_001`. Reason: a host listener that commits only for existing
accounts (a "last seen" write, an audit row) would otherwise turn the code, the `_partial` error key and the
`Retry-After` into an account-existence oracle on the login. The attempt itself never commits anything a client could
repeat, so `CONFLICT_001` is true for what the bundle did. The commit of host code on the same request is the host's to
report. Needs owner OK as an exemption of REQ-RUNTIME-003 rule 6.

## Host options

```yaml
nowo_login_throttle:
    conflict_response:
        mode: bundle   # bundle (default): the answer above. host: nothing registered, you map the exception.
        status: ~      # 409 or 503; null follows nowo_transaction_doctrine.conflict_response.status, else 409
```

- **Leave it to the host:** `mode: host`. The exception is a `Nowo\TransactionContract\TransientStorageFailure`
  (`LoginThrottleStorageFailure`, with `retryAfterSeconds()` and the storage error as `previous`, for the logs only,
  never for the client). Unmapped, it ends as a 500 on the login path.
- **Your own page:** a `kernel.exception` listener at a priority above -64 sets its response first; the bundle's
  subscriber skips an event that already has one. Keep the status, `Retry-After` and the same body for every account.
- **503:** `status: 503`.
- **NowoTransactionDoctrineBundle:** with no `status` set, its `conflict_response` template, translations, JSON error key,
  path prefixes and formatters apply to this answer. With `status` set, the bundle builds its own responder: the
  template, `json_error` and `json_path_prefixes` of that bundle are not used (defaults), only its translations and Twig.

## Residual risks

- **A conflict after a successful password.** The reset can lose the lock too; the answer is the same 409 and the
  login is ended, so a visitor who provoked contention on their own IP address could still tell "password right" from
  "password wrong" for that one request only through timing or retries, never through a session. The right attempt is
  recorded. Keep `attempt_lock_timeout` short.
- **A lock flood.** Many attempts of one IP address queue on its lock for up to `attempt_lock_timeout`; the ones that a
  limit already refuses never take it. A distributed attack from many addresses takes one lock each.
- **MySQL / MariaDB `REPEATABLE READ` host transaction around the login:** gap locks may make neighbouring IP addresses
  wait for the host's commit ([HIGH-LOAD, Known issues](HIGH-LOAD.md#known-issues)). Do not wrap the login.
- **Other platforms** have no lock: the limiter reads the counts back after recording, so the lockout may start one
  attempt late, never earlier or never.
- **A host that decorates the store** must keep raising `TransientStorageFailure` for conflicts (it is converted to the
  bundle's type at the runner, which a decorating store may bypass).

## Tests

`tests/Transaction/AttemptLockRunnerTest.php` and `LoginThrottleStorageFailureTest.php` (conversion, code, generic
message), `tests/EventSubscriber/StorageFailureSubscriberTest.php` (409, headers, `CONFLICT_001`, identical answers for
every account, never a success, guarded firewalls only, host answer wins), `tests/DependencyInjection/ConflictResponsePassTest.php`
(modes, status), `tests/RateLimiter/DatabaseRateLimiterLockTest.php` (a conflict is never an allow),
`tests/Database/LoadReadinessTest.php` (two-process races and lock timeouts on MySQL 8.4, MariaDB 11.4, PostgreSQL 16,
DBAL 3 and 4).
