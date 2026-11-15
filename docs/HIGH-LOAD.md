# High load

Since 3.15.0 (Beacon spec 239) the database limiter (`storage: database`) is built for many concurrent logins on
long-running workers: FrankenPHP worker mode with the kernel **not** reset between requests, RoadRunner, PHP-FPM pools.
This bundle is a brute-force control, so the rule is simple: **under concurrency an attempt is never under-counted**,
and the lockout starts at exactly the configured attempt.

`storage: cache` uses Symfony's own `login_throttling` limiter; configure its `lock_factory` for concurrent requests
(see [CONFIGURATION](CONFIGURATION.md)). The rest of this page is about `storage: database`.

## Table of contents

- [What the bundle writes](#what-the-bundle-writes)
- [One unit per attempt, one lock per IP address](#one-unit-per-attempt-one-lock-per-ip-address)
- [Races that no longer happen](#races-that-no-longer-happen)
- [Inside a transaction of your application](#inside-a-transaction-of-your-application)
- [When the store is unavailable: fail closed](#when-the-store-is-unavailable-fail-closed)
- [Your entity manager is yours](#your-entity-manager-is-yours)
- [Look-alike usernames and exact identifiers](#look-alike-usernames-and-exact-identifiers)
- [Cleanup](#cleanup)
- [Your own store](#your-own-store)
- [Database settings](#database-settings)
- [Workers](#workers)
- [Tests](#tests)

## What the bundle writes

One table, `login_attempts` (with your `doctrine.table_prefix`):

- every failed login that is not already refused **inserts one row** (IP address, normalised username or NULL, time);
- a successful login **clears** its IP + username bucket: the newest row of that bucket is deleted and the older ones
  lose their username (they keep counting for the IP address until they expire);
- `nowo:login-throttle:cleanup` deletes expired rows in batches.

The bundle has no HTTP route of its own, so it never answers 503 or 409 itself: a throttled login keeps Symfony's
`login_throttling` answer (`TooManyLoginAttemptsAuthenticationException`, your login page's error).

## One unit per attempt, one lock per IP address

`DatabaseRateLimiter::consume()` reads the counts of the attempt (IP + username, IP without a username, the per-IP
cap), records the attempt when no limit is reached yet, and reads the counts again. `reset()` (successful login) clears
the bucket. Since 3.15 each of those is **one unit** of `Transaction\AttemptLockRunner`, run through
`LoginAttemptLockInterface::withAttemptLock()`:

1. a **lock of the IP address** is taken first. Every bucket of an attempt belongs to one IP address, so one lock per IP
   address covers them all, and attempts of different IP addresses never wait for each other:

   | Server | Lock |
   |--------|------|
   | MySQL / MariaDB | `GET_LOCK('nowo_login_throttle_<sha1(table, IP)>', 10)`: a user lock of the session, taken before the transaction starts and always released in `finally`, also after a failure. If the release itself fails outside a transaction of your application, the connection is closed, so the server drops the lock instead of a worker keeping it. |
   | PostgreSQL | `pg_try_advisory_xact_lock(<64-bit key of (table, IP)>)`, polled (2 ms doubling up to 50 ms) for up to 10 s: held until the end of the transaction. The unit's own transaction is `READ COMMITTED` (set per transaction, whatever `default_transaction_isolation` says), so its reads see what the previous holder committed. No `lock_timeout` is set and no session setting changes, so a timeout never aborts a transaction of yours. |
   | SQLite | An empty `UPDATE` of the attempt table: the database write lock first, so the busy timeout queues the other writers (a plain read first would let two processes deadlock on the lock upgrade). SQLite serialises every attempt of the database, whatever the IP address. |
   | Other platforms | No lock (see [Your own store](#your-own-store) for what the limiter guarantees without one). |

2. **one transaction per unit**, opened and committed by nowo-tech/transaction-doctrine's DBAL runner
   (`DoctrineTransactionRunner::forConnection()`, implementing nowo-tech/transaction-contract). The unit's reads start
   after the lock, so even MySQL's `REPEATABLE READ` snapshot holds every attempt committed before. After any failure
   nothing of the unit is kept and no transaction of the bundle stays open; a transient failure (deadlock, lock wait
   timeout, serialization failure) becomes `Nowo\TransactionContract\TransientStorageFailure` (Retry-After hint: 1 s).

The events follow the unit: `LoginThrottledEvent` and `LoginAttemptsClearedEvent` are dispatched after it committed,
never inside it. A unit makes one attempt: it is never replayed (a login is not worth a second try at the database;
the client retries).

The lock is held for the few statements of one attempt (a handful of milliseconds). A brute-force burst from one IP
address therefore queues on its own lock: each attempt is decided after the previous one, which is exactly the point.

## Races that no longer happen

Measured with concurrent PHP processes on MySQL 8.4 and PostgreSQL 16 (`tests/Database`, see [Tests](#tests)). Every
row fails on 3.14.0 and passes on 3.15.0.

| Before 3.15 | Since 3.15 |
|-------------|------------|
| Parallel failed logins racing around the threshold could **all be refused**, attempts below the threshold included, and more rows than attempts up to the lockout were recorded (4 processes × 8 attempts of one username, `max_count_attempts: 5`: 7-8 rows instead of 5; without a username, bound 3: 0 attempts let through instead of 2; rotating usernames, cap 6: 4 instead of 5) | Exactly `max_count_attempts - 1` attempts reach the password check, the lockout starts at exactly the `max_count_attempts`-th, and exactly that many rows are recorded |
| The same for a client rotating usernames from one IP address (per-IP cap) and for requests without a username (`max_count_attempts_without_username`) | Exactly cap - 1 / bound - 1 let through |
| An attempt being decided (recorded, not counted yet) let the next attempt of its IP address count its row, and was then refused itself | The next attempt of the IP address waits for the lock |
| A successful login could clear the bucket in the middle of a failed attempt's decision: the failure's row was deleted and the failure read a count of 0, **one attempt let through past the threshold** | The reset waits for the decision: the failure is decided with its own row counted, then the bucket is cleared |
| **Under-count:** an attempt recorded inside a transaction your application left open was invisible to the next attempt until your commit, so both were let through (**one attempt past the threshold per open transaction**) | The next attempt waits for that commit, then counts it: always on PostgreSQL, on MySQL / MariaDB when the next attempt runs inside a transaction too (see the next section) |
| **Under-count (MySQL / MariaDB):** inside a transaction of your application that had already read something, the counts came from its `REPEATABLE READ` snapshot, which hid every attempt committed since, so the attempt was let through past the threshold | Locking reads (`LOCK IN SHARE MODE`) inside your transaction: they read past the snapshot |

Outside a transaction of your application, 3.14.0 never let an attempt through past the threshold: the counts were
read back after the insert, so an attempt always counted itself and every attempt committed before it. The races
there over-refused (fail closed). The two rows marked **Under-count** are the ones that let extra attempts through.

## Inside a transaction of your application

The bundle does not open a second connection. When your code (a listener, a transaction-per-request middleware, a
transaction a previous request of the worker left open, `auto_commit: false`) has a transaction open on the bundle's
connection when the login runs, the unit runs **inside your transaction** (the attempt keeps its own savepoint, as
before 3.15, so a failed insert never aborts your transaction, and a DBAL 3 host without savepoints never sees its
transaction marked rollback-only):

- **PostgreSQL:** the advisory lock lasts until **your** commit or rollback. Every other attempt of the same IP address
  waits for it, then counts the attempt (or not, if you rolled back). The unit reads with your transaction's isolation:
  under `READ COMMITTED` (PostgreSQL's default) it sees every committed attempt; a `REPEATABLE READ` or `SERIALIZABLE`
  transaction of yours that had already read something hides the attempts committed since (do not authenticate inside
  one).
- **MySQL / MariaDB:** the user lock is released after the unit, and the unit's reads are **locking reads**: they see
  every attempt committed since your snapshot, and they wait for an attempt that another open transaction has recorded
  but not committed yet. An attempt decided **outside** any transaction of yours does not wait for such an uncommitted
  attempt (it cannot see it).
- Your transaction decides: an attempt is durable once **you commit**, and **a rollback discards it**. An application
  that rolls back the transaction around a failed login (or never commits it) does not count its failed logins.

Recommendation: do not wrap the login in a transaction. If you must (a transaction-per-request middleware), commit
it on authentication failures too.

## When the store is unavailable: fail closed

| Failure | Result |
|---------|--------|
| The database is down, a statement fails | The DBAL exception propagates out of `consume()`: the request ends in an error (500) **before the password is checked**. No credential is verified while the limiter cannot count. |
| The lock of the IP address is not granted within 10 s, a deadlock, a lock wait timeout | `TransientStorageFailure` (Retry-After 1 s) out of `consume()`: the same, before the password check. Nothing of the attempt is kept. |
| The same during `reset()` (after a **successful** login) | The exception propagates out of Symfony's `LoginSuccessEvent`; the password was right, so this is not a brute-force path. The bucket keeps its rows (the user may meet the lockout sooner). |

This is the behaviour of every earlier version (the exception class of a transient failure is new: it was the DBAL
exception, which stays its `previous`). The bundle never turns a storage failure into an accepted attempt. Map
`TransientStorageFailure` to a 503 in your own error handling if you want a softer page.

## Your entity manager is yours

Every statement of the limiter is DBAL on the connection of the entity manager that maps `LoginAttempt` (your default
one unless you give the bundle its own). The bundle never `persist()`s, `flush()`es, `clear()`s, closes or resets your
manager, in the middle of a request or after it: a failed unit rolls back only its own statements. A **dedicated**
manager (one that maps the bundle's entities alone) that something else closed is replaced through the registry before
it is used, as since 3.10.

## Look-alike usernames and exact identifiers

Unchanged by 3.15, and covered by the same lock (one per IP address):

- the limiter's username is normalised (trimmed, lower-cased; `UsernameNormalizer::forLimiter()`), and the IP + username
  bucket counts the rows whose username has the same folded skeleton (case, accents, compatibility forms, NFC), so
  `bób`, `ｂob` or `BOB` never get a fresh budget against `bob`;
- clearing a bucket after a successful login compares IP and username byte for byte (`===`) after the read, and no read
  that decides a count or a deletion has a `LIMIT` before that comparison;
- the columns use a binary **NO PAD** collation on MySQL 8 (`utf8mb4_0900_bin`) and MariaDB (`utf8mb4_nopad_bin`), so a
  trailing space never folds two identifiers together (see [UPGRADING 3.11.0](UPGRADING.md#to-3110)).

## Cleanup

`nowo:login-throttle:cleanup` never deletes a row a limiter still counts: since 3.15 its age threshold is at least the
longest `timeout` of your database firewalls. A `--watch-period` (or a `watch_period`) below it used to clear lockouts in
progress (every bucket of every IP address younger than the threshold lost its rows at once); the command now keeps
those rows and says so. Rows older than every `timeout` count for nobody, so deleting them races with nothing.
`LoginAttemptRepository::cleanup($seconds)` called from your own code deletes what it is told: pass at least the
longest `timeout`.

## Your own store

A store you alias to `LoginAttemptStoreInterface` keeps the limiter's behaviour without a lock: it reads the counts back
after recording, so an attempt always counts itself and everything its store shows it, and attempts racing around the
threshold may all be refused (never let through, as long as the store's counts see every write committed before
them). Implement `Nowo\LoginThrottleBundle\Repository\LoginAttemptLockInterface` to serialise the units of one IP
address: `withAttemptLock(string $ipAddress, callable $unit)` runs `$unit($store)` while no other unit of that IP
address runs, and the limiter then reads and writes through the `$store` it is handed (a Redis store would use a lock
key per IP; see `tests/Support/LockingDelegateStore.php`).

## Database settings

- **Lock waits:** a unit waits up to 10 s for the lock of its IP address, then fails closed. Inside a transaction of
  yours on MySQL, a locking read may also wait for another open transaction up to `innodb_lock_wait_timeout` (50 s by
  default): keep it below your request timeout (5-10 s for web workers).
- **DBAL 3:** nothing to set. The unit's own transaction is a top-level one; inside your transaction the attempt uses an
  explicit savepoint, whatever `use_savepoints` says.
- **Connection poolers:** PgBouncer in transaction pooling mode is fine (the PostgreSQL lock is transaction-scoped). On
  MySQL the user lock lives in the session for the few statements of a unit: ProxySQL keeps the session's backend while
  a user lock is held. Statement pooling is not supported.
- **Replicas:** the limiter must read the primary (a lagging replica under-counts). Do not route the bundle's connection
  to a read replica.

## Workers

The runner, the repository and the limiter hold no state between requests: the lock is released (MySQL) or ended with
the transaction (PostgreSQL) in every unit, the locking-read store is a copy made for one unit, and the transaction is
closed before the unit returns, also after a failure. `tests/Integration/WorkerModeKeptAliveKernelTest.php` runs
1,200 logins through one kept-alive kernel. `composer igor` and PhpStanFrankenPhp's worker rules (with the
load-readiness flags of 1.4) run in CI.

## Tests

- `tests/Transaction/AttemptLockRunnerTest.php`: every platform branch of the runner (lock order, release after a
  failure, lock not granted, release failure).
- `tests/Repository/AttemptLockTest.php`, `tests/RateLimiter/DatabaseRateLimiterLockTest.php`: units on SQLite, inside a
  transaction of the host, and the limiter deciding through the store a unit receives.
- `tests/Database/LoadReadinessTest.php`: concurrent PHP processes (`tests/Database/Support/attempt-worker.php`), each on
  its own connection, on a real MySQL / MariaDB or PostgreSQL server: parallel bursts (username, per-IP cap, without a
  username), an attempt paused after its insert, an attempt inside an open host transaction, a host snapshot, a reset
  during a decision, a lock not granted. Skipped without `LOGIN_THROTTLE_TEST_DATABASE_URL`. The race tests use only
  API that existed in 3.14.0: against 3.14.0, 7 of them fail on MySQL 8.4 and 6 on PostgreSQL 16 (the snapshot test
  passes there: PostgreSQL's `READ COMMITTED` never hid committed rows).
- CI job **Load readiness**: MySQL 8.4 and PostgreSQL 16, DBAL 4 and DBAL 3, `--fail-on-skipped`, plus the whole suite on
  that DBAL.

```bash
LOGIN_THROTTLE_TEST_DATABASE_URL='mysql://root@127.0.0.1:3306/login_throttle?serverVersion=8.4.0&charset=utf8mb4' \
  vendor/bin/phpunit --fail-on-skipped tests/Database
```
