FROM composer:2.8

RUN set -eux; \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers; \
    pecl install xdebug; \
    docker-php-ext-enable xdebug; \
    apk del .build-deps; \
    { \
        echo 'xdebug.mode=coverage'; \
        echo 'xdebug.start_with_request=no'; \
    } > /usr/local/etc/php/conf.d/zz-xdebug.ini
