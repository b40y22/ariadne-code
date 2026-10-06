export HOST_UID := $(shell id -u)
export HOST_GID := $(shell id -g)

RUN := docker compose run --rm php

.PHONY: build install test stan lint fix check demo snapshots up down web-install web-check

build:
	docker compose build

install:
	$(RUN) composer install

test:
	$(RUN) vendor/bin/phpunit

stan:
	$(RUN) vendor/bin/phpstan analyse --memory-limit=512M

lint:
	$(RUN) vendor/bin/pint --test

fix:
	$(RUN) vendor/bin/pint

check: lint stan test

# `make up PROJECT=path/to/code` opens a directory in project mode; plain `make up` opens the demo project.
up:
	$(if $(PROJECT),PROJECT_DIR=$(abspath $(PROJECT)) PROJECT_ROOT=/project PROJECT_NAME=$(notdir $(abspath $(PROJECT)))) docker compose up -d api web
	@echo "UI: http://localhost:$${WEB_PORT:-5180}   API: http://localhost:$${API_PORT:-8090}"

down:
	docker compose down

web-install:
	docker compose run --rm web npm ci

web-check:
	docker compose run --rm web npm run typecheck
	docker compose run --rm web npm test

# Rebuilds tests/fixtures/*.graph.json from the fixtures. Review the diff: it is the visible effect of an analyzer change.
snapshots:
	$(RUN) sh -c 'cd tests/fixtures && for f in *.php; do php ../../bin/ariadne analyze $$f > $${f%.php}.graph.json; done'

demo:
	$(RUN) php bin/ariadne analyze tests/fixtures/OrderShowcase.php
