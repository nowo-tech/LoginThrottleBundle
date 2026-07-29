# Makefile for Login Throttle Bundle Demo (FrankenPHP + MySQL)

# Prefer Compose V2 plugin (GitHub Actions / modern Docker Desktop); fall back to docker-compose V1 (REQ-MAKE-010).
COMPOSE ?= $(shell docker compose version >/dev/null 2>&1 && echo "docker compose" || echo "docker-compose")

.PHONY: help up up-symfony8 down down-symfony8 install-symfony8 database-symfony8 shell-symfony8 logs-symfony8 test-symfony8 update-bundle update-bundle-symfony8 release-check verify demo-smoke release-verify clean

help:
	@echo "Login Throttle Bundle - Demo Commands"
	@echo ""
	@echo "Symfony 8 FrankenPHP demo (Port 8002):"
	@echo "  up / up-symfony8     Start stack, install, migrate, fixtures"
	@echo "  down / down-symfony8 Stop containers"
	@echo "  demo-smoke           REQ-TEST-011: up -> HTTP 200 -> down"
	@echo "  release-verify       Alias of demo-smoke"
	@echo "  install-symfony8     Composer install"
	@echo "  database-symfony8    Create DB / migrate / fixtures"
	@echo "  shell-symfony8       Shell in PHP container"
	@echo "  logs-symfony8        Follow logs"
	@echo "  test-symfony8        Demo PHPUnit"
	@echo "  update-bundle        Sync path-repo bundle"
	@echo "  release-check        update-bundle + test + verify"
	@echo "  verify               Print demo URL"
	@echo "  clean                Remove vendor/cache artifacts"
	@echo ""
	@echo "Access: http://localhost:8002 (PORT from .env)"

up up-symfony8:
	@set -e; \
	echo "Starting Symfony 8 FrankenPHP demo..."; \
	cd demo-symfony8; \
	if [ ! -f .env ]; then \
		cp .env.example .env && echo "Created .env from .env.example"; \
	fi; \
	$(COMPOSE) build; \
	$(COMPOSE) up -d; \
	echo "Waiting for containers to be ready..."; \
	sleep 5; \
	echo "Installing dependencies..."; \
	$(COMPOSE) exec -T php composer install --no-interaction --no-scripts; \
	$(COMPOSE) exec -T php php bin/console cache:clear --env=dev --no-warmup || true; \
	$(COMPOSE) exec -T php php bin/console cache:warmup --env=dev; \
	echo "Setting up database..."; \
	if $(COMPOSE) exec -T php test -f bin/console; then \
		$(COMPOSE) exec -T php php bin/console doctrine:database:create --if-not-exists; \
		$(COMPOSE) exec -T php php bin/console doctrine:migrations:migrate --no-interaction; \
		($(COMPOSE) exec -T php php bin/console doctrine:schema:update --force --complete || true); \
		$(COMPOSE) exec -T php php bin/console doctrine:fixtures:load --no-interaction; \
		echo "Database setup completed"; \
	else \
		echo "Error: bin/console not found." && exit 1; \
	fi; \
	PORT=$$(grep "^PORT=" .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=$$(grep "^PORT=" .env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	PORT=$${PORT:-8002}; \
	echo "Demo started at: http://localhost:$$PORT"

down down-symfony8:
	cd demo-symfony8 && $(COMPOSE) down

# REQ-TEST-011
demo-smoke:
	@set -e; \
	PORT_EXAMPLE=$$(grep "^PORT=" demo-symfony8/.env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT_EXAMPLE" ] && PORT_EXAMPLE=8002; \
	echo "Demo smoke: demo-symfony8 (expect http://127.0.0.1:$$PORT_EXAMPLE)"; \
	$(MAKE) up-symfony8; \
	PORT=$$(grep "^PORT=" demo-symfony8/.env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=$$PORT_EXAMPLE; \
	sleep 8; \
	code=$$(curl -s -o /dev/null -w "%{http_code}" "http://127.0.0.1:$$PORT/" 2>/dev/null || echo "000"); \
	$(MAKE) down-symfony8 2>/dev/null || true; \
	if [ "$$code" != "200" ] && [ "$$code" != "302" ]; then echo "demo failed with HTTP $$code (port $$PORT)"; exit 1; fi; \
	echo "demo-symfony8 OK (HTTP $$code)"

release-verify: demo-smoke

install-symfony8:
	cd demo-symfony8 && $(COMPOSE) exec -T php composer install

database-symfony8:
	cd demo-symfony8 && $(COMPOSE) exec -T php composer database

shell-symfony8:
	cd demo-symfony8 && $(COMPOSE) exec php sh

logs-symfony8:
	cd demo-symfony8 && $(COMPOSE) logs -f

test-symfony8:
	cd demo-symfony8 && $(COMPOSE) exec -T php composer test

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
	@echo "Symfony 8 demo: http://localhost:$$(cd demo-symfony8 && grep -E '^PORT=' .env.example | cut -d= -f2 | tr -d '\r')"

release-check:
	@$(MAKE) update-bundle
	@$(MAKE) test-symfony8
	@$(MAKE) verify

# REQ-MAKE-008: update-deps-all (REQ-MAKE-008)
BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST)))/..)
# Optional: monorepo helper absent on standalone GitHub Actions checkout (REQ-MAKE-009).
-include $(BUNDLE_ROOT)/../.scripts/Makefile.demo-aggregate-update-deps.mk
