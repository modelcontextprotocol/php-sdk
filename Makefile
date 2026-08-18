.PHONY: deps-stable deps-low cs phpstan tests unit-tests integration-tests inspector-tests coverage ci ci-stable ci-lowest conformance-tests conformance-server conformance-client conformance-draft conformance-draft-server conformance-draft-client check-conformance-repo docs

# The published conformance release carries no 2026-07-28 scenarios, so the
# draft targets run a local checkout instead.
# Override with `make conformance-draft CONFORMANCE_REPO=/path/to/conformance`.
CONFORMANCE_REPO ?= $(CURDIR)/../conformance
CONFORMANCE_DRAFT = node $(CONFORMANCE_REPO)/dist/index.js

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
	cd tests/Conformance && npx @modelcontextprotocol/conformance server --url http://localhost:8000/ --spec-version 2025-11-25 --output-dir results || true
	php tests/Conformance/score.php server
	docker compose -f tests/Conformance/Fixtures/docker-compose.yml down

conformance-client:
	rm -rf tests/Conformance/results
	cd tests/Conformance && npx @modelcontextprotocol/conformance client --command "php $(CURDIR)/tests/Conformance/client.php" --suite all --spec-version 2025-11-25 --expected-failures conformance-baseline.yml --output-dir results || true
	php tests/Conformance/score.php client

# --- 2026-07-28 (SEP-2575 stateless lifecycle) ------------------------------
# Local-checkout only until upstream publishes the draft scenarios.

conformance-draft: conformance-draft-server conformance-draft-client

check-conformance-repo:
	@test -f $(CONFORMANCE_REPO)/dist/index.js || { \
		echo "No conformance build at $(CONFORMANCE_REPO)/dist/index.js."; \
		echo "Clone modelcontextprotocol/conformance and run 'npm install && npm run build' there,"; \
		echo "or point CONFORMANCE_REPO at an existing checkout."; \
		exit 1; \
	}

conformance-draft-server: check-conformance-repo
	docker compose -f tests/Conformance/Fixtures/docker-compose.yml up -d
	@echo "Waiting for server to start..."
	@sleep 5
	rm -rf tests/Conformance/results-2026-07-28
	cd tests/Conformance && $(CONFORMANCE_DRAFT) server --url http://localhost:8000/stateless --suite all --spec-version 2026-07-28 --expected-failures conformance-baseline-2026-07-28.yml --output-dir results-2026-07-28 || true
	docker compose -f tests/Conformance/Fixtures/docker-compose.yml down

conformance-draft-client: check-conformance-repo
	rm -rf tests/Conformance/results-2026-07-28
	cd tests/Conformance && $(CONFORMANCE_DRAFT) client --command "php $(CURDIR)/tests/Conformance/client.php" --suite all --spec-version 2026-07-28 --expected-failures conformance-baseline-2026-07-28.yml --output-dir results-2026-07-28 || true

coverage:
	XDEBUG_MODE=coverage vendor/bin/phpunit --testsuite=unit --coverage-html=coverage

ci: ci-stable

ci-stable: deps-stable cs phpstan tests

ci-lowest: deps-low cs phpstan tests

docs:
	vendor/bin/phpdoc
	@grep -q 'No errors have been found' .phpdoc/build/reports/errors.html || \
		(echo "Documentation errors found. See build/docs/reports/errors.html" && exit 1)
