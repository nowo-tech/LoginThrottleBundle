# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.0.8] - 2025-01-15

### Fixed
- **Release Workflow Execution**: Fixed critical YAML syntax errors preventing workflows from running
  - Removed unused "Build release body" step that caused YAML parsing errors
  - Fixed permissions configuration in `sync-releases.yml` (removed duplicate `contents: read`)
  - Workflows now execute correctly when tags are pushed
  - Enables automatic release creation and synchronization

## [0.0.7] - 2025-01-15

### Fixed
- **Workflow Syntax Errors**: Fixed YAML syntax errors in GitHub Actions workflows
  - Corrected multiline template literals in `release.yml` (line 129)
  - Corrected multiline template literals in `sync-releases.yml` (line 145)
  - Replaced problematic template literals with string concatenation for proper YAML parsing

- **Entity Column Mapping**: Added explicit column names to `LoginAttempt` entity
  - Added `ip_address` column name mapping
  - Added `created_at` column name mapping
  - Ensures proper database column mapping and compatibility

- **Login Attempt Information Display**: Fixed issue where attempt counts were not displayed correctly
  - Added optional `username` parameter to `LoginThrottleInfoService::getAttemptInfo()` method
  - Updated `SecurityController` and `AdminController` to pass `lastUsername` from `AuthenticationUtils`
  - Ensures username is available even after authentication error redirect, allowing correct attempt counting
  - Fixes display of current attempts, max attempts, and remaining attempts in error messages

### Changed
- **Release Workflow Improvements**: Enhanced release creation workflow
  - Improved error handling and logging in release workflows
  - Better consistency using GitHub Script API for both create and update operations
  - More reliable release creation process

## [0.0.6] - 2025-01-15

### Added
- **Automatic Release Management with CHANGELOG**:
  - Enhanced `sync-releases.yml` workflow to automatically update existing releases missing changelog notes
  - Workflow now detects both missing releases and releases without CHANGELOG content
  - Improved summary reporting showing which releases were created vs updated

### Changed
- **Release Workflow Improvements**:
  - Modified `release.yml` to check if release exists before creating
  - Automatically updates existing releases with CHANGELOG if missing
  - Both workflows now ensure all releases always have CHANGELOG notes
  - Better error handling and reporting in release workflows

### Fixed
- **Release Synchronization**:
  - Fixed issue where releases could be created without CHANGELOG content
  - Ensures all releases are properly documented with changelog entries
  - Automatic retroactive update of existing releases

## [0.0.5] - 2025-01-15

### Added
- **Comprehensive Test Coverage**:
  - Added `LoginAttemptTest` for complete entity coverage
  - Added `DatabaseRateLimiterTest` with all scenarios (blocked, not blocked, max attempts, etc.)
  - Added `DatabaseRateLimiterFactoryTest` for factory pattern
  - Tests cover all methods and edge cases
  - Ensures 100% code coverage requirement

### Changed
- **Release Workflow Improvements**:
  - Improved `sync-releases.yml` workflow to use GitHub Script instead of jq for better reliability
  - Fixed output variable names in workflow
  - Better error handling and summary reporting

### Fixed
- **Workflow Reliability**:
  - Fixed sync-releases workflow to properly detect and create missing releases
  - Improved changelog pattern matching in release workflows

## [0.0.4] - 2025-01-15

### Added
- **Automated Release Management**:
  - New `sync-releases.yml` workflow to automatically find and create releases for tags without releases
  - Workflow can be triggered manually via GitHub Actions UI
  - Automatic daily check at 2 AM UTC for missing releases
  - Backup trigger when new tags are pushed
  - Automatically extracts changelog entries and tag messages for releases

### Changed
- **Release Workflow Improvements**:
  - Improved CHANGELOG pattern matching in `release.yml` workflow
  - Fixed regex pattern to properly escape dots in version numbers
  - Better error messages when changelog entries are not found
- **Internationalization (i18n) Support**:
  - Added translation files for Spanish (`messages.es.yaml`) and English (`messages.en.yaml`)
  - Translation keys under `nowo_login_throttle` domain for error and info messages
  - Support for displaying login attempt information in multiple languages
  - `symfony/translation` added as suggested dependency for i18n support

- **Login Attempt Information Service**:
  - New `LoginThrottleInfoService` to retrieve login attempt information for display
  - Service provides current attempts, remaining attempts, blocking status, and retry time
  - Automatic detection of tracking type (by IP or by username/email)
  - Support for both database and cache storage backends

- **Enhanced Repository Methods**:
  - Added `countAttemptsByIp()` method to count attempts by IP address only
  - Added `countAttemptsByUsername()` method to count attempts by username/email only
  - Updated `getAttempts()` to support filtering by IP only or username only

- **Improved User Experience**:
  - Login error messages now display attempt count and remaining attempts
  - Different messages for IP-based vs email-based tracking
  - Visual feedback showing when account is blocked and retry time
  - Messages automatically adapt based on tracking method (IP or email)

- **Demo Project Enhancements**:
  - Updated login templates to use translations from the bundle
  - Added attempt information display in error messages
  - Fixed API login route to use named routes instead of hardcoded paths
  - Improved form handling for API login with JSON submission

### Changed
- **Repository Methods**:
  - `getAttempts()` now accepts empty string for IP to filter by username only
  - `getAttempts()` now accepts null for username to filter by IP only

- **Service Configuration**:
  - `LoginThrottleInfoService` automatically detects tracking type based on username availability
  - Service returns `tracking_type` field indicating whether tracking is by 'ip' or 'username'

### Fixed
- **Demo Project**:
  - Fixed API login form to use named route `api_login` instead of hardcoded path
  - Fixed migration to include `roles` column in `users` table
  - Fixed migration to create `login_attempts` table with all required columns
  - Improved Makefile to handle schema update errors gracefully

### Testing
- Added comprehensive test suite for `LoginThrottleInfoService`
- Added tests for new repository methods (`countAttemptsByIp`, `countAttemptsByUsername`)
- Tests cover all tracking scenarios (IP-based, username-based, blocked accounts)
- Maintained 100% code coverage requirement

## [0.0.3] - 2025-12-30

### Added
- **Demo Project Improvements**:
  - Added navigation links between different firewall login pages
  - Each login page now includes links to test other firewalls (Main, API, Admin)
  - Navigation shows configuration summary (max attempts, timeout) for each firewall
  - Improved Makefile with step-by-step database setup messages
  - Added `doctrine:schema:update` to automatically create `login_attempts` table from entity mapping
  - Fixtures now load automatically when running `make up-symfony7`

### Fixed
- **Demo Database Setup**:
  - Fixed migration to use `doctrine:schema:update` for automatic `login_attempts` table creation
  - Removed manual `login_attempts` table creation from migration (now created automatically from entity)
  - Improved error handling in Makefile for database setup
  - Fixed `AppFixtures.php` to include demo users (demo@example.com and admin@example.com)

### Changed
- **Demo Project Configuration**:
  - Updated `composer.json` database script to include `doctrine:schema:update --force --complete`
  - Makefile now executes database commands step-by-step with informative messages
  - Better user experience when setting up the demo project

## [0.0.2] - 2025-12-30

### Fixed
- **Critical Bug Fix**: Corrected `RateLimit` namespace import in `DatabaseRateLimiter`
  - Changed from incorrect `Symfony\Component\HttpFoundation\RateLimiter\RateLimit` 
  - To correct `Symfony\Component\RateLimiter\RateLimit`
  - This fix resolves fatal errors when using database storage with Symfony 7.x
  - Affects: `DatabaseRateLimiter` class when `storage='database'` is configured

### Changed
- **Demo Project Improvements**:
  - Added `bin/console` file for Symfony demo project
  - Updated `docker-compose.yml` to properly load `.env` file
  - Updated `composer.json` to use Symfony ^7.4 (fixes security advisories)
  - Fixed `doctrine.yaml` configuration (removed invalid options)
  - Improved `Makefile` to handle `bin/console` existence checks
  - Updated `.env.example` with proper environment variables

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

