# Wave — developer entry points. `make help` lists targets.
SHELL := /bin/sh
.DEFAULT_GOAL := help

COMPOSE ?= docker compose
EXEC_PHP := $(COMPOSE) exec -T php
TOOLS := $(COMPOSE) --profile tools

.PHONY: help env up down stop restart build pull ps logs sh console composer \
        init qa test test-media audit audit-php audit-symfony audit-composer audit-npm \
        fpm-config db-tunnel minio-init reset

help: ## Show this help
	@awk 'BEGIN {FS = ":.*##"} /^[a-zA-Z_-]+:.*##/ {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

.env:
	@cp .env.example .env
	@for key in APP_SECRET STREAM_SIGNING_KEY JWT_PASSPHRASE MINIO_ROOT_PASSWORD S3_SECRET_KEY; do \
		val=$$(openssl rand -hex 32); \
		sed -i.bak "s|^$$key=change-me$$|$$key=$$val|" .env; \
	done; rm -f .env.bak
	@echo ".env created from .env.example with random secrets"

env: .env ## Create .env from .env.example (random secrets) if missing

up: .env ## Build and start the dev stack (http://localhost:8090, Mailpit :8027)
	$(COMPOSE) up -d --build --remove-orphans
	@$(MAKE) --no-print-directory init
	@$(COMPOSE) ps

init: ## Idempotent app bootstrap: JWT keypair if missing (composer jwt-keys) + DB migrations
	@if [ -f backend/bin/console ] && [ -f backend/vendor/autoload_runtime.php ]; then \
		$(EXEC_PHP) composer jwt-keys --no-interaction && \
		$(EXEC_PHP) php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration; \
	else echo "init: backend not installed yet (make composer c=install), skipped"; fi

down: ## Stop and remove containers (volumes kept)
	$(COMPOSE) --profile tools down --remove-orphans

stop: ## Stop containers
	$(COMPOSE) stop

restart: down up ## Recreate the stack

build: .env ## Build php and worker images
	$(COMPOSE) build php worker

pull: ## Pull pinned third-party images
	$(COMPOSE) pull --ignore-buildable

ps: ## List services
	$(COMPOSE) ps

logs: ## Follow logs (s=<service> to filter)
	$(COMPOSE) logs -f --tail=100 $(s)

sh: ## Shell in the php container
	$(COMPOSE) exec php sh

console: ## Symfony console: make console c="debug:router"
	$(COMPOSE) exec php php bin/console $(c)

composer: ## Composer with registry access: make composer c="install"
	$(TOOLS) run --rm composer $(c)

test: ## Backend test suite
	$(EXEC_PHP) php vendor/bin/phpunit $(c)

test-media: ## Media tests with real ffmpeg (worker image)
	$(COMPOSE) run --rm --no-deps -T --entrypoint php worker vendor/bin/phpunit tests/Media $(c)

# Full quality gate, strictly sequential (the security suite and the media
# suite must never run at the same time: shared test DB).
qa: ## phpstan + php-cs-fixer + phpunit + test-media + audit, in sequence
	$(EXEC_PHP) php vendor/bin/phpstan analyse --memory-limit=1G --no-progress
	$(EXEC_PHP) php vendor/bin/php-cs-fixer fix --dry-run --diff --using-cache=no
	$(MAKE) --no-print-directory test
	$(MAKE) --no-print-directory test-media
	$(MAKE) --no-print-directory audit

fpm-config: ## Dump the effective FPM configuration (php-fpm -tt)
	$(EXEC_PHP) php-fpm -tt 2>&1

db-tunnel: ## Expose postgres on 127.0.0.1:5436 for a SQL client
	$(TOOLS) up -d db-tunnel

minio-init: ## Re-run bucket/policy bootstrap
	$(COMPOSE) up --force-recreate minio-init

reset: ## DANGER: remove containers AND volumes (db, objects, caches)
	$(COMPOSE) --profile tools down -v --remove-orphans

# ADR 006: non-zero exit if any check fails (all checks run, then report).
audit: ## Security audit: PHP >= 8.4.27, security-http >= 7.4.13, composer + npm audit
	@status=0; \
	$(MAKE) --no-print-directory audit-php || status=1; \
	$(MAKE) --no-print-directory audit-symfony || status=1; \
	$(MAKE) --no-print-directory audit-composer || status=1; \
	$(MAKE) --no-print-directory audit-npm || status=1; \
	if [ $$status -ne 0 ]; then echo "make audit: FAILED"; else echo "make audit: OK"; fi; \
	exit $$status

audit-php: ## Fail if the running php image is < 8.4.27
	@$(EXEC_PHP) sh -s < infra/scripts/check-php-version.sh

audit-symfony: ## Fail if symfony/security-http or http-kernel < 7.4.13
	@$(EXEC_PHP) sh -s < infra/scripts/check-symfony-security.sh

audit-composer: ## composer audit (needs registry access: tools profile)
	@$(TOOLS) run --rm composer audit --locked

audit-npm: ## npm audit --audit-level=high
	@$(COMPOSE) exec -T front npm audit --audit-level=high
