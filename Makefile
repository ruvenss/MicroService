# MicroService — common tasks. The "auto-docker" entrypoints are build/up.
.PHONY: help build up down logs shell key test stan cs dev-db

help:
	@echo "build   - build the production app image"
	@echo "up      - start app + redis (external DB via .env)"
	@echo "down    - stop the app stack"
	@echo "logs    - tail app logs"
	@echo "shell   - shell into the app container"
	@echo "key     - mint an API key inside the container (SCOPES=... NAME=...)"
	@echo "dev-db  - start the dev MySQL + Redis (docker-compose.dev.yml)"
	@echo "test/stan/cs - run the host quality gates"

build:
	docker compose build

up:
	docker compose up -d --build

down:
	docker compose down

logs:
	docker compose logs -f app

shell:
	docker compose exec app bash

key:
	docker compose exec app php spark key:create --name "$(NAME)" --scopes "$(SCOPES)"

dev-db:
	docker compose -f docker-compose.dev.yml up -d

test:
	composer test

stan:
	composer stan

cs:
	composer cs
