.PHONY: deps-stable deps-low cs rector phpstan tests coverage run-examples ci ci-stable ci-lowest docs

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

coverage:
	XDEBUG_MODE=coverage vendor/bin/phpunit --testsuite=unit --coverage-html=coverage

ci: ci-stable

ci-stable: deps-stable cs phpstan tests

ci-lowest: deps-low cs phpstan tests

docs:
	vendor/bin/phpdoc
	@grep -q 'No errors have been found' build/docs/reports/errors.html || \
		(echo "Documentation errors found. See build/docs/reports/errors.html" && exit 1)
