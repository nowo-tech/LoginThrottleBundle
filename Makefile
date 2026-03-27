COMPOSE = docker compose
SERVICE_PHP = php

.PHONY: help ensure-up up down build shell install assets test test-coverage test-with-db \
	test-coverage-with-db cs-check cs-fix rector rector-dry phpstan qa release-check \
	release-check-demos composer-sync clean update validate validate-translations

help:
	@echo "Usage: make <target>"
	@echo ""
	@echo "Container:"
	@echo "  up down build shell"
	@echo "Dependencies:"
	@echo "  install"
	@echo "Assets:"
	@echo "  assets"
	@echo "Tests:"
	@echo "  test test-coverage test-with-db test-coverage-with-db"
	@echo "Quality:"
	@echo "  cs-check cs-fix rector rector-dry phpstan qa validate-translations"
	@echo "Release:"
	@echo "  release-check composer-sync"
	@echo "Cleanup:"
	@echo "  clean"
	@echo "Composer:"
	@echo "  update validate"
	@echo "Demos:"
	@echo "  release-check-demos"

ensure-up:
	@$(COMPOSE) ps -q $(SERVICE_PHP) >/dev/null 2>&1 || true
	@$(COMPOSE) up -d --build
	@sleep 2
	@$(COMPOSE) exec -T $(SERVICE_PHP) sh -lc 'test -d vendor || composer install --no-interaction'

up:
	@$(MAKE) ensure-up

down:
	@$(COMPOSE) down

build:
	@$(COMPOSE) build --no-cache

shell:
	@$(COMPOSE) exec $(SERVICE_PHP) sh

install: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer install --no-interaction

assets:
	@echo "No frontend assets in this bundle."

test: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer test

test-with-db: test

test-coverage: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer test-coverage | tee coverage-php.txt
	@./.scripts/php-coverage-percent.sh coverage-php.txt

test-coverage-with-db: test-coverage

cs-check: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer cs-check

cs-fix: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer cs-fix

rector-dry: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer rector-dry

rector: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer rector

phpstan: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer phpstan

validate-translations: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) php vendor/bin/yaml-lint src/Resources/translations

qa: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer qa

composer-sync: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer validate --strict
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer update --lock --no-interaction

release-check:
	@$(MAKE) ensure-up
	@$(MAKE) composer-sync
	@$(MAKE) cs-fix
	@$(MAKE) cs-check
	@$(MAKE) rector-dry
	@$(MAKE) phpstan
	@$(MAKE) test-coverage
	@$(MAKE) validate-translations
	@$(MAKE) release-check-demos

release-check-demos:
	@$(MAKE) -C demo release-check

clean:
	rm -rf vendor .phpunit.cache coverage
	rm -f coverage.xml coverage-php.txt .php-cs-fixer.cache

update: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer update

validate: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer validate --strict

