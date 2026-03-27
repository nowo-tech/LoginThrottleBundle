# Login Throttle Bundle

[![CI](https://github.com/nowo-tech/LoginThrottleBundle/actions/workflows/ci.yml/badge.svg)](https://github.com/nowo-tech/LoginThrottleBundle/actions/workflows/ci.yml) [![Packagist Version](https://img.shields.io/packagist/v/nowo-tech/login-throttle-bundle.svg?style=flat)](https://packagist.org/packages/nowo-tech/login-throttle-bundle) [![Packagist Downloads](https://img.shields.io/packagist/dt/nowo-tech/login-throttle-bundle.svg)](https://packagist.org/packages/nowo-tech/login-throttle-bundle) [![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE) [![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php)](https://php.net) [![Symfony](https://img.shields.io/badge/Symfony-6%20%7C%207%20%7C%208-000000?logo=symfony)](https://symfony.com) [![GitHub stars](https://img.shields.io/github/stars/nowo-tech/login-throttle-bundle.svg?style=social&label=Star)](https://github.com/nowo-tech/LoginThrottleBundle) [![Coverage](https://img.shields.io/badge/Coverage-100%25-brightgreen)](#tests-and-coverage)

> ⭐ **Found this useful?** Give it a star on GitHub! It helps us maintain and improve the project.

Symfony bundle for login throttling using native Symfony `login_throttling` feature with pre-configured settings.

This bundle replaces deprecated bundles like `anyx/login-gate-bundle` by using Symfony's native login throttling feature introduced in Symfony 5.2.

## Features

- ✅ Native Symfony `login_throttling` integration
- ✅ Pre-configured settings with sensible defaults
- ✅ Automatic configuration file generation
- ✅ Command to automatically configure `security.yaml`
- ✅ **Multiple firewalls support** - Configure independent throttling for each firewall
- ✅ **Database storage support** - Store login attempts in database for auditing
- ✅ Custom rate limiter support
- ✅ Compatible with Symfony 6.0, 7.0, and 8.0
- ✅ Easy migration from `anyx/login-gate-bundle`
- ✅ Same configuration options as the deprecated bundle
- ✅ Complete test suite with 100% coverage requirement
- ✅ Comprehensive documentation
- ✅ Demo project included
- ✅ GitHub Actions CI/CD

## Installation

```bash
composer require nowo-tech/login-throttle-bundle
```

Then, register the bundle in your `config/bundles.php`:

```php
<?php

return [
  // ...
  Nowo\LoginThrottleBundle\NowoLoginThrottleBundle::class => ['all' => true],
];
```

> **Note**: If you're using Symfony Flex, the bundle will be registered automatically and a default configuration file will be created at `config/packages/nowo_login_throttle.yaml`.

## Configuration

When installed, a default configuration file is automatically created at `config/packages/nowo_login_throttle.yaml` (if not using Flex, it will be created on first bundle boot).

You can configure the login throttling settings:

**Single Firewall Configuration (Simple)**:
```yaml
nowo_login_throttle:
  enabled: true
  max_count_attempts: 3
  timeout: 600     # Ban period in seconds (600 = 10 minutes)
  watch_period: 3600  # See docs: affects DB limiter service name / sharing; counting window is `timeout`
  firewall: 'main'   # Firewall name where login_throttling should be applied
  storage: 'cache'   # Storage backend: 'cache' (default) or 'database'
  rate_limiter: null  # Optional: Custom rate limiter service ID
  cache_pool: 'cache.rate_limiter' # Cache pool for rate limiter state (only when storage=cache)
  lock_factory: null  # Optional: Lock factory service ID (only when storage=cache)
```

**Multiple Firewalls Configuration (Advanced)**:
```yaml
nowo_login_throttle:
  firewalls:
    main:
      enabled: true
      max_count_attempts: 3
      timeout: 600
      storage: 'cache'
    api:
      enabled: true
      max_count_attempts: 5
      timeout: 300
      storage: 'database'
```

See [Configuration Documentation](docs/CONFIGURATION.md#multiple-firewalls) for more details on multiple firewalls configuration.

### Configuration Options

| Option | Type | Default | Description |
|-------|------|---------|-------------|
| `enabled` | `bool` | `true` | Enable or disable login throttling |
| `max_count_attempts` | `int` | `3` | Maximum number of login attempts before throttling (maps to `max_attempts` in Symfony `login_throttling`) |
| `timeout` | `int` | `600` | Ban period in seconds (maps to `interval` in Symfony `login_throttling`, e.g., 600 = 10 minutes) |
| `watch_period` | `int` | `3600` | With **`storage: database`**, part of the **generated limiter service ID** and **shared-limiter** grouping. **Counting/ban window** uses **`timeout`**. For pruning old DB rows, call **`LoginAttemptRepository::cleanup($watchPeriod)`** from your own scheduled task (see [DATABASE_STORAGE.md](docs/DATABASE_STORAGE.md)). |
| `firewall` | `string` | `'main'` | Firewall name where `login_throttling` should be applied |
| `storage` | `string` | `'cache'` | Storage backend: `'cache'` (uses Symfony cache) or `'database'` (stores in database via Doctrine ORM). See [DATABASE_STORAGE.md](docs/DATABASE_STORAGE.md) for details. |
| `rate_limiter` | `string\|null` | `null` | Custom rate limiter service ID (optional). If not provided, Symfony will use default login throttling rate limiter, or database rate limiter if `storage=database` |
| `cache_pool` | `string` | `'cache.rate_limiter'` | Cache pool to use for storing the limiter state (only used when `storage=cache`) |
| `lock_factory` | `string\|null` | `null` | Lock factory service ID for rate limiter (optional, only used when `storage=cache`). Set to null to disable locking |

## Setup

**Important**: The bundle does NOT automatically configure `security.yaml`. You must run the command below or manually configure `login_throttling` in `security.yaml`.

After installing and configuring the bundle, you need to configure your `security.yaml` file with the `login_throttling` settings.

### Automatic Configuration (Recommended)

Run the provided command to automatically configure `security.yaml`:

```bash
php bin/console nowo:login-throttle:configure-security
```

This command will:
1. Read your `nowo_login_throttle.yaml` configuration
2. Add or update `login_throttling` in your `security.yaml`
3. Configure all firewalls specified in your bundle configuration (single or multiple)
4. Automatically set the correct rate limiter service IDs (especially important for database storage)

**Note**: If `login_throttling` is already configured in `security.yaml`, the command will skip it unless you use the `--force` option.

### Configuration Priority

**What Symfony uses**: Symfony's security system reads `security.yaml` directly. The `login_throttling` configuration in `security.yaml` is what actually controls throttling behavior.

**What happens if configurations differ**:
- If `security.yaml` has `login_throttling` configured but `nowo_login_throttle.yaml` has different values, **Symfony will use what's in `security.yaml`**.
- The bundle configuration (`nowo_login_throttle.yaml`) is only used by the command as a template to update `security.yaml`.
- To sync them, run: `php bin/console nowo:login-throttle:configure-security --force`

**Best Practice**: Configure the bundle in `nowo_login_throttle.yaml` and run the command to keep `security.yaml` in sync.

### Manual Configuration

Alternatively, you can manually add the `login_throttling` configuration to your `config/packages/security.yaml`:

```yaml
security:
  firewalls:
    main:
      login_throttling:
        max_attempts: 3
        interval: '10 minutes'
```

The `interval` value is automatically converted from seconds (in your bundle config) to a human-readable format (e.g., `600` seconds = `'10 minutes'`).

**Important**: When configuring manually, ensure the configuration matches `nowo_login_throttle.yaml` to avoid confusion. For database storage, you must also ensure the rate limiter service IDs are correct (they are automatically generated by the bundle when processing configuration).

## Migration from anyx/login-gate-bundle

This bundle is designed as a drop-in replacement for `anyx/login-gate-bundle`. The configuration options are compatible:

### Quick Migration

1. **Remove old bundle:**
  ```bash
  composer remove anyx/login-gate-bundle
  ```

2. **Install new bundle:**
  ```bash
  composer require nowo-tech/login-throttle-bundle
  ```

3. **Update configuration:**

  **Before (anyx/login-gate-bundle):**
  ```yaml
  # config/packages/login_gate.yaml
  login_gate:
    storages: ['orm']
    options:
      max_count_attempts: 3
      timeout: 600
      watch_period: 3600
  ```

  **After (nowo-tech/login-throttle-bundle):**
  ```yaml
  # config/packages/nowo_login_throttle.yaml
  nowo_login_throttle:
    enabled: true
    max_count_attempts: 3
    timeout: 600
    watch_period: 3600
    firewall: 'main'
  ```

4. **Configure security:**
  ```bash
  php bin/console nowo:login-throttle:configure-security
  ```

5. **Clear cache:**
  ```bash
  php bin/console cache:clear
  ```

### Complete Migration Guide

For detailed migration instructions, including storage migration, code changes, and troubleshooting, see the [complete migration guide](docs/MIGRATION_FROM_ANYX.md).

## How It Works

This bundle uses Symfony's native `login_throttling` feature, which:

1. **Tracks failed login attempts** per IP address and username combination
2. **Blocks further attempts** when the maximum number of attempts is reached
3. **Automatically resets** after the specified interval
4. **Uses Symfony's rate limiter** component for efficient tracking

The throttling is handled automatically by Symfony's security system - you don't need to add any code to your controllers or authentication logic.

## Requirements

- PHP >= 8.1, < 8.6
- Symfony >= 6.0 || >= 7.0 || >= 8.0
- Symfony Security Bundle
- Symfony Rate Limiter component

## Commands

### Configure Security

Automatically configures `security.yaml` with `login_throttling` settings:

```bash
php bin/console nowo:login-throttle:configure-security
```

Options:
- `--force` or `-f`: Force update even if `login_throttling` is already configured

## Development

### Using Docker

```bash
# Start the container
make up

# Install dependencies
make install

# Run tests
make test

# Run tests with coverage
make test-coverage

# Run all QA checks
make qa
```

### Without Docker

```bash
composer install
composer test
composer test-coverage
composer qa
```

### Development Tools

- **Makefile**: Simplifies Docker commands for development
- **PHP-CS-Fixer**: Enforces PSR-12 code style
- **PHPUnit**: Complete test suite with coverage
- **GitHub Actions**: Automated CI/CD pipeline

## Testing

The bundle includes comprehensive tests with **100% code coverage requirement**. All tests are located in the `tests/` directory.

### Running Tests

```bash
# Run all tests
composer test

# Run tests with coverage report
composer test-coverage
```

### Test Coverage

The bundle requires 100% code coverage. The CI/CD pipeline validates this requirement automatically.

## Code Quality

The bundle uses PHP-CS-Fixer to enforce code style (PSR-12).

```bash
# Check code style
composer cs-check

# Fix code style
composer cs-fix
```

The GitHub Actions CI/CD pipeline automatically:
- Checks code style on pull requests
- Applies code style fixes on push to main/master
- Validates 100% test coverage
- Runs tests on multiple PHP and Symfony versions

## Tests and coverage

- Tests: PHPUnit (PHP)
- PHP: 51.74%
- TS/JS: N/A
- Python: N/A

## License

The MIT License (MIT). Please see [LICENSE](LICENSE) for more information.

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## Author

Created by [Héctor Franco Aceituno](https://github.com/HecFranco) at [Nowo.tech](https://nowo.tech)

## Service Configuration Examples

For detailed examples of service configurations for different deployment scenarios:

- **Local Development**: File-based cache
- **Docker Containers**: Redis for shared state
- **Kubernetes**: Redis with lock factory for distributed systems
- **Multiple Environments**: Environment-specific configurations

See [docs/SERVICES.md](docs/SERVICES.md) for complete examples.

## Demo Project

A complete demo project is included in the `demo/` directory demonstrating:

- Login throttling in action
- Configuration examples
- Docker setup for easy testing
- Complete authentication system

### Quick Start with Demo

```bash
cd demo
make up-symfony7
```

Access the demo at: http://localhost:8001

See [demo/README.md](demo/README.md) for detailed instructions.

FrankenPHP worker mode: Supported and documented for demos in `docs/DEMO-FRANKENPHP.md`.

## Documentation

- [Installation](docs/INSTALLATION.md)
- [Configuration](docs/CONFIGURATION.md)
- [Usage](docs/USAGE.md)
- [Contributing](docs/CONTRIBUTING.md)
- [Changelog](docs/CHANGELOG.md)
- [Upgrading](docs/UPGRADING.md)
- [Release](docs/RELEASE.md)
- [Security](docs/SECURITY.md)
- [Engram](docs/ENGRAM.md)

### Additional documentation

- [Demo with FrankenPHP](docs/DEMO-FRANKENPHP.md)
- [Database storage](docs/DATABASE_STORAGE.md)
- [Migration from Anyx](docs/MIGRATION_FROM_ANYX.md)
- [Translations](docs/TRANSLATIONS.md)
- [Services](docs/SERVICES.md)
- [Branching](docs/BRANCHING.md)

## Related

- [Symfony Security Documentation - Limiting Login Attempts](https://symfony.com/doc/current/security.html#limiting-login-attempts)
- [anyx/login-gate-bundle (deprecated)](https://packagist.org/packages/anyx/login-gate-bundle)

