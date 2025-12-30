# Login Throttle Bundle - Demo

This directory contains a demo project for Symfony 7.0 demonstrating the usage of the Login Throttle Bundle.

## Features

- **Multiple Firewalls**: Demonstrates 3 different firewalls with independent throttling configurations
  - **Main Firewall**: Standard web login (3 attempts, 10 minutes)
  - **API Firewall**: API authentication (5 attempts, 5 minutes) - more lenient
  - **Admin Firewall**: Admin panel (3 attempts, 30 minutes) - very strict
- **Authentication System**: Complete login system with Symfony Security
  - Form-based authentication (main and admin)
  - JSON-based authentication (API)
  - Login throttling protection per firewall
  - Visual feedback for throttled attempts
- **Login Throttling Demonstration**: 
  - Shows how login throttling works with multiple firewalls
  - Each firewall has independent throttling state
  - Demonstrates different configurations (attempts, timeout, storage)
  - Shows blocking after max attempts per firewall
- **Docker Setup**: Complete Docker environment for easy development
- **MySQL Database**: User management with Doctrine ORM and database storage for login attempts

## Requirements

- Docker and Docker Compose
- Or PHP 8.2+ and Composer (for local development)
- MySQL 8.0 (included in Docker Compose)

## Quick Start with Docker

The easiest way to start the demo is using the Makefile:

```bash
cd demo
make up-symfony7  # Automatically creates .env, installs dependencies, sets up database, and runs migrations
```

**Note**: The `make up-symfony7` command automatically:
- Creates `.env` file from `.env.example` if it doesn't exist
- Installs Composer dependencies (requires the bundle to be published on Packagist)
- Creates database and runs migrations
- Sets up initial data with demo users

### Manual Setup

If you prefer to set up manually:

```bash
# Navigate to the demo directory
cd demo/demo-symfony7

# Copy .env.example to .env if not already done
cp .env.example .env

# Start containers
docker-compose up -d

# Install dependencies
docker-compose exec php composer install

# Setup database (create, migrate, load fixtures)
docker-compose exec php composer database

# Access at: http://localhost:8001 (port configured in .env file)
```

## Demo Users

The demo includes the following test users:

- **demo@example.com** / **demo123** - Regular user (ROLE_USER)
- **admin@example.com** / **admin123** - Admin user (ROLE_ADMIN, ROLE_USER)

## Testing Multiple Firewalls

This demo includes **3 different firewalls** with different throttling configurations:

### 1. Main Firewall (Standard Web Login)
- **URL**: http://localhost:8001/login
- **Configuration**: 3 attempts, 10 minutes timeout
- **Storage**: Database
- **Use Case**: Standard web application login

**Test**: Try 3 wrong passwords → blocked for 10 minutes

### 2. API Firewall (API Authentication)
- **URL**: http://localhost:8001/api/login-page
- **Configuration**: 5 attempts, 5 minutes timeout
- **Storage**: Database
- **Use Case**: API endpoints (more lenient for API usage)

**Test**: Try 5 wrong passwords → blocked for 5 minutes

### 3. Admin Firewall (Admin Panel)
- **URL**: http://localhost:8001/admin/login
- **Configuration**: 3 attempts, 30 minutes timeout
- **Storage**: Database
- **Use Case**: Admin panel (very strict security)

**Test**: Try 3 wrong passwords → blocked for 30 minutes

### Key Points

- **Independent Throttling**: Each firewall has its own throttling state. Being blocked in one firewall doesn't affect the others.
- **Different Configurations**: Each firewall demonstrates different throttling settings suitable for different use cases.
- **Database Storage**: All firewalls use database storage to demonstrate persistent login attempt tracking.

## Configuration

The demo uses **multiple firewalls configuration** to showcase different throttling settings:

```yaml
# config/packages/nowo_login_throttle.yaml
nowo_login_throttle:
    firewalls:
        # Main firewall - Standard web login
        main:
            enabled: true
            max_count_attempts: 3
            timeout: 600  # 10 minutes
            storage: 'database'
        # API firewall - More lenient for API usage
        api:
            enabled: true
            max_count_attempts: 5
            timeout: 300  # 5 minutes
            storage: 'database'
        # Admin firewall - Very strict security
        admin:
            enabled: true
            max_count_attempts: 3
            timeout: 1800  # 30 minutes
            storage: 'database'
```

**Important**: The `security.yaml` file does not include `login_throttling` configuration by default. After installing the bundle, you must run the command to configure it:

```bash
php bin/console nowo:login-throttle:configure-security
```

This command will automatically add `login_throttling` to each firewall in `security.yaml` based on the bundle configuration:

```yaml
security:
    firewalls:
        main:
            login_throttling:
                max_attempts: 3
                interval: '10 minutes'
                limiter: 'nowo_login_throttle.database_rate_limiter.shared_...'  # Auto-generated
        api:
            login_throttling:
                max_attempts: 5
                interval: '5 minutes'
                limiter: 'nowo_login_throttle.database_rate_limiter.shared_...'  # Auto-generated
        admin:
            login_throttling:
                max_attempts: 3
                interval: '30 minutes'
                limiter: 'nowo_login_throttle.database_rate_limiter.shared_...'  # Auto-generated
```

The rate limiter service IDs are automatically generated by the bundle based on the configuration. Firewalls with the same configuration (max_attempts, timeout, watch_period) will share the same rate limiter service.

## Local Development (Without Docker)

If you prefer to run without Docker:

```bash
# Install dependencies
composer install

# Setup database
composer database

# Start Symfony server
symfony server:start

# Access at: http://localhost:8000
```

## Makefile Commands

From the `demo/` directory:

```bash
make up-symfony7        # Start demo containers
make down-symfony7      # Stop demo containers
make install-symfony7   # Install dependencies
make database-symfony7  # Setup database
make shell-symfony7      # Open shell in PHP container
make logs-symfony7      # Show container logs
make test-symfony7      # Run tests
```

## Project Structure

```
demo-symfony7/
├── config/              # Symfony configuration
│   ├── packages/
│   │   ├── nowo_login_throttle.yaml  # Bundle configuration
│   │   └── security.yaml              # Security with login_throttling
│   └── routes.yaml
├── src/
│   ├── Controller/     # Demo controllers
│   └── Entity/         # User entity
├── templates/          # Twig templates
├── DataFixtures/      # Demo users fixtures
├── migrations/         # Database migrations
├── docker-compose.yml  # Docker setup
└── Dockerfile          # PHP container
```

## Troubleshooting

### Port already in use

If port 8001 is already in use, change it in `.env`:

```bash
PORT=8002
```

### Database connection errors

Ensure MySQL container is running:

```bash
docker-compose ps
docker-compose logs mysql
```

### Cache issues

Clear Symfony cache:

```bash
docker-compose exec php php bin/console cache:clear
```

### Login throttling not working

1. Verify bundle is enabled in `config/packages/nowo_login_throttle.yaml`
2. **Run the configuration command** (required!): `docker-compose exec php php bin/console nowo:login-throttle:configure-security`
   - This command configures `login_throttling` in `security.yaml` based on the bundle configuration
   - The bundle does NOT automatically configure `security.yaml` - you must run this command
3. Verify `security.yaml` has `login_throttling` configured for all firewalls
4. Ensure database table exists: `docker-compose exec php php bin/console doctrine:schema:update --force`
5. Clear cache: `docker-compose exec php php bin/console cache:clear`

**Note**: If you manually edited `login_throttling` in `security.yaml` and it doesn't match the bundle configuration, Symfony will use what's in `security.yaml`. To sync them, run the command with `--force`: `php bin/console nowo:login-throttle:configure-security --force`

### Multiple firewalls not working

1. Verify `nowo_login_throttle.yaml` uses `firewalls` configuration (not single firewall)
2. Check `security.yaml` has all three firewalls (main, api, admin) configured
3. Run configure command: `docker-compose exec php php bin/console nowo:login-throttle:configure-security --force`
4. Clear cache: `docker-compose exec php php bin/console cache:clear`

## More Information

For more information about the Login Throttle Bundle, see:
- [Bundle README](../../README.md)
- [Configuration Guide](../../docs/CONFIGURATION.md)
- [Service Examples](../../docs/SERVICES.md)

