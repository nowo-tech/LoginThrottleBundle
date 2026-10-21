<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Nowo\LoginThrottleBundle\Doctrine\TablePrefixListener;
use Nowo\LoginThrottleBundle\Entity\LoginAttempt;
use PHPUnit\Framework\TestCase;

final class TablePrefixListenerTest extends TestCase
{
    public function testEmptyPrefixLeavesNamesUnchanged(): void
    {
        $metadata = $this->metadata(LoginAttempt::class);

        (new TablePrefixListener(''))->loadClassMetadata($this->event($metadata));

        $this->assertSame('login_attempts', $metadata->getTableName());
        $this->assertSame(['idx_ip_username', 'idx_created_at'], array_keys($metadata->table['indexes'] ?? []));
    }

    public function testHostEntitiesAreIgnored(): void
    {
        $metadata = $this->metadata(\stdClass::class);

        (new TablePrefixListener('app_'))->loadClassMetadata($this->event($metadata));

        $this->assertSame('login_attempts', $metadata->getTableName());
    }

    public function testBundleTableIndexesAndUniqueConstraintsArePrefixed(): void
    {
        $metadata = $this->metadata(LoginAttempt::class);
        $metadata->table['uniqueConstraints'] = ['uniq_x' => ['columns' => ['id']], 0 => ['columns' => ['ip_address']]];

        (new TablePrefixListener('app_'))->loadClassMetadata($this->event($metadata));

        $this->assertSame('app_login_attempts', $metadata->getTableName());
        $this->assertSame(['app_idx_ip_username', 'app_idx_created_at'], array_keys($metadata->table['indexes'] ?? []));
        $this->assertSame(['app_uniq_x', 0], array_keys($metadata->table['uniqueConstraints'] ?? []));
    }

    public function testEntitiesWithoutIndexesOnlyGetTheTablePrefix(): void
    {
        $metadata = new ClassMetadata(LoginAttempt::class);
        $metadata->setPrimaryTable(['name' => 'login_attempts']);

        (new TablePrefixListener('app_'))->loadClassMetadata($this->event($metadata));

        $this->assertSame('app_login_attempts', $metadata->getTableName());
        $this->assertArrayNotHasKey('indexes', $metadata->table);
    }

    /**
     * @param class-string $class
     *
     * @return ClassMetadata<object>
     */
    private function metadata(string $class): ClassMetadata
    {
        $metadata = new ClassMetadata($class);
        $metadata->setPrimaryTable([
            'name' => 'login_attempts',
            'indexes' => [
                'idx_ip_username' => ['columns' => ['ip_address', 'username']],
                'idx_created_at' => ['columns' => ['created_at']],
            ],
        ]);

        return $metadata;
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function event(ClassMetadata $metadata): LoadClassMetadataEventArgs
    {
        return new LoadClassMetadataEventArgs($metadata, $this->createStub(EntityManagerInterface::class));
    }
}
