# Installation

This guide covers installing `LoginThrottleBundle` in a Symfony application.

## With Symfony Flex

If you use [Symfony Flex](https://symfony.com/doc/current/setup/flex.html) and the bundle is installed from a package registry, the Flex recipe will:

- Register the bundle for `dev` and `test` (so throttling + routes for the demos can work safely)
- Create `config/packages/nowo_login_throttle.yaml` with sensible defaults (if it does not exist)
- Add the bundle routes to `config/routes.yaml` for `dev`/`test`

You do not need to edit any file manually.

## Without Flex (manual)

1. Register the bundle in `config/bundles.php` for `dev` and `test`:

```php
<?php

return [
    // ...
    Nowo\LoginThrottleBundle\NowoLoginThrottleBundle::class => ['dev' => true, 'test' => true],
];
```

2. Import the routes (so the “open in IDE / demo” endpoints can work in `dev` and `test`):

```yaml
when@dev:
    nowo_login_throttle:
        resource: '@NowoLoginThrottleBundle/Resources/config/routes.yaml'

when@test:
    nowo_login_throttle:
        resource: '@NowoLoginThrottleBundle/Resources/config/routes.yaml'
```

3. Configure the bundle using `config/packages/nowo_login_throttle.yaml`.

## Verify

1. Clear cache: `php bin/console cache:clear --env=dev`
2. Ensure the configuration file exists: `config/packages/nowo_login_throttle.yaml`
3. Run the demo or any relevant test flow you have for login throttling.

