# Demo applications and FrankenPHP

The **Login Throttle Bundle** demo applications (demo-symfony6, demo-symfony7, demo-symfony8) currently run with **PHP-FPM** in Docker, not FrankenPHP.

If you want to run the demos (or your own app using this bundle) with **FrankenPHP** (Caddy + PHP, including worker mode for production), use the same approach as other Nowo bundles that ship FrankenPHP-based demos:

- **[TwigInspectorBundle — Demo with FrankenPHP (development and production)](https://github.com/nowo-tech/TwigInspectorBundle/blob/main/docs/DEMO-FRANKENPHP.md)** — Full description of the two-Caddyfile setup (production with worker, development without worker), entrypoint that copies `Caddyfile.dev` when `APP_ENV=dev`, optional `docker/php-dev.ini` and `config/packages/dev/twig.yaml`, and troubleshooting.
- **[IconSelectorBundle — DEMO-FRANKENPHP.md](https://github.com/nowo-tech/IconSelectorBundle/blob/main/docs/DEMO-FRANKENPHP.md)** — Same pattern, alternate reference.

The Login Throttle Bundle is compatible with FrankenPHP; only the demo stack would need to be migrated from PHP-FPM to FrankenPHP (Dunglas image + Caddyfile) to match that pattern.
