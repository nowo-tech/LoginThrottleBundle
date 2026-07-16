# Upgrade Guide

This guide provides step-by-step instructions for upgrading the Login Throttle Bundle between versions.

## General Upgrade Process

1. **Backup your configuration**: Always backup your `config/packages/nowo_login_throttle.yaml` file before upgrading
2. **Check the changelog**: Review [CHANGELOG.md](CHANGELOG.md) for breaking changes in the target version
3. **Update composer**: Run `composer update nowo-tech/login-throttle-bundle`
4. **Update configuration**: Apply any configuration changes required for the new version
5. **Clear cache**: Run `php bin/console cache:clear`
6. **Test your application**: Verify that login throttling functionality works as expected

## Upgrade Instructions by Version

### Upgrading to 2.2.0

**Release Date**: 2026-07-16

#### What's New

- **Maintainer git hygiene** — CI and hooks enforce no Cursor co-author trailers ([`GITHUB_CI.md`](GITHUB_CI.md)).
- **Single demo** — Only `demo-symfony8` ships in the repository.
- **Code of Conduct** — Community standards in `CODE_OF_CONDUCT.md`.

#### Breaking Changes

None for application integrators — No changes to bundle configuration, public API, or runtime requirements (PHP 8.2+, Symfony 7.0+).

Contributors/maintainers cloning this repo should run `make setup-hooks` so local commits respect REQ-GIT-001.

#### Upgrade Steps

1. **Update the bundle**:
   ```bash
   composer update nowo-tech/login-throttle-bundle
   ```

2. **Clear cache**:
   ```bash
   php bin/console cache:clear
   ```

3. **Test login throttling** on all configured firewalls.

If you previously used `demo/demo-symfony7` from this repository, switch to `demo/demo-symfony8`.

### Upgrading to 2.1.0

**Release Date**: 2026-07-09

#### What's New

- **GitHub Spec Kit** for maintainers — baseline spec under `specs/001-baseline/` and workflow docs in [`SPEC-KIT.md`](SPEC-KIT.md).
- **Demo Docker fixes** — `intl` extension in Symfony 7 and 8 demo images.

#### Breaking Changes

None — No changes to bundle configuration, public API, or runtime requirements.

#### Upgrade Steps

1. **Update the bundle**:
   ```bash
   composer update nowo-tech/login-throttle-bundle
   ```

2. **Clear cache**:
   ```bash
   php bin/console cache:clear
   ```

3. **Test login throttling** on all configured firewalls.

### Upgrading to 2.0.0

**Release Date**: 2026-07-02

#### What's New

- **Modern stack baseline** — Aligns with Symfony 7 LTS and PHP 8.2+.
- **CI reliability** — Full test matrix on supported PHP/Symfony versions with enforced 100% coverage.
- **Leaner demos** — Symfony 7 and 8 demo applications only (`demo-symfony7`, `demo-symfony8`).

#### Breaking Changes

- **PHP 8.2+ required** — PHP 8.1 is no longer supported.
- **Symfony 7.0+ required** — Symfony 6.x is no longer supported. Symfony 8.x remains supported.
- **No configuration migration** — Existing `nowo_login_throttle.yaml` and `security.yaml` settings remain valid; only platform requirements change.

#### Upgrade Steps

1. **Upgrade PHP and Symfony** in your application before updating the bundle:
   ```bash
   php -v   # Must be >= 8.2
   composer show symfony/framework-bundle   # Must be >= 7.0
   ```

2. **Update the bundle**:
   ```bash
   composer require nowo-tech/login-throttle-bundle:^2.0
   ```

3. **Clear cache**:
   ```bash
   php bin/console cache:clear
   ```

4. **Test login throttling** on all configured firewalls.

If you cannot upgrade to PHP 8.2 or Symfony 7 yet, stay on `nowo-tech/login-throttle-bundle` `^1.0`.

### Upgrading to 1.0.0

**Release Date**: 2025-07-02

#### What's New

- **First stable release** — The bundle API is declared stable. All `0.0.x` configuration remains valid; no migration is required for existing projects.
- **Symfony Flex recipe 1.0** — New installs via Flex get bundle registration and default configuration automatically.
- **Quality tooling** — PHPStan, Rector, and `make release-check` for maintainers and contributors.
- **Expanded demos** — Symfony 7.4 and 8.1 demo applications with Docker setup.
- **Documentation** — Clarified `watch_period` semantics, database cleanup workflow, and demo `security.yaml` setup.

#### Breaking Changes

None — This release marks API stability without removing or renaming configuration options from `0.0.15`.

#### Upgrade Steps

1. **Update the bundle**:
   ```bash
   composer update nowo-tech/login-throttle-bundle
   ```

2. **Clear cache**:
   ```bash
   php bin/console cache:clear
   ```

3. **(Optional) Refresh `security.yaml` service IDs** — If you use database storage and want human-readable limiter service names:
   ```bash
   php bin/console nowo:login-throttle:configure-security --force
   ```

4. **Test your application** — Verify login throttling on all configured firewalls.

#### Notes for `0.0.x` Users

- Composer constraint `^0.0` continues to resolve `1.0.0` (semver-compatible).
- To opt into the stable line explicitly, update your `composer.json`:
  ```json
  "nowo-tech/login-throttle-bundle": "^1.0"
  ```

### Upgrading to 0.0.15

**Release Date**: 2025-01-15

#### What's New

- **Human-Readable Rate Limiter Service Names**: Database rate limiter services now use descriptive names instead of MD5 hashes
  - Old format: `nowo_login_throttle.database_rate_limiter.shared_140eca5c8f17f7276926bf9f93b6d859`
  - New format: `nowo_login_throttle.database_rate_limiter.shared_3_600s_3600s` (3 attempts, 600s timeout, 3600s watch period)
  - Service names now clearly show the configuration values
  - Existing configurations will continue to work, but new configurations will use the new naming format

- **Countdown Timer Documentation**: Comprehensive guide for implementing real-time countdown timers
  - See `docs/TRANSLATIONS.md#countdown-timer-cuenta-regresiva` for complete implementation guide
  - Includes JavaScript examples and customization options

#### Breaking Changes

None - This is a minor release with improvements and documentation updates.

#### Upgrade Steps

1. **Update the bundle**:
   ```bash
   composer update nowo-tech/login-throttle-bundle
   ```

2. **Clear cache**:
   ```bash
   php bin/console cache:clear
   ```

3. **Update service names (optional)**: If you have manually configured rate limiter service IDs in `security.yaml`, you can regenerate them using the new human-readable format:
   ```bash
   php bin/console nowo:login-throttle:configure-security --force
   ```
   
   This will update service IDs to the new descriptive format (e.g., `shared_3_600s_3600s` instead of hash-based names).

4. **No configuration changes required** - Existing configurations will continue to work as-is.

#### New Features Usage

**Using Human-Readable Service Names**:

When using database storage with multiple firewalls, the bundle automatically generates descriptive service IDs:

```yaml
# config/packages/security.yaml
security:
    firewalls:
        main:
            login_throttling:
                limiter: nowo_login_throttle.database_rate_limiter.shared_3_600s_3600s
```

The service name format is: `shared_{max_attempts}_{timeout}s_{watch_period}s`

**Implementing Countdown Timer**:

See `docs/TRANSLATIONS.md` for complete examples on implementing real-time countdown timers in your login templates.

### Upgrading to 0.0.14

**Release Date**: 2025-01-15

#### What's New

- **Test Suite Improvements**: All tests now pass successfully
  - Fixed compatibility issues with PHPUnit 10
  - Improved test reliability and error handling
  - Better mocking strategies for Doctrine ORM components

#### Breaking Changes

None - This is a patch release with test fixes and improvements.

#### Upgrade Steps

1. **Update the bundle**:
   ```bash
   composer update nowo-tech/login-throttle-bundle
   ```

2. **Clear cache**:
   ```bash
   php bin/console cache:clear
   ```

3. **No configuration changes required** - This release only includes test improvements.

### Upgrading to 0.0.13

**Release Date**: 2025-01-15

#### What's New

- **Extended Translation Support**: Added 20 additional language translations
  - The bundle now supports 24 languages total (English, Spanish, and 22 additional languages)
  - All new translations are automatically available in `src/Resources/translations/`
  - No configuration changes required - translations are loaded automatically based on Symfony's locale settings

#### Breaking Changes

None - This is a minor release with new translations and bug fixes.

#### Upgrade Steps

1. **Update the bundle**:
   ```bash
   composer update nowo-tech/login-throttle-bundle
   ```

2. **Clear cache**:
   ```bash
   php bin/console cache:clear
   ```

3. **Verify translations** (optional):
   - Check that translations are available in `vendor/nowo-tech/login-throttle-bundle/src/Resources/translations/`
   - If you have custom translations, ensure they follow the same structure

#### New Features

- **24 Language Support**: The bundle now includes translations for:
  - English (en), Spanish (es), French (fr), German (de), Italian (it)
  - Portuguese (pt), Brazilian Portuguese (pt_BR), Dutch (nl), Polish (pl), Russian (ru)
  - Simplified Chinese (zh_CN), Traditional Chinese (zh_TW), Japanese (ja), Korean (ko)
  - Arabic (ar), Turkish (tr), Czech (cs), Swedish (sv), Norwegian (no)
  - Danish (da), Finnish (fi), Greek (el), Hungarian (hu), Romanian (ro)

- **Automatic Translation Loading**: Translations are automatically loaded based on your Symfony application's locale configuration

### Upgrading to 0.0.12

**Release Date**: 2025-01-15

#### What's New

- **Release Workflow Improvements**: Better error handling for release synchronization
- **PHPUnit 10 Compatibility**: Full compatibility with PHPUnit 10.x test framework
- **Demo Enhancements**: Added development tools (Web Profiler, Debug Bundle, phpMyAdmin)
- **Translation Fixes**: Improved translation file structure

#### Breaking Changes

None - This is a patch release with bug fixes and improvements.

#### Upgrade Steps

1. **Update composer**:
   ```bash
   composer update nowo-tech/login-throttle-bundle
   ```

2. **Clear cache**:
   ```bash
   php bin/console cache:clear
   ```

No configuration changes required. This release only includes bug fixes, test improvements, and demo enhancements.

### Upgrading to 0.0.11

**Release Date**: 2025-01-15

#### What's New

- **Workflow Fixes**: Fixed critical JavaScript syntax errors in GitHub Actions workflows
- **Release Reliability**: Improved release creation and synchronization workflows
- **Better Error Handling**: Enhanced workflow error handling and logging

#### Breaking Changes

None - This is a patch release with workflow fixes and improvements.

#### Upgrade Steps

1. **Update composer**:
   ```bash
   composer update nowo-tech/login-throttle-bundle
   ```

2. **Clear cache**:
   ```bash
   php bin/console cache:clear
   ```

No configuration changes required. This release only includes workflow fixes and improvements.

### Upgrading to 0.0.10

**Release Date**: 2025-01-15

#### What's New

- **Symfony 7 Compatibility**: Fixed compatibility issues with Symfony 7.x rate limiter component
- **Configuration Fixes**: Improved handling of empty firewalls configuration
- **Test Suite Improvements**: Enhanced test reliability and compatibility

#### Breaking Changes

None - This is a patch release with bug fixes and compatibility improvements.

#### Upgrade Steps

1. **Update composer**:
   ```bash
   composer update nowo-tech/login-throttle-bundle
   ```

2. **Clear cache**:
   ```bash
   php bin/console cache:clear
   ```

No configuration changes required. This release only includes compatibility fixes and test improvements.

### Upgrading to 0.0.9

**Release Date**: 2025-01-15

#### What's New

- **Workflow Improvements**: Fixed tag fetching and JavaScript syntax errors in GitHub Actions workflows
- **Admin Firewall Fix**: Fixed access denied error after failed login attempts in admin firewall
- **Translation Support**: Enhanced translation display with proper parameter handling and default values
- **Service Improvements**: Better error handling when repository is not available

#### Breaking Changes

None - This is a patch release with bug fixes and improvements.

#### Upgrade Steps

1. **Update composer**:
   ```bash
   composer update nowo-tech/login-throttle-bundle
   ```

2. **Clear cache**:
   ```bash
   php bin/console cache:clear
   ```

3. **If using admin firewall**, verify your `config/packages/security.yaml` has `failure_path` configured:
   ```yaml
   admin:
       form_login:
           login_path: admin_login
           check_path: admin_login
           default_target_path: admin_home
           failure_path: admin_login  # Add this if missing
   ```

4. **If using translations**, ensure your `config/packages/framework.yaml` has translator enabled:
   ```yaml
   framework:
       translator:
           enabled: true
           fallbacks:
               - en
   ```

No other configuration changes required. This release focuses on bug fixes and translation improvements.

### Upgrading to 0.0.8

**Release Date**: 2025-01-15

#### What's New

- **Workflow Fixes**: Fixed critical YAML syntax errors that prevented GitHub Actions workflows from executing. Release workflows now work correctly.

#### Breaking Changes

None - This is a patch release with critical bug fixes.

#### Upgrade Steps

1. **Update composer**:
   ```bash
   composer update nowo-tech/login-throttle-bundle
   ```

2. **Clear cache**:
   ```bash
   php bin/console cache:clear
   ```

No configuration changes required. This release only includes workflow fixes.

### Upgrading to 0.0.7

**Release Date**: 2025-01-15

#### What's New

- **Workflow Fixes**: Fixed YAML syntax errors in GitHub Actions workflows that were preventing proper execution.

- **Entity Improvements**: Added explicit column name mappings to the `LoginAttempt` entity for better database compatibility.

#### Breaking Changes

None - This is a patch release with bug fixes and improvements.

#### Upgrade Steps

1. **Update composer**:
   ```bash
   composer update nowo-tech/login-throttle-bundle
   ```

2. **Clear cache**:
   ```bash
   php bin/console cache:clear
   ```

3. **Update database schema** (if needed):
   ```bash
   php bin/console doctrine:schema:update --force
   ```
   Or run migrations if you're using Doctrine Migrations.

No configuration changes required. This release only includes bug fixes and improvements.

### Upgrading to 0.0.6

**Release Date**: 2025-01-15

#### What's New

- **Enhanced Release Management**: GitHub Actions workflows now automatically ensure all releases include CHANGELOG notes. The `sync-releases.yml` workflow will automatically update existing releases that are missing changelog content.

- **Improved Release Workflows**: Both `release.yml` and `sync-releases.yml` workflows have been enhanced to:
  - Check if releases already exist before creating
  - Update existing releases with CHANGELOG if missing
  - Provide better reporting on what actions were taken

#### Breaking Changes

None - This is a patch release with workflow improvements only.

#### Upgrade Steps

1. **Update composer**:
   ```bash
   composer update nowo-tech/login-throttle-bundle
   ```

2. **Clear cache**:
   ```bash
   php bin/console cache:clear
   ```

No configuration changes required. This release only improves GitHub Actions workflows for release management.

**Note**: If you run the `sync-releases.yml` workflow manually or it runs automatically, it will update any existing releases that are missing CHANGELOG notes.

### Upgrading to 0.0.5

**Release Date**: 2025-01-15

#### What's New

- **Enhanced Test Coverage**: Complete test suite now covers all classes including Entity, RateLimiter, and Factory classes, ensuring 100% code coverage.

- **Improved Release Workflows**: Better reliability and error handling in automated release management workflows.

#### Breaking Changes

None - This is a patch release with test improvements and workflow enhancements.

#### Upgrade Steps

1. **Update composer**:
   ```bash
   composer update nowo-tech/login-throttle-bundle
   ```

2. **Clear cache**:
   ```bash
   php bin/console cache:clear
   ```

No configuration changes required. This release only improves test coverage and workflow reliability.

### Upgrading to 0.0.4

**Release Date**: 2025-01-15

#### What's New

- **Internationalization (i18n) Support**: The bundle now includes translation files for Spanish and English. Messages for login attempt information are automatically translated based on your application's locale.

- **Login Attempt Information Display**: When login fails, users now see helpful information including:
  - Current number of attempts
  - Remaining attempts before blocking
  - Whether the account is blocked
  - When they can try again (if blocked)

- **Smart Tracking Detection**: The system automatically detects whether throttling is tracked by IP address or by email/username, and displays appropriate messages.

- **Enhanced Repository Methods**: New methods for more granular control over attempt counting.

- **Automated Release Management**: New GitHub Actions workflow (`sync-releases.yml`) that automatically detects and creates releases for tags that don't have releases. This ensures all tags are properly documented with releases.

#### Breaking Changes

None - This is a minor release with new features. All existing functionality remains backward compatible.

#### Upgrade Steps

1. **Update composer**:
   ```bash
   composer update nowo-tech/login-throttle-bundle
   ```

2. **Install translation component (if not already installed)**:
   ```bash
   composer require symfony/translation
   ```
   
   > **Note**: The translation component is optional but recommended for i18n support. The bundle will work without it, but messages will not be translated.

3. **Configure translator in `config/packages/framework.yaml`** (if not already configured):
   ```yaml
   framework:
       translator:
           default_path: '%kernel.project_dir%/translations'
           fallbacks:
               - en
   ```

4. **Clear cache**:
   ```bash
   php bin/console cache:clear
   ```

5. **Update your login templates (optional)**:
   
   If you want to display attempt information in your login templates, inject the `LoginThrottleInfoService` in your controller:
   
   ```php
   use Nowo\LoginThrottleBundle\Service\LoginThrottleInfoService;
   
   public function login(AuthenticationUtils $authenticationUtils, Request $request, LoginThrottleInfoService $throttleInfoService): Response
   {
       $error = $authenticationUtils->getLastAuthenticationError();
       $attemptInfo = null;
       
       if ($error) {
           $attemptInfo = $throttleInfoService->getAttemptInfo('main', $request);
       }
       
       return $this->render('security/login.html.twig', [
           'error' => $error,
           'attempt_info' => $attemptInfo,
       ]);
   }
   ```
   
   Then in your template, use the translations:
   
   ```twig
   {% if error and attempt_info %}
       {% if attempt_info.is_blocked %}
           ⚠️ {{ 'nowo_login_throttle.error.account_blocked'|trans({'%max_attempts%': attempt_info.max_attempts}, 'nowo_login_throttle') }}
       {% else %}
           📊 {% if attempt_info.tracking_type == 'username' %}
               {{ 'nowo_login_throttle.info.attempts_count_by_email'|trans({'%current%': attempt_info.current_attempts, '%max%': attempt_info.max_attempts}, 'nowo_login_throttle') }}
           {% else %}
               {{ 'nowo_login_throttle.info.attempts_count_by_ip'|trans({'%current%': attempt_info.current_attempts, '%max%': attempt_info.max_attempts}, 'nowo_login_throttle') }}
           {% endif %}
       {% endif %}
   {% endif %}
   ```

6. **Test your application**: Verify that login throttling works correctly and that attempt information is displayed when appropriate.

#### New Features Usage

**Using LoginThrottleInfoService in Controllers**:

```php
use Nowo\LoginThrottleBundle\Service\LoginThrottleInfoService;

class SecurityController extends AbstractController
{
    public function login(
        AuthenticationUtils $authenticationUtils,
        Request $request,
        LoginThrottleInfoService $throttleInfoService
    ): Response {
        $error = $authenticationUtils->getLastAuthenticationError();
        $attemptInfo = $error ? $throttleInfoService->getAttemptInfo('main', $request) : null;
        
        return $this->render('security/login.html.twig', [
            'error' => $error,
            'attempt_info' => $attemptInfo,
        ]);
    }
}
```

**Available Translation Keys**:

- `nowo_login_throttle.error.account_blocked` - Account blocked message
- `nowo_login_throttle.error.retry_after` - Retry after time message
- `nowo_login_throttle.info.attempts_count_by_ip` - Attempts count by IP
- `nowo_login_throttle.info.attempts_count_by_email` - Attempts count by email
- `nowo_login_throttle.info.remaining_attempts` - Remaining attempts message
- `nowo_login_throttle.info.last_attempt_warning` - Last attempt warning

**Adding Custom Translations**:

You can override translations by creating your own translation files in `translations/` directory with the same keys.

### Upgrading to 0.0.3

**Release Date**: 2025-12-30

#### What's New

- **Demo Project Improvements**:
  - Navigation links between different firewall login pages
  - Automatic fixtures loading on project startup
  - Improved database setup with `doctrine:schema:update`
  - Better error handling and informative messages

#### Breaking Changes

None - This is a patch release with demo improvements only.

#### Upgrade Steps

1. **Update composer**:
   ```bash
   composer update nowo-tech/login-throttle-bundle
   ```

2. **Clear cache**:
   ```bash
   php bin/console cache:clear
   ```

No configuration changes required. This release only improves the demo project.

### Upgrading to 0.0.2

**Release Date**: 2025-12-30

#### What's New

- **Demo Project Improvements**:
  - Added `bin/console` file for Symfony demo project
  - Updated `docker-compose.yml` to properly load `.env` file
  - Updated `composer.json` to use Symfony ^7.4 (fixes security advisories)
  - Fixed `doctrine.yaml` configuration (removed invalid options)
  - Improved `Makefile` to handle `bin/console` existence checks

#### Breaking Changes

None - This is a patch release with demo improvements only.

### Upgrading to 0.0.1 (Initial Release)

**Release Date**: 2025-12-30

#### What's New

- Initial release of Login Throttle Bundle
- Native Symfony `login_throttling` integration
- Pre-configured settings with sensible defaults
- Automatic configuration file generation
- Command to automatically configure `security.yaml`
- Support for custom rate limiters
- Configuration options compatible with `anyx/login-gate-bundle`
- **Multiple Firewalls Support**: Configure independent throttling settings for each firewall
- **Database Storage Support**: Store login attempts in database instead of cache for auditing

#### Breaking Changes

N/A - This is the initial release.

#### Configuration

Basic configuration example:

```yaml
nowo_login_throttle:
    enabled: true
    max_count_attempts: 3
    timeout: 600
    watch_period: 3600
    firewall: 'main'
    rate_limiter: null
    cache_pool: 'cache.rate_limiter'
    lock_factory: null
```

#### Upgrade Steps

1. **Install the bundle**:
   ```bash
   composer require nowo-tech/login-throttle-bundle
   ```

2. **Register the bundle** in `config/bundles.php`:
   ```php
   return [
       // ...
       Nowo\LoginThrottleBundle\NowoLoginThrottleBundle::class => ['all' => true],
   ];
   ```

3. **Configure the bundle** (configuration file is automatically generated):
   ```yaml
   # config/packages/nowo_login_throttle.yaml
   nowo_login_throttle:
       enabled: true
       max_count_attempts: 3
       timeout: 600
       firewall: 'main'
   ```

4. **Configure security.yaml**:
   ```bash
   php bin/console nowo:login-throttle:configure-security
   ```

5. **Clear cache**:
   ```bash
   php bin/console cache:clear
   ```

6. **Verify installation**:
   ```bash
   php bin/console debug:config nowo_login_throttle
   ```

#### Migration from anyx/login-gate-bundle

If you're migrating from `anyx/login-gate-bundle`:

1. **Remove the old bundle**:
   ```bash
   composer remove anyx/login-gate-bundle
   ```

2. **Install the new bundle**:
   ```bash
   composer require nowo-tech/login-throttle-bundle
   ```

3. **Update configuration**:
   - Rename `config/packages/login_gate.yaml` to `config/packages/nowo_login_throttle.yaml`
   - Update the configuration alias from `login_gate` to `nowo_login_throttle`
   - Map the old options to new options:
     ```yaml
     # Old (anyx/login-gate-bundle)
     login_gate:
         storages: ['orm']
         options:
             max_count_attempts: 3
             timeout: 600
             watch_period: 3600
     
     # New (nowo-tech/login-throttle-bundle)
     nowo_login_throttle:
         enabled: true
         max_count_attempts: 3
         timeout: 600
         watch_period: 3600
         firewall: 'main'
     ```

4. **Remove old code**:
   - Remove any `BruteForceChecker` usage from your controllers
   - Remove event listeners for `security.brute_force_attempt` if you had any
   - Remove any custom username resolvers (Symfony handles this automatically)

5. **Configure security.yaml**:
   ```bash
   php bin/console nowo:login-throttle:configure-security
   ```

6. **Clear cache**:
   ```bash
   php bin/console cache:clear
   ```

7. **Test the application**: Verify that login throttling works correctly

## Troubleshooting Upgrades

### Common Issues

#### Issue: "Unrecognized option" error after upgrade

**Solution**: Clear Symfony cache and update composer dependencies:
```bash
php bin/console cache:clear
composer update nowo-tech/login-throttle-bundle
```

#### Issue: Configuration validation errors

**Solution**: Check your configuration against the latest documentation:
```bash
php bin/console debug:config nowo_login_throttle
```

#### Issue: Services not found after upgrade

**Solution**: Clear cache and rebuild container:
```bash
php bin/console cache:clear
php bin/console cache:warmup
```

#### Issue: Login throttling not working after migration

**Solution**: 
1. Verify `security.yaml` has `login_throttling` configured:
   ```bash
   php bin/console debug:config security
   ```
2. Run the configure command:
   ```bash
   php bin/console nowo:login-throttle:configure-security
   ```
3. Clear cache:
   ```bash
   php bin/console cache:clear
   ```

#### Issue: Fatal error "Class Symfony\Component\HttpFoundation\RateLimiter\RateLimit not found" when using database storage

**Affected Versions**: v0.0.1 (initial release, before bug fix)

**Solution**: 
This bug was fixed in v0.0.1 (updated release). Update to the latest version:
```bash
composer update nowo-tech/login-throttle-bundle
```

**What was fixed**: The `DatabaseRateLimiter` class was using an incorrect namespace for `RateLimit`. The correct namespace is `Symfony\Component\RateLimiter\RateLimit` (not `Symfony\Component\HttpFoundation\RateLimiter\RateLimit`).

**Note**: This only affects users who have configured `storage: 'database'`. Users with `storage: 'cache'` (default) are not affected.

### Getting Help

If you encounter issues during upgrade:

1. Check the [CHANGELOG.md](CHANGELOG.md) for known issues
2. Review the [CONFIGURATION.md](CONFIGURATION.md) for configuration examples
3. Open an issue on [GitHub](https://github.com/nowo-tech/LoginThrottleBundle/issues)

## Version Compatibility

| Bundle Version | Symfony Version | PHP Version | Features |
|---------------|-----------------|-------------|----------|
| 2.2.0         | 7.0, 8.0, 8.1   | 8.2, 8.3, 8.4, 8.5 | Single Symfony 8 demo; REQ-GIT-001 / GITHUB_CI; Code of Conduct; no integrator API changes |
| 2.1.0         | 7.0, 8.0, 8.1   | 8.2, 8.3, 8.4, 8.5 | Spec Kit baseline; demo Docker intl fix; no integrator changes |
| 2.0.0         | 7.0, 8.0, 8.1   | 8.2, 8.3, 8.4, 8.5 | Raised minimum PHP/Symfony; removed Symfony 6 demo; 100% CI coverage; no config changes |
| 1.0.0         | 6.0, 7.0, 8.0, 8.1 | 8.1, 8.2, 8.3, 8.4, 8.5 | Stable API; Flex recipe 1.0; PHPStan/Rector; expanded demos; documentation clarifications |
| 0.0.15        | 6.0, 7.0, 8.0   | 8.1, 8.2, 8.3, 8.4, 8.5 | Human-readable DB limiter service names, countdown timer docs, demo countdown UI |
| 0.0.8         | 6.0, 7.0, 8.0   | 8.1, 8.2, 8.3, 8.4, 8.5 | Single & Multiple firewalls, Cache & Database storage, i18n support, Attempt info display, 100% test coverage, Enhanced release workflows, Fixed workflow execution |
| 0.0.7         | 6.0, 7.0, 8.0   | 8.1, 8.2, 8.3, 8.4, 8.5 | Single & Multiple firewalls, Cache & Database storage, i18n support, Attempt info display, 100% test coverage, Enhanced release workflows, Fixed workflow syntax |
| 0.0.6         | 6.0, 7.0, 8.0   | 8.1, 8.2, 8.3, 8.4, 8.5 | Single & Multiple firewalls, Cache & Database storage, i18n support, Attempt info display, 100% test coverage, Enhanced release workflows |
| 0.0.5         | 6.0, 7.0, 8.0   | 8.1, 8.2, 8.3, 8.4, 8.5 | Single & Multiple firewalls, Cache & Database storage, i18n support, Attempt info display, 100% test coverage |
| 0.0.4         | 6.0, 7.0, 8.0   | 8.1, 8.2, 8.3, 8.4, 8.5 | Single & Multiple firewalls, Cache & Database storage, i18n support, Attempt info display |
| 0.0.3         | 6.0, 7.0, 8.0   | 8.1, 8.2, 8.3, 8.4, 8.5 | Single & Multiple firewalls, Cache & Database storage, Improved demo |
| 0.0.2         | 6.0, 7.0, 8.0   | 8.1, 8.2, 8.3, 8.4, 8.5 | Single & Multiple firewalls, Cache & Database storage |
| 0.0.1         | 6.0, 7.0, 8.0   | 8.1, 8.2, 8.3, 8.4, 8.5 | Single & Multiple firewalls, Cache & Database storage |

## Additional Resources

- [CHANGELOG.md](CHANGELOG.md) - Complete version history
- [CONFIGURATION.md](CONFIGURATION.md) - Detailed configuration guide
- [SERVICES.md](SERVICES.md) - Service configuration examples for different deployment scenarios
- [CONTRIBUTING.md](CONTRIBUTING.md) - Contribution guidelines

## Notes

- Always test upgrades in a development environment first
- Keep backups of your configuration files
- Review breaking changes in the changelog before upgrading
- The bundle maintains backward compatibility within major versions (1.x.x)
- Symfony's native `login_throttling` requires Symfony 5.2 or higher

