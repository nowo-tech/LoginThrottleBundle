<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Service;

use Nowo\LoginThrottleBundle\Throttle\ThrottleStatus;

/**
 * Throttling state of a login bucket, computed with the limiter's own rules (3.9).
 *
 * HTTP-free: pass the client IP and the submitted username (it is normalised like the limiter does). Default
 * implementation: {@see LoginThrottleStatusProvider}.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
interface LoginThrottleStatusProviderInterface
{
    /**
     * @param string      $firewallName The firewall name
     * @param string      $ipAddress    The client IP ("unknown" when the request has none, as the limiter does)
     * @param string|null $username     The submitted username (null or blank: the IP-only bucket)
     */
    public function status(string $firewallName, string $ipAddress, ?string $username = null): ThrottleStatus;
}
