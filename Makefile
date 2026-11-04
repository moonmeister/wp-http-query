# Test environment and decision tests for the feature plugin.
#
#   make up        start both WordPress environments (7.1.4 and nightly)
#   make test      run the decision tests against both
#   make check     up + test
#   make open      open wp-admin for both (ENV=stable or ENV=nightly for one)
#   make down      stop both (alias: make stop)
#   make destroy   remove both and their data (alias: make purge; asks first)
#
# Requires Docker and Node 20+.

.DEFAULT_GOAL := help
.PHONY: help install up down stop destroy purge status test test-stable test-nightly check

help: ## List targets
	@grep -E '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | awk -F':.*## ' '{printf "  %-14s %s\n", $$1, $$2}'

node_modules: package.json
	npm install
	@touch node_modules

install: node_modules ## Install wp-env, vitest and test deps

up: install ## Start both environments (stable :8871, nightly :8872)
	scripts/env.sh start

down: install ## Stop both environments
	scripts/env.sh stop

stop: down ## Alias for down

destroy: install ## Remove both environments and their data (asks first)
	scripts/env.sh destroy

purge: destroy ## Alias for destroy

status: install ## Show both environments
	scripts/env.sh status

open: ## Open wp-admin in a browser (both, or ENV=stable / ENV=nightly)
	scripts/env.sh open $(ENV)

test: install ## Run the decision tests against both environments
	npx vitest run

test-stable: install ## Run the decision tests against 7.1.4 only
	SITES=stable npx vitest run

test-nightly: install ## Run the decision tests against nightly only
	SITES=nightly npx vitest run

check: up test ## Start everything, then test
