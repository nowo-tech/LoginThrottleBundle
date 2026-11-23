<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Doctrine;

use Nowo\MigrationsKitContracts\Collation\ExactKeyColumn;
use Nowo\MigrationsKitContracts\Collation\ExactKeyColumnsInterface;

/**
 * The bundle's own declaration of the identity / access-key columns of its table (REQ-SEC-006).
 *
 * The two columns of the attempts table are the throttle's access keys: the limiter counts the rows of *one* account
 * and of *one* address with `=`, and blocks on that count. On a folding collation (`utf8mb4_0900_ai_ci`, the MySQL 8+
 * default a column with no `COLLATE` inherits) `alice`, `Alice` and `alicé` are the same bucket, so an attacker locks
 * a real account out by hammering a look-alike of it; on a PAD SPACE one `alice` and `alice ` are too. They are
 * therefore kept on the binary NO PAD collation of the server, which {@see ExactCollationListener} applies to the
 * generated schema — and this declaration is what makes a host (and this bundle's own CI) *assert* it, so a column
 * that never got the `ALTER` of UPGRADING, or a new key column nobody collated, is reported instead of trusted.
 *
 * `nowo:migrations:check-key-collations` discovers it with no registration by the host: MigrationsKit tags every
 * service implementing {@see ExactKeyColumnsInterface} by autoconfiguration. A host that excused these columns by
 * hand in `nowo_migrations_kit.collation_check.ignore_columns` can delete those entries: a declared column is exempt
 * from the uniform collation gate by construction.
 *
 * The table name is resolved the way the schema resolves it (the configured `doctrine.table_prefix` on the entity's
 * own table name), never a literal, so a host with a prefix is not reported a failure on a table it renamed. The
 * columns are declared *inactive* instead of omitted when the entity is not mapped
 * (`doctrine.register_mapping: false`, or `auto` with no firewall on `storage: database`): there is then no table,
 * and the check reports them as skipped with that condition rather than as a missing table.
 *
 * Stateless and database-free (REQ-RUNTIME-002): the list is built from the configuration on every call, so the
 * service is safe to keep between requests in a FrankenPHP worker.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class LoginThrottleExactKeyColumns implements ExactKeyColumnsInterface
{
    /**
     * The package that owns the table, as reported by the aggregate run.
     */
    public const OWNER = 'nowo-tech/login-throttle-bundle';

    /**
     * The entity's own table name, before the configured prefix.
     */
    public const TABLE = 'login_attempts';

    /**
     * The condition reported for a column of an unmapped entity.
     */
    public const MAPPED_CONDITION = 'nowo_login_throttle.doctrine.register_mapping: the LoginAttempt entity is mapped';

    /**
     * @param string $tablePrefix    nowo_login_throttle.doctrine.table_prefix
     * @param bool   $mappingEnabled resolved nowo_login_throttle.doctrine.register_mapping (the entity is mapped)
     */
    public function __construct(
        private string $tablePrefix = '',
        private bool $mappingEnabled = true,
    ) {
    }

    public function exactKeyColumns(): array
    {
        $table = $this->tablePrefix . self::TABLE;
        $condition = $this->mappingEnabled ? null : self::MAPPED_CONDITION;

        return [
            new ExactKeyColumn(
                $table,
                'username',
                self::OWNER,
                'throttle bucket of one account: a folding collation lets a look-alike username lock the real one out',
                $condition,
                $this->mappingEnabled,
            ),
            new ExactKeyColumn(
                $table,
                'ip_address',
                self::OWNER,
                'throttle bucket of one address: the per-IP cap is counted on this value with =',
                $condition,
                $this->mappingEnabled,
            ),
        ];
    }
}
