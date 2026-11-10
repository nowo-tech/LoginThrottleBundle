<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Fixtures\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A host application entity sharing the default EntityManager with the bundle's LoginAttempt.
 */
#[ORM\Entity]
#[ORM\Table(name: 'host_account')]
class HostAccount
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column(name: 'display_name', type: Types::STRING, length: 255)]
        private string $displayName,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function setDisplayName(string $displayName): void
    {
        $this->displayName = $displayName;
    }
}
