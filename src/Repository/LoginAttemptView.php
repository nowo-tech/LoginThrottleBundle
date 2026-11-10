<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Repository;

use Nowo\LoginThrottleBundle\Entity\LoginAttempt;

/**
 * Read-only view of one stored login attempt (3.9), so callers outside the bundle never handle the Doctrine entity.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
final readonly class LoginAttemptView
{
    /**
     * @param int|null           $id        Row id (null when not stored)
     * @param string             $ipAddress Client IP
     * @param string|null        $username  Normalised username, null for IP-only attempts
     * @param \DateTimeImmutable $createdAt When the attempt was recorded
     * @param bool               $blocked   The row's blocked flag
     */
    public function __construct(
        public ?int $id,
        public string $ipAddress,
        public ?string $username,
        public \DateTimeImmutable $createdAt,
        public bool $blocked = false,
    ) {
    }

    /**
     * Builds the view of an entity.
     */
    public static function fromEntity(LoginAttempt $attempt): self
    {
        return new self(
            $attempt->getId(),
            $attempt->getIpAddress(),
            $attempt->getUsername(),
            $attempt->getCreatedAt(),
            $attempt->isBlocked(),
        );
    }
}
