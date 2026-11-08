# Compilazione e pacchettizzazione dell'app Impagina.
#
# `make dist` produce build/artifacts/impagina-<versione>.tar.gz, l'archivio da
# scompattare in custom_apps/ di una istanza Nextcloud.

app_name := impagina
build_dir := $(CURDIR)/build
staging_dir := $(build_dir)/$(app_name)
artifacts_dir := $(build_dir)/artifacts
version := $(shell sed -n 's|.*<version>\(.*\)</version>.*|\1|p' appinfo/info.xml)

composer := COMPOSER_ALLOW_SUPERUSER=1 composer
php := php -d memory_limit=900M

.PHONY: aiuto
aiuto:
	@echo "make deps      installa le dipendenze PHP e JS"
	@echo "make build     compila il frontend"
	@echo "make test      linter, analisi statica e test"
	@echo "make dist      crea build/artifacts/$(app_name)-$(version).tar.gz"
	@echo "make clean     rimuove build/ e gli artefatti compilati"

.PHONY: deps
deps:
	$(composer) install
	npm ci

.PHONY: build
build:
	# Vite non svuota js/ e css/: senza questo, i chunk delle build
	# precedenti finirebbero nell'archivio.
	rm -rf js css
	npm run build

.PHONY: test
test:
	$(composer) lint
	$(php) vendor/bin/php-cs-fixer fix --dry-run --diff
	$(php) vendor/bin/psalm --threads=1 --no-cache --no-progress
	$(php) vendor/bin/rector process --dry-run --no-progress-bar
	$(php) vendor/bin/phpunit -c tests/phpunit.xml --fail-on-warning --fail-on-risky
	npm run lint
	npm run stylelint
	npm run typecheck

# L'archivio contiene solo cio' che serve a runtime: niente sorgenti del
# frontend, niente dipendenze di sviluppo, niente test.
.PHONY: dist
dist: build
	rm -rf "$(staging_dir)"
	mkdir -p "$(staging_dir)" "$(artifacts_dir)"
	cp -r appinfo lib templates img js css l10n "$(staging_dir)/"
	cp composer.json LICENSE README.md CHANGELOG.md "$(staging_dir)/"
	# Il codice dell'app e' MIT, ma js/ incorpora librerie @nextcloud/* sotto
	# AGPL/GPL (elencate nei file js/*.license): il loro testo viaggia con l'archivio.
	cp -r LICENSES "$(staging_dir)/"
	# L'app non ha dipendenze di produzione, ma l'autoloader PSR-4 serve.
	$(composer) dump-autoload --no-dev --optimize --quiet
	mkdir -p "$(staging_dir)/vendor"
	cp vendor/autoload.php "$(staging_dir)/vendor/"
	cp -r vendor/composer "$(staging_dir)/vendor/"
	$(composer) dump-autoload --quiet
	find "$(staging_dir)" -name '*.map' -delete
	tar -czf "$(artifacts_dir)/$(app_name)-$(version).tar.gz" \
		--owner=0 --group=0 \
		-C "$(build_dir)" "$(app_name)"
	@echo "creato $(artifacts_dir)/$(app_name)-$(version).tar.gz"

.PHONY: clean
clean:
	rm -rf "$(build_dir)" js css
