# Prefer Compose V2 plugin (GitHub Actions / modern Docker Desktop); fall back to docker-compose V1 (REQ-MAKE-010).
COMPOSE_BIN := $(shell docker compose version >/dev/null 2>&1 && echo "docker compose" || echo "docker-compose")
COMPOSE     := $(COMPOSE_BIN)
SERVICE_PHP = php

.PHONY: help ensure-up up down down-dev build shell install assets test test-coverage test-with-db \
	test-coverage-with-db cs-check cs-fix rector rector-dry phpstan igor qa release-check \
	release-check-demos demo-smoke composer-sync clean update validate validate-translations \
	setup-hooks check-no-cursor-coauthor check-open-prs check-twig-extra check-no-raw-html-form \
	strip-cursor-coauthor-from-history update-deps

help:
	@echo "Login Throttle Bundle - Development Commands"
	@echo ""
	@echo "Usage: make <target>"
	@echo ""
	@echo "Container:"
	@echo "  up                Start Docker container"
	@echo "  down              Stop Docker container"
	@echo "  build             Rebuild Docker image (no cache)"
	@echo "  shell             Open shell in container"
	@echo ""
	@echo "Dependencies:"
	@echo "  install           Install Composer dependencies"
	@echo ""
	@echo "Assets:"
	@echo "  assets            No-op (no frontend assets)"
	@echo ""
	@echo "Tests:"
	@echo "  test              Run PHPUnit tests"
	@echo "  test-coverage     Run tests with coverage (prints the PHP percentage)"
	@echo ""
	@echo "Quality:"
	@echo "  cs-check          Check code style"
	@echo "  cs-fix            Fix code style"
	@echo "  rector            Apply Rector refactoring"
	@echo "  rector-dry        Run Rector in dry-run mode"
	@echo "  phpstan           Run PHPStan static analysis"
	@echo "  igor              Run Igor worker-state audit (REQ-CS-008)"
	@echo "  qa                Run cs-check and tests"
	@echo ""
	@echo "Release:"
	@echo "  release-check     Pre-release chain (REQ-MAKE-002), then demo release-check"
	@echo "  composer-sync     Validate composer.json and align composer.lock"
	@echo ""
	@echo "Cleanup:"
	@echo "  clean             Remove vendor, caches and coverage"
	@echo ""
	@echo "Composer:"
	@echo "  update            Update dependencies (bundle container only)"
	@echo "  update-deps       Update bundle and demo dependencies (REQ-MAKE-008)"
	@echo "  validate          Validate composer.json (--strict)"
	@echo ""
	@echo "Bundle-specific:"
	@echo "  validate-translations  YAML lint + key parity across locales (REQ-MAKE-004)"
	@echo "  check-no-cursor-coauthor  Fail on Cursor co-author trailers (REQ-GIT-001)"
	@echo "  check-open-prs    Fail if unresolved open GitHub PRs remain (REQ-REL-003)"
	@echo "  check-no-raw-html-form  Fail on raw <form>/<input>/<textarea>/<select> in demo Twig (REQ-TWIG-005)"
	@echo "  setup-hooks       Install git hooks (.githooks)"
	@echo "  down-dev          Stop root container (non-destructive)"
	@echo "  test-with-db / test-coverage-with-db  Aliases of test / test-coverage"
	@echo ""
	@echo "Demos:"
	@echo "  demo-smoke        Boot demo/symfony8 and assert HTTP 200 (REQ-TEST-011)"
	@echo "  release-check-demos  Run release-check in the demo"

ensure-up:
	@$(COMPOSE) ps -q $(SERVICE_PHP) >/dev/null 2>&1 || true
	@$(COMPOSE) up -d --build
	@sleep 2
	@$(COMPOSE) exec -T $(SERVICE_PHP) sh -lc 'test -d vendor || composer install --no-interaction'

up:
	@$(MAKE) ensure-up

down:
	@$(COMPOSE) down

down-dev:
	@$(COMPOSE) down --remove-orphans

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
	@$(COMPOSE) exec -T $(SERVICE_PHP) php .scripts/validate-translations.php

qa: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer qa

composer-sync: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer validate --strict
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer update --lock --no-interaction


# Run Igor worker-state audit (REQ-CS-008)
igor: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer igor

check-open-prs:
	@bash .scripts/check-open-prs.sh

check-no-raw-html-form:
	@bash .scripts/check-no-raw-html-form.sh

# REQ-MAKE-002 order (the bundle ships no Twig; the raw-form gate covers demo templates).
release-check:
	@$(MAKE) ensure-up
	@$(MAKE) check-no-cursor-coauthor
	@$(MAKE) check-open-prs
	@$(MAKE) check-no-raw-html-form
	@$(MAKE) composer-sync
	@$(MAKE) cs-fix
	@$(MAKE) cs-check
	@$(MAKE) rector-dry
	@$(MAKE) phpstan
	@$(MAKE) igor
	@$(MAKE) validate-translations
	@$(MAKE) test-coverage
	@$(MAKE) release-check-demos

release-check-demos:
	@$(MAKE) -C demo release-check

demo-smoke:
	@$(MAKE) -C demo demo-smoke

clean:
	rm -rf vendor .phpunit.cache coverage
	rm -f coverage.xml coverage-php.txt .php-cs-fixer.cache

update: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer update

validate: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer validate --strict

setup-hooks:
	@chmod +x .githooks/pre-commit 2>/dev/null || true
	@chmod +x .githooks/commit-msg 2>/dev/null || true
	@git config core.hooksPath .githooks
	@echo "✅ Git hooks installed (.githooks — includes commit-msg for REQ-GIT-001)."

# REQ-MAKE-008: update-deps (REQ-MAKE-008)
BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST))))
# Optional: monorepo helper absent on standalone GitHub Actions checkout (REQ-MAKE-009).
-include $(BUNDLE_ROOT)/../.scripts/Makefile.update-deps.mk

check-no-cursor-coauthor:
	@chmod +x .scripts/check-no-cursor-coauthor.sh
	@./.scripts/check-no-cursor-coauthor.sh HEAD

strip-cursor-coauthor-from-history:
	@chmod +x .scripts/strip-cursor-coauthor-from-history.sh
	@./.scripts/strip-cursor-coauthor-from-history.sh main
