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

### Upgrading to Next Version (Unreleased)

**Release Date**: TBD

#### What's New

- **Multiple Firewalls Support**: Added support for configuring multiple firewalls with independent throttling settings. Each firewall can have its own `max_count_attempts`, `timeout`, `storage`, and `rate_limiter` configuration.
- Database Storage Support: Added option to store login attempts in database instead of cache

#### Breaking Changes

None - The bundle maintains backward compatibility. Existing single firewall configurations continue to work.

#### New Configuration Option: Multiple Firewalls

If you have multiple firewalls and want to configure throttling independently for each, you can now use the `firewalls` configuration:

**Before (Single Firewall)**:
```yaml
nowo_login_throttle:
    enabled: true
    max_count_attempts: 3
    timeout: 600
    firewall: 'main'
```

**After (Multiple Firewalls)**:
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

**Note**: The old single firewall configuration still works. You don't need to change anything unless you want to configure multiple firewalls.

#### Upgrade Steps

1. **Update composer**:
   ```bash
   composer update nowo-tech/login-throttle-bundle
   ```

2. **Clear cache**:
   ```bash
   php bin/console cache:clear
   ```

3. **(Optional) Migrate to multiple firewalls configuration**:
   If you want to configure multiple firewalls, update your configuration as shown above. Otherwise, no changes are needed.

4. **Run configure command** (if using multiple firewalls):
   ```bash
   php bin/console nowo:login-throttle:configure-security
   ```

5. **Test your application**: Verify that login throttling works correctly for all firewalls

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
3. Open an issue on [GitHub](https://github.com/nowo-tech/login-throttle-bundle/issues)

## Version Compatibility

| Bundle Version | Symfony Version | PHP Version | Features |
|---------------|-----------------|-------------|----------|
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
- The bundle maintains backward compatibility within major versions (0.x.x)
- Symfony's native `login_throttling` requires Symfony 5.2 or higher

