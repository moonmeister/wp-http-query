# Test environment and decision tests for the feature plugin.
#
#   make up        start both WordPress environments (7.1.4 and nightly)
#   make test      run the decision tests against both
#   make check     up + test
#   make down      stop both;  make destroy  remove them entirely
#
# Requires Docker and Node 20+.

.DEFAULT_GOAL := help
.PHONY: help install up down destroy status test test-stable test-nightly check

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

destroy: install ## Remove both environments and their data
	scripts/env.sh destroy

status: install ## Show both environments
	scripts/env.sh status

test: install ## Run the decision tests against both environments
	npx vitest run

test-stable: install ## Run the decision tests against 7.1.4 only
	SITES=stable npx vitest run

test-nightly: install ## Run the decision tests against nightly only
	SITES=nightly npx vitest run

check: up test ## Start everything, then test
