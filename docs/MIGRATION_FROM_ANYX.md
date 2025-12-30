# Migration Guide: From anyx/login-gate-bundle to nowo-tech/login-throttle-bundle

This guide will help you migrate from the deprecated `anyx/login-gate-bundle` to `nowo-tech/login-throttle-bundle`, which uses Symfony's native `login_throttling` feature.

## Table of Contents

- [Overview](#overview)
- [Why Migrate?](#why-migrate)
- [Prerequisites](#prerequisites)
- [Step-by-Step Migration](#step-by-step-migration)
- [Configuration Mapping](#configuration-mapping)
- [Code Changes](#code-changes)
- [Storage Migration](#storage-migration)
- [Testing Your Migration](#testing-your-migration)
- [Troubleshooting](#troubleshooting)
- [FAQ](#faq)

## Overview

The `anyx/login-gate-bundle` is deprecated because Symfony 5.2+ includes native login throttling functionality. This bundle provides a drop-in replacement that:

- Uses Symfony's native `login_throttling` feature
- Maintains compatibility with your existing configuration
- Provides the same security benefits
- Is actively maintained and follows Symfony best practices

## Why Migrate?

1. **Deprecated Package**: `anyx/login-gate-bundle` is no longer maintained
2. **Native Support**: Symfony's native implementation is more efficient and better integrated
3. **Better Performance**: Uses Symfony's rate limiter component with optimized caching
4. **Future-Proof**: Compatible with Symfony 6.0, 7.0, and 8.0
5. **Active Maintenance**: Regular updates and security patches

## Prerequisites

Before starting the migration, ensure you have:

- PHP >= 8.1
- Symfony >= 6.0 (or >= 5.2 if you're on Symfony 5.x)
- Composer installed
- Backup of your current configuration

## Step-by-Step Migration

### Step 1: Remove the Old Bundle

```bash
composer remove anyx/login-gate-bundle
```

### Step 2: Remove Bundle Registration

Remove the bundle from `config/bundles.php`:

```php
<?php

return [
    // Remove this line:
    // Anyx\LoginGateBundle\LoginGateBundle::class => ['all' => true],
    
    // ... other bundles
];
```

### Step 3: Install the New Bundle

```bash
composer require nowo-tech/login-throttle-bundle
```

The bundle will be automatically registered if you're using Symfony Flex. Otherwise, add it to `config/bundles.php`:

```php
<?php

return [
    // ... other bundles
    Nowo\LoginThrottleBundle\NowoLoginThrottleBundle::class => ['all' => true],
];
```

### Step 4: Update Configuration

#### Before (anyx/login-gate-bundle)

```yaml
# config/packages/login_gate.yaml
login_gate:
    storages: ['orm']  # or 'session', 'memcached', 'redis'
    options:
        max_count_attempts: 3
        timeout: 600
        watch_period: 3600
```

#### After (nowo-tech/login-throttle-bundle)

```yaml
# config/packages/nowo_login_throttle.yaml
nowo_login_throttle:
    enabled: true
    max_count_attempts: 3
    timeout: 600          # Ban period in seconds (600 = 10 minutes)
    watch_period: 3600    # Period for tracking attempts (informational)
    firewall: 'main'      # Firewall name
    rate_limiter: null    # Optional: Custom rate limiter service ID
    cache_pool: 'cache.rate_limiter'  # Cache pool for rate limiter state
    lock_factory: null    # Optional: Lock factory service ID
```

### Step 5: Configure Security

Run the automatic configuration command:

```bash
php bin/console nowo:login-throttle:configure-security
```

Or manually update `config/packages/security.yaml`:

```yaml
security:
    firewalls:
        main:
            login_throttling:
                max_attempts: 3
                interval: '10 minutes'
```

### Step 6: Remove Old Configuration Files

Delete the old configuration file:

```bash
rm config/packages/login_gate.yaml
```

### Step 7: Update Database (if using ORM storage)

If you were using ORM storage with `anyx/login-gate-bundle`, you have two options:

**Option 1: Continue using Database Storage (Recommended)**

Configure the new bundle to use database storage:

```yaml
# config/packages/nowo_login_throttle.yaml
nowo_login_throttle:
    storage: 'database'  # Use database storage like the old bundle
    # ... other configuration
```

Then run migrations to create the `login_attempts` table:
```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

See [DATABASE_STORAGE.md](DATABASE_STORAGE.md) for complete instructions.

**Option 2: Switch to Cache Storage**

If you want to use cache storage (default, more efficient), you can remove old database tables (only if you're certain they're not used elsewhere):

```sql
-- Only if you're certain these tables are not used
DROP TABLE IF EXISTS login_attempt;
DROP TABLE IF EXISTS login_gate;
```

**Note**: The new bundle uses cache by default, but you can use database storage if you prefer (or need it for auditing purposes).

### Step 8: Clear Cache

```bash
php bin/console cache:clear
```

## Configuration Mapping

| anyx/login-gate-bundle | nowo-tech/login-throttle-bundle | Notes |
|------------------------|----------------------------------|-------|
| `storages: ['orm']` | `storage: 'database'` | Uses database storage (requires Doctrine ORM). See [DATABASE_STORAGE.md](DATABASE_STORAGE.md) |
| `storages: ['session']` | `storage: 'cache'` | Uses Symfony cache (session not needed) |
| `storages: ['memcached']` | `storage: 'cache'` + `cache_pool: 'cache.memcached'` | Configure Memcached cache pool |
| `storages: ['redis']` | `storage: 'cache'` + `cache_pool: 'cache.redis'` | Configure Redis cache pool |
| `options.max_count_attempts` | `max_count_attempts` | Same value |
| `options.timeout` | `timeout` | Same value (in seconds) |
| `options.watch_period` | `watch_period` | Same value (informational) |
| N/A | `firewall` | Specify firewall name |
| N/A | `rate_limiter` | Optional custom rate limiter |
| N/A | `lock_factory` | Optional lock factory for distributed systems |

## Code Changes

### No Code Changes Required!

The new bundle works automatically with Symfony's security system. You don't need to modify your controllers or authentication logic.

#### Before (anyx/login-gate-bundle)

The old bundle required manual integration in some cases:

```php
// Old bundle might have required manual checks
use Anyx\LoginGateBundle\Service\BruteForceChecker;

// This is no longer needed
```

#### After (nowo-tech/login-throttle-bundle)

No code changes needed! Symfony handles everything automatically:

```php
// Nothing to add - it just works!
// Symfony's security system handles throttling automatically
```

## Storage Migration

### From ORM Storage

If you were using ORM storage with `anyx/login-gate-bundle`, you can now use database storage with the new bundle:

**Option 1: Use Database Storage (Recommended for ORM migration)**

```yaml
# config/packages/nowo_login_throttle.yaml
nowo_login_throttle:
    enabled: true
    storage: 'database'  # Use database storage like the old bundle
    max_count_attempts: 3
    timeout: 600
    watch_period: 3600
    firewall: 'main'
```

Then run migrations to create the `login_attempts` table:
```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

See [DATABASE_STORAGE.md](DATABASE_STORAGE.md) for complete instructions.

**Option 2: Use Cache Storage (Recommended for new projects)**

The new bundle uses Symfony's cache system by default, which is more efficient:

**Migration Steps:**

1. **Configure Cache Pool** (if not already configured):

```yaml
# config/packages/cache.yaml
framework:
    cache:
        pools:
            cache.rate_limiter:
                adapter: cache.adapter.filesystem
                # Or use Redis/Memcached for distributed systems
```

2. **For Distributed Systems** (Kubernetes, multiple servers):

```yaml
# config/packages/cache.yaml
framework:
    cache:
        pools:
            cache.rate_limiter:
                adapter: cache.adapter.redis
                provider: cache.redis_provider
```

See [SERVICES.md](SERVICES.md) for detailed examples.

### From Session Storage

Session storage is no longer needed. The bundle uses cache, which is more efficient and works across multiple servers.

### From Memcached/Redis Storage

If you were using Memcached or Redis, configure the cache pool accordingly:

```yaml
# config/packages/nowo_login_throttle.yaml
nowo_login_throttle:
    cache_pool: 'cache.redis'  # or 'cache.memcached'
```

## Testing Your Migration

### 1. Test Login Throttling

1. Try to log in with incorrect credentials multiple times
2. After the maximum attempts (default: 3), you should see a throttling message
3. Wait for the timeout period (default: 10 minutes)
4. Try to log in again - it should work

### 2. Verify Configuration

```bash
# Check bundle configuration
php bin/console debug:config nowo_login_throttle

# Check security configuration
php bin/console debug:config security
```

### 3. Test with Different Firewalls

If you have multiple firewalls, ensure each one is configured correctly:

```yaml
# config/packages/nowo_login_throttle.yaml
nowo_login_throttle:
    firewall: 'api'  # or 'admin', etc.
```

Then run the configuration command again:

```bash
php bin/console nowo:login-throttle:configure-security
```

## Troubleshooting

### Issue: Login throttling not working after migration

**Solution:**
1. Verify the bundle is enabled: `php bin/console debug:config nowo_login_throttle`
2. Check security.yaml has `login_throttling` configured
3. Clear cache: `php bin/console cache:clear`
4. Verify firewall name matches: `php bin/console debug:firewall`

### Issue: "Rate limiter not found" error

**Solution:**
1. Ensure Symfony rate limiter component is installed: `composer require symfony/rate-limiter`
2. Configure cache pool: See [SERVICES.md](SERVICES.md)
3. Clear cache: `php bin/console cache:clear`

### Issue: Throttling not working across multiple servers

**Solution:**
Use a shared cache (Redis/Memcached) instead of file cache:

```yaml
# config/packages/nowo_login_throttle.yaml
nowo_login_throttle:
    cache_pool: 'cache.redis'  # or 'cache.memcached'
```

For Kubernetes, also enable lock factory:

```yaml
nowo_login_throttle:
    cache_pool: 'cache.redis'
    lock_factory: 'lock.factory'
```

### Issue: Old database tables still exist

**Solution:**
The old bundle's database tables are no longer needed. You can safely remove them:

```sql
DROP TABLE IF EXISTS login_attempt;
DROP TABLE IF EXISTS login_gate;
```

**Warning**: Only do this if you're certain these tables are not used by other parts of your application.

### Issue: Configuration command not found

**Solution:**
1. Clear cache: `php bin/console cache:clear`
2. Verify bundle is installed: `composer show nowo-tech/login-throttle-bundle`
3. Check bundle is registered: `php bin/console debug:container | grep login.throttle`

## FAQ

### Q: Do I need to migrate my database?

**A:** No. The new bundle uses Symfony's cache system instead of database tables. You can optionally remove old database tables if they're no longer needed.

### Q: Will my existing configuration work?

**A:** Yes! The configuration options are compatible. You just need to update the file name and structure slightly.

### Q: Do I need to change my code?

**A:** No code changes are required. The bundle works automatically with Symfony's security system.

### Q: What about custom storage backends?

**A:** The bundle supports two storage backends:
- **Cache**: Symfony's cache system supports multiple backends (file, Redis, Memcached). Configure the appropriate cache pool.
- **Database**: Use `storage: 'database'` to store attempts in a database table via Doctrine ORM. See [DATABASE_STORAGE.md](DATABASE_STORAGE.md).

### Q: Can I use a custom rate limiter?

**A:** Yes! Set the `rate_limiter` option to your custom rate limiter service ID.

### Q: Is this bundle compatible with Symfony 5.x?

**A:** The bundle requires Symfony 6.0+, but Symfony 5.2+ has native login throttling. If you're on Symfony 5.x, you can configure it manually without this bundle.

### Q: What happens to existing login attempts in the database?

**A:** 
- If you're migrating to **cache storage**: Existing login attempts in the database are no longer used. Old database records can be safely removed after migration.
- If you're using **database storage**: You can continue using your existing database. The new bundle uses the `login_attempts` table (which may be different from the old bundle's table name).

### Q: How do I configure for Kubernetes/multiple servers?

**A:** Use Redis or Memcached cache pool and enable lock factory. See [SERVICES.md](SERVICES.md) for detailed examples.

### Q: Can I customize the throttling behavior?

**A:** Yes! You can:
- Set custom `max_count_attempts` and `timeout`
- Use a custom rate limiter
- Configure different cache pools
- Enable lock factory for distributed systems

## Additional Resources

- [README.md](../README.md) - Bundle overview and quick start
- [CONFIGURATION.md](CONFIGURATION.md) - Detailed configuration guide
- [SERVICES.md](SERVICES.md) - Service configuration examples (Docker, Kubernetes)
- [UPGRADING.md](UPGRADING.md) - Upgrade guide between bundle versions
- [Symfony Security Documentation](https://symfony.com/doc/current/security.html#limiting-login-attempts) - Official Symfony documentation

## Need Help?

If you encounter issues during migration:

1. Check the [Troubleshooting](#troubleshooting) section
2. Review the [FAQ](#faq)
3. Check the [documentation](../README.md)
4. Open an issue on [GitHub](https://github.com/nowo-tech/login-throttle-bundle/issues)

## Summary

Migrating from `anyx/login-gate-bundle` to `nowo-tech/login-throttle-bundle` is straightforward:

1. ✅ Remove old bundle
2. ✅ Install new bundle
3. ✅ Update configuration (compatible format)
4. ✅ Run configuration command
5. ✅ Clear cache
6. ✅ Test login throttling

**No code changes required!** The bundle works automatically with Symfony's security system.

---

**Migration completed successfully?** Consider giving the bundle a ⭐ on GitHub!

