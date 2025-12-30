# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.0.1] - 2025-12-30

### Added
- **Initial release of Login Throttle Bundle**
  - Native Symfony `login_throttling` integration
  - Pre-configured settings with sensible defaults
  - Automatic configuration file generation
  - Command to automatically configure `security.yaml`
  - Support for custom rate limiters
  - Configuration options compatible with `anyx/login-gate-bundle`

- **Multiple Firewalls Support**: Added support for configuring multiple firewalls with independent throttling settings. Each firewall can have its own `max_count_attempts`, `timeout`, `storage`, and `rate_limiter` configuration. Firewalls with identical database storage configurations automatically share rate limiters. See [CONFIGURATION.md](docs/CONFIGURATION.md#multiple-firewalls) for examples.

- **Database Storage Support**: Added option to store login attempts in database instead of cache
  - New `storage` configuration option with values `'cache'` (default) or `'database'`
  - `DatabaseRateLimiter` service for database-backed rate limiting
  - Automatic service registration when `storage='database'` is configured
  - Full integration with Doctrine ORM for storing login attempts
  - Complete documentation in [DATABASE_STORAGE.md](DATABASE_STORAGE.md)
  - Supports migration from `anyx/login-gate-bundle` with ORM storage
  - Login attempts stored in `login_attempts` table with proper indexing
  - Repository methods for querying and auditing login attempts
  - Cleanup functionality for old login attempts

- **Configuration Options**:
  - `max_count_attempts` (maps to `max_attempts` in Symfony)
  - `timeout` (maps to `interval` in Symfony, converted from seconds)
  - `watch_period` (for informational purposes)
  - `firewall` (firewall name configuration, for single firewall setup)
  - `firewalls` (multiple firewalls configuration)
  - `storage` (storage backend: `'cache'` or `'database'`)
  - `rate_limiter` (optional custom rate limiter service)
  - `cache_pool` (cache pool for rate limiter state)
  - `lock_factory` (optional lock factory for rate limiter)

- **Documentation**:
  - Complete README with installation and usage instructions
  - CHANGELOG following Keep a Changelog format
  - CONFIGURATION guide with examples
  - UPGRADING guide with migration instructions from `anyx/login-gate-bundle`
  - CONTRIBUTING guide for contributors
  - BRANCHING strategy documentation
  - SERVICES.md with deployment examples (local, Docker, Kubernetes)

- **Testing**:
  - Complete test suite with PHPUnit
  - Tests for Bundle class
  - Tests for Configuration class
  - Tests for Extension class
  - 100% code coverage requirement

- **Development Tools**:
  - Makefile for development commands
  - PHP-CS-Fixer configuration (PSR-12)
  - PHPUnit configuration with coverage
  - GitHub Actions CI/CD workflow
    - Tests on multiple PHP (8.1-8.5) and Symfony (6.4, 7.0, 8.0) versions
    - Automatic code style fixes on push to main/master
    - Code style checks on pull requests
    - 100% code coverage validation
    - Codecov integration

- **Demo Project**:
  - Complete Symfony 7.0 demo project
  - Docker setup with PHP, Nginx, and MySQL
  - Authentication system demonstrating login throttling
  - Demo users for testing (`demo@example.com` / `demo123`, `admin@example.com` / `admin123`)
  - Makefile for demo management
  - Complete CRUD interface
  - Visual feedback for throttled attempts

- **Compatibility**:
  - Support for Symfony 6.0, 7.0, and 8.0
  - Support for PHP 8.1, 8.2, 8.3, 8.4, and 8.5

### Features

- ✅ Native Symfony login throttling integration
- ✅ Pre-configured settings with sensible defaults
- ✅ Automatic configuration file generation
- ✅ Command to automatically configure `security.yaml`
- ✅ Custom rate limiter support
- ✅ Database storage support (optional, via Doctrine ORM)
- ✅ Multiple firewalls support
- ✅ Easy migration from `anyx/login-gate-bundle`
- ✅ Compatible with Symfony 6.0, 7.0, and 8.0
- ✅ Configurable via YAML
- ✅ Comprehensive test suite with 100% coverage requirement
- ✅ Complete demo project
- ✅ GitHub Actions CI/CD
- ✅ Extensive documentation

