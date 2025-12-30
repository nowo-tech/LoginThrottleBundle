# Configuration Guide

This document describes how to configure the Login Throttle Bundle.

For service configuration examples for different deployment scenarios (local development, Docker containers, Kubernetes), see [SERVICES.md](SERVICES.md).

## Configuration File

The bundle configuration is defined in `config/packages/nowo_login_throttle.yaml`:

```yaml
nowo_login_throttle:
    enabled: true
    max_count_attempts: 3
    timeout: 600          # Ban period in seconds (600 = 10 minutes)
    watch_period: 3600    # Period for tracking attempts (for informational purposes)
    firewall: 'main'      # Firewall name where login_throttling should be applied
    storage: 'cache'      # Storage backend: 'cache' (default) or 'database'
    rate_limiter: null    # Optional: Custom rate limiter service ID
    cache_pool: 'cache.rate_limiter'  # Cache pool for rate limiter state (only when storage=cache)
    lock_factory: null    # Optional: Lock factory service ID (only when storage=cache)
```

## Configuration Options

| Option | Type | Default | Description |
|-------|------|---------|-------------|
| `enabled` | `bool` | `true` | Enable or disable login throttling |
| `max_count_attempts` | `int` | `3` | Maximum number of login attempts before throttling (maps to `max_attempts` in Symfony `login_throttling`) |
| `timeout` | `int` | `600` | Ban period in seconds (maps to `interval` in Symfony `login_throttling`, e.g., 600 = 10 minutes) |
| `watch_period` | `int` | `3600` | Period in seconds for tracking attempts (for informational purposes, Symfony handles this automatically) |
| `firewall` | `string` | `'main'` | Firewall name where `login_throttling` should be applied |
| `storage` | `string` | `'cache'` | Storage backend: `'cache'` (uses Symfony cache) or `'database'` (stores in database via Doctrine ORM). See [DATABASE_STORAGE.md](DATABASE_STORAGE.md) for details. |
| `rate_limiter` | `string\|null` | `null` | Custom rate limiter service ID (optional). If not provided, Symfony will use default login throttling rate limiter, or database rate limiter if `storage=database` |
| `cache_pool` | `string` | `'cache.rate_limiter'` | Cache pool to use for storing the limiter state (only used when `storage=cache`) |
| `lock_factory` | `string\|null` | `null` | Lock factory service ID for rate limiter (optional, only used when `storage=cache`). Set to null to disable locking |

## How It Works

### Login Throttling

The bundle uses Symfony's native `login_throttling` feature, which:

1. **Tracks failed login attempts** per IP address and username combination
2. **Blocks further attempts** when the maximum number of attempts is reached
3. **Automatically resets** after the specified interval
4. **Uses Symfony's rate limiter** component for efficient tracking

The throttling is handled automatically by Symfony's security system - you don't need to add any code to your controllers or authentication logic.

### Rate Limiter

Symfony uses a rate limiter to track login attempts. You can:

1. **Use the default rate limiter**: Symfony automatically creates a rate limiter for login throttling
2. **Use a custom rate limiter**: Configure your own rate limiter service for more control

#### Custom Rate Limiter

To use a custom rate limiter, first create a rate limiter in `config/packages/framework.yaml`:

```yaml
framework:
    rate_limiter:
        login_throttle_limiter:
            policy: 'fixed_window'
            limit: 3
            interval: '10 minutes'
```

Then configure it in the bundle:

```yaml
nowo_login_throttle:
    rate_limiter: 'login_throttle_limiter'
    max_count_attempts: 3
    timeout: 600
```

**Note**: When using a custom rate limiter, the `max_attempts` and `interval` in `login_throttling` configuration are ignored. The rate limiter configuration takes precedence.

## Examples

### Basic Configuration

```yaml
nowo_login_throttle:
    enabled: true
    max_count_attempts: 3
    timeout: 600
    firewall: 'main'
```

### Advanced Configuration with Custom Rate Limiter

```yaml
# config/packages/framework.yaml
framework:
    rate_limiter:
        custom_login_limiter:
            policy: 'sliding_window'
            limit: 5
            interval: '15 minutes'

# config/packages/nowo_login_throttle.yaml
nowo_login_throttle:
    enabled: true
    max_count_attempts: 5
    timeout: 900
    firewall: 'main'
    rate_limiter: 'custom_login_limiter'
    cache_pool: 'cache.rate_limiter'
    lock_factory: 'lock.factory'
```

### Multiple Firewalls

The bundle supports two configuration modes:

#### Single Firewall Configuration (Simple Mode)

For a single firewall, use the simple configuration:

```yaml
nowo_login_throttle:
    enabled: true
    max_count_attempts: 3
    timeout: 600
    firewall: 'main'  # Configure for main firewall
```

#### Multiple Firewalls Configuration (Advanced Mode)

For multiple firewalls with independent throttling settings, use the `firewalls` configuration:

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
        admin:
            enabled: true
            max_count_attempts: 3
            timeout: 900
            storage: 'cache'
            rate_limiter: 'admin_login_limiter'  # Custom rate limiter
```

Each firewall can have:
- **Independent settings**: Different `max_count_attempts`, `timeout`, `storage`, etc.
- **Shared rate limiters**: Use the same `rate_limiter` service ID to share throttling state across firewalls
- **Different storage backends**: Mix cache and database storage for different firewalls

#### Sharing Rate Limiters Across Firewalls

To share the same rate limiter (and thus share throttling state) across multiple firewalls, use the same `rate_limiter` service ID:

```yaml
# config/packages/framework.yaml
framework:
    rate_limiter:
        shared_login_limiter:
            policy: 'fixed_window'
            limit: 5
            interval: '10 minutes'

# config/packages/nowo_login_throttle.yaml
nowo_login_throttle:
    firewalls:
        main:
            max_count_attempts: 5
            timeout: 600
            rate_limiter: 'shared_login_limiter'  # Shared limiter
        api:
            max_count_attempts: 5
            timeout: 600
            rate_limiter: 'shared_login_limiter'  # Same limiter = shared state
```

When using database storage, firewalls with the same configuration (same `max_count_attempts`, `timeout`, `watch_period`) automatically share the same rate limiter service.

### Disabling Login Throttling

To disable login throttling:

```yaml
nowo_login_throttle:
    enabled: false
```

## Automatic Configuration

**Important**: The bundle does NOT automatically configure `security.yaml`. You must either:
1. Run the provided command (recommended), or
2. Manually configure `login_throttling` in `security.yaml`

After installing the bundle, run the command to automatically configure `security.yaml`:

```bash
php bin/console nowo:login-throttle:configure-security
```

This command will:
1. Read your `nowo_login_throttle.yaml` configuration
2. Add or update `login_throttling` in your `security.yaml`
3. Configure all firewalls specified in your bundle configuration (single or multiple)
4. Automatically set the correct rate limiter service IDs for database storage

**Note**: If `login_throttling` is already configured in `security.yaml`, the command will skip it unless you use the `--force` option.

### Configuration Priority

**What Symfony uses**: Symfony's security system reads `security.yaml` directly. The `login_throttling` configuration in `security.yaml` is what actually controls throttling behavior.

**What the bundle does**: The bundle configuration (`nowo_login_throttle.yaml`) is used by the command to generate/update the `login_throttling` section in `security.yaml`. The bundle itself does not read or enforce its own configuration directly.

**What happens if configurations differ**:
- If `security.yaml` has `login_throttling` configured but `nowo_login_throttle.yaml` has different values, **Symfony will use what's in `security.yaml`**.
- The bundle configuration in `nowo_login_throttle.yaml` is only used as a template by the command to update `security.yaml`.
- To sync them, run the command: `php bin/console nowo:login-throttle:configure-security --force`

**Best Practice**: Keep configurations in sync by:
1. Configuring the bundle in `nowo_login_throttle.yaml`
2. Running the command to update `security.yaml`
3. Avoid manually editing `login_throttling` in `security.yaml` (use the bundle config instead)

### Manual Configuration

If you prefer not to use the command, you can manually add the `login_throttling` configuration to your `config/packages/security.yaml`:

```yaml
security:
    firewalls:
        main:
            login_throttling:
                max_attempts: 3
                interval: '10 minutes'
                # Optional: custom rate limiter
                limiter: 'login_throttle_limiter'
                # Optional: custom cache pool
                cache_pool: 'cache.rate_limiter'
                # Optional: lock factory
                lock_factory: 'lock.factory'
```

**Important Notes on Manual Configuration**:
- When configuring manually, ensure the configuration matches `nowo_login_throttle.yaml` to avoid confusion
- For database storage, you must ensure the rate limiter service IDs are correct (they are automatically generated by the bundle)
- The `interval` value is automatically converted from seconds in the bundle config to a human-readable format (e.g., `600` seconds = `'10 minutes'`)
- If you manually configure `security.yaml`, changes to `nowo_login_throttle.yaml` will not be reflected in `security.yaml` until you run the command

## Storage Backends

The bundle supports two storage backends for login attempts:

### Cache Storage (Default)

Uses Symfony's cache system (file, Redis, Memcached, etc.). This is the default and recommended for most applications:

```yaml
nowo_login_throttle:
    storage: 'cache'  # Default
    cache_pool: 'cache.rate_limiter'
```

**Benefits:**
- Very fast (~1-5ms per operation)
- Works out of the box
- No database setup required
- Best for high-traffic applications

### Database Storage

Stores login attempts in a database table using Doctrine ORM. Similar to `anyx/login-gate-bundle` with ORM storage:

```yaml
nowo_login_throttle:
    storage: 'database'
```

**Benefits:**
- Persistent storage for auditing
- Can query and analyze login attempts
- No external dependencies (Redis/Memcached)
- Better for compliance/auditing requirements

For detailed information on database storage, see [DATABASE_STORAGE.md](DATABASE_STORAGE.md).

## Migration from anyx/login-gate-bundle

This bundle is designed as a drop-in replacement for `anyx/login-gate-bundle`. The configuration options are compatible:

### Before (anyx/login-gate-bundle)

```yaml
# config/packages/login_gate.yaml
login_gate:
    storages: ['orm']
    options:
        max_count_attempts: 3
        timeout: 600
        watch_period: 3600
```

### After (nowo-tech/login-throttle-bundle)

**Option 1: Using Database Storage (like the old bundle)**

```yaml
# config/packages/nowo_login_throttle.yaml
nowo_login_throttle:
    enabled: true
    storage: 'database'  # Use database storage
    max_count_attempts: 3
    timeout: 600
    watch_period: 3600
    firewall: 'main'
```

**Option 2: Using Cache Storage (default, recommended for new projects)**

```yaml
# config/packages/nowo_login_throttle.yaml
nowo_login_throttle:
    enabled: true
    storage: 'cache'  # Default, uses Symfony cache
    max_count_attempts: 3
    timeout: 600
    watch_period: 3600
    firewall: 'main'
```

Then run:

```bash
php bin/console nowo:login-throttle:configure-security
```

See [DATABASE_STORAGE.md](DATABASE_STORAGE.md) for detailed information on database storage, or [MIGRATION_FROM_ANYX.md](MIGRATION_FROM_ANYX.md) for complete migration instructions.

## Best Practices

1. **Choose the right storage backend**:
   - Use `storage: 'cache'` (default) for high-traffic applications and better performance
   - Use `storage: 'database'` for auditing requirements, compliance, or when you need to query login attempts
2. **Set appropriate timeout**: Balance security with user experience (600 seconds = 10 minutes is a good default)
3. **Configure max attempts**: 3-5 attempts is usually sufficient to prevent brute force attacks
4. **Use custom rate limiter for advanced scenarios**: If you need more control, create a custom rate limiter
5. **Test throttling behavior**: Ensure throttling works correctly in your application flow
6. **Monitor failed attempts**: Consider logging failed login attempts for security monitoring
7. **Use appropriate cache pool** (when using cache storage): Use a dedicated cache pool for rate limiting to avoid conflicts
8. **Enable lock factory for high concurrency** (when using cache storage): Use lock factory if you have high concurrent login attempts
9. **Set up cleanup tasks** (when using database storage): Regularly clean up old login attempts to prevent database bloat

## Service Configuration Examples

For detailed examples of service configurations for different deployment scenarios (local development, Docker containers, Kubernetes), see [SERVICES.md](SERVICES.md).

The document includes:
- Local development configurations
- Docker container setups
- Kubernetes deployments
- Environment-specific configurations
- Advanced rate limiter configurations
- Storage comparison (File, Redis, Database, Memcached)

## Troubleshooting

### Issue: Login throttling not working

**Solution**: Ensure that:
1. The bundle is enabled (`enabled: true`)
2. `security.yaml` has `login_throttling` configured
3. Run `php bin/console nowo:login-throttle:configure-security` to configure `security.yaml`
4. Clear cache: `php bin/console cache:clear`

### Issue: Custom rate limiter not working

**Solution**: 
1. Verify the rate limiter is defined in `framework.yaml`
2. Check that the service ID matches in both configurations
3. Clear cache: `php bin/console cache:clear`
4. Verify the rate limiter service exists: `php bin/console debug:container | grep rate_limiter`

### Issue: Configuration not loading

**Solution**:
1. Clear cache: `php bin/console cache:clear`
2. Verify configuration: `php bin/console debug:config nowo_login_throttle`
3. Check file location: `config/packages/nowo_login_throttle.yaml`
4. Ensure bundle is registered in `config/bundles.php`

### Issue: Rate limiting not working across containers

**Solution**: Use a shared cache (Redis/Memcached) instead of file cache. See [SERVICES.md](SERVICES.md) for examples.

### Issue: Race conditions in distributed systems

**Solution**: Enable lock factory for Kubernetes or multiple containers:
```yaml
lock_factory: 'lock.factory'
```

### Issue: Command not found

**Solution**: 
1. Clear cache: `php bin/console cache:clear`
2. Verify bundle is installed: `composer show nowo-tech/login-throttle-bundle`
3. Check bundle is registered: `php bin/console debug:container | grep login.throttle`

