<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\MySQL84Platform;
use Doctrine\DBAL\Schema\ColumnEditor;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\LoginThrottleBundle\Entity\LoginAttempt;
use Psr\Log\LoggerInterface;

/**
 * Byte-exact collation for the key columns (`username`, `ip_address`) of the login attempts table on MySQL / MariaDB
 * (REQ-SEC-006, 3.10.2).
 *
 * The default MySQL / MariaDB collations fold case and accents (`Alice` = `alice` = `alicé`) and the PAD SPACE ones
 * also ignore trailing spaces (`alice ` = `alice`). The repository re-checks every row with `===`; the binary NO PAD
 * collation keeps the database itself exact: MariaDB `utf8mb4_nopad_bin`, MySQL 8.0+ `utf8mb4_0900_bin`, and
 * `utf8mb4_bin` (PAD SPACE, the re-check covers it) only for MySQL 5.7 or a `server_version` without the patch number,
 * with a warning. PostgreSQL and SQLite are untouched (they reject MySQL collation names, so it cannot live in the
 * mapping). Applied to the generated schema (schema tool, `doctrine:migrations:diff`); existing tables need the
 * `ALTER` of UPGRADING.
 *
 * Stateless (REQ-RUNTIME-002): the platform is read from the registry on each event.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
final readonly class ExactCollationListener
{
    /** Key columns of the login attempts table. */
    public const COLUMNS = ['username', 'ip_address'];

    public function __construct(
        private ManagerRegistry $doctrine,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * The exact-match collation on that platform, null when its default is already exact.
     *
     * @return non-empty-string|null
     */
    public static function collationFor(AbstractPlatform $platform): ?string
    {
        return match (true) {
            $platform instanceof MariaDBPlatform => 'utf8mb4_nopad_bin',
            $platform instanceof MySQL80Platform => 'utf8mb4_0900_bin',
            $platform instanceof AbstractMySQLPlatform => 'utf8mb4_bin',
            default => null,
        };
    }

    /**
     * The exact-match collation for that connection: as {@see collationFor()}, except that MySQL 8.0.0 to 8.0.16 (which
     * DBAL also maps to the 8.0 platform) lack `utf8mb4_0900_bin` and get `utf8mb4_bin`. The server version is read
     * only for the 8.0 platform; when it cannot be read, the platform decides.
     *
     * @return non-empty-string|null
     */
    public static function collationForConnection(Connection $connection): ?string
    {
        $platform = $connection->getDatabasePlatform();
        $collation = self::collationFor($platform);
        if ('utf8mb4_0900_bin' !== $collation || $platform instanceof MySQL84Platform) {
            return $collation;
        }

        try {
            $version = self::serverVersion($connection);
        } catch (\Throwable) {
            return $collation;
        }

        return null !== $version && 1 === preg_match('/^\d+\.\d+\.\d+/', $version) && version_compare($version, '8.0.17', '<') ? 'utf8mb4_bin' : $collation;
    }

    /**
     * The server version: `Connection::getServerVersion()` on DBAL 4 (private on DBAL 3), else the native PDO connection;
     * null when neither tells (the platform then decides).
     *
     * @internal public for tests
     */
    public static function serverVersion(object $connection): ?string
    {
        $version = null;
        $public = [$connection, 'getServerVersion'];
        $native = [$connection, 'getNativeConnection'];
        if (\is_callable($public)) {
            $version = $public();
        } elseif (\is_callable($native)) {
            $handle = $native();
            $version = $handle instanceof \PDO ? $handle->getAttribute(\PDO::ATTR_SERVER_VERSION) : null;
        }

        return \is_string($version) ? $version : null;
    }

    public function postGenerateSchemaTable(GenerateSchemaTableEventArgs $args): void
    {
        $class = $args->getClassMetadata()->getName();
        if (LoginAttempt::class !== $class) {
            return;
        }

        $manager = $this->doctrine->getManagerForClass($class);
        $collation = $manager instanceof EntityManagerInterface ? self::collationForConnection($manager->getConnection()) : null;
        if (null === $collation) {
            return;
        }

        if ('utf8mb4_bin' === $collation) {
            $this->logger?->warning('LoginThrottle: utf8mb4_bin (PAD SPACE) used for the login attempt key columns. MySQL 5.7 or 8.0.0-8.0.16, or a server_version without the patch number: on MySQL 8.0.17+ set the full server_version (e.g. "8.4.3") so utf8mb4_0900_bin (NO PAD) is chosen.');
        }

        $table = $args->getClassTable();
        foreach (self::COLUMNS as $column) {
            // the editor path replaces the table, which GenerateSchemaTableEventArgs::setClassTable() accepts only when
            // Schema::edit() exists too (DBAL 4.3 has Table::edit() but not Schema::edit())
            // @phpstan-ignore function.alreadyNarrowedType, function.alreadyNarrowedType
            $edited = self::collate($table, $column, $collation, method_exists(Table::class, 'edit') && method_exists(Schema::class, 'edit'));
            if ($edited instanceof Table) {
                $table = $edited;
            }
        }
        if ($table !== $args->getClassTable()) {
            $args->setClassTable($table);
        }
    }

    /**
     * Gives `$column` the utf8mb4 charset and `$collation`: through a table editor (DBAL 4.5+; returns the new table) or
     * in place (older DBAL; returns null).
     *
     * @param non-empty-string $column
     * @param non-empty-string $collation
     */
    public static function collate(Table $table, string $column, string $collation, bool $editor): ?Table
    {
        if ($editor) {
            return $table->edit()->modifyColumnByUnquotedName($column, static function (ColumnEditor $columnEditor) use ($collation): void {
                $columnEditor->setCharset('utf8mb4')->setCollation($collation);
            })->create();
        }

        $table->getColumn($column)
            ->setPlatformOption('charset', 'utf8mb4')
            ->setPlatformOption('collation', $collation);

        return null;
    }
}
