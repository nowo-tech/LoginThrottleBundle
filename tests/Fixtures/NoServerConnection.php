<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Fixtures;

use Doctrine\DBAL\Connection;

/**
 * A connection that never asks the server for its current database: DBAL 3's SchemaTool reads it with a
 * `SELECT DATABASE()` (through the schema manager's search paths), which connects, while DBAL 4 does not. The platform
 * comes from the `serverVersion` parameter, so a schema can be generated for a MySQL / MariaDB platform without one.
 */
final class NoServerConnection extends Connection
{
    public function getDatabase(): string
    {
        return 'collation_test';
    }
}
