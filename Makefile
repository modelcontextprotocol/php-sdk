.PHONY: deps-stable deps-low cs phpstan tests unit-tests integration-tests inspector-tests coverage ci ci-stable ci-lowest conformance-tests conformance-server conformance-client conformance-draft conformance-draft-server conformance-draft-client docs

# The 2026-07-28 scenarios ship on the `alpha` dist-tag; `latest` (0.1.x) has
# none of them. Pinned to the same version CI runs (see
# .github/workflows/pipeline.yaml), so a local pass means a green pipeline.
CONFORMANCE_VERSION ?= 0.2.0-alpha.11
CONFORMANCE = npx --yes @modelcontextprotocol/conformance@$(CONFORMANCE_VERSION)

deps-stable:
	composer update --prefer-stable

deps-low:
	composer update --prefer-lowest

cs:
	vendor/bin/php-cs-fixer fix --diff --verbose

phpstan:
	vendor/bin/phpstan --memory-limit=-1

tests:
	vendor/bin/phpunit

unit-tests:
	vendor/bin/phpunit --testsuite=unit

integration-tests:
	vendor/bin/phpunit --testsuite=integration

inspector-tests:
	vendor/bin/phpunit --testsuite=inspector

conformance-tests: conformance-server conformance-client

conformance-server:
	docker compose -f tests/Conformance/Fixtures/docker-compose.yml up -d
	@echo "Waiting for server to start..."
	@sleep 5
	rm -rf tests/Conformance/results
	cd tests/Conformance && $(CONFORMANCE) server --url http://localhost:8000/ --suite all --spec-version 2025-11-25 --expected-failures conformance-baseline-2025-11-25.yml --output-dir results || true
	php tests/Conformance/score.php server 2025-11-25
	docker compose -f tests/Conformance/Fixtures/docker-compose.yml down

conformance-client:
	rm -rf tests/Conformance/results
	cd tests/Conformance && $(CONFORMANCE) client --command "php $(CURDIR)/tests/Conformance/client.php" --suite all --spec-version 2025-11-25 --expected-failures conformance-baseline-2025-11-25.yml --output-dir results || true
	php tests/Conformance/score.php client 2025-11-25

# --- 2026-07-28 (SEP-2575 stateless lifecycle) ------------------------------

conformance-draft: conformance-draft-server conformance-draft-client

conformance-draft-server:
	docker compose -f tests/Conformance/Fixtures/docker-compose.yml up -d
	@echo "Waiting for server to start..."
	@sleep 5
	rm -rf tests/Conformance/results-2026-07-28
	cd tests/Conformance && $(CONFORMANCE) server --url http://localhost:8000/ --suite all --spec-version 2026-07-28 --expected-failures conformance-baseline-2026-07-28.yml --output-dir results-2026-07-28 || true
	php tests/Conformance/score.php server 2026-07-28 results-2026-07-28
	docker compose -f tests/Conformance/Fixtures/docker-compose.yml down

conformance-draft-client:
	rm -rf tests/Conformance/results-2026-07-28
	cd tests/Conformance && $(CONFORMANCE) client --command "php $(CURDIR)/tests/Conformance/client.php" --suite all --spec-version 2026-07-28 --expected-failures conformance-baseline-2026-07-28.yml --output-dir results-2026-07-28 || true
	php tests/Conformance/score.php client 2026-07-28 results-2026-07-28

coverage:
	XDEBUG_MODE=coverage vendor/bin/phpunit --testsuite=unit --coverage-html=coverage

ci: ci-stable

ci-stable: deps-stable cs phpstan tests

ci-lowest: deps-low cs phpstan tests

docs:
	vendor/bin/phpdoc
	@grep -q 'No errors have been found' .phpdoc/build/reports/errors.html || \
		(echo "Documentation errors found. See build/docs/reports/errors.html" && exit 1)
