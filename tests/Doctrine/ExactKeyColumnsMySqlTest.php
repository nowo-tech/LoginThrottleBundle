<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Doctrine;

use PHPUnit\Framework\Attributes\Group;

/**
 * The REQ-SEC-006 gate on MySQL (8.4 in CI): the declared key columns must be `utf8mb4_0900_bin`.
 */
#[Group('database')]
final class ExactKeyColumnsMySqlTest extends ExactKeyColumnsDatabaseTestCase
{
    protected function databaseUrlVariable(): string
    {
        return 'LOGIN_THROTTLE_TEST_MYSQL_URL';
    }
}
