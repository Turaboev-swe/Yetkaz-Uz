.DEFAULT_GOAL := help
DC := docker compose

.PHONY: help build up down restart logs shell psql redis \
        migrate fresh seed test key install-deps horizon reverb

help: ## Buyruqlar ro'yxati
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | \
	  awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

build: ## Docker image'larni yig'ish
	$(DC) build

up: ## Barcha servislarni ko'tarish (app, horizon, reverb, scheduler, postgres, redis)
	$(DC) up -d

up-dev: ## up + Vite dev server
	$(DC) --profile dev up -d

down: ## Servislarni to'xtatish
	$(DC) down

down-v: ## Servislarni to'xtatish + volume'larni o'chirish (baza tozalanadi)
	$(DC) down -v

restart: ## app'ni qayta ishga tushirish
	$(DC) restart app

logs: ## Loglar (app)
	$(DC) logs -f app

shell: ## app konteyneriga kirish
	$(DC) exec app bash

psql: ## PostgreSQL konsoli
	$(DC) exec postgres psql -U yetkaz -d yetkaz

redis: ## Redis konsoli
	$(DC) exec redis redis-cli

install-deps: ## Bog'liqliklarni o'rnatish (vendor volume yangilash)
	$(DC) run --rm app composer install

key: ## APP_KEY generatsiya
	$(DC) run --rm app php artisan key:generate

migrate: ## Migratsiyalar
	$(DC) exec app php artisan migrate

fresh: ## Bazani qayta qurish + seed
	$(DC) exec app php artisan migrate:fresh --seed

seed: ## Namuna ma'lumot
	$(DC) exec app php artisan db:seed

test: ## Testlar (yetkaz_test bazasida)
	$(DC) exec app php artisan test

horizon: ## Horizon holati
	$(DC) exec app php artisan horizon:status
