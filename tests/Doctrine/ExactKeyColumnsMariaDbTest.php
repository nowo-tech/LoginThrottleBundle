<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Doctrine;

use PHPUnit\Framework\Attributes\Group;

/**
 * The REQ-SEC-006 gate on MariaDB (11.4 in CI): the declared key columns must be `utf8mb4_nopad_bin`.
 */
#[Group('database')]
final class ExactKeyColumnsMariaDbTest extends ExactKeyColumnsDatabaseTestCase
{
    protected function databaseUrlVariable(): string
    {
        return 'LOGIN_THROTTLE_TEST_MARIADB_URL';
    }
}
