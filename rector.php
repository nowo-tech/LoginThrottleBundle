<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\ClassMethod\LocallyCalledStaticMethodToNonStaticRector;
use Rector\CodeQuality\Rector\Concat\DirnameDirConcatStringToDirectStringPathRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\MethodCall\RemoveNullArgOnNullDefaultParamRector;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withSkip([
        __DIR__ . '/demo',
        __DIR__ . '/vendor',

        // Each skip below is deliberate and scoped to one rule in the named files. Do not "fix" `composer rector-dry`
        // by applying the hunks or by dropping the skip: the reviewed intent is recorded here.

        // LoginAttemptRepository::isExact() is a pure function (it reads only its arguments, never $this), so it is
        // intentionally `static`; an instance method would imply state it does not have.
        // The two test helpers collationOf() / charsetOf() are pure in the same way.
        LocallyCalledStaticMethodToNonStaticRector::class => [
            __DIR__ . '/src/Repository/LoginAttemptRepository.php',
            __DIR__ . '/tests/Doctrine/ExactCollationListenerTest.php',
        ],

        // The explicit `null` in `new LoginAttempt('192.168.1.1', null)` documents the IP-only bucket (a cleared attempt
        // with no username); dropping it would read as if the second argument were irrelevant, when it is the whole
        // point of that test case.
        RemoveNullArgOnNullDefaultParamRector::class => [
            __DIR__ . '/tests/Repository/LoginAttemptRepositoryTest.php',
        ],

        // `\dirname(__DIR__) . '/Fixtures/Entity'` sits beside `\dirname(__DIR__, 2) . '/src/Entity'` in one call; the
        // rewrite to `__DIR__ . '/../Fixtures/Entity'` would mix two path styles in the same array for no gain.
        DirnameDirConcatStringToDirectStringPathRector::class => [
            __DIR__ . '/tests/Doctrine/ExactCollationListenerTest.php',
        ],
    ])
    // Machine-wide cap: at most 2 worker processes; generous timeout for slow (WSL2 / CI) hosts.
    ->withParallel(300, 2, 10)
    ->withPhpVersion(PhpVersion::PHP_82)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
    );
