# Production image for Coolify (or any Docker host).
# Base: serversideup/php (PHP 8.3 + Nginx, runs as a non-root user, has Laravel start-up automation).
FROM serversideup/php:8.3-fpm-nginx

# PHP extensions this app needs beyond the base image (Excel import/export, locale formatting).
USER root
RUN install-php-extensions intl gd zip \
    # The application sends the security headers itself (App\Http\Middleware\SecurityHeaders);
    # empty Nginx's copy so every header is sent once, not twice with conflicting values.
    && printf '# Security headers are set by the application.\n' > /etc/nginx/server-opts.d/security.conf
COPY --chmod=755 docker/entrypoint.d/ /etc/entrypoint.d/
USER www-data

# --- Runtime settings (can be overridden by Coolify environment variables) ---------------------------
ENV PHP_OPCACHE_ENABLE=1 \
    PHP_UPLOAD_MAX_FILE_SIZE=8M \
    PHP_POST_MAX_SIZE=10M \
    HEALTHCHECK_PATH=/up \
    AUTORUN_ENABLED=true \
    AUTORUN_LARAVEL_MIGRATION_ISOLATION=false \
    AUTORUN_LARAVEL_STORAGE_LINK=false \
    LOG_CHANNEL=stderr

WORKDIR /var/www/html

# Install PHP packages first so Docker can cache this layer until composer.lock changes.
COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# Application code.
COPY --chown=www-data:www-data . .
RUN composer install --no-dev --optimize-autoloader --prefer-dist --no-interaction \
    && php artisan package:discover --ansi \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

# Nginx listens on 8080 inside the container: set "Ports Exposes" to 8080 in Coolify.
EXPOSE 8080
