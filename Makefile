.DEFAULT_GOAL := help
.PHONY: help start stop install db db-reset lint stan test test-unit test-integration test-contract cc

SYMFONY  = symfony console
COMPOSER = composer
PHP      = php
PHPUNIT  = vendor/bin/phpunit
PHPSTAN  = vendor/bin/phpstan
PHPCSFIXER = vendor/bin/php-cs-fixer

# ── Couleurs ─────────────────────────────────────────────────────────────────
BOLD  = \033[1m
GREEN = \033[0;32m
RESET = \033[0m

## ─────────────────────────────────────────────────────────────────────────────
## Démarrage
## ─────────────────────────────────────────────────────────────────────────────

start: install db ## Installe les dépendances, initialise la DB et démarre le serveur
	@echo "$(GREEN)$(BOLD)Symfony KYC — démarrage...$(RESET)"
	symfony serve --daemon --no-tls
	@echo "$(GREEN)$(BOLD)Serveur démarré sur http://localhost:8000$(RESET)"
	@$(SYMFONY) about

stop: ## Arrête le serveur Symfony
	symfony server:stop

## ─────────────────────────────────────────────────────────────────────────────
## Installation
## ─────────────────────────────────────────────────────────────────────────────

install: ## Installe les dépendances Composer
	$(COMPOSER) install --prefer-dist --no-interaction

## ─────────────────────────────────────────────────────────────────────────────
## Base de données
## ─────────────────────────────────────────────────────────────────────────────

db: ## Initialise la base et joue les migrations (SQLite : fichier créé automatiquement)
	$(SYMFONY) doctrine:migrations:migrate --no-interaction --allow-no-migration

db-reset: ## Recrée la base from scratch (drop + migrate)
	$(SYMFONY) doctrine:database:drop --force --if-exists
	$(SYMFONY) doctrine:migrations:migrate --no-interaction --allow-no-migration

## ─────────────────────────────────────────────────────────────────────────────
## Qualité de code
## ─────────────────────────────────────────────────────────────────────────────

lint: ## Vérifie le style (PHP CS Fixer dry-run)
	$(PHPCSFIXER) fix --dry-run --diff

lint-fix: ## Corrige le style automatiquement
	$(PHPCSFIXER) fix

stan: ## Analyse statique PHPStan niveau 9
	$(PHPSTAN) analyse --no-progress

## ─────────────────────────────────────────────────────────────────────────────
## Tests
## ─────────────────────────────────────────────────────────────────────────────

test: ## Lance toutes les suites de tests
	$(PHPUNIT) --testdox

test-unit: ## Lance uniquement les tests unitaires (domaine)
	$(PHPUNIT) --testsuite Unit --testdox

test-integration: ## Lance uniquement les tests d'intégration
	$(PHPUNIT) --testsuite Integration --testdox

test-contract: ## Lance uniquement les tests de contrat des ports
	$(PHPUNIT) --testsuite Contract --testdox

## ─────────────────────────────────────────────────────────────────────────────
## Utilitaires
## ─────────────────────────────────────────────────────────────────────────────

cc: ## Vide le cache Symfony
	$(SYMFONY) cache:clear

check: lint stan test ## Passe lint + stan + tests (CI locale complète)

help: ## Affiche cette aide
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  $(BOLD)%-18s$(RESET) %s\n", $$1, $$2}'
