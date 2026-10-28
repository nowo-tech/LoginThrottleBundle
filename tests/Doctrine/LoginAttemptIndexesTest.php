<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Doctrine;

use Doctrine\ORM\Mapping\Index;
use Nowo\LoginThrottleBundle\Entity\LoginAttempt;
use PHPUnit\Framework\TestCase;

/**
 * The throttle lookups (LoginAttemptRepository) filter by username or IP plus a created_at window.
 */
final class LoginAttemptIndexesTest extends TestCase
{
    public function testThrottleLookupsHaveCompositeIndexes(): void
    {
        $indexes = [];
        foreach ((new \ReflectionClass(LoginAttempt::class))->getAttributes(Index::class) as $attribute) {
            $index = $attribute->newInstance();
            $indexes[$index->name] = $index->columns;
        }

        $this->assertSame([
            'idx_ip_username' => ['ip_address', 'username'],
            'idx_created_at' => ['created_at'],
            'idx_username_created_at' => ['username', 'created_at'],
            'idx_ip_created_at' => ['ip_address', 'created_at'],
        ], $indexes);
    }
}
