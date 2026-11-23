<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Doctrine;

use Nowo\LoginThrottleBundle\Doctrine\LoginThrottleExactKeyColumns;
use Nowo\MigrationsKitContracts\Collation\ExactKeyColumn;
use PHPUnit\Framework\TestCase;

/**
 * REQ-SEC-006: the declaration names both key columns of the attempts table, on the *configured* table name, and
 * declares them inactive (never omits them) when the entity is not mapped.
 */
final class LoginThrottleExactKeyColumnsTest extends TestCase
{
    public function testItDeclaresBothKeyColumnsOfTheUnprefixedTable(): void
    {
        $columns = (new LoginThrottleExactKeyColumns())->exactKeyColumns();

        self::assertSame(
            ['login_attempts.username', 'login_attempts.ip_address'],
            array_map(static fn (ExactKeyColumn $column): string => $column->describe(), $columns),
        );
        foreach ($columns as $column) {
            self::assertTrue($column->active);
            self::assertNull($column->condition);
            self::assertSame(LoginThrottleExactKeyColumns::OWNER, $column->owner);
            self::assertNotSame('', $column->purpose);
        }
    }

    public function testItResolvesTheConfiguredTablePrefix(): void
    {
        $columns = (new LoginThrottleExactKeyColumns('app_'))->exactKeyColumns();

        self::assertSame(
            ['app_login_attempts.username', 'app_login_attempts.ip_address'],
            array_map(static fn (ExactKeyColumn $column): string => $column->describe(), $columns),
        );
    }

    public function testAnUnmappedEntityIsDeclaredInactiveWithItsCondition(): void
    {
        $columns = (new LoginThrottleExactKeyColumns('app_', false))->exactKeyColumns();

        self::assertCount(2, $columns);
        foreach ($columns as $column) {
            self::assertFalse($column->active, $column->describe());
            self::assertSame(LoginThrottleExactKeyColumns::MAPPED_CONDITION, $column->condition);
        }
    }

    public function testItIsSafeToCallMoreThanOnce(): void
    {
        // REQ-RUNTIME-002: nothing is memoised, so a worker that keeps the service gets the same answer every time.
        $declaration = new LoginThrottleExactKeyColumns('app_');

        self::assertEquals($declaration->exactKeyColumns(), $declaration->exactKeyColumns());
    }
}
