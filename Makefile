.PHONY: help install up down restart logs test test-local clean

PORT ?= 8080

help:
	@echo "EQ Finance - Commands:"
	@echo "  make install    Build and start containers (one-command setup)"
	@echo "  make up         Start containers with docker compose"
	@echo "  make down       Stop containers"
	@echo "  make restart    Restart containers"
	@echo "  make logs       Stream container logs"
	@echo "  make test       Run test suite in container"
	@echo "  make test-local Run tests with local PHP"

install: up

up:
	docker compose up -d --build
	@echo "EQ Finance running at http://localhost:$(PORT)/admin/"

down:
	docker compose down

restart: down up

logs:
	docker compose logs -f

test:
	docker compose exec web php tests/run-tests.php

test-local:
	php tests/run-tests.php

