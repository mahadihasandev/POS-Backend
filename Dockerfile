FROM serversideup/php:8.4-fpm-nginx

# Switch to root to install PostgreSQL and PHP extensions
USER root

RUN install-php-extensions pgsql pdo_pgsql bcmath opcache redis

# Set application directory
WORKDIR /var/www/html

# Copy source code with correct unprivileged ownership
COPY --chown=www-data:www-data . /var/www/html

# Install Composer production dependencies
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# Ensure framework storage directories exist with write permissions
RUN mkdir -p /var/www/html/storage/framework/sessions \
             /var/www/html/storage/framework/views \
             /var/www/html/storage/framework/cache/data \
             /var/www/html/storage/logs \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Install startup script
COPY --chmod=755 ./docker/entrypoint.d/99-laravel-init.sh /etc/entrypoint.d/99-laravel-init.sh

# Run as non-root user
USER www-data

# Set webroot to public directory
ENV NGINX_WEBROOT="/var/www/html/public"
ENV SHOW_WELCOME_MESSAGE=false
