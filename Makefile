COMPOSE := docker compose
APP     := $(COMPOSE) exec app
NODE    := $(COMPOSE) exec node

.DEFAULT_GOAL := help
.PHONY: help up down fresh empty sh test check tenancy lint analyse

help: ## Lista los comandos disponibles
	@grep -hE '^[a-z-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-10s\033[0m %s\n", $$1, $$2}'

up: ## Levanta el stack (http://localhost:8080)
	$(COMPOSE) up -d --build

down: ## Baja el stack conservando los volúmenes
	$(COMPOSE) down

fresh: ## Borra todo y siembra el escenario de prueba
	$(APP) php artisan db:reset-demo --force

empty: ## Borra todo y deja la base vacía, como para producción
	$(APP) php artisan db:reset-empty

sh: ## Shell dentro del contenedor app
	$(APP) sh

lint: ## Pint
	$(APP) composer run lint

analyse: ## PHPStan nivel 6
	$(APP) composer run analyse

test: ## Pest
	$(APP) composer run test

check: ## Pint + PHPStan + Pest + ESLint + tsc + Vitest + build
	$(APP) composer run check
	$(NODE) npm run check

tenancy: ## Gate de aislamiento multi-tenant (auditoría estática + suite)
	$(APP) composer run tenancy
