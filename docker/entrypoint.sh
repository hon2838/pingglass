#!/bin/bash
set -e

# Wait for DB to be reachable
echo "Waiting for database connection..."
until php -r "
try {
    \$pdo = new PDO('mysql:host='.getenv('DB_HOST').';port='.getenv('DB_PORT').';dbname='.getenv('DB_DATABASE'), getenv('DB_USERNAME'), getenv('DB_PASSWORD'));
    exit(0);
} catch (Exception \$e) {
    exit(1);
}
"; do
    sleep 2
done
echo "Database connected!"

# Ensure .env file exists
if [ ! -f /var/www/html/.env ]; then
    if [ -f /var/www/html/.env.example ]; then
        cp /var/www/html/.env.example /var/www/html/.env
    else
        touch /var/www/html/.env
    fi
fi

# Generate key if not set
if [ -z "$APP_KEY" ]; then
    php artisan key:generate --force
fi

# Run database migrations and setup
php artisan storage:link || true
php artisan migrate --force

if [ ! -f /var/www/html/storage/app/.seeded ]; then
    echo "Running initial database seeder..."
    php artisan db:seed --force
    touch /var/www/html/storage/app/.seeded
else
    echo "Database already seeded, skipping."
fi

php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Start PHP-FPM
php-fpm -D

# Start Nginx
nginx

# Start Background Queue Workers (probes & maintenance queue)
for i in $(seq 1 4); do
    (
        while true; do
            php artisan queue:work redis --queue=probes --sleep=1 --tries=3 --timeout=120 --max-time=3600
            sleep 1
        done
    ) &
done

(
    while true; do
        php artisan queue:work redis --queue=default,maintenance --sleep=1 --tries=3 --timeout=60 --max-time=3600
        sleep 1
    done
) &

# Start Laravel Scheduler loop in foreground
echo "PingGlass worker services started. Running scheduler..."
while true; do
    php artisan schedule:run --verbose --no-interaction || true
    sleep 60
done
