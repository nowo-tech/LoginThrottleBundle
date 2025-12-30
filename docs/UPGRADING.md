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

### Getting Help

If you encounter issues during upgrade:

1. Check the [CHANGELOG.md](CHANGELOG.md) for known issues
2. Review the [CONFIGURATION.md](CONFIGURATION.md) for configuration examples
3. Open an issue on [GitHub](https://github.com/nowo-tech/login-throttle-bundle/issues)

## Version Compatibility

| Bundle Version | Symfony Version | PHP Version | Features |
|---------------|-----------------|-------------|----------|
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

