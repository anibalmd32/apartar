# Apartar — comandos de trabajo (05 §9.4).
#
# `config:cache` no aparece en ningún objetivo a propósito: cachear la
# configuración en desarrollo produce el clásico "cambié el .env y no toma
# efecto" (REQ-INF-004 RB-6 y SP-4). `optimize` es solo de producción y lo
# aplica el entrypoint cuando APP_ENV=production.

COMPOSE := docker compose
APP     := $(COMPOSE) exec app

.DEFAULT_GOAL := help
.PHONY: help up down fresh sh test check tenancy

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

test: ## Pest
	$(APP) ./vendor/bin/pest

check: ## Pint + PHPStan + Pest + ESLint + tsc + Vitest
	$(APP) ./vendor/bin/pint --test
	$(APP) ./vendor/bin/phpstan analyse
	$(APP) ./vendor/bin/pest
	$(COMPOSE) exec node npm run lint
	$(COMPOSE) exec node npx tsc --noEmit
	$(COMPOSE) exec node npx vitest run

tenancy: ## Auditoría de aislamiento + suite tests/Feature/Tenancy
	$(APP) php artisan tenancy:audit
	$(APP) ./vendor/bin/pest tests/Feature/Tenancy
