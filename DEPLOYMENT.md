# Deployment Guide

This covers getting PingGlass running on a Linux server with Nginx, PHP-FPM, MySQL, Redis, fping, and Supervisor. If you're just developing locally, the README quick start is enough.

## What you need

- Ubuntu 22.04+ (or similar Debian-based distro)
- PHP 8.2 with these extensions: `pdo_mysql`, `mbstring`, `openssl`, `json`, `curl`, `xml`, `redis`, `sockets`
- Composer
- MySQL 8.0+
- Redis
- Node.js 18+ and npm
- Nginx
- fping
- Supervisor

## 1. System packages

```bash
sudo apt update
sudo apt install -y php8.2-fpm php8.2-cli php8.2-mysql php8.2-mbstring \
    php8.2-xml php8.2-curl php8.2-redis php8.2-sockets php8.2-json \
    mysql-server redis-server nginx supervisor fping curl unzip

# Install Composer if you don't have it
curl -sS https://getcomposer.org/installer | sudo php -- --install-dir=/usr/local/bin --filename=composer

# Install Node.js (use nvm or NodeSource)
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs
```

Make sure fping is actually installed and accessible:

```bash
which fping
# Should print something like /usr/bin/fping

# Quick test
fping -C 3 -q -t 2000 8.8.8.8
# Should print latency values to stderr
```

## 2. Get the code

```bash
sudo mkdir -p /var/www/pingglass
sudo chown $USER:$USER /var/www/pingglass
git clone <your-repo-url> /var/www/pingglass
cd /var/www/pingglass
```

## 3. PHP dependencies

```bash
composer install --no-dev --optimize-autoloader
```

## 4. Environment config

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env`:

```env
APP_NAME=PingGlass
APP_ENV=production
APP_DEBUG=false
APP_URL=https://pingglass.yourdomain.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=pingglass
DB_USERNAME=pingglass
DB_PASSWORD=make-this-strong

REDIS_HOST=127.0.0.1

QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis

PINGGLASS_FPING_PATH=/usr/bin/fping

# Only set this true if you need to monitor internal IPs
PINGGLASS_ALLOW_PRIVATE_TARGETS=false
```

Create the MySQL database and user:

```sql
CREATE DATABASE pingglass CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'pingglass'@'127.0.0.1' IDENTIFIED BY 'make-this-strong';
GRANT ALL PRIVILEGES ON pingglass.* TO 'pingglass'@'127.0.0.1';
FLUSH PRIVILEGES;
```

## 5. Database setup

Pick one:

**Option A** — Import the sample SQL. This creates all tables, the default admin user, and some sample targets:

```bash
mysql -u pingglass -p pingglass < database/pingglass_sample.sql
```

**Option B** — Run migrations and the seeder:

```bash
php artisan migrate --force
php artisan db:seed --force
```

## 6. Build the frontend

```bash
npm install
npm run build
```

## 7. Laravel setup

```bash
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Set correct permissions:

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

## 8. Nginx

Create `/etc/nginx/sites-available/pingglass`:

```nginx
server {
    listen 80;
    server_name pingglass.yourdomain.com;
    root /var/www/pingglass/public;
    index index.php;

    charset utf-8;

    # Increase for CSV exports of large datasets
    client_max_body_size 10m;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 60;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    # Cache static assets
    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
    }
}
```

Enable it:

```bash
sudo ln -s /etc/nginx/sites-available/pingglass /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

For HTTPS, use Certbot:

```bash
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d pingglass.yourdomain.com
```

## 9. Scheduler

One cron entry handles everything — the scheduler figures out what needs to run and when.

```bash
crontab -e
```

Add:

```
* * * * * cd /var/www/pingglass && php artisan schedule:run >> /dev/null 2>&1
```

What the scheduler runs:

| Command | How often | What it does |
|---------|-----------|-------------|
| `pingglass:probe-cycle` | Every minute | Dispatches chunk jobs only for enabled targets that are due |
| `pingglass:complete-cycles` | Every 2 min | Closes stale cycles that didn't finish (fallback) |
| `pingglass:evaluate-staleness` | Every 3 min | Marks targets as unknown if no data is arriving |
| `pingglass:aggregate-five-minute` | Every 5 min | Rolls up raw measurements into 5-minute buckets |
| `pingglass:aggregate-hourly` | Hourly | Rolls up raw measurements into hourly buckets |
| `pingglass:cleanup` | Daily at 3am | Deletes old raw data per retention settings |

## 10. Queue workers

The probe jobs are the critical path. The default config gives you 4 probe workers and 2 general workers — adjust based on target count and interval. For roughly 10,000 targets at a one-minute interval and chunk size 200, start with 20-30 probe workers and tune from observed cycle duration, file-descriptor use, network rate, and MySQL load.

Create `/etc/supervisor/conf.d/pingglass-probes.conf`:

```ini
[program:pingglass-probes]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/pingglass/artisan queue:work redis --queue=probes --sleep=1 --tries=3 --timeout=120 --max-time=3600
autostart=true
autorestart=true
numprocs=4
redirect_stderr=true
stdout_logfile=/var/www/pingglass/storage/logs/probes.log
stopwaitsecs=30
```

Create `/etc/supervisor/conf.d/pingglass-default.conf`:

```ini
[program:pingglass-default]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/pingglass/artisan queue:work redis --queue=default,maintenance --tries=3 --max-time=60
autostart=true
autorestart=true
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/pingglass/storage/logs/worker.log
stopwaitsecs=30
```

Start everything:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start all
```

Check that workers are running:

```bash
sudo supervisorctl status
```

You should see something like:

```
pingglass-default:pingglass-default_00   RUNNING
pingglass-default:pingglass-default_01   RUNNING
pingglass-probes:pingglass-probes_00     RUNNING
pingglass-probes:pingglass-probes_01     RUNNING
pingglass-probes:pingglass-probes_02     RUNNING
pingglass-probes:pingglass-probes_03     RUNNING
```

After deploying updates, restart the workers so they pick up code changes:

```bash
sudo supervisorctl restart all
```

## 11. First login

Go to `https://pingglass.yourdomain.com/login`

- **Email:** `admin@pingglass.local`
- **Password:** `password`

Change the password immediately. Create additional admin users from the admin panel if needed.

## 12. Verify everything works

Check the System Health page in the admin panel. It verifies:
- Database connection
- Redis connection
- Queue depth
- Worker activity (recent completed cycles)
- fping availability and functionality
- TCP socket probing capability
- Scheduler freshness
- Failed jobs count

If fping shows as "not found" or "not working", double-check the path in `.env` and make sure the www-data user can execute it.

## Backups

The important stuff to back up:

```bash
mysqldump -u root -p pingglass \
    users categories targets target_states monitor_settings \
    incidents measurement_rollups scope_measurement_rollups audit_logs \
    > pingglass-backup.sql
```

The raw `measurements` table grows fast and is less critical — it's retained for 30 days and the interesting parts get rolled into `measurement_rollups`. You can skip it in backups if storage is tight.

A reasonable backup schedule:
- **Daily:** config tables + rollups (small, compresses well)
- **Weekly:** full database including raw measurements

## Updating

```bash
cd /var/www/pingglass
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
npm install && npm run build
php artisan config:cache
php artisan route:cache
php artisan view:cache
sudo supervisorctl restart all
```

If you use Horizon for queue monitoring (optional), restart that too.

## Troubleshooting

**No data showing up on graphs:**
1. Check System Health page — is the scheduler running?
2. `sudo supervisorctl status` — are workers alive?
3. `tail -f storage/logs/probes.log` — look for errors
4. Verify fping works: `sudo -u www-data fping -C 3 -q -t 2000 8.8.8.8`

**Targets showing as "Unknown":**
- The staleness evaluator marks targets unknown when it hasn't received data for a while. Usually means the queue workers or scheduler stopped.

**High queue depth:**
- Confirm `PINGGLASS_PROBE_CHUNK_SIZE=100` and `REDIS_QUEUE_RETRY_AFTER=240`.
- Each probe job handles a bounded target chunk. At 10,000 targets and chunk size 200, a full cycle creates about 50 jobs.
- Check DNS response time, worker file-descriptor limits, database write latency, and failed jobs before increasing worker count.

**fping permission errors:**
- Make sure fping is setuid root, or run: `sudo chmod u+s $(which fping)`
- Alternatively, allow the www-data user to run fping via sudoers

**Redis connection refused:**
- Check Redis is running: `sudo systemctl status redis`
- Check the REDIS_HOST in `.env` matches where Redis is listening
