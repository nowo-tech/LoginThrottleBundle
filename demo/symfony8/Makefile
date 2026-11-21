# REQ-DEMO-005: make up prints Demo started at: http://localhost:<PORT>
.PHONY: up down update-bundle test-e2e

up down update-bundle:
	@$(MAKE) -C .. $@

# REQ-DEMO-013: Playwright e2e (the demo-platform rules, axe and the firewall flows), run in the official image pinned to
# @playwright/test (e2e/package.json). The demo must be up (make up).
PLAYWRIGHT_VERSION := $(shell sed -n 's/.*"@playwright\/test": "\([0-9.]*\)".*/\1/p' e2e/package.json)
PLAYWRIGHT_IMAGE ?= mcr.microsoft.com/playwright:v$(PLAYWRIGHT_VERSION)-jammy

test-e2e:
	@mkdir -p e2e/node_modules
	@PORT=$$(grep -E '^PORT=' .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); PORT=$${PORT:-8002}; \
	docker run --rm --network host \
		-v "$(CURDIR)/e2e:/e2e" -w /e2e \
		-e DEMO_BASE_URL=http://127.0.0.1:$$PORT \
		$(PLAYWRIGHT_IMAGE) \
		bash -lc 'npm ci --no-fund --no-audit && npx playwright test --project=chromium'
