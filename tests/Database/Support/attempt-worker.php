<?php

declare(strict_types=1);

/**
 * A second (third, …) login request on its own connection, for the load-readiness tests.
 *
 * php attempt-worker.php <database url> <command> <ip> <username|-> <max attempts> <per-IP cap> [arguments]
 *
 * - `burst <count> <without-username bound|->`: prints READY, waits for a line, then consumes `<count>` attempts
 *   (a username ending in `*` gets a unique suffix per attempt) and prints `accepted` / `refused` per attempt;
 * - `paused <clock call>`: consumes one attempt on a repository clock that prints PAUSED on its `<clock call>`-th
 *   reading and waits for a line before it answers (the reads after the attempt's insert start at call 3);
 * - `host <hold|commit>`: opens a transaction of the "host", writes a host row, consumes one attempt and prints the
 *   result; `hold` then prints HELD and waits for a line before it commits;
 * - `snapshot`: opens a transaction of the "host", reads the attempt table (the REPEATABLE READ snapshot starts
 *   there), prints READY, waits for a line, consumes one attempt, prints the result and commits;
 * - `reset`: prints READY, waits for a line, resets the bucket (successful login), prints `reset`.
 *
 * Every failure prints its class. The process ends with DONE.
 */

use Nowo\LoginThrottleBundle\Tests\Database\Support\AttemptDatabase;
use Psr\Clock\ClockInterface;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[, $url, $command, $ip, $username, $max, $cap] = array_pad($argv, 7, '');
$arguments = array_slice($argv, 7);
$username = '-' === $username ? null : $username;

$say = static function (string $line): void {
    fwrite(\STDOUT, $line . "\n");
    fflush(\STDOUT);
};
$wait = static function (): void {
    fgets(\STDIN);
};

$clock = null;
if ('paused' === $command) {
    $clock = new class((int) ($arguments[0] ?? 3), $say, $wait) implements ClockInterface {
        private int $calls = 0;

        public function __construct(private readonly int $pauseAt, private readonly Closure $say, private readonly Closure $wait)
        {
        }

        public function now(): DateTimeImmutable
        {
            if (++$this->calls === $this->pauseAt) {
                ($this->say)('PAUSED');
                ($this->wait)();
            }

            return new DateTimeImmutable();
        }
    };
}

$entityManager = AttemptDatabase::entityManager($url);
$connection = $entityManager->getConnection();
$repository = AttemptDatabase::repository($entityManager, $clock);
$withoutUsername = isset($arguments[1]) && '-' !== $arguments[1] ? (int) $arguments[1] : null;
$limiter = AttemptDatabase::limiter($repository, (int) $max, (int) $cap, $withoutUsername);
$consume = static fn (?string $name): string => $limiter->consume(AttemptDatabase::request($ip, $name))->isAccepted() ? 'accepted' : 'refused';

try {
    switch ($command) {
        case 'burst':
            $say('READY');
            $wait();
            for ($i = 0, $count = (int) ($arguments[0] ?? 1); $i < $count; ++$i) {
                $name = null !== $username && str_ends_with($username, '*') ? substr($username, 0, -1) . getmypid() . '-' . $i : $username;
                $say($consume($name));
            }
            break;
        case 'paused':
            $say($consume($username));
            break;
        case 'host':
            $connection->beginTransaction();
            $connection->insert('host_load_note', ['id' => getmypid()]);
            $say($consume($username));
            if ('hold' === ($arguments[0] ?? 'commit')) {
                $say('HELD');
                $wait();
            }
            $connection->commit();
            break;
        case 'snapshot':
            $connection->beginTransaction();
            $connection->fetchOne('SELECT COUNT(*) FROM login_attempts');
            $say('READY');
            $wait();
            $say($consume($username));
            $connection->commit();
            break;
        case 'reset':
            $say('READY');
            $wait();
            $limiter->reset(AttemptDatabase::request($ip, $username));
            $say('reset');
            break;
        default:
            $say('unknown command ' . $command);
    }
} catch (Throwable $failure) {
    $say($failure::class);
}
$say('DONE');
