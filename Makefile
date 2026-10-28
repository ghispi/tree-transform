PHP_VERSION ?= 8.4
DC = PHP_VERSION=$(PHP_VERSION) HOST_UID=$(shell id -u) HOST_GID=$(shell id -g) docker compose
RUN = $(DC) run --rm php

.PHONY: help build install update shell test coverage stan cs cs-fix infection bench docs ci

help: ## Show targets
	@grep -E '^[a-z-]+:.*##' $(MAKEFILE_LIST) | awk -F':.*## ' '{printf "  %-12s %s\n", $$1, $$2}'

build: ## Build the PHP image (PHP_VERSION=8.4|8.5)
	$(DC) build

install: ## composer install
	$(RUN) composer install

update: ## composer update
	$(RUN) composer update

shell: ## Shell in the PHP container
	$(RUN) sh

test: ## Run PHPUnit
	$(RUN) vendor/bin/phpunit

coverage: ## PHPUnit with coverage report
	$(RUN) vendor/bin/phpunit --coverage-text --coverage-html var/coverage

stan: ## PHPStan
	$(RUN) vendor/bin/phpstan analyse

cs: ## Check code style
	$(RUN) vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix: ## Fix code style
	$(RUN) vendor/bin/php-cs-fixer fix

infection: ## Mutation testing
	$(RUN) vendor/bin/infection --threads=max

bench: ## phpbench
	$(RUN) vendor/bin/phpbench run --report=aggregate

docs: ## Regenerate example outputs and the example blocks in the docs
	$(RUN) php bin/docs-sync --outputs

ci: cs stan test ## Everything CI runs (fast part)
