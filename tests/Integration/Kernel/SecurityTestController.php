<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Integration\Kernel;

use Symfony\Component\HttpFoundation\Response;

/** The pages of {@see SecurityTestKernel}. */
final class SecurityTestController
{
    public function __invoke(): Response
    {
        return new Response('page');
    }
}
