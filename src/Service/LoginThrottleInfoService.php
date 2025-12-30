<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Service;

use Nowo\LoginThrottleBundle\Repository\LoginAttemptRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Service to provide login throttling information for display in error messages.
 *
 * This service helps retrieve information about login attempts, remaining attempts,
 * and throttling status to display user-friendly messages.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
class LoginThrottleInfoService
{
    private ?LoginAttemptRepository $repository = null;
    private ?array $firewallsConfig = null;

    /**
     * Set the login attempt repository (for database storage).
     *
     * @param LoginAttemptRepository|null $repository The repository
     */
    #[Required]
    public function setRepository(?LoginAttemptRepository $repository): void
    {
        $this->repository = $repository;
    }

    /**
     * Set the firewalls configuration.
     *
     * @param array|null $firewallsConfig The firewalls configuration
     */
    public function setFirewallsConfig(?array $firewallsConfig): void
    {
        $this->firewallsConfig = $firewallsConfig;
    }

    /**
     * Get login attempt information for a given firewall and request.
     *
     * @param string      $firewallName The firewall name
     * @param Request     $request      The request
     * @param string|null $username     Optional username (if not available in request)
     *
     * @return array{current_attempts: int, max_attempts: int, remaining_attempts: int, is_blocked: bool, retry_after: \DateTimeImmutable|null, tracking_type: string}
     */
    public function getAttemptInfo(string $firewallName, Request $request, ?string $username = null): array
    {
        $config = $this->getFirewallConfig($firewallName);
        if (null === $config) {
            return [
                'current_attempts' => 0,
                'max_attempts' => 0,
                'remaining_attempts' => 0,
                'is_blocked' => false,
                'retry_after' => null,
                'tracking_type' => 'ip',
            ];
        }

        $maxAttempts = $config['max_attempts'] ?? 3;
        // Use timeout in seconds if available, otherwise convert from interval
        $timeout = $config['timeout'] ?? $this->getTimeoutSeconds($config['interval'] ?? '10 minutes');
        $storage = $config['storage'] ?? 'cache';

        $ipAddress = $request->getClientIp() ?? 'unknown';
        // Use provided username or try to extract from request
        if (null === $username || '' === $username) {
            $username = $this->extractUsername($request);
        }

        if ('database' === $storage && null !== $this->repository) {
            return $this->getAttemptInfoFromDatabase($ipAddress, $username, $maxAttempts, $timeout);
        }

        // For cache storage, try to use rate limiter if available
        $result = $this->getAttemptInfoFromCache($config, $request, $maxAttempts, $timeout);
        $result['tracking_type'] = null !== $username && '' !== $username ? 'username' : 'ip';

        return $result;
    }

    /**
     * Get attempt info from database storage.
     *
     * @param string      $ipAddress   IP address
     * @param string|null $username    Username
     * @param int         $maxAttempts Maximum attempts
     * @param int         $timeout     Timeout in seconds
     *
     * @return array{current_attempts: int, max_attempts: int, remaining_attempts: int, is_blocked: bool, retry_after: \DateTimeImmutable|null, tracking_type: string}
     */
    private function getAttemptInfoFromDatabase(string $ipAddress, ?string $username, int $maxAttempts, int $timeout): array
    {
        // Determine tracking type: if username is available, track by username; otherwise by IP
        $trackingType = 'ip';
        $currentAttempts = 0;
        $isBlocked = false;
        $retryAfter = null;

        if (null !== $username && '' !== $username) {
            // Track by username/email
            $trackingType = 'username';
            // Count attempts by username only (as requested by user)
            // This shows attempts for this email regardless of IP
            $currentAttempts = $this->repository->countAttemptsByUsername($username, $timeout);
            $isBlocked = $currentAttempts >= $maxAttempts;

            if ($isBlocked) {
                // Get attempts by username to calculate retry_after (pass empty string for IP to ignore it)
                $attempts = $this->repository->getAttempts('', $username, $timeout);
                if (!empty($attempts)) {
                    $oldestAttempt = end($attempts);
                    if ($oldestAttempt) {
                        $retryAfter = $oldestAttempt->getCreatedAt()->modify(sprintf('+%d seconds', $timeout));
                    }
                }
            }
        } else {
            // Track by IP address
            $trackingType = 'ip';
            // Count attempts by IP only (as requested by user)
            // This shows attempts from this IP regardless of username
            $currentAttempts = $this->repository->countAttemptsByIp($ipAddress, $timeout);
            $isBlocked = $currentAttempts >= $maxAttempts;

            if ($isBlocked) {
                // Get attempts by IP to calculate retry_after
                $attempts = $this->repository->getAttempts($ipAddress, null, $timeout);
                if (!empty($attempts)) {
                    $oldestAttempt = end($attempts);
                    if ($oldestAttempt) {
                        $retryAfter = $oldestAttempt->getCreatedAt()->modify(sprintf('+%d seconds', $timeout));
                    }
                }
            }
        }

        $remainingAttempts = max(0, $maxAttempts - $currentAttempts);

        return [
            'current_attempts' => $currentAttempts,
            'max_attempts' => $maxAttempts,
            'remaining_attempts' => $remainingAttempts,
            'is_blocked' => $isBlocked,
            'retry_after' => $retryAfter,
            'tracking_type' => $trackingType,
        ];
    }

    /**
     * Get attempt info from cache storage (using rate limiter).
     *
     * @param array   $config      Firewall configuration
     * @param Request $request     The request
     * @param int     $maxAttempts Maximum attempts
     * @param int     $timeout     Timeout in seconds
     *
     * @return array{current_attempts: int, max_attempts: int, remaining_attempts: int, is_blocked: bool, retry_after: \DateTimeImmutable|null, tracking_type: string}
     */
    private function getAttemptInfoFromCache(array $config, Request $request, int $maxAttempts, int $timeout): array
    {
        // For cache storage, we can't easily get the exact count without consuming a token
        // So we return a conservative estimate
        // In a real implementation, you might want to peek at the rate limiter state
        return [
            'current_attempts' => 0, // Unknown for cache storage
            'max_attempts' => $maxAttempts,
            'remaining_attempts' => 0, // Unknown for cache storage
            'is_blocked' => false,
            'retry_after' => null,
            'tracking_type' => 'ip',
        ];
    }

    /**
     * Get firewall configuration.
     *
     * @param string $firewallName The firewall name
     *
     * @return array|null The configuration or null if not found
     */
    private function getFirewallConfig(string $firewallName): ?array
    {
        if (null === $this->firewallsConfig) {
            return null;
        }

        // Check if it's a single firewall configuration (backward compatibility)
        if (isset($this->firewallsConfig['max_attempts'])) {
            // Single firewall config - check if it matches the requested firewall
            $configuredFirewall = $this->firewallsConfig['firewall'] ?? 'main';
            if ($firewallName === $configuredFirewall) {
                return $this->firewallsConfig;
            }

            return null;
        }

        // Multiple firewalls configuration
        return $this->firewallsConfig[$firewallName] ?? null;
    }

    /**
     * Extract username from request.
     *
     * @param Request $request The request
     *
     * @return string|null The username or null
     */
    private function extractUsername(Request $request): ?string
    {
        $username = $request->request->get('_username')
            ?? $request->request->get('username')
            ?? $request->request->get('email');

        return $username ? (string) $username : null;
    }

    /**
     * Convert interval string to seconds.
     *
     * @param string $interval Interval string (e.g., "10 minutes", "1 hour")
     *
     * @return int Seconds
     */
    private function getTimeoutSeconds(string $interval): int
    {
        // Parse Symfony interval format (e.g., "10 minutes", "1 hour")
        if (preg_match('/^(\d+)\s+(second|minute|hour|day|week|month|year)s?$/i', $interval, $matches)) {
            $value = (int) $matches[1];
            $unit = strtolower($matches[2]);

            return match ($unit) {
                'second' => $value,
                'minute' => $value * 60,
                'hour' => $value * 3600,
                'day' => $value * 86400,
                'week' => $value * 604800,
                'month' => $value * 2592000, // Approximate
                'year' => $value * 31536000, // Approximate
                default => 600,
            };
        }

        return 600; // Default to 10 minutes
    }
}
