SHELL := /bin/bash

YARN := $(shell command -v yarn 2> /dev/null)
NODE_PREFIX=$(shell pwd)
COMPOSER_BIN := $(shell command -v composer 2> /dev/null)
NPM := $(shell command -v npm 2> /dev/null)

# bin file definitions
PHPUNIT=php -d zend.enable_gc=0  "$(PWD)/../../lib/composer/bin/phpunit"
PHPUNITDBG=phpdbg -qrr -d memory_limit=4096M -d zend.enable_gc=0 "$(PWD)/../../lib/composer/bin/phpunit"
PHP_CS_FIXER=php -d zend.enable_gc=0 vendor-bin/owncloud-codestyle/vendor/bin/php-cs-fixer
PHAN=php -d zend.enable_gc=0 vendor-bin/phan/vendor/bin/phan
PHPSTAN=php -d zend.enable_gc=0 vendor-bin/phpstan/vendor/bin/phpstan

KARMA=$(NODE_PREFIX)/node_modules/.bin/karma

app_name=openidconnect
build_dir=$(CURDIR)/build
dist_dir=$(build_dir)/dist
src_files=README.md LICENSE
src_dirs=appinfo img l10n lib vendor
all_src=$(src_dirs) $(src_files)

occ=$(CURDIR)/../../occ
private_key=$(HOME)/.owncloud/certificates/$(app_name).key
certificate=$(HOME)/.owncloud/certificates/$(app_name).crt
sign=$(occ) integrity:sign-app --privateKey="$(private_key)" --certificate="$(certificate)"
sign_skip_msg="Skipping signing, either no key and certificate found in $(private_key) and $(certificate) or occ can not be found at $(occ)"
ifneq (,$(wildcard $(private_key)))
ifneq (,$(wildcard $(certificate)))
ifneq (,$(wildcard $(occ)))
	CAN_SIGN=true
endif
endif
endif

.DEFAULT_GOAL := all

help:
	@fgrep -h "##" $(MAKEFILE_LIST) | fgrep -v fgrep | sed -e 's/\\$$//' | sed -e 's/##//' | sed -e 's/  */ /' | column -t -s :

##
## Entrypoints
##----------------------

.PHONY: all
all: install-deps

# Remove the appstore build
.PHONY: clean
clean: clean-nodejs-deps clean-composer-deps
	rm -rf ./build

.PHONY: clean-nodejs-deps
clean-nodejs-deps:
	rm -Rf $(nodejs_deps)

.PHONY: clean-composer-deps
clean-composer-deps:
	rm -rf ./vendor
	rm -Rf vendor-bin/**/vendor vendor-bin/**/composer.lock

.PHONY: dev
dev: ## Initialize dev environment
dev: install-deps

$(KARMA): $(nodejs_deps)

.PHONY: dist
dist: ## Build distribution
dist: composer distdir sign package

.PHONY: composer
composer:
	$(COMPOSER_BIN) install --no-dev

.PHONY: distdir
distdir:
	rm -rf $(dist_dir)
	mkdir -p $(dist_dir)/$(app_name)
	cp -R $(all_src) $(dist_dir)/$(app_name)
	rm -Rf $(dist_dir)/$(app_name)/l10n/.tx
# appinfo/ is copied wholesale and signature.json is not gitignored, so one left
# behind in the source tree - by a stray `integrity:sign-app --path=.`, or by
# getting committed - would travel into the package. With no key present there is
# no re-sign to overwrite it, and it would then be packaged as a signature whose
# hashes describe a different tree.
	rm -f $(dist_dir)/$(app_name)/appinfo/signature.json

.PHONY: sign
sign:
ifdef CAN_SIGN
	$(sign) --path="$(dist_dir)/$(app_name)"
else
	@echo $(sign_skip_msg)
endif

.PHONY: package
package:
# This branch targets ownCloud 10, whose integrity checker reads a single
# `certificate` and RSA/PSS only - it has no dispatch on the `v`/`alg` fields the
# current signature format adds. A package signed in that newer format fails
# integrity:check-app with "App Certificate is not valid" on every oc10 install,
# which is exactly what shipped as v2.3.4. Refuse to package one.
#
# This checks the envelope format, not whether the certificate chains to core's
# root - it cannot: `occ integrity:check-app` is useless here because
# Checker::isCodeCheckEnforced() returns false for the `git` channel, so any occ
# reachable from a build tree reports success unconditionally. The authoritative
# check is installing the built artifact into a real owncloud/server:10.16.x and
# running integrity:check-app there, which is part of cutting a release on this
# line. Classification matches core's own (v/certificates => current), and
# anything it cannot classify fails rather than passing.
#
# An unsigned build is tolerated by default, because that is what CI produces -
# the trivy workflow builds the dist tree with no signing secrets. Pass
# REQUIRE_SIGNATURE=1 to reject it, which is what cutting a release does:
# CAN_SIGN degrades to a printed message when the key, the cert or occ is
# missing, so without that the release path can hand back an unsigned tarball
# and exit 0.
#
# Which format is required is read from appinfo/info.xml's max-version rather
# than hardcoded, so this hunk is inert rather than harmful if it is ever merged
# or cherry-picked towards a branch targeting ownCloud 11. The version is parsed
# as XML, not by line-matching, so reformatting info.xml cannot silently disable
# the check - an unreadable max-version is an error, like an unclassifiable
# signature. That test has to come before the unsigned one, or REQUIRE_SIGNATURE=1
# on an ownCloud 11 branch would fail a build this check has no opinion about.
	@verdict=$$(php -d display_errors=0 -d error_reporting=0 -r '$$xml = @simplexml_load_file($$argv[1]); $$max = $$xml === false ? null : (string)($$xml->dependencies->owncloud["max-version"] ?? ""); if ($$max === null || $$max === "") { echo "noversion"; exit; } if ($$max !== "10" && \strpos($$max, "10.") !== 0) { echo "notoc10"; exit; } if (!\file_exists($$argv[2])) { echo "unsigned"; exit; } $$d = json_decode(file_get_contents($$argv[2]), true); if (!\is_array($$d)) { echo "unclassifiable"; } elseif (isset($$d["v"]) || isset($$d["certificates"])) { echo "current"; } elseif (isset($$d["certificate"], $$d["hashes"], $$d["signature"])) { echo "legacy"; } else { echo "unclassifiable"; }' appinfo/info.xml "$(dist_dir)/$(app_name)/appinfo/signature.json" 2>/dev/null); \
	case "$$verdict" in \
		legacy|notoc10) ;; \
		unsigned) case "$(REQUIRE_SIGNATURE)" in \
				''|0|no|false) ;; \
				*) echo "ERROR: REQUIRE_SIGNATURE was asked for but the package is unsigned."; \
					echo "       $(sign_skip_msg)"; exit 1;; \
			esac;; \
		current) echo "ERROR: appinfo/signature.json is in the current signature format, which ownCloud 10 cannot verify."; \
			echo "       Sign this release line with occ integrity:sign-app and its G1 key."; exit 1;; \
		noversion) echo "ERROR: could not read max-version from appinfo/info.xml, so the required"; \
			echo "       signature format is unknown. Refusing to package."; exit 1;; \
		'') echo "ERROR: php produced no verdict. Is php on PATH and built with simplexml?"; \
			echo "       Refusing to package without checking the signature."; exit 1;; \
		*) echo "ERROR: could not classify appinfo/signature.json (php said '$$verdict')."; \
			echo "       Refusing to package a signature that cannot be checked."; exit 1;; \
	esac
	tar -czf $(dist_dir)/$(app_name).tar.gz -C $(dist_dir) $(app_name)

##
## Tests
##----------------------

.PHONY: test-php-unit
test-php-unit: ## Run php unit tests
test-php-unit: vendor/bin/phpunit
	$(PHPUNIT) --configuration ./phpunit.xml --testsuite openidconnect-unit

.PHONY: test-php-unit-dbg
test-php-unit-dbg: ## Run php unit tests using phpdbg
test-php-unit-dbg: vendor/bin/phpunit
	$(PHPUNITDBG) --configuration ./phpunit.xml --testsuite openidconnect-unit

.PHONY: test-php-style
test-php-style: ## Run php-cs-fixer and check owncloud code-style
test-php-style: vendor-bin/owncloud-codestyle/vendor
	$(PHP_CS_FIXER) fix -v --diff --allow-risky yes --dry-run

.PHONY: test-php-style-fix
test-php-style-fix: ## Run php-cs-fixer and fix code style issues
test-php-style-fix: vendor-bin/owncloud-codestyle/vendor
	$(PHP_CS_FIXER) fix -v --diff --allow-risky yes

.PHONY: test-php-phan
test-php-phan: ## Run phan
test-php-phan: vendor-bin/phan/vendor
	$(PHAN) --config-file .phan/config.php --require-config-exists

.PHONY: test-php-phpstan
test-php-phpstan: ## Run phpstan
test-php-phpstan: vendor-bin/phpstan/vendor
	$(PHPSTAN) analyse --memory-limit=4G --configuration=./phpstan.neon --no-progress --level=5 appinfo lib

.PHONY: test-js
test-js: $(nodejs_deps)
	$(KARMA) start tests/js/karma.config.js --single-run

##
## Dependency management
##----------------------

.PHONY: install-deps
install-deps: ## Install dependencies
install-deps: install-php-deps install-js-deps

composer.lock: composer.json
	@echo composer.lock is not up to date.

.PHONY: install-php-deps
install-php-deps: ## Install PHP dependencies
install-php-deps: vendor vendor-bin composer.json composer.lock

.PHONY: install-js-deps
install-js-deps: ## Install PHP dependencies
install-js-deps: $(nodejs_deps)

vendor: composer.lock
	$(COMPOSER_BIN) install --no-dev

vendor/bin/phpunit: composer.lock
	$(COMPOSER_BIN) install

vendor/bamarni/composer-bin-plugin: composer.lock
	$(COMPOSER_BIN) install

vendor-bin/owncloud-codestyle/vendor: vendor/bamarni/composer-bin-plugin vendor-bin/owncloud-codestyle/composer.lock
	$(COMPOSER_BIN) bin owncloud-codestyle install --no-progress

vendor-bin/owncloud-codestyle/composer.lock: vendor-bin/owncloud-codestyle/composer.json
	@echo owncloud-codestyle composer.lock is not up to date.

vendor-bin/phan/vendor: vendor/bamarni/composer-bin-plugin vendor-bin/phan/composer.lock
	$(COMPOSER_BIN) bin phan install --no-progress

vendor-bin/phan/composer.lock: vendor-bin/phan/composer.json
	@echo phan composer.lock is not up to date.

vendor-bin/phpstan/vendor: vendor/bamarni/composer-bin-plugin vendor-bin/phpstan/composer.lock
	$(COMPOSER_BIN) bin phpstan install --no-progress

vendor-bin/phpstan/composer.lock: vendor-bin/phpstan/composer.json
	@echo phpstan composer.lock is not up to date.


#
# Translation
#--------------------------------------

.PHONY: l10n-push
l10n-push:
	cd l10n && tx push -s --skip

.PHONY: l10n-pull
l10n-pull:
	cd l10n && tx pull -a --skip --minimum-perc=75

.PHONY: l10n-clean
l10n-clean:
	rm -rf l10n/l10n.pl
	find l10n -type f -name \*.po -or -name \*.pot | xargs rm -f
	find l10n -type f -name uz.\* -or -name yo.\* -or -name ne.\* -or -name or_IN.\* | xargs git rm -f || true

.PHONY: l10n-read
l10n-read: l10n/l10n.pl
	cd l10n && perl l10n.pl $(app_name) read

.PHONY: l10n-write
l10n-write: l10n/l10n.pl
	cd l10n && perl l10n.pl $(app_name) write

l10n/l10n.pl:
	wget -qO l10n/l10n.pl https://raw.githubusercontent.com/owncloud-ci/transifex/d1c63674d791fe8812216b29da9d8f2f26e7e138/rootfs/usr/bin/l10n
