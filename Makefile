COMPOSE := docker compose
DEV     := $(COMPOSE) -f docker-compose.yml -f docker-compose.override.yml -f docker-compose.dev.yml --profile dev
APP     := $(COMPOSE) exec app

.DEFAULT_GOAL := help
.PHONY: help up down dev logs fresh empty sh test check tenancy lint analyse

help: ## Lista los comandos disponibles
	@grep -hE '^[a-z-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-10s\033[0m %s\n", $$1, $$2}'

up: ## Construye las imágenes y levanta el stack como en producción (http://localhost:8080)
	$(COMPOSE) up -d --build --remove-orphans

down: ## Baja el stack conservando los volúmenes
	$(DEV) down --remove-orphans

dev: ## Levanta el stack con el código montado y Vite en caliente (más lento en Windows)
	$(DEV) up -d --build --remove-orphans

logs: ## Sigue los logs de todos los servicios
	$(COMPOSE) logs -f --tail=100

fresh: ## Borra todo y siembra el escenario de prueba dentro de Docker
	$(APP) php artisan db:reset-demo --force

empty: ## Borra todo y deja la base vacía, como para producción, dentro de Docker
	$(APP) php artisan db:reset-empty

sh: ## Shell dentro del contenedor app
	$(APP) sh

lint: ## Pint, en el host
	composer run lint

analyse: ## PHPStan nivel 6, en el host
	composer run analyse

test: ## Pest, en el host
	composer run test

check: ## Pint + PHPStan + Pest + ESLint + tsc + Vitest + build, en el host
	composer run check
	npm run check

tenancy: ## Gate de aislamiento multi-tenant, en el host
	composer run tenancy
