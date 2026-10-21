<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Doctrine;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;

/**
 * Prepends `nowo_login_throttle.doctrine.table_prefix` to the tables of LoginThrottle entities only (REQ-ORM-002), and to their
 * explicitly named indexes / unique constraints so they stay unique on databases with schema-wide index names.
 * Registered only when the prefix is not empty.
 */
final readonly class TablePrefixListener
{
    private const ENTITY_NAMESPACE = 'Nowo\\LoginThrottleBundle\\Entity\\';

    public function __construct(
        private string $tablePrefix,
    ) {
    }

    public function loadClassMetadata(LoadClassMetadataEventArgs $event): void
    {
        $metadata = $event->getClassMetadata();
        if ('' === $this->tablePrefix || !\str_starts_with($metadata->getName(), self::ENTITY_NAMESPACE)) {
            return;
        }

        $table = ['name' => $this->tablePrefix . $metadata->getTableName()];
        foreach (['indexes', 'uniqueConstraints'] as $key) {
            $definitions = $metadata->table[$key] ?? [];
            if ([] === $definitions) {
                continue;
            }

            $renamed = [];
            foreach ($definitions as $name => $definition) {
                $renamed[\is_string($name) ? $this->tablePrefix . $name : $name] = $definition;
            }
            $table[$key] = $renamed;
        }

        $metadata->setPrimaryTable($table);
    }
}
