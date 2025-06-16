# Use PHP 8.3 with FPM and Alpine Linux
FROM php:8.3-fpm-alpine

# Set environment variables
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV COMPOSER_NO_INTERACTION=1
ENV COMPOSER_MEMORY_LIMIT=-1

# Install system dependencies
RUN apk add --no-cache \
    git \
    curl \
    libpng-dev \
    oniguruma-dev \
    libxml2-dev \
    postgresql-dev \
    zip \
    unzip \
    nginx \
    supervisor \
    bash \
    && rm -rf /var/cache/apk/*

# Install PHP extensions
RUN docker-php-ext-configure gd \
    && docker-php-ext-install \
        pdo \
        pdo_pgsql \
        pgsql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        xml \
        dom \
        fileinfo \
        filter \
        hash \
        openssl \
        session \
        tokenizer

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www

# Copy composer files first (for better caching)
COPY composer.json ./

# Create basic Laravel structure if not exists
RUN mkdir -p app bootstrap config database public resources routes storage tests \
    && mkdir -p storage/app storage/framework storage/logs \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views \
    && mkdir -p bootstrap/cache

# Create minimal Laravel files if they don't exist
RUN echo '<?php return [];' > config/app.php \
    && echo '<?php return [];' > config/database.php \
    && echo '<?php return [];' > config/cache.php \
    && echo '<?php return [];' > config/session.php

# Install PHP dependencies with error handling
RUN composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --prefer-dist \
    --no-scripts \
    || (echo "Composer install failed, trying with scripts..." && \
        composer install \
        --no-dev \
        --optimize-autoloader \
        --no-interaction \
        --prefer-dist)

# Copy application code
COPY . .

# Create necessary directories and set permissions
RUN mkdir -p /var/www/storage/logs \
    && mkdir -p /var/www/storage/framework/cache \
    && mkdir -p /var/www/storage/framework/sessions \
    && mkdir -p /var/www/storage/framework/views \
    && mkdir -p /var/www/bootstrap/cache \
    && mkdir -p /run/nginx \
    && mkdir -p /var/log/supervisor

# Set permissions
RUN chown -R www-data:www-data /var/www \
    && chmod -R 755 /var/www/storage \
    && chmod -R 755 /var/www/bootstrap/cache

# Copy configuration files
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Create startup script
RUN echo '#!/bin/bash' > /start.sh \
    && echo 'set -e' >> /start.sh \
    && echo 'echo "Starting application..."' >> /start.sh \
    && echo 'if [ ! -f /var/www/.env ]; then' >> /start.sh \
    && echo '  cp /var/www/.env.example /var/www/.env' >> /start.sh \
    && echo 'fi' >> /start.sh \
    && echo 'php artisan config:cache || echo "Config cache failed"' >> /start.sh \
    && echo 'php artisan route:cache || echo "Route cache failed"' >> /start.sh \
    && echo 'php artisan view:cache || echo "View cache failed"' >> /start.sh \
    && echo 'exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf' >> /start.sh \
    && chmod +x /start.sh

# Expose port 80
EXPOSE 80

# Health check
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD curl -f http://localhost/ || exit 1

# Start the application
CMD ["/start.sh"]
