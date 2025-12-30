<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Entity to store login attempts in the database.
 *
 * This entity is used when you want to store login attempts in the database
 * instead of using cache/Redis. It tracks failed login attempts per IP and username.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
#[ORM\Entity]
#[ORM\Table(name: 'login_attempts')]
#[ORM\Index(columns: ['ip_address', 'username'], name: 'idx_ip_username')]
#[ORM\Index(columns: ['created_at'], name: 'idx_created_at')]
class LoginAttempt
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 45)]
    private string $ipAddress;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $username = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $blocked = false;

    /**
     * Constructor.
     *
     * @param string      $ipAddress The IP address
     * @param string|null $username  The username (optional)
     */
    public function __construct(string $ipAddress, ?string $username = null)
    {
        $this->ipAddress = $ipAddress;
        $this->username = $username;
        $this->createdAt = new \DateTimeImmutable();
    }

    /**
     * Get the ID.
     *
     * @return int|null The ID
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Get the IP address.
     *
     * @return string The IP address
     */
    public function getIpAddress(): string
    {
        return $this->ipAddress;
    }

    /**
     * Get the username.
     *
     * @return string|null The username
     */
    public function getUsername(): ?string
    {
        return $this->username;
    }

    /**
     * Get the creation date.
     *
     * @return \DateTimeImmutable The creation date
     */
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Check if the attempt is blocked.
     *
     * @return bool True if blocked
     */
    public function isBlocked(): bool
    {
        return $this->blocked;
    }

    /**
     * Set the blocked status.
     *
     * @param bool $blocked The blocked status
     *
     * @return self
     */
    public function setBlocked(bool $blocked): self
    {
        $this->blocked = $blocked;

        return $this;
    }
}

