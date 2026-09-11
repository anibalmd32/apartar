# Apartar — comandos de trabajo (05 §9.4).
#
# Cada objetivo es un **alias de una línea** sobre los scripts de `composer` y
# de `npm`: la receta se escribe una sola vez y CI invoca esos scripts, no
# `make`. Así nadie queda bloqueado por no tener `make` instalado y no hay dos
# definiciones de la misma comprobación divergiendo.
#
# `config:cache` no aparece en ningún objetivo a propósito: cachear la
# configuración en desarrollo produce el clásico "cambié el .env y no toma
# efecto" (REQ-INF-004 RB-6 y SP-4). `optimize` es solo de producción y lo
# aplica el entrypoint cuando APP_ENV=production.

COMPOSE := docker compose
APP     := $(COMPOSE) exec app
NODE    := $(COMPOSE) exec node

.DEFAULT_GOAL := help
.PHONY: help up down fresh sh test check tenancy lint analyse

help: ## Lista los comandos disponibles
	@grep -hE '^[a-z-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-10s\033[0m %s\n", $$1, $$2}'

up: ## Levanta el stack (http://localhost:8080)
	$(COMPOSE) up -d --build

down: ## Baja el stack conservando los volúmenes
	$(COMPOSE) down

fresh: ## Recrea el esquema y siembra el escenario de desarrollo
	$(APP) php artisan migrate:fresh --seed --seeder=Database\\Seeders\\DevSeeder

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

tenancy: ## Gate de aislamiento multi-tenant
	$(APP) composer run tenancy
