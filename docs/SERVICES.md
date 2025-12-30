# Service Configuration Examples

This document provides examples of different service configurations for the Login Throttle Bundle, considering various deployment scenarios including local development, Docker containers, and Kubernetes.

## Table of Contents

- [Local Development](#local-development)
- [Docker Containers](#docker-containers)
- [Kubernetes](#kubernetes)
- [Multiple Environments](#multiple-environments)
- [Advanced Configurations](#advanced-configurations)

## Local Development

### Basic Configuration (File Cache)

For local development, you can use the default file-based cache:

```yaml
# config/packages/nowo_login_throttle.yaml
nowo_login_throttle:
    enabled: true
    max_count_attempts: 3
    timeout: 600
    firewall: 'main'
    cache_pool: 'cache.app'  # Default Symfony cache
```

```yaml
# config/packages/framework.yaml
framework:
    cache:
        app: cache.adapter.filesystem
```

### Local Development with Redis (Optional)

If you have Redis running locally:

```yaml
# config/packages/nowo_login_throttle.yaml
nowo_login_throttle:
    enabled: true
    max_count_attempts: 3
    timeout: 600
    firewall: 'main'
    cache_pool: 'cache.rate_limiter'
```

```yaml
# config/packages/framework.yaml
framework:
    cache:
        pools:
            cache.rate_limiter:
                adapter: cache.adapter.redis
                provider: 'redis://localhost:6379'
```

## Docker Containers

### Docker with Redis Service

When running in Docker containers, use Redis for shared state across containers:

```yaml
# config/packages/nowo_login_throttle.yaml
nowo_login_throttle:
    enabled: true
    max_count_attempts: 5
    timeout: 900
    firewall: 'main'
    cache_pool: 'cache.rate_limiter'
    rate_limiter: 'login_throttle_limiter'
```

```yaml
# config/packages/framework.yaml
framework:
    cache:
        pools:
            cache.rate_limiter:
                adapter: cache.adapter.redis
                provider: 'redis://redis:6379'  # Docker service name
    rate_limiter:
        login_throttle_limiter:
            policy: 'sliding_window'
            limit: 5
            interval: '15 minutes'
```

### Docker Compose Example

```yaml
# docker-compose.yml
version: '3.8'

services:
    php:
        image: php:8.2-fpm
        volumes:
            - .:/var/www/html
        depends_on:
            - redis
    
    redis:
        image: redis:7-alpine
        ports:
            - "6379:6379"
        volumes:
            - redis_data:/data

volumes:
    redis_data:
```

### Docker with Environment Variables

Use environment variables for different environments:

```yaml
# config/packages/nowo_login_throttle.yaml
nowo_login_throttle:
    enabled: true
    max_count_attempts: '%env(int:LOGIN_MAX_ATTEMPTS)%'
    timeout: '%env(int:LOGIN_TIMEOUT)%'
    firewall: '%env(LOGIN_FIREWALL)%'
    cache_pool: '%env(LOGIN_CACHE_POOL)%'
```

```bash
# .env
LOGIN_MAX_ATTEMPTS=5
LOGIN_TIMEOUT=900
LOGIN_FIREWALL=main
LOGIN_CACHE_POOL=cache.rate_limiter
```

```yaml
# config/packages/framework.yaml
framework:
    cache:
        pools:
            cache.rate_limiter:
                adapter: cache.adapter.redis
                provider: '%env(REDIS_URL)%'
```

## Kubernetes

### Kubernetes with Redis Cluster

For Kubernetes deployments, use Redis for distributed rate limiting:

```yaml
# config/packages/nowo_login_throttle.yaml
nowo_login_throttle:
    enabled: true
    max_count_attempts: 5
    timeout: 900
    firewall: 'main'
    cache_pool: 'cache.rate_limiter'
    rate_limiter: 'login_throttle_limiter'
    lock_factory: 'lock.factory'  # Important for distributed systems
```

```yaml
# config/packages/framework.yaml
framework:
    cache:
        pools:
            cache.rate_limiter:
                adapter: cache.adapter.redis
                provider: '%env(REDIS_URL)%'
    lock:
        default: 'redis://%env(REDIS_URL)%'
    rate_limiter:
        login_throttle_limiter:
            policy: 'sliding_window'
            limit: 5
            interval: '15 minutes'
```

### Kubernetes ConfigMap Example

```yaml
# k8s/configmap.yaml
apiVersion: v1
kind: ConfigMap
metadata:
    name: login-throttle-config
data:
    LOGIN_MAX_ATTEMPTS: "5"
    LOGIN_TIMEOUT: "900"
    LOGIN_FIREWALL: "main"
    REDIS_URL: "redis://redis-service:6379"
```

### Kubernetes with Redis Sentinel (High Availability)

For production Kubernetes with Redis Sentinel:

```yaml
# config/packages/nowo_login_throttle.yaml
nowo_login_throttle:
    enabled: true
    max_count_attempts: 5
    timeout: 900
    firewall: 'main'
    cache_pool: 'cache.rate_limiter'
    rate_limiter: 'login_throttle_limiter'
    lock_factory: 'lock.factory'
```

```yaml
# config/packages/framework.yaml
framework:
    cache:
        pools:
            cache.rate_limiter:
                adapter: cache.adapter.redis
                provider: 'redis://%env(REDIS_SENTINEL_HOST)%:%env(REDIS_SENTINEL_PORT)%'
                options:
                    replication: 'sentinel'
                    service: '%env(REDIS_SENTINEL_SERVICE)%'
    lock:
        default: 'redis://%env(REDIS_SENTINEL_HOST)%:%env(REDIS_SENTINEL_PORT)%'
    rate_limiter:
        login_throttle_limiter:
            policy: 'sliding_window'
            limit: 5
            interval: '15 minutes'
```

## Multiple Environments

### Environment-Specific Configuration

```yaml
# config/packages/nowo_login_throttle.yaml (base)
nowo_login_throttle:
    enabled: true
    max_count_attempts: 3
    timeout: 600
    firewall: 'main'
    cache_pool: 'cache.rate_limiter'
```

```yaml
# config/packages/dev/nowo_login_throttle.yaml
nowo_login_throttle:
    enabled: true
    max_count_attempts: 10  # More lenient in dev
    timeout: 300
    cache_pool: 'cache.app'  # Use file cache in dev
```

```yaml
# config/packages/prod/nowo_login_throttle.yaml
nowo_login_throttle:
    enabled: true
    max_count_attempts: 5
    timeout: 900
    cache_pool: 'cache.rate_limiter'  # Use Redis in production
    rate_limiter: 'login_throttle_limiter'
    lock_factory: 'lock.factory'
```

### Environment-Specific Cache Configuration

```yaml
# config/packages/dev/framework.yaml
framework:
    cache:
        app: cache.adapter.filesystem
```

```yaml
# config/packages/prod/framework.yaml
framework:
    cache:
        pools:
            cache.rate_limiter:
                adapter: cache.adapter.redis
                provider: '%env(REDIS_URL)%'
    lock:
        default: 'redis://%env(REDIS_URL)%'
```

## Advanced Configurations

### Custom Rate Limiter with Token Bucket Policy

```yaml
# config/packages/nowo_login_throttle.yaml
nowo_login_throttle:
    enabled: true
    max_count_attempts: 10
    timeout: 600
    firewall: 'main'
    rate_limiter: 'login_token_bucket'
    cache_pool: 'cache.rate_limiter'
```

```yaml
# config/packages/framework.yaml
framework:
    cache:
        pools:
            cache.rate_limiter:
                adapter: cache.adapter.redis
                provider: '%env(REDIS_URL)%'
    rate_limiter:
        login_token_bucket:
            policy: 'token_bucket'
            limit: 10
            rate:
                interval: '1 minute'
                amount: 2  # Allow 2 attempts per minute
```

### Multiple Firewalls with Different Limits

```yaml
# config/packages/nowo_login_throttle.yaml
nowo_login_throttle:
    enabled: true
    max_count_attempts: 5
    timeout: 900
    firewall: 'main'  # Default firewall
    cache_pool: 'cache.rate_limiter'
```

For additional firewalls, configure them separately:

```yaml
# config/packages/security.yaml
security:
    firewalls:
        main:
            login_throttling:
                max_attempts: 5
                interval: '15 minutes'
                limiter: 'login_throttle_limiter'
        
        api:
            login_throttling:
                max_attempts: 10
                interval: '1 hour'
                limiter: 'api_login_limiter'
```

```yaml
# config/packages/framework.yaml
framework:
    rate_limiter:
        login_throttle_limiter:
            policy: 'sliding_window'
            limit: 5
            interval: '15 minutes'
        api_login_limiter:
            policy: 'fixed_window'
            limit: 10
            interval: '1 hour'
```

### Database-Backed Rate Limiter (Alternative)

If you prefer database storage over Redis:

```yaml
# config/packages/nowo_login_throttle.yaml
nowo_login_throttle:
    enabled: true
    max_count_attempts: 5
    timeout: 900
    firewall: 'main'
    cache_pool: 'cache.database'
```

```yaml
# config/packages/framework.yaml
framework:
    cache:
        pools:
            cache.database:
                adapter: cache.adapter.doctrine
                provider: doctrine.dbal.default_connection
```

**Note**: Database-backed cache is slower than Redis but doesn't require additional services.

### Memcached Configuration

Alternative to Redis using Memcached:

```yaml
# config/packages/nowo_login_throttle.yaml
nowo_login_throttle:
    enabled: true
    max_count_attempts: 5
    timeout: 900
    firewall: 'main'
    cache_pool: 'cache.rate_limiter'
```

```yaml
# config/packages/framework.yaml
framework:
    cache:
        pools:
            cache.rate_limiter:
                adapter: cache.adapter.memcached
                provider: 'memcached://localhost:11211'
```

## Storage Comparison

| Storage Type | Use Case | Pros | Cons |
|-------------|----------|------|------|
| **File Cache** | Local development | No dependencies, simple setup | Not shared across containers |
| **Redis** | Containers/Kubernetes | Fast, shared state, scalable | Requires Redis service |
| **Database** | Simple deployments | No extra services | Slower, database load |
| **Memcached** | Alternative to Redis | Fast, simple | Less features than Redis |

## Best Practices

1. **Development**: Use file cache (`cache.app`) for simplicity
2. **Docker**: Use Redis for shared state across containers
3. **Kubernetes**: Use Redis with lock factory for distributed systems
4. **Production**: Always use Redis or Memcached, never file cache
5. **High Availability**: Use Redis Sentinel or cluster for production
6. **Environment Variables**: Use env vars for different environments
7. **Lock Factory**: Always enable lock factory in distributed systems (Kubernetes, multiple containers)

## Troubleshooting

### Issue: Rate limiting not working across containers

**Solution**: Ensure you're using a shared cache (Redis/Memcached), not file cache:
```yaml
cache_pool: 'cache.rate_limiter'  # Not cache.app
```

### Issue: Race conditions in Kubernetes

**Solution**: Enable lock factory:
```yaml
lock_factory: 'lock.factory'
```

### Issue: Redis connection errors

**Solution**: Check Redis URL and network connectivity:
```bash
# Test Redis connection
redis-cli -h redis-service -p 6379 ping
```

### Issue: Different limits per environment

**Solution**: Use environment-specific configuration files:
- `config/packages/dev/nowo_login_throttle.yaml`
- `config/packages/prod/nowo_login_throttle.yaml`

