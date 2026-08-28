<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Service;

use Nowo\LoginThrottleBundle\Entity\LoginAttempt;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptRepositoryInterface;
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
final class LoginThrottleInfoService
{
    private ?LoginAttemptRepositoryInterface $repository = null;

    /** @var array<string, mixed>|null */
    private ?array $firewallsConfig = null;

    /**
     * Set the login attempt repository (for database storage).
     *
     * @param LoginAttemptRepositoryInterface|null $repository The repository
     */
    #[Required]
    public function setRepository(?LoginAttemptRepositoryInterface $repository): void
    {
        $this->repository = $repository;
    }

    /**
     * Set the firewalls configuration.
     *
     * @param array<string, mixed>|null $firewallsConfig The firewalls configuration
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

        if ('database' === $storage) {
            $repository = $this->repository;
            if (!$repository instanceof LoginAttemptRepositoryInterface) {
                // Repository not available, return default values
                return [
                    'current_attempts' => 0,
                    'max_attempts' => $maxAttempts,
                    'remaining_attempts' => $maxAttempts,
                    'is_blocked' => false,
                    'retry_after' => null,
                    'tracking_type' => null !== $username && '' !== $username ? 'username' : 'ip',
                ];
            }

            return $this->getAttemptInfoFromDatabase($repository, $ipAddress, $username, $maxAttempts, $timeout);
        }

        // For cache storage, try to use rate limiter if available
        $result = $this->getAttemptInfoFromCache($maxAttempts);
        $result['tracking_type'] = null !== $username && '' !== $username ? 'username' : 'ip';

        return $result;
    }

    /**
     * Get attempt info from database storage.
     *
     * @param LoginAttemptRepositoryInterface $repository  Repository
     * @param string                          $ipAddress   IP address
     * @param string|null                     $username    Username
     * @param int                             $maxAttempts Maximum attempts
     * @param int                             $timeout     Timeout in seconds
     *
     * @return array{current_attempts: int, max_attempts: int, remaining_attempts: int, is_blocked: bool, retry_after: \DateTimeImmutable|null, tracking_type: string}
     */
    private function getAttemptInfoFromDatabase(
        LoginAttemptRepositoryInterface $repository,
        string $ipAddress,
        ?string $username,
        int $maxAttempts,
        int $timeout
    ): array {
        // Determine tracking type: if username is available, track by username; otherwise by IP
        $trackingType = 'ip';
        $currentAttempts = 0;
        $isBlocked = false;
        $retryAfter = null;

        if (null !== $username && '' !== $username) {
            // Track by username/email
            $trackingType = 'username';
            // Count attempts by username (shows attempts for this email regardless of IP)
            // This matches what the user requested: show attempts by email when tracking by email
            $currentAttempts = $repository->countAttemptsByUsername($username, $timeout);
            $isBlocked = $currentAttempts >= $maxAttempts;

            if ($isBlocked) {
                // Get attempts by username to calculate retry_after (pass empty string for IP to ignore it)
                $attempts = $repository->getAttempts('', $username, $timeout);
                $retryAfter = $this->calculateRetryAfterFromAttempts($attempts, $timeout);
            }
        } else {
            // Track by IP address
            $trackingType = 'ip';
            // Count attempts by IP only (shows attempts from this IP regardless of username)
            // This matches what the user requested: show attempts by IP when tracking by IP
            $currentAttempts = $repository->countAttemptsByIp($ipAddress, $timeout);
            $isBlocked = $currentAttempts >= $maxAttempts;

            if ($isBlocked) {
                // Get attempts by IP to calculate retry_after
                $attempts = $repository->getAttempts($ipAddress, null, $timeout);
                $retryAfter = $this->calculateRetryAfterFromAttempts($attempts, $timeout);
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
     * @param list<LoginAttempt> $attempts
     */
    private function calculateRetryAfterFromAttempts(array $attempts, int $timeout): ?\DateTimeImmutable
    {
        if ([] === $attempts) {
            return null;
        }

        $oldestAttempt = $attempts[array_key_last($attempts)];
        $retryAfter = $oldestAttempt->getCreatedAt()->modify(\sprintf('+%d seconds', $timeout));

        return $retryAfter instanceof \DateTimeImmutable ? $retryAfter : null;
    }

    /**
     * Get attempt info from cache storage (using rate limiter).
     *
     * @param int $maxAttempts Maximum attempts
     *
     * @return array{current_attempts: int, max_attempts: int, remaining_attempts: int, is_blocked: bool, retry_after: \DateTimeImmutable|null, tracking_type: string}
     */
    private function getAttemptInfoFromCache(int $maxAttempts): array
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
     * @return array<string, mixed>|null The configuration or null if not found
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
        $firewallConfig = $this->firewallsConfig[$firewallName] ?? null;

        return \is_array($firewallConfig) ? $firewallConfig : null;
    }

    /**
     * Extract username from request.
     *
     * Checks nested bags such as {@code login_form[_username]} (AuthKit) before flat keys.
     *
     * @param Request $request The request
     *
     * @return string|null The username or null
     */
    private function extractUsername(Request $request): ?string
    {
        $loginForm = $request->request->all('login_form');

        foreach (['_username', 'username', 'email'] as $key) {
            $value = $loginForm[$key] ?? null;

            if (\is_string($value) && '' !== $value) {
                return $value;
            }
        }

        foreach (['_username', 'username', 'email'] as $key) {
            $value = $request->request->get($key);

            if (\is_string($value) && '' !== $value) {
                return $value;
            }
        }

        return null;
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
