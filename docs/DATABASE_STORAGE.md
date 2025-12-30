# Database Storage for Login Attempts

This guide explains how to configure the Login Throttle Bundle to store login attempts in a database instead of using cache/Redis.

## Overview

By default, the bundle uses Symfony's cache system (which can be file, Redis, Memcached, etc.) to store login attempt data. However, if you prefer to store attempts in a database (like `anyx/login-gate-bundle` did with ORM storage), you can configure the bundle to use database storage.

## Benefits of Database Storage

- **Persistence**: Login attempts are stored permanently (until cleaned up)
- **Auditing**: You can query and analyze login attempts
- **No External Dependencies**: Doesn't require Redis or Memcached
- **Compatibility**: Similar to `anyx/login-gate-bundle` ORM storage

## Requirements

- Doctrine ORM installed (`doctrine/orm` and `doctrine/doctrine-bundle`)
- Database connection configured
- Doctrine migrations enabled (optional, but recommended)

## Quick Start

### Step 1: Configure Storage

Update your bundle configuration to use database storage:

```yaml
# config/packages/nowo_login_throttle.yaml
nowo_login_throttle:
    enabled: true
    storage: 'database'  # Use database instead of cache
    max_count_attempts: 3
    timeout: 600
    watch_period: 3600
    firewall: 'main'
```

### Step 2: Configure Doctrine

Ensure Doctrine is configured in your `config/packages/doctrine.yaml`:

```yaml
doctrine:
    dbal:
        url: '%env(resolve:DATABASE_URL)%'
    orm:
        auto_generate_proxy_classes: true
        entity_managers:
            default:
                auto_mapping: true
                mappings:
                    NowoLoginThrottleBundle:
                        type: attribute
                        is_bundle: true
                        dir: 'Entity'
                        prefix: 'Nowo\LoginThrottleBundle\Entity'
                        alias: NowoLoginThrottleBundle
```

### Step 3: Create Migration

Generate a migration for the `LoginAttempt` entity:

```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

Or manually create the table:

```sql
CREATE TABLE login_attempts (
    id INT AUTO_INCREMENT NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    username VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    blocked TINYINT(1) DEFAULT 0 NOT NULL,
    INDEX idx_ip_username (ip_address, username),
    INDEX idx_created_at (created_at),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
```

### Step 4: Configure Security

**Important**: You must run this command to configure `security.yaml`. The bundle does not automatically configure it.

Run the automatic configuration command:

```bash
php bin/console nowo:login-throttle:configure-security
```

This will automatically configure `security.yaml` to use the database rate limiter with the correct service IDs.

**Note**: The command automatically generates and sets the correct rate limiter service IDs (e.g., `nowo_login_throttle.database_rate_limiter.shared_...`) for database storage. For multiple firewalls with the same configuration, they will share the same rate limiter service ID.

### Step 5: Clear Cache

```bash
php bin/console cache:clear
```

## Configuration Options

When using database storage, the following configuration options are relevant:

```yaml
nowo_login_throttle:
    enabled: true
    storage: 'database'           # Required: set to 'database'
    max_count_attempts: 3         # Maximum attempts before blocking
    timeout: 600                  # Block period in seconds
    watch_period: 3600            # Period for cleanup (in seconds)
    firewall: 'main'              # Firewall name
    rate_limiter: null            # Will use database rate limiter automatically
```

**Note**: When `storage: 'database'`, the `cache_pool` and `lock_factory` options are ignored since database storage doesn't use cache.

## How It Works

When `storage: 'database'` is configured:

1. The bundle automatically registers a `DatabaseRateLimiter` service
2. This rate limiter stores login attempts in the `login_attempts` table
3. Each failed login attempt is recorded with IP address, username, and timestamp
4. The rate limiter checks the database to determine if a user/IP should be blocked
5. After the timeout period, attempts are no longer counted

## Manual Security Configuration

**Note**: Manual configuration is not recommended for database storage because the rate limiter service IDs are automatically generated. If you configure manually, you must ensure the service IDs match what the bundle generates.

If you prefer to configure `security.yaml` manually:

```yaml
security:
    firewalls:
        main:
            login_throttling:
                limiter: 'nowo_login_throttle.database_rate_limiter'
```

The `max_attempts` and `interval` options in `login_throttling` are ignored when using a custom rate limiter - the values are taken from the bundle configuration.

## Cleanup

Database storage accumulates login attempt records over time. You should set up a cleanup task to remove old records.

### Using Symfony Scheduler

```php
// config/packages/scheduler.yaml
framework:
    scheduler:
        tasks:
            cleanup_login_attempts:
                type: command
                command: 'nowo:login-throttle:cleanup'
                frequency: '0 2 * * *'  # Run daily at 2 AM
```

### Manual Cleanup Command

The bundle provides a cleanup command (you can create it):

```php
// src/Command/CleanupLoginAttemptsCommand.php
<?php

namespace App\Command;

use Nowo\LoginThrottleBundle\Repository\LoginAttemptRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'nowo:login-throttle:cleanup',
    description: 'Clean up old login attempts'
)]
class CleanupLoginAttemptsCommand extends Command
{
    public function __construct(
        private readonly LoginAttemptRepository $repository
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $watchPeriod = 3600; // 1 hour - use the same value as watch_period
        $deleted = $this->repository->cleanup($watchPeriod);
        $output->writeln(sprintf('Cleaned up %d old login attempts', $deleted));

        return Command::SUCCESS;
    }
}
```

Then run it periodically:

```bash
# Manual cleanup
php bin/console nowo:login-throttle:cleanup

# Or via cron
0 2 * * * cd /path/to/project && php bin/console nowo:login-throttle:cleanup
```

## Querying Login Attempts

You can query login attempts for analysis or auditing:

```php
use Nowo\LoginThrottleBundle\Repository\LoginAttemptRepository;

// Inject the repository
public function __construct(
    private readonly LoginAttemptRepository $repository
) {
}

// Get attempts for an IP address
$attempts = $this->repository->getAttempts('192.168.1.1', null, 3600);

// Count attempts
$count = $this->repository->countAttempts('192.168.1.1', 'user@example.com', 3600);

// Check if blocked
$isBlocked = $this->repository->isBlocked('192.168.1.1', 'user@example.com', 3, 600);
```

## Performance Considerations

### Indexes

The `LoginAttempt` entity includes indexes on:
- `ip_address` and `username` (composite index)
- `created_at` (for cleanup queries)

These indexes ensure efficient queries.

### Database Load

Database storage may have higher latency than cache/Redis:
- **Cache/Redis**: ~1-5ms per operation
- **Database**: ~10-50ms per operation (depending on database)

For high-traffic applications, consider:
1. Using database connection pooling
2. Using a read replica for queries
3. Using cache/Redis for better performance

### When to Use Database Storage

Use database storage when:
- ✅ You need to audit/login attempts
- ✅ You want persistence without external services
- ✅ You have low to medium traffic
- ✅ You're migrating from `anyx/login-gate-bundle` with ORM storage

Use cache/Redis when:
- ✅ You need maximum performance
- ✅ You have high traffic
- ✅ You don't need to audit attempts
- ✅ You already have Redis/Memcached infrastructure

## Migration from Cache to Database

If you're currently using cache and want to switch to database:

1. **Install Doctrine** (if not already installed):
   ```bash
   composer require doctrine/orm doctrine/doctrine-bundle
   ```

2. **Update configuration**:
   ```yaml
   nowo_login_throttle:
       storage: 'database'  # Change from 'cache' to 'database'
   ```

3. **Run migrations** to create the `login_attempts` table

4. **Clear cache**:
   ```bash
   php bin/console cache:clear
   ```

5. **Reconfigure security**:
   ```bash
   php bin/console nowo:login-throttle:configure-security
   ```

**Note**: Existing cache data won't be migrated. Only new login attempts will be stored in the database.

## Troubleshooting

### Issue: "Table login_attempts does not exist"

**Solution**: Run migrations to create the table:
```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

### Issue: "Service not found: Nowo\LoginThrottleBundle\Repository\LoginAttemptRepository"

**Solution**: Ensure Doctrine is installed and configured:
```bash
composer require doctrine/orm doctrine/doctrine-bundle
```

### Issue: Rate limiter not working

**Solution**:
1. Verify `storage: 'database'` is set in configuration
2. Check that the `login_attempts` table exists
3. Clear cache: `php bin/console cache:clear`
4. Verify security.yaml uses the database rate limiter

### Issue: High database load

**Solution**:
1. Ensure indexes are created (they should be automatic)
2. Set up cleanup task to remove old records
3. Consider using cache/Redis for better performance
4. Use database connection pooling

## Comparison: Cache vs Database Storage

| Feature | Cache Storage | Database Storage |
|---------|---------------|------------------|
| Performance | Very Fast (~1-5ms) | Moderate (~10-50ms) |
| Persistence | Temporary (expires) | Permanent (until cleanup) |
| Auditing | No | Yes (can query) |
| External Dependencies | Redis/Memcached (optional) | Database only |
| Setup Complexity | Low | Medium (requires migrations) |
| Cleanup Required | No | Yes (recommended) |
| Best For | High traffic, no auditing needs | Low-medium traffic, auditing needs |

## Additional Resources

- [README.md](../README.md) - Bundle overview
- [CONFIGURATION.md](CONFIGURATION.md) - Complete configuration guide
- [MIGRATION_FROM_ANYX.md](MIGRATION_FROM_ANYX.md) - Migration guide from anyx/login-gate-bundle
- [Symfony Doctrine Documentation](https://symfony.com/doc/current/doctrine.html) - Official Doctrine documentation
