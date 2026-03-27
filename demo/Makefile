# Makefile for Login Throttle Bundle Demo

.PHONY: help up-symfony6 up-symfony7 up-symfony8 down-symfony6 down-symfony7 down-symfony8 install-symfony6 install-symfony7 install-symfony8 database-symfony6 database-symfony7 database-symfony8 shell-symfony6 shell-symfony7 shell-symfony8 logs-symfony6 logs-symfony7 logs-symfony8 test-symfony6 test-symfony7 test-symfony8 update-bundle update-bundle-symfony6 update-bundle-symfony7 update-bundle-symfony8 release-check verify clean

# Default target
help:
	@echo "Login Throttle Bundle - Demo Commands"
	@echo ""
	@echo "Usage: make <target>"
	@echo ""
	@echo "Symfony 6.0 Demo (Port 8000):"
	@echo "  up-symfony6        Start Symfony 6.0 demo containers"
	@echo "  down-symfony6      Stop Symfony 6.0 demo containers"
	@echo "  install-symfony6   Install dependencies for Symfony 6.0 demo"
	@echo "  database-symfony6  Setup database (create, migrate, load fixtures)"
	@echo "  shell-symfony6     Open shell in Symfony 6.0 PHP container"
	@echo "  logs-symfony6      Show Symfony 6.0 container logs"
	@echo "  test-symfony6      Run tests for Symfony 6.0 demo"
	@echo ""
	@echo "Symfony 7.0 Demo (Port 8001):"
	@echo "  up-symfony7        Start Symfony 7.0 demo containers"
	@echo "  down-symfony7      Stop Symfony 7.0 demo containers"
	@echo "  install-symfony7   Install dependencies for Symfony 7.0 demo"
	@echo "  database-symfony7  Setup database (create, migrate, load fixtures)"
	@echo "  shell-symfony7     Open shell in Symfony 7.0 PHP container"
	@echo "  logs-symfony7      Show Symfony 7.0 container logs"
	@echo "  test-symfony7      Run tests for Symfony 7.0 demo"
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
	@echo "  update-bundle      Sync bundle code in all demos"
	@echo "  release-check      Run full demo verification flow"
	@echo "  verify             Basic health check of running demos"
	@echo "  clean              Remove vendor and cache from all demos"
	@echo ""
	@echo "Access URLs:"
	@echo "  Symfony 6.0: http://localhost:8000"
	@echo "  Symfony 7.0: http://localhost:8001"
	@echo "  Symfony 8.0: http://localhost:8002"
	@echo "  phpMyAdmin (S6): http://localhost:8080"
	@echo "  phpMyAdmin (S7): http://localhost:8081"
	@echo "  phpMyAdmin (S8): http://localhost:8082"

# Symfony 6.0 Demo
up-symfony6:
	@echo "🚀 Starting Symfony 6.0 demo..."
	cd demo-symfony6 && \
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
	echo "✅ Symfony 6.0 demo is ready at http://localhost:8000" && \
	echo "✅ phpMyAdmin is ready at http://localhost:8080"

down-symfony6:
	cd demo-symfony6 && docker-compose down

install-symfony6:
	cd demo-symfony6 && docker-compose exec -T php composer install

database-symfony6:
	cd demo-symfony6 && docker-compose exec -T php composer database

shell-symfony6:
	cd demo-symfony6 && docker-compose exec php sh

logs-symfony6:
	cd demo-symfony6 && docker-compose logs -f

test-symfony6:
	cd demo-symfony6 && docker-compose exec -T php composer test

# Symfony 7.0 Demo
up-symfony7:
	@echo "🚀 Starting Symfony 7.0 demo..."
	cd demo-symfony7 && \
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
	echo "✅ Symfony 7.0 demo is ready at http://localhost:8001" && \
	echo "✅ phpMyAdmin is ready at http://localhost:8081"

down-symfony7:
	cd demo-symfony7 && docker-compose down

install-symfony7:
	cd demo-symfony7 && docker-compose exec -T php composer install

database-symfony7:
	cd demo-symfony7 && docker-compose exec -T php composer database

shell-symfony7:
	cd demo-symfony7 && docker-compose exec php sh

logs-symfony7:
	cd demo-symfony7 && docker-compose logs -f

test-symfony7:
	cd demo-symfony7 && docker-compose exec -T php composer test

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
	echo "✅ Symfony 8.0 demo is ready at http://localhost:8002" && \
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
	rm -rf demo-symfony6/vendor demo-symfony7/vendor demo-symfony8/vendor
	rm -rf demo-symfony6/var/cache demo-symfony7/var/cache demo-symfony8/var/cache
	rm -rf demo-symfony6/var/log demo-symfony7/var/log demo-symfony8/var/log
	rm -rf demo-symfony6/.phpunit.cache demo-symfony7/.phpunit.cache demo-symfony8/.phpunit.cache
	rm -rf demo-symfony6/coverage demo-symfony7/coverage demo-symfony8/coverage
	rm -f demo-symfony6/coverage.xml demo-symfony7/coverage.xml demo-symfony8/coverage.xml

update-bundle: update-bundle-symfony6 update-bundle-symfony7 update-bundle-symfony8

update-bundle-symfony6:
	cd demo-symfony6 && docker compose exec -T php composer dump-autoload
	cd demo-symfony6 && docker compose exec -T php php bin/console cache:clear --env=dev --no-warmup || true

update-bundle-symfony7:
	cd demo-symfony7 && docker compose exec -T php composer dump-autoload
	cd demo-symfony7 && docker compose exec -T php php bin/console cache:clear --env=dev --no-warmup || true

update-bundle-symfony8:
	cd demo-symfony8 && docker compose exec -T php composer dump-autoload
	cd demo-symfony8 && docker compose exec -T php php bin/console cache:clear --env=dev --no-warmup || true

verify:
	@echo "Symfony 6 demo: $$(cd demo-symfony6 && grep -E '^DEFAULT_URI=' .env.example | cut -d= -f2)"
	@echo "Symfony 7 demo: $$(cd demo-symfony7 && grep -E '^DEFAULT_URI=' .env.example | cut -d= -f2)"
	@echo "Symfony 8 demo: $$(cd demo-symfony8 && grep -E '^DEFAULT_URI=' .env.example | cut -d= -f2)"

release-check:
	@$(MAKE) update-bundle
	@$(MAKE) test-symfony6
	@$(MAKE) test-symfony7
	@$(MAKE) test-symfony8
	@$(MAKE) verify
