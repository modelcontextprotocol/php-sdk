.PHONY: deps-stable deps-low cs phpstan tests unit-tests inspector-tests coverage ci ci-stable ci-lowest conformance-tests

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

inspector-tests:
	vendor/bin/phpunit --testsuite=inspector

conformance-tests:
	docker compose -f tests/Conformance/Fixtures/docker-compose.yml up -d
	@echo "Waiting for server to start..."
	@sleep 5
	cd tests/Conformance && npx @modelcontextprotocol/conformance server --url http://localhost:8000/ || true
	docker compose -f tests/Conformance/Fixtures/docker-compose.yml down

coverage:
	XDEBUG_MODE=coverage vendor/bin/phpunit --testsuite=unit --coverage-html=coverage

ci: ci-stable

ci-stable: deps-stable cs phpstan tests

ci-lowest: deps-low cs phpstan tests
