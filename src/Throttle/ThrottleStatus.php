<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Throttle;

use Nowo\LoginThrottleBundle\Service\LoginThrottleInfoService;

/**
 * Read-only throttling state of one IP + username bucket, as the database limiter enforces it (3.9).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
final readonly class ThrottleStatus
{
    public const TRACKING_IP = 'ip';
    public const TRACKING_USERNAME = 'username';

    /**
     * @param int                     $currentAttempts   Attempts in the IP + username bucket within the timeout window
     * @param int                     $maxAttempts       Limit of the IP + username bucket
     * @param int                     $remainingAttempts Attempts left before the next one is refused (0 when blocked)
     * @param bool                    $blocked           Whether the next attempt is refused
     * @param \DateTimeImmutable|null $retryAfter        When the blocking bucket frees up (null when not blocked)
     * @param string                  $trackingType      {@see self::TRACKING_USERNAME} with a username, else {@see self::TRACKING_IP}
     * @param int                     $currentIpAttempts Attempts from the IP, whatever the username
     * @param int                     $maxAttemptsPerIp  Limit per IP, whatever the username
     * @param bool                    $tracked           False when the firewall is unknown or does not use database storage (figures unknown)
     */
    public function __construct(
        public int $currentAttempts,
        public int $maxAttempts,
        public int $remainingAttempts,
        public bool $blocked,
        public ?\DateTimeImmutable $retryAfter,
        public string $trackingType,
        public int $currentIpAttempts = 0,
        public int $maxAttemptsPerIp = 0,
        public bool $tracked = true,
    ) {
    }

    /**
     * The array shape of {@see LoginThrottleInfoService::getAttemptInfo()}.
     *
     * @return array{current_attempts: int, max_attempts: int, remaining_attempts: int, is_blocked: bool, retry_after: \DateTimeImmutable|null, tracking_type: string}
     */
    public function toArray(): array
    {
        return [
            'current_attempts' => $this->currentAttempts,
            'max_attempts' => $this->maxAttempts,
            'remaining_attempts' => $this->remainingAttempts,
            'is_blocked' => $this->blocked,
            'retry_after' => $this->retryAfter,
            'tracking_type' => $this->trackingType,
        ];
    }
}
