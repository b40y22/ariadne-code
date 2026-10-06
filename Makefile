export HOST_UID := $(shell id -u)
export HOST_GID := $(shell id -g)

RUN := docker compose run --rm php

.PHONY: build install test stan lint fix check demo

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

demo:
	$(RUN) php bin/ariadne analyze tests/fixtures/OrderService.php
