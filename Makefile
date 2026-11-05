# Test environment and decision tests for the feature plugin.
#
#   make up        start the WordPress test site (nightly: the plugin needs 7.2+)
#   make test      run the decision tests against it
#   make check     up + test
#   make dist      build dist/wp-http-query-<version>.zip (VERSION=0.1.0 stamps that version in)
#   make lint      PHPCS (WordPress standard + PHPCompatibility) on plugin/
#   make open      open wp-admin
#   make down      stop it
#   make destroy   remove it and its data (asks first)
#
#   make demo          a separate site for trying the plugin by hand: nightly, :8873, plugin only,
#                      default settings, no test fixtures. Opens Settings → HTTP QUERY
#   make demo-down     stop it
#   make demo-destroy  remove it and its data (asks first)
#
# Requires Docker and Node 24.18+ (26, the current LTS, recommended; see .nvmrc). make lint needs PHP and Composer instead.

.DEFAULT_GOAL := help
.PHONY: help install up down destroy status open test check lint demo demo-down demo-destroy

help: ## List targets
	@grep -E '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | awk -F':.*## ' '{printf "  %-14s %s\n", $$1, $$2}'

node_modules: package.json
	npm install
	@touch node_modules

install: node_modules ## Install wp-env, vitest and test deps

up: install ## Start the test site (nightly :8872)
	scripts/env.sh start

down: install ## Stop the test site
	scripts/env.sh stop

destroy: install ## Remove the test site and its data (asks first)
	scripts/env.sh destroy

status: install ## Show the test site
	scripts/env.sh status

open: ## Open wp-admin in a browser
	scripts/env.sh open $(ENV)

test: install ## Run the decision tests
	SITES=$(SITES) npx vitest run
	@# Then the tests that change site state, serially, never alongside the above.
	SITES=$(SITES) npx vitest run --config vitest.lifecycle.config.ts

check: up test ## Start everything, then test

demo: install ## Start the try-by-hand site (nightly :8873, plugin only, defaults) and open its settings
	scripts/env.sh start demo
	scripts/env.sh open demo

demo-down: install ## Stop the demo site
	scripts/env.sh stop demo

demo-destroy: install ## Remove the demo site and its data (asks first)
	scripts/env.sh destroy demo

.PHONY: dist
dist: ## Build the plugin zip, dist/wp-http-query-<version>.zip (VERSION=x.y.z stamps a version)
	scripts/dist.sh "$(VERSION)"

vendor: composer.json composer.lock
	composer install
	@touch vendor

lint: vendor ## Check plugin/ against WordPress Coding Standards and PHP 8.0+ (needs PHP + Composer)
	vendor/bin/phpcs
