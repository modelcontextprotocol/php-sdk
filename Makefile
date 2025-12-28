.PHONY: deps-stable deps-low cs phpstan tests unit-tests inspector-tests coverage ci ci-stable ci-lowest

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

conformance-server:
	php -S localhost:8000 examples/server/conformance/server.php

conformance-tests:
	npx @modelcontextprotocol/conformance server --url http://localhost:8000/

coverage:
	XDEBUG_MODE=coverage vendor/bin/phpunit --testsuite=unit --coverage-html=coverage

ci: ci-stable

ci-stable: deps-stable cs phpstan tests

ci-lowest: deps-low cs phpstan tests
