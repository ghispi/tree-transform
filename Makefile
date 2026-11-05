PHP_VERSION ?= 8.4
DC = PHP_VERSION=$(PHP_VERSION) HOST_UID=$(shell id -u) HOST_GID=$(shell id -g) docker compose
RUN = $(DC) run --rm php

.PHONY: help build install update shell test coverage stan cs cs-fix infection bench bench-report docs docs-site test-db db-down api-snapshot bc-check ci

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

docs-site: ## Build the documentation site into var/docs-site/site (DOCS_REF: git ref for links out of docs/, default main)
	$(RUN) env DOCS_REF=$(or $(DOCS_REF),main) php bin/docs-site
	$(DC) --profile docs build -q docs
	$(DC) --profile docs run --rm docs zensical build --clean --strict

test-db: ## Integration tests on MySQL and PostgreSQL (starts both in Docker)
	$(DC) --profile db up -d --wait mysql postgres
	$(DC) run --rm -e TT_DATABASE_URL=pdo-mysql://app:app@mysql/tree_transform php vendor/bin/phpunit --testsuite integration
	$(DC) run --rm -e TT_DATABASE_URL=pdo-pgsql://app:app@postgres/tree_transform php vendor/bin/phpunit --testsuite integration

db-down: ## Stop the test databases
	$(DC) --profile db down

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

bench-report: ## Full benchmark matrix on PHP 8.4 and 8.5, JIT off and on (~35 min, quiet machine); rewrites the tables in docs/benchmarks.md
	@for v in 8.4 8.5; do for j in off on; do \
		echo "== PHP $$v, JIT $$j"; \
		PHP_VERSION=$$v HOST_UID=$$(id -u) HOST_GID=$$(id -g) docker compose run --rm php sh benchmarks/run-report.sh $$j var/bench-report/php$$v-jit-$$j.xml || exit 1; \
	done; done
	$(RUN) php benchmarks/render-report.php var/bench-report docs/benchmarks.md

docs: ## Regenerate example outputs and the example blocks in the docs
	$(RUN) php bin/docs-sync --outputs

api-snapshot: ## Record the public API in tests/Api/public-api.txt (review the diff)
	$(DC) run --rm -e UPDATE_API_SNAPSHOT=1 php vendor/bin/phpunit --testsuite api

BC_FROM ?= $(shell git describe --tags --abbrev=0)
bc-check: ## Backward compatibility check against the latest tag (BC_FROM=vX.Y.Z for another)
	$(RUN) composer install -d tools/bc-check --no-interaction --quiet
	$(RUN) tools/bc-check/vendor/bin/roave-backward-compatibility-check --from=$(BC_FROM) --format=markdown

ci: cs stan test ## Everything CI runs (fast part)
