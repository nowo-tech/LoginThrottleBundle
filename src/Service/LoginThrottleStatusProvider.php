<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Service;

use Nowo\LoginThrottleBundle\Repository\LoginAttemptRepositoryInterface;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptStoreInterface;
use Nowo\LoginThrottleBundle\Throttle\ThrottleRules;
use Nowo\LoginThrottleBundle\Throttle\ThrottleStatus;
use Nowo\LoginThrottleBundle\Throttle\UsernameNormalizer;

/**
 * Default {@see LoginThrottleStatusProviderInterface} (3.9).
 *
 * For {@code storage: database} firewalls it reads the store with {@see ThrottleRules}, the same rules the database
 * limiter applies (IP + username bucket, per-IP cap, bound without a username, retry time). For other firewalls the
 * figures are unknown: {@see ThrottleStatus::$tracked} is false.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
final readonly class LoginThrottleStatusProvider implements LoginThrottleStatusProviderInterface
{
    /**
     * @param array<string, mixed>                                            $firewalls  The {@code nowo_login_throttle.firewalls} parameter
     * @param LoginAttemptRepositoryInterface|LoginAttemptStoreInterface|null $repository The attempt store ({@see LoginAttemptStoreInterface} since 3.14; null when there is none)
     */
    public function __construct(
        private array $firewalls,
        private LoginAttemptRepositoryInterface|LoginAttemptStoreInterface|null $repository = null,
    ) {
    }

    public function status(string $firewallName, string $ipAddress, ?string $username = null): ThrottleStatus
    {
        $username = UsernameNormalizer::normalize($username);
        $trackingType = null !== $username ? ThrottleStatus::TRACKING_USERNAME : ThrottleStatus::TRACKING_IP;
        $config = self::firewallConfig($this->firewalls, $firewallName);

        if (null === $config) {
            return new ThrottleStatus(0, 0, 0, false, null, ThrottleStatus::TRACKING_IP, tracked: false);
        }

        $maxAttempts = $this->intOr($config['max_attempts'] ?? null, 3);

        if ('database' !== ($config['storage'] ?? 'cache') || null === $this->repository) {
            return new ThrottleStatus(0, $maxAttempts, 0, false, null, $trackingType, tracked: false);
        }

        $maxPerIp = $config['max_attempts_per_ip'] ?? null;
        $maxWithoutUsername = $config['max_attempts_without_username'] ?? null;
        $rules = new ThrottleRules(
            $this->repository,
            $maxAttempts,
            $this->intOr($config['timeout'] ?? null, 600),
            \is_int($maxPerIp) ? $maxPerIp : null,
            \is_int($maxWithoutUsername) ? $maxWithoutUsername : null,
        );

        return $rules->status($ipAddress, $username);
    }

    /**
     * Resolves one firewall's entry of the {@code nowo_login_throttle.firewalls} parameter.
     *
     * Single-firewall mode stores one entry whose {@code firewall} key names it; multi-firewall mode keys by name.
     *
     * @param array<string, mixed>|null $firewalls    The parameter
     * @param string                    $firewallName The firewall name
     *
     * @return array<string, mixed>|null The entry, or null when the firewall is not throttled by the bundle
     */
    public static function firewallConfig(?array $firewalls, string $firewallName): ?array
    {
        if (null === $firewalls) {
            return null;
        }

        // Legacy flat shape (single firewall configuration)
        if (isset($firewalls['max_attempts'])) {
            return $firewallName === ($firewalls['firewall'] ?? 'main') ? $firewalls : null;
        }

        $config = $firewalls[$firewallName] ?? null;

        if (!\is_array($config)) {
            return null;
        }

        /* @var array<string, mixed> $config */
        return $config;
    }

    private function intOr(mixed $value, int $default): int
    {
        return \is_int($value) ? $value : $default;
    }
}
