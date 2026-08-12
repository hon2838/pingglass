# Stage 1: Build Frontend Assets
FROM node:18-alpine AS frontend-builder
WORKDIR /app
COPY package*.json ./
RUN npm install
COPY . .
RUN npm run build

# Stage 2: Composer Dependencies
FROM composer:2 AS composer-builder
WORKDIR /app
COPY composer*.json ./
ENV COMPOSER_AUDIT_ABANDONED=ignore
ENV COMPOSER_AUDIT_BLOCK=false
RUN composer config audit.block false 2>/dev/null || true
RUN composer config audit.blocked-packages false 2>/dev/null || true
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs --no-security-blocking
COPY . .
RUN composer dump-autoload --optimize --ignore-platform-reqs

# Stage 3: Production Runtime Image
FROM php:8.2-fpm-alpine

# Install pre-compiled php extension installer helper
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

# Install system packages & dependencies
RUN apk add --no-cache \
    nginx \
    fping \
    bash \
    net-tools \
    libcap

# Install PHP extensions using pre-compiled binaries
RUN install-php-extensions pdo_mysql sockets bcmath pcntl intl zip redis

# Set capabilities for fping so it can send raw ICMP packets without full root
RUN setcap cap_net_raw+ep /usr/sbin/fping

WORKDIR /var/www/html

# Copy application files and built frontend/vendor assets
COPY --from=composer-builder /app /var/www/html
COPY --from=frontend-builder /app/public/build /var/www/html/public/build

# Setup storage & bootstrap permissions
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Copy Nginx configuration
COPY docker/nginx.conf /etc/nginx/nginx.conf

# Copy Entrypoint script
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
