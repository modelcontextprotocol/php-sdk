FROM php:8.2-fpm-alpine

# Install dependencies
RUN apk add --no-cache \
    curl \
    git \
    unzip

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /app

# Install PHP extensions
RUN docker-php-ext-install opcache

# Configure PHP-FPM to listen on TCP
RUN sed -i 's/listen = .*/listen = 9000/' /usr/local/etc/php-fpm.d/www.conf

EXPOSE 9000

CMD ["php-fpm"]
