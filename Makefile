# Test environment and decision tests for the feature plugin.
#
#   make up        start all three WordPress sites (7.0, 7.1, nightly)
#   make test      run the decision tests against all three (SITES=7.0,nightly for some)
#   make check     up + test
#   make open      open wp-admin for all three (ENV=7.0, ENV=7.1 or ENV=nightly for one)
#   make down      stop all three
#   make destroy   remove all three and their data (asks first)
#
# Requires Docker and Node 24.18+ (26, the current LTS, recommended; see .nvmrc).

.DEFAULT_GOAL := help
.PHONY: help install up down destroy status open test check

help: ## List targets
	@grep -E '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | awk -F':.*## ' '{printf "  %-14s %s\n", $$1, $$2}'

node_modules: package.json
	npm install
	@touch node_modules

install: node_modules ## Install wp-env, vitest and test deps

up: install ## Start all sites (7.0 :8870, 7.1 :8871, nightly :8872)
	scripts/env.sh start

down: install ## Stop all sites
	scripts/env.sh stop

destroy: install ## Remove all sites and their data (asks first)
	scripts/env.sh destroy

status: install ## Show all sites
	scripts/env.sh status

open: ## Open wp-admin in a browser (all, or ENV=7.0 / ENV=7.1 / ENV=nightly)
	scripts/env.sh open $(ENV)

test: install ## Run the decision tests (all sites, or SITES=7.0,nightly)
	SITES=$(SITES) npx vitest run
	@# Then the tests that change site state, serially, never alongside the above.
	SITES=$(SITES) npx vitest run --config vitest.lifecycle.config.ts

check: up test ## Start everything, then test

.PHONY: dist
dist: ## Build the plugin zip, dist/wp-http-query-<version>.zip
	scripts/dist.sh
