# Makefile for Login Throttle Bundle Demo

.PHONY: help up-symfony7 down-symfony7 install-symfony7 database-symfony7 shell-symfony7 logs-symfony7 test-symfony7 clean

# Default target
help:
	@echo "Login Throttle Bundle - Demo Commands"
	@echo ""
	@echo "Usage: make <target>"
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
	@echo "Other commands:"
	@echo "  clean              Remove vendor and cache from demo"
	@echo ""
	@echo "Access URL:"
	@echo "  Symfony 7.0: http://localhost:8001"

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
	docker-compose exec -T php composer install --no-interaction && \
	echo "🗄️  Setting up database..." && \
	docker-compose exec -T php composer database && \
	echo "✅ Symfony 7.0 demo is ready at http://localhost:8001"

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

clean:
	rm -rf demo-symfony7/vendor
	rm -rf demo-symfony7/var/cache
	rm -rf demo-symfony7/var/log
	rm -rf demo-symfony7/.phpunit.cache
	rm -rf demo-symfony7/coverage
	rm -f demo-symfony7/coverage.xml

