# Local gate before a PR: what CI runs on a release tag, minus wp-env (integration: bin/wpenv-start, then bin/test).
PHP_IMAGE ?= php:8.1-cli
DOCKER_PHP = docker run --rm -v "$(CURDIR)":/app -w /app $(PHP_IMAGE)

.PHONY: check
check:
	bin/composer install --no-interaction --quiet
	$(DOCKER_PHP) php bin/make-php --check
	$(DOCKER_PHP) ./vendor/bin/phpunit --testsuite unit
