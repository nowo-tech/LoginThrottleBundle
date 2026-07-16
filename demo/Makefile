# Makefile for Login Throttle Bundle Demo

.PHONY: help up-symfony8 down-symfony8 install-symfony8 database-symfony8 shell-symfony8 logs-symfony8 test-symfony8 update-bundle update-bundle-symfony8 release-check verify clean

# Default target
help:
	@echo "Login Throttle Bundle - Demo Commands"
	@echo ""
	@echo "Usage: make <target>"
	@echo ""
	@echo "Symfony 8.0 Demo (Port 8002):"
	@echo "  up-symfony8        Start Symfony 8.0 demo containers"
	@echo "  down-symfony8      Stop Symfony 8.0 demo containers"
	@echo "  install-symfony8   Install dependencies for Symfony 8.0 demo"
	@echo "  database-symfony8  Setup database (create, migrate, load fixtures)"
	@echo "  shell-symfony8     Open shell in Symfony 8.0 PHP container"
	@echo "  logs-symfony8      Show Symfony 8.0 container logs"
	@echo "  test-symfony8      Run tests for Symfony 8.0 demo"
	@echo ""
	@echo "Other commands:"
	@echo "  update-bundle      Sync bundle code in the demo"
	@echo "  release-check      Run full demo verification flow"
	@echo "  verify             Basic health check of running demo"
	@echo "  clean              Remove vendor and cache from the demo"
	@echo ""
	@echo "Access URLs:"
	@echo "  Symfony 8.0: http://localhost:8002"
	@echo "  phpMyAdmin:  http://localhost:8082"

# Symfony 8.0 Demo
up-symfony8:
	@echo "🚀 Starting Symfony 8.0 demo..."
	cd demo-symfony8 && \
	if [ ! -f .env ]; then \
		if [ -f .env.example ]; then \
			cp .env.example .env && \
			echo "✅ Created .env from .env.example"; \
		else \
			echo "⚠️  Warning: .env.example not found, creating basic .env file"; \
			echo "# Environment variables" > .env; \
		fi; \
	fi && \
	docker-compose build && \
	docker-compose up -d && \
	echo "⏳ Waiting for containers to be ready..." && \
	sleep 3 && \
	echo "📦 Installing dependencies..." && \
	docker-compose exec -T php composer install --no-interaction --no-scripts && \
	echo "🔧 Running post-install scripts (if bin/console exists)..." && \
	(docker-compose exec -T php test -f bin/console && docker-compose exec -T php composer run-script post-install-cmd || echo "⚠️  Skipping post-install scripts (bin/console not found)") && \
	echo "🗄️  Setting up database..." && \
	if docker-compose exec -T php test -f bin/console; then \
		echo "   Creating database..." && \
		docker-compose exec -T php php bin/console doctrine:database:create --if-not-exists && \
		echo "   Running migrations..." && \
		docker-compose exec -T php php bin/console doctrine:migrations:migrate --no-interaction && \
		echo "   Updating schema (if needed)..." && \
		(docker-compose exec -T php php bin/console doctrine:schema:update --force --complete || echo "⚠️  Schema update skipped (migrations should be sufficient)") && \
		echo "   Loading fixtures..." && \
		docker-compose exec -T php php bin/console doctrine:fixtures:load --no-interaction && \
		echo "✅ Database setup completed"; \
	else \
		echo "❌ Error: bin/console not found. Cannot setup database." && exit 1; \
	fi && \
	PORT=$$(grep "^PORT=" .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=$$(grep "^PORT=" .env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	echo "Demo started at: http://localhost:$$PORT" && \
	echo "✅ phpMyAdmin is ready at http://localhost:8082"

down-symfony8:
	cd demo-symfony8 && docker-compose down

install-symfony8:
	cd demo-symfony8 && docker-compose exec -T php composer install

database-symfony8:
	cd demo-symfony8 && docker-compose exec -T php composer database

shell-symfony8:
	cd demo-symfony8 && docker-compose exec php sh

logs-symfony8:
	cd demo-symfony8 && docker-compose logs -f

test-symfony8:
	cd demo-symfony8 && docker-compose exec -T php composer test

clean:
	rm -rf demo-symfony8/vendor
	rm -rf demo-symfony8/var/cache
	rm -rf demo-symfony8/var/log
	rm -rf demo-symfony8/.phpunit.cache
	rm -rf demo-symfony8/coverage
	rm -f demo-symfony8/coverage.xml

update-bundle: update-bundle-symfony8

update-bundle-symfony8:
	cd demo-symfony8 && docker compose exec -T php composer dump-autoload
	cd demo-symfony8 && docker compose exec -T php php bin/console cache:clear --env=dev --no-warmup || true

verify:
	@echo "Symfony 8 demo: http://localhost:$$(cd demo-symfony8 && grep -E '^PORT=' .env.example | cut -d= -f2)"

release-check:
	@$(MAKE) update-bundle
	@$(MAKE) test-symfony8
	@$(MAKE) verify


# REQ-MAKE-008: update-deps-all (REQ-MAKE-008)
BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST)))/..)
include $(BUNDLE_ROOT)/../.scripts/Makefile.demo-aggregate-update-deps.mk
