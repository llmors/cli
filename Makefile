.DEFAULT_GOAL := help
SHELL := /bin/bash

PHP ?= php
COMPOSER ?= composer
BOX := $(PHP) -d phar.readonly=0 vendor/bin/box

.PHONY: help install cs cs-fix stan test check phar binary clean

help: ## List available targets
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

install: ## Install Composer dependencies
	$(COMPOSER) install

cs: ## Check coding standards (dry-run)
	$(PHP) vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix: ## Apply coding-standard fixes
	$(PHP) vendor/bin/php-cs-fixer fix

stan: ## Run static analysis
	$(PHP) vendor/bin/phpstan analyse

test: ## Run the test suite
	$(PHP) vendor/bin/phpunit

check: cs stan test ## Run cs + stan + test

phar: ## Compile build/llmor.phar (Box excludes dev dependencies automatically)
	$(COMPOSER) install --optimize-autoloader
	$(BOX) compile
	@echo "Built build/llmor.phar"

binary: ## Build a self-contained static binary (requires static-php-cli on PATH)
	bash scripts/build-binary.sh

clean: ## Remove build artefacts
	rm -rf build .phpunit.cache .php-cs-fixer.cache
