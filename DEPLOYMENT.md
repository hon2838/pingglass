# PingGlass Production Deployment and Operations Guide

This guide describes the current PingGlass release. It covers fresh installations, existing databases, Ubuntu/Nginx/Supervisor, Alpine/Docker, queue safety, administrator bootstrap, 10,000-target tuning, monitoring, backups, upgrades, and failure recovery.

Read the timeout and queue sections before changing worker count or chunk size. PingGlass deliberately depends on this ordering:

```text
ProbeTargetsChunk timeout: 110 seconds
Probe worker timeout:      120 seconds
Redis retry_after:         240 seconds
```

The job timeout must be lower than the worker timeout, and the worker timeout must be lower than Redis `retry_after`.

## Contents

1. [Current production architecture](#current-production-architecture)
2. [Capacity and host planning](#capacity-and-host-planning)
3. [Fresh Ubuntu installation](#fresh-ubuntu-installation)
4. [Environment reference](#environment-reference)
5. [Database initialization](#database-initialization)
6. [Existing database upgrade](#existing-database-upgrade)
7. [`fping` permissions](#fping-permissions)
8. [Nginx and PHP-FPM](#nginx-and-php-fpm)
9. [Scheduler](#scheduler)
10. [Supervisor workers](#supervisor-workers)
11. [Docker and Alpine deployment](#docker-and-alpine-deployment)
12. [Administrator bootstrap and account management](#administrator-bootstrap-and-account-management)
13. [Post-deployment verification](#post-deployment-verification)
14. [Large installations: 10,000+ targets](#large-installations-10000-targets)
15. [Operational monitoring](#operational-monitoring)
16. [Troubleshooting](#troubleshooting)
17. [Safe application updates](#safe-application-updates)
18. [Backups and restore preparation](#backups-and-restore-preparation)
19. [Rollback planning](#rollback-planning)
20. [1Panel Deployment](#1panel-deployment)

## Current production architecture

A complete PingGlass installation has these continuously available components:

| Component | Responsibility |
|---|---|
| Nginx or another web server | Serves static assets and forwards PHP requests. |
| PHP-FPM | Handles public and admin web requests. |
| MySQL 8+ | Stores configuration, targets, states, measurements, rollups, incidents, users, and audit logs. |
| Redis | Stores queues, cache entries, settings cache, DNS cache, scheduler locks, and chunk execution locks. |
| Probe workers | Consume the `probes` queue and run `ProbeTargetsChunk`. |
| General workers | Consume `default,maintenance`. |
| Laravel scheduler | Dispatches probes, rollups, cleanup, and staleness evaluation. |
| `fping` | Performs batched ICMP sampling. |

The scheduler does not perform network probes itself. It claims due targets and dispatches queue jobs. If the scheduler runs but probe workers do not, jobs accumulate. If workers run but the scheduler does not, no new cycles are created.

### Current queue model

- One chunk job handles 25-250 targets.
- The configured default chunk size is 200.
- A conservative starting value for a hostname-heavy 10k installation is 100.
- ICMP targets in a chunk use one `fping` process.
- TCP targets in a chunk connect concurrently during each sample round.
- Jobs have up to three attempts and a short-lived per-chunk execution lock.
- Measurement writes are idempotent on target, protocol, and cycle.
- Targets are claimed by `active_probe_cycle_id` so a slow cycle does not enqueue the same target again.
- Abandoned cycles time out after 15 minutes by default and release their claims.

### Data model additions in the current release

Existing installations gain:

- `targets.probe_interval_seconds`
- `targets.next_probe_at`
- `targets.active_probe_cycle_id`
- `targets_due_probe_index`
- `scope_measurement_rollups`
- A guaranteed unique `target_states.target_id` relationship

Existing target rows are preserved. Their initial `next_probe_at` is null, so they are due during the first cycle after the upgrade.

## Capacity and host planning

### Minimum software versions

- Linux server or container host
- PHP 8.2+
- MySQL 8.0+
- Redis 6+
- Composer 2
- Node.js 18+ and npm for asset builds
- `fping`
- Nginx, Apache, Caddy, or another PHP-capable reverse proxy
- Supervisor, systemd, Docker, Kubernetes, or another process supervisor

### Required PHP extensions

At minimum, install the extensions required by Laravel and these application-specific extensions:

- `pdo_mysql`
- `mbstring`
- `openssl`
- `tokenizer`
- `xml`
- `ctype`
- `curl`
- `fileinfo`
- `redis`
- `sockets`

### Resource guidance

There is no universal worker count because hostname quality, ICMP/TCP timeouts, sample count, database latency, and packet loss all affect duration.

Use these as starting points, not guarantees:

| Targets | Starting chunk | Probe workers | Suggested interval |
|---:|---:|---:|---:|
| Up to 1,000 | 100-200 | 4-8 | 1-5 minutes |
| 1,000-5,000 | 100-200 | 8-20 | 2-5 minutes |
| 5,000-12,000 | 100 | 20-30 | 5 minutes initially |
| More than 12,000 | Benchmark | Benchmark | 5 minutes or longer initially |

A one-minute schedule at 10,000 targets is an extremely high write workload:

```text
10,000 targets x 1 protocol x 1,440 cycles/day = 14.4 million rows/day
10,000 targets x 2 protocols x 1,440 cycles/day = 28.8 million rows/day
```

Plan MySQL storage, IOPS, backups, cleanup, binary logs, and replication accordingly.

## Fresh Ubuntu installation

The examples below use Ubuntu 22.04/24.04, PHP 8.2, `/var/www/pingglass`, and the `www-data` service account. Adjust versions and paths for your distribution.

### 1. Install system packages

```sh
sudo apt update
sudo apt install -y \
  php8.2-fpm php8.2-cli php8.2-mysql php8.2-mbstring \
  php8.2-xml php8.2-curl php8.2-redis php8.2-sockets \
  mysql-server redis-server nginx supervisor fping curl unzip git
```

Some Ubuntu versions provide `json` as part of PHP core and do not have a separate `php8.2-json` package.

Install Composer if it is not already installed:

```sh
curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php
sudo php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
rm /tmp/composer-setup.php
composer --version
```

Install a supported Node.js release using your preferred package source, then verify:

```sh
node --version
npm --version
```

### 2. Create the database

Enter MySQL as an administrative user:

```sh
sudo mysql
```

Create a database and dedicated account:

```sql
CREATE DATABASE pingglass CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'pingglass'@'127.0.0.1' IDENTIFIED BY 'replace-with-a-long-random-password';
GRANT ALL PRIVILEGES ON pingglass.* TO 'pingglass'@'127.0.0.1';
FLUSH PRIVILEGES;
```

Do not reuse the MySQL root account in the application.

### 3. Install the application

```sh
sudo mkdir -p /var/www/pingglass
sudo chown "$USER":"$USER" /var/www/pingglass
git clone https://github.com/samleong123/pingglass.git /var/www/pingglass
cd /var/www/pingglass

composer install --no-dev --optimize-autoloader
npm install --no-audit --no-fund
npm run build

cp .env.example .env
php artisan key:generate
```

Edit `.env` before running migrations.

### 4. Create runtime directories and permissions

```sh
php artisan storage:link
sudo chown -R www-data:www-data /var/www/pingglass/storage /var/www/pingglass/bootstrap/cache
sudo find /var/www/pingglass/storage /var/www/pingglass/bootstrap/cache -type d -exec chmod 775 {} \;
sudo find /var/www/pingglass/storage /var/www/pingglass/bootstrap/cache -type f -exec chmod 664 {} \;
```

The application code should not be writable by the web-server account. Only `storage` and `bootstrap/cache` require application writes.

## Environment reference

Copy `.env.example` and set every secret. Never commit the production `.env` file.

### Core application

```env
APP_NAME=PingGlass
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://status.example.com

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_MAINTENANCE_DRIVER=file
```

| Variable | Notes |
|---|---|
| `APP_KEY` | Generate once with `php artisan key:generate`; preserve it during upgrades/restores. |
| `APP_DEBUG` | Must be `false` on public production systems. |
| `APP_URL` | Canonical HTTPS URL used by Laravel and generated routes. |

Keep Laravel's internal application/database timestamp behavior in UTC. Use `PINGGLASS_DISPLAY_TIMEZONE` for human-readable UI timestamps.

### MySQL

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=pingglass
DB_USERNAME=pingglass
DB_PASSWORD=replace-with-a-long-random-password
```

### Redis, queues, cache, and sessions

```env
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis

REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
REDIS_QUEUE_RETRY_AFTER=240
```

`REDIS_QUEUE_RETRY_AFTER` is a safety value, not a performance tuning knob. Keep it above the 120-second worker timeout. The current recommended value is 240.

Use separate Redis credentials/network controls when Redis is not local. Do not expose Redis directly to the internet.

### Probe engine and display

```env
PINGGLASS_FPING_PATH=/usr/bin/fping
PINGGLASS_DISPLAY_TIMEZONE=Asia/Kuala_Lumpur
PINGGLASS_PROBE_INTERVAL=60
PINGGLASS_CYCLE_TIMEOUT_MINUTES=15
PINGGLASS_PROBE_CHUNK_SIZE=100
PINGGLASS_FPING_INTERVAL_MS=1

PINGGLASS_DNS_CACHE_TTL=3600
PINGGLASS_DNS_CACHE_JITTER=3600
PINGGLASS_DNS_FAILURE_CACHE_TTL=300
PINGGLASS_DNS_FAILURE_CACHE_JITTER=300

PINGGLASS_ICMP_SAMPLES=10
PINGGLASS_TCP_SAMPLES=10
PINGGLASS_ICMP_TIMEOUT=2000
PINGGLASS_TCP_TIMEOUT=2000

PINGGLASS_LOSS_THRESHOLD_PERCENT=10
PINGGLASS_LATENCY_THRESHOLD_MS=200
PINGGLASS_DOWN_CONFIRMATION_CYCLES=3
PINGGLASS_RECOVERY_CONFIRMATION_CYCLES=2

PINGGLASS_RAW_RETENTION_DAYS=30
PINGGLASS_ROLLUP5M_RETENTION_DAYS=180
PINGGLASS_ALLOW_PRIVATE_TARGETS=false
```

| Variable | Default | Operational meaning |
|---|---:|---|
| `PINGGLASS_FPING_PATH` | `/usr/bin/fping` | Absolute `fping` binary path. Alpine commonly uses `/usr/sbin/fping`. |
| `PINGGLASS_DISPLAY_TIMEZONE` | `Asia/Kuala_Lumpur` | Human-readable UI timezone. Malaysia/Singapore is UTC+8. Data stays UTC. |
| `PINGGLASS_PROBE_INTERVAL` | `60` | Fallback global interval before/without a database setting. |
| `PINGGLASS_CYCLE_TIMEOUT_MINUTES` | `15` | Time before an incomplete cycle is abandoned and claims are released. Minimum effective value is 5. |
| `PINGGLASS_PROBE_CHUNK_SIZE` | `200` | Targets per job, clamped to 25-250. Use 100 initially for a large hostname-heavy deployment. |
| `PINGGLASS_FPING_INTERVAL_MS` | `1` | Minimum global spacing between `fping` packets. |
| `PINGGLASS_DNS_CACHE_TTL` | `3600` | Base successful DNS-cache lifetime. |
| `PINGGLASS_DNS_CACHE_JITTER` | `3600` | Additional deterministic successful-cache lifetime. |
| `PINGGLASS_DNS_FAILURE_CACHE_TTL` | `300` | Base negative DNS-cache lifetime. |
| `PINGGLASS_DNS_FAILURE_CACHE_JITTER` | `300` | Additional deterministic negative-cache lifetime. |
| `PINGGLASS_ALLOW_PRIVATE_TARGETS` | `false` | Allows private/internal probes when true. This expands SSRF reach intentionally. |

The sample/probe/threshold/retention values are stored in `monitor_settings` after seeding and are editable from the admin Settings page. Once present in the database, those values override their `.env` fallbacks.

### Mail

PingGlass currently records incidents in the application/database. Configure Laravel mail settings if your broader installation or future integrations require mail; the default `.env.example` uses the log mailer.

```env
MAIL_MAILER=log
MAIL_HOST=127.0.0.1
MAIL_PORT=2525
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_ENCRYPTION=null
MAIL_FROM_ADDRESS="noreply@pingglass.local"
MAIL_FROM_NAME="${APP_NAME}"
```

With `MAIL_MAILER=log`, messages are written to Laravel logs rather than delivered. Use an SMTP or API-backed Laravel mail transport before depending on email delivery.

### Remaining Laravel runtime settings

The current `.env.example` also includes:

```env
APP_FAKER_LOCALE=en_US
BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=debug

SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
CACHE_PREFIX=pingglass
```

Operational notes:

- `APP_FAKER_LOCALE` affects development factories/seed data, not probe behavior.
- `BCRYPT_ROUNDS` controls password-hash work factor. Benchmark before increasing it on low-power systems.
- Consider `LOG_LEVEL=info` or `warning` in mature production deployments; keep enough detail while diagnosing queue/probe behavior.
- `SESSION_LIFETIME` is in minutes.
- Set `SESSION_ENCRYPT=true` if you require encrypted Redis session payloads and have measured the overhead.
- Set `SESSION_DOMAIN` only when authentication must span explicitly trusted subdomains.
- `FILESYSTEM_DISK=local` is sufficient for current application assets/runtime storage.
- Use a unique `CACHE_PREFIX` when multiple Laravel applications share one Redis logical database.
- Broadcasting is not part of the current probe/status workflow; `log` is a safe default.

### Apply environment changes

```sh
cd /var/www/pingglass
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
```

All long-running queue workers must restart after environment or code changes.

## Database initialization

### Recommended fresh-production path

```sh
cd /var/www/pingglass
php artisan migrate --force
php artisan db:seed --force
```

The seeder creates bootstrap development/sample data. Before exposing the installation, replace the bootstrap administrator password and remove or disable sample targets you do not want.

### Sample SQL path

`database/pingglass_sample.sql` is a complete sample snapshot. It is useful for demonstrations but migrations are the recommended production source of truth.

```sh
mysql -u pingglass -p pingglass < database/pingglass_sample.sql
php artisan migrate --force
```

Running migrations after importing the snapshot ensures its recorded schema is aligned with the checked-out release.

### Confirm schema state

```sh
php artisan migrate:status
php artisan tinker --execute='dump([
    "targets" => \App\Models\Target::count(),
    "users" => \App\Models\User::count(),
    "migrations" => \Illuminate\Support\Facades\DB::table("migrations")->count(),
]);'
```

## Existing database upgrade

This is the safest path for an installation that already has categories, targets, states, incidents, measurements, and rollups.

### Upgrade principles

- Back up before changing code or schema.
- Do not import `pingglass_sample.sql` over an existing database.
- Do not run `migrate:fresh` or `db:wipe`.
- Drain or deliberately clear old probe jobs before starting workers on new code.
- Stop the scheduler while transitioning queue payloads.
- Preserve `.env` and `APP_KEY`.
- Run the scope-rollup backfill after migration.

### 1. Record current state

```sh
cd /www/sites/pingglass-time.samsam123.name.my/index

php artisan --version
php artisan migrate:status
php artisan schedule:list
php artisan queue:failed
```

Record queue state:

```sh
php artisan tinker --execute='dump([
    "pending" => \Illuminate\Support\Facades\Redis::llen("queues:probes"),
    "reserved" => \Illuminate\Support\Facades\Redis::zcard("queues:probes:reserved"),
    "delayed" => \Illuminate\Support\Facades\Redis::zcard("queues:probes:delayed"),
    "claimed" => \App\Models\Target::whereNotNull("active_probe_cycle_id")->count(),
    "running_cycles" => \App\Models\ProbeCycle::where("status", "running")->count(),
]);'
```

### 2. Back up

```sh
mysqldump --single-transaction --routines --triggers \
  -h 127.0.0.1 -u pingglass -p pingglass \
  | gzip > "pingglass-before-upgrade-$(date +%Y%m%d-%H%M%S).sql.gz"
```

Also copy `.env` and any deployment/process-manager files to secure backup storage.

### 3. Pause dispatch and drain workers

Disable the external `schedule:run` cron entry or stop the supervised scheduler. Do not start a manual probe cycle during the maintenance window.

Preferred approach:

1. Let current `probes` jobs finish.
2. Wait for `pending=0` and `reserved=0`.
3. Stop/restart workers only after the queue is drained.

If old serialized jobs are known to be incompatible and must be discarded, stop all probe workers first, then run:

```sh
php artisan queue:clear redis --queue=probes
```

Clearing jobs can leave database claims on old running cycles. Only when all old workers are stopped, mark those cycles abandoned and release their target claims:

```sh
php artisan tinker --execute='\Illuminate\Support\Facades\DB::transaction(function () {
    $ids = \App\Models\ProbeCycle::where("status", "running")->pluck("id");
    \App\Models\Target::whereIn("active_probe_cycle_id", $ids)->update(["active_probe_cycle_id" => null]);
    \App\Models\ProbeCycle::whereIn("id", $ids)->update(["status" => "timed_out", "completed_at" => now()]);
    dump(["abandoned_cycles" => $ids->all()]);
});'
```

This recovery command intentionally abandons active cycles. Never run it while their workers are still probing.

### 4. Deploy code and dependencies

```sh
git pull --ff-only
composer install --no-dev --optimize-autoloader
npm install --no-audit --no-fund
npm run build
```

### 5. Run migrations

```sh
php artisan migrate --force
```

The high-scale migrations:

1. Add target scheduling/claim columns and the due-target index.
2. Create category/global scope rollups.
3. Remove duplicate target-state rows while keeping the newest and enforce one state per target.

#### Duplicate index migration history

Older copies of the third migration attempted to add `target_states_target_id_unique` even when an equivalent unique index already existed, producing MySQL error 1061.

The current migration checks for an equivalent unique index under any name before adding one. If an older deployment failed with:

```text
Duplicate key name 'target_states_target_id_unique'
```

deploy the current migration file and rerun:

```sh
php artisan migrate --force
```

Do not drop a valid unique `target_id` index just to make the migration pass. Inspect it when necessary:

```sql
SHOW INDEX FROM target_states;
```

### 6. Refresh optimized caches

```sh
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan schedule:clear-cache
```

### 7. Backfill public graphs

```sh
php artisan pingglass:backfill-scope-rollups --hours=24
```

Increase `--hours` only when you intentionally want a larger historical backfill and have measured database load.

### 8. Start workers and resume scheduler

Start/restart workers with the current commands, then restore exactly one scheduler mechanism.

### 9. Remove old failed-job records

First inspect them:

```sh
php artisan queue:failed
```

Do not retry legacy `ProbeTarget`, `BatchIcmpProbe`, or old one-attempt chunk payloads after changing queue architecture. When no longer needed for diagnosis:

```sh
php artisan queue:flush
```

`queue:flush` deletes failed-job records only. It does not clear pending jobs.

## `fping` permissions

### Find the installed path

Ubuntu commonly uses:

```sh
command -v fping
# /usr/bin/fping
```

Alpine commonly uses:

```sh
command -v fping
# /usr/sbin/fping
```

Set `PINGGLASS_FPING_PATH` to the exact result and rebuild the config cache.

### Test as the worker user

```sh
sudo -u www-data /usr/bin/fping -C 3 -q -p 500 -t 500 1.1.1.1
```

Expected output resembles:

```text
1.1.1.1 : 3.12 3.05 3.08
```

`fping` writes summary results to stderr in quiet count mode; this is normal and supported by the driver.

### Grant raw-socket access

Preferred approaches depend on the host and package:

- Use the distribution package's existing capabilities/setuid configuration.
- Grant `cap_net_raw` to the binary when supported.
- In a container, grant only `NET_RAW` and ensure the binary can use it.
- As a fallback, set the binary setuid root after evaluating the security tradeoff.

Capability example:

```sh
sudo setcap cap_net_raw+ep "$(command -v fping)"
getcap "$(command -v fping)"
```

Setuid fallback:

```sh
sudo chmod u+s "$(command -v fping)"
ls -l "$(command -v fping)"
```

Do not run the entire web application as root merely to make ICMP work.

## Nginx and PHP-FPM

Create `/etc/nginx/sites-available/pingglass`:

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name status.example.com;

    root /var/www/pingglass/public;
    index index.php;
    charset utf-8;

    client_max_body_size 10m;

    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options SAMEORIGIN always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        fastcgi_read_timeout 60;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    location ~* \.(?:css|js|png|jpg|jpeg|gif|ico|svg|webp|woff|woff2)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
        try_files $uri =404;
    }
}
```

Enable and verify:

```sh
sudo ln -s /etc/nginx/sites-available/pingglass /etc/nginx/sites-enabled/pingglass
sudo nginx -t
sudo systemctl reload nginx
```

Add HTTPS before public use. With Certbot:

```sh
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d status.example.com
```

## Scheduler

Use exactly one scheduler runner.

### Cron method

Install the cron entry under the same user that can read the application and write Laravel runtime files:

```cron
* * * * * cd /var/www/pingglass && php artisan schedule:run >> /dev/null 2>&1
```

Verify:

```sh
php artisan schedule:list
php artisan schedule:run -v
```

### Supervised scheduler method

If cron is unavailable, supervise:

```sh
php artisan schedule:work
```

Do not run `schedule:work` and an every-minute cron entry together.

### Scheduled tasks

| Frequency | Task |
|---|---|
| Every minute | Dispatch due probe chunks. |
| Every 2 minutes | Complete full cycles and time out abandoned cycles. |
| Every 3 minutes | Mark stale targets Unknown. |
| Every 5 minutes | Create five-minute target and scope rollups. |
| Hourly | Create hourly target and scope rollups. |
| Daily at 03:00 | Apply raw/five-minute retention. |

## Supervisor workers

### Probe workers

Create `/etc/supervisor/conf.d/pingglass-probes.conf`:

```ini
[program:pingglass-probes]
process_name=%(program_name)s_%(process_num)02d
directory=/var/www/pingglass
command=/usr/bin/php artisan queue:work redis --queue=probes --sleep=1 --tries=3 --timeout=120 --max-time=3600
user=www-data
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=135
numprocs=8
redirect_stderr=true
stdout_logfile=/var/www/pingglass/storage/logs/probes.log
stdout_logfile_maxbytes=50MB
stdout_logfile_backups=5
```

Use `numprocs=20` to `30` only after measuring host and database capacity for a large installation.

`stopwaitsecs` should be greater than the worker timeout so Supervisor can allow graceful job completion before sending a hard kill.

### General workers

Create `/etc/supervisor/conf.d/pingglass-default.conf`:

```ini
[program:pingglass-default]
process_name=%(program_name)s_%(process_num)02d
directory=/var/www/pingglass
command=/usr/bin/php artisan queue:work redis --queue=default,maintenance --sleep=1 --tries=3 --timeout=60 --max-time=3600
user=www-data
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=75
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/pingglass/storage/logs/worker.log
stdout_logfile_maxbytes=25MB
stdout_logfile_backups=5
```

### Apply Supervisor configuration

```sh
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status
```

After any PHP code or `.env` change:

```sh
php artisan optimize:clear
php artisan config:cache
php artisan queue:restart
```

Or restart through Supervisor:

```sh
sudo supervisorctl restart pingglass-probes:*
sudo supervisorctl restart pingglass-default:*
```

## Docker and Alpine deployment

The production container must include PHP extensions, Composer-installed dependencies, built frontend assets, and `fping`.

### Prefer installation at image-build time

For Alpine-based PHP images, add `fping` to the Dockerfile instead of installing it on every container start:

```dockerfile
RUN apk add --no-cache fping \
    && chmod u+s /usr/sbin/fping
```

Evaluate whether `cap_add: NET_RAW` plus file capabilities can replace setuid in your environment. Keep privileges as narrow as possible.

### Recommended service separation

The cleanest model uses separate containers/processes for:

- `web`: PHP-FPM
- `probes`: multiple probe-worker replicas or a supervised worker container
- `maintenance`: two `default,maintenance` workers
- `scheduler`: one `schedule:work` process
- MySQL
- Redis
- Nginx/reverse proxy

All application containers must use the same code release, `.env` values, database, Redis instance, and `APP_KEY`.

### Single-container command compatible with the current installation

When PHP-FPM and workers must run in one container, this command matches the latest queue behavior:

```sh
sh -c '
  set -e

  apk add --no-cache fping
  chmod u+s "$(command -v fping)"

  cd /www/sites/pingglass-time.samsam123.name.my/index

  for i in $(seq 1 30); do
    (
      while true; do
        php artisan queue:work redis \
          --queue=probes \
          --sleep=1 \
          --tries=3 \
          --timeout=120 \
          --max-time=3600
        sleep 1
      done
    ) &
  done

  for i in 1 2; do
    (
      while true; do
        php artisan queue:work redis \
          --queue=default,maintenance \
          --sleep=1 \
          --tries=3 \
          --timeout=60 \
          --max-time=3600
        sleep 1
      done
    ) &
  done

  exec php-fpm -F
'
```

Important:

- This command does not run the Laravel scheduler.
- Keep exactly one external cron `schedule:run` or one separately supervised `schedule:work` process.
- The shell loops restart workers after their one-hour `--max-time` recycle.
- `--tries=3` matches the current job's retry/duplicate-delivery strategy.
- `--timeout=120` must remain below Redis retry-after 240.
- Restart the container after deploying PHP code or changing configuration.
- Thirty probe workers are a high-concurrency setting, not a universal default.

### Docker health and capability checks

Inside the application container:

```sh
command -v fping
ls -l "$(command -v fping)"
php artisan tinker --execute='dump(config("pingglass.fping_path"));'
"$(command -v fping)" -C 3 -q -p 500 -t 500 1.1.1.1
```

Also check file descriptors:

```sh
ulimit -n
```

A worker handles at most one chunk at a time, but total container socket use is approximately chunk size multiplied by active probe workers during a TCP round.

## Administrator bootstrap and account management

The login page intentionally does not display or prefill bootstrap credentials.

For a fresh seeded installation, replace the bootstrap account details before exposing the site. In a trusted maintenance shell:

```sh
php artisan tinker
```

Then update the first user with values you choose:

```php
$user = App\Models\User::oldest('id')->firstOrFail();
$user->name = 'Your Administrator Name';
$user->email = 'your-admin@example.com';
$user->password = 'Choose-A-Long-Unique-Password-123';
$user->save();
```

The User model hashes assigned passwords automatically. Avoid putting real passwords directly in shell command arguments because shell history and process listings may expose them.

After login, open **Admin -> Administrators** to:

- Create additional administrator accounts.
- Change your own password.
- Change another administrator's password.
- See account creation time and identify the current account.

All accounts are full administrators. New and changed passwords require:

- At least 12 characters.
- Uppercase and lowercase letters.
- At least one number.
- Matching password confirmation.

User creation and password changes are audit logged without storing password values.

## Post-deployment verification

### 1. Application and migrations

```sh
php artisan about
php artisan migrate:status
php artisan route:list --path=admin/users
php artisan schedule:list
```

### 2. Runtime configuration

```sh
php artisan tinker --execute='dump([
    "fping_path" => config("pingglass.fping_path"),
    "display_timezone" => config("pingglass.display_timezone"),
    "chunk_size" => config("pingglass.probe_chunk_size"),
    "cycle_timeout_minutes" => config("pingglass.cycle_timeout_minutes"),
    "job_timeout" => (new \App\Jobs\ProbeTargetsChunk([], 0))->timeout,
    "job_tries" => (new \App\Jobs\ProbeTargetsChunk([], 0))->tries,
    "redis_retry_after" => config("queue.connections.redis.retry_after"),
]);'
```

Recommended large-installation output:

```text
fping_path             /usr/sbin/fping (Alpine) or /usr/bin/fping (Ubuntu)
display_timezone       Asia/Kuala_Lumpur
chunk_size             100
cycle_timeout_minutes  15
job_timeout            110
job_tries              3
redis_retry_after      240
```

### 3. Scheduler and dispatch

Run one manual cycle only when the scheduler is paused or you know no dispatch is currently running:

```sh
php artisan pingglass:probe-cycle
```

Expected example:

```text
Cycle #134: 10914 due targets, 21827 protocol probes, 110 chunk jobs.
```

### 4. Queue state

```sh
php artisan tinker --execute='dump([
    "pending" => \Illuminate\Support\Facades\Redis::llen("queues:probes"),
    "reserved" => \Illuminate\Support\Facades\Redis::zcard("queues:probes:reserved"),
    "delayed" => \Illuminate\Support\Facades\Redis::zcard("queues:probes:delayed"),
    "claimed" => \App\Models\Target::whereNotNull("active_probe_cycle_id")->count(),
    "latest_cycle" => \App\Models\ProbeCycle::latest("id")->first()?->toArray(),
]);'
```

With 30 active probe workers, `reserved` often reaches 30 while pending jobs fall. A reserved job is not automatically a problem; it means a worker is processing it.

### 5. Failed jobs

```sh
php artisan queue:failed
```

Inspect the most recent exception:

```sh
php artisan tinker --execute='$failure = \Illuminate\Support\Facades\DB::table("failed_jobs")->latest("id")->first(); dump([
    "failed_at" => $failure?->failed_at,
    "exception" => $failure?->exception,
]);'
```

### 6. Admin health page

Open `/admin/health` and confirm:

- Database: Healthy
- Redis: Healthy
- Queue: Healthy or a queue depth that is actively decreasing
- Worker: recent completed cycle
- Failed Jobs: zero after known historical failures are cleared
- `fping`: correct path and working
- TCP probe: working
- Scheduler: recent cycle in UTC+8 (or configured display timezone)
- Probe Configuration: chunk 100, job timeout 110s, Redis retry-after 240s

### 7. Web UI

Verify:

- Login has no displayed default credentials and uses a generic email placeholder.
- Public, login, and admin pages show the GitHub-linked footer.
- Admin -> Administrators can create a user and change a password.
- Dashboard and incident timestamps show the configured timezone.
- Chart axes and tooltips show the configured timezone.
- Public global and category graphs contain data after rollup/backfill.

## Large installations: 10,000+ targets

### Start safely

Recommended initial values for the current 10k installation:

```env
PINGGLASS_PROBE_CHUNK_SIZE=100
PINGGLASS_CYCLE_TIMEOUT_MINUTES=15
REDIS_QUEUE_RETRY_AFTER=240
PINGGLASS_DNS_CACHE_TTL=3600
PINGGLASS_DNS_CACHE_JITTER=3600
PINGGLASS_DNS_FAILURE_CACHE_TTL=300
PINGGLASS_DNS_FAILURE_CACHE_JITTER=300
```

Worker command:

```sh
php artisan queue:work redis --queue=probes --sleep=1 --tries=3 --timeout=120 --max-time=3600
```

Start with a five-minute global probe interval unless measurements prove the complete workload comfortably fits into a shorter interval.

### Understand cold versus warm jobs

Hostname resolution uses PHP's blocking `dns_get_record`. A cold 100-target chunk can spend most of its duration resolving hostnames serially before ICMP/TCP starts.

The shared staggered DNS cache changes steady-state behavior:

- Successes remain cached for 1-2 hours by default.
- Failures remain cached for 5-10 minutes.
- Deterministic jitter spreads expiry across the configured window.

Do not clear the complete application cache during peak probing unless necessary. Clearing DNS cache makes the next hostname-heavy cycle cold.

### Expected probe time components

For 10 samples and a 1,000 ms timeout/period:

- ICMP count mode requires roughly nine sample periods plus timeout/headroom.
- TCP runs 10 concurrent rounds; unreachable sockets can consume close to one timeout per round.
- ICMP and TCP execute sequentially inside a chunk job.
- DNS happens before the drivers and can dominate a cold job.
- Database upserts and status/incident evaluation run after probes.

A completed 80-100 second cold job is not itself a failure, but it leaves little headroom under the 110-second job timeout and cannot sustain a true one-minute cycle across several worker waves.

### Do not increase chunk size blindly

At chunk 100, a 90-second job becoming chunk 200 can exceed 110 seconds because DNS and database work increase. Increase only after warm jobs consistently complete below 45-60 seconds.

Suggested process:

1. Keep chunk 100.
2. Deploy and warm the staggered DNS cache.
3. Observe at least several complete cycles.
4. Record median and p95 job durations.
5. Verify no failed jobs, Redis re-deliveries, MySQL pressure, or socket/file-descriptor warnings.
6. Try chunk 150.
7. Re-measure.
8. Try 200 only if substantial headroom remains.

### Effective concurrency

```text
maximum TCP sockets per round ~= chunk size x active probe workers
```

Examples:

| Chunk | Workers | Approximate upper bound |
|---:|---:|---:|
| 100 | 20 | 2,000 sockets |
| 100 | 30 | 3,000 sockets |
| 200 | 30 | 6,000 sockets |

This is an upper bound; actual endpoints and fast failures reduce instantaneous use. Check:

- Per-process `ulimit -n`
- Container/host file descriptor limits
- NAT/conntrack capacity
- Source-port capacity
- Firewall rate limits
- Resolver capacity
- MySQL connection and write pressure

### Probe interval and sample count

Reducing samples often improves throughput more predictably than increasing chunk size.

For a high-scale installation, consider:

- ICMP samples: 3-5
- TCP samples: 3-5
- Timeout: 500-1,000 ms when network geography permits
- Default interval: 300 seconds

Change these through Admin -> Settings and evaluate data quality against throughput. Do not use an unrealistically short timeout for distant or congested targets.

### Cycle completion window

The 15-minute cycle timeout applies to a full cycle, not each job. It must accommodate queue waiting plus all worker waves. Increase it only if healthy, expected cycles require longer than 15 minutes; a very long value can hide stopped workers and retain claims longer after crashes.

## Operational monitoring

### Quick status snapshot

```sh
php artisan tinker --execute='dump([
    "pending" => \Illuminate\Support\Facades\Redis::llen("queues:probes"),
    "reserved" => \Illuminate\Support\Facades\Redis::zcard("queues:probes:reserved"),
    "delayed" => \Illuminate\Support\Facades\Redis::zcard("queues:probes:delayed"),
    "failed" => \Illuminate\Support\Facades\DB::table("failed_jobs")->count(),
    "claimed" => \App\Models\Target::whereNotNull("active_probe_cycle_id")->count(),
    "latest" => \App\Models\ProbeCycle::latest("id")->first()?->toArray(),
    "last_completed" => \App\Models\ProbeCycle::where("status", "completed")->latest("completed_at")->first()?->toArray(),
]);'
```

### Queue trend

The important signal is direction:

- Pending decreasing between dispatches: workers have positive throughput.
- Pending returns to near zero: capacity fits the due workload.
- Pending never falls: interval/worker/probe configuration is overloaded.
- Reserved near worker count: workers are busy.
- Delayed jobs: duplicate lock deferrals or explicit job releases may be occurring.

### Cycle trend

Track:

- Target count
- Expected probe count
- Successful + failed count
- Completed/timed-out status
- Cycle duration
- Difference between cycle start times and target intervals

### MySQL growth queries

```sql
SELECT COUNT(*) AS measurements FROM measurements;
SELECT MIN(measured_at), MAX(measured_at) FROM measurements;
SELECT granularity, COUNT(*) FROM measurement_rollups GROUP BY granularity;
SELECT granularity, COUNT(*) FROM scope_measurement_rollups GROUP BY granularity;
```

For table sizes:

```sql
SELECT
    table_name,
    ROUND((data_length + index_length) / 1024 / 1024, 2) AS total_mb
FROM information_schema.tables
WHERE table_schema = 'pingglass'
ORDER BY data_length + index_length DESC;
```

### Logs

Useful sources:

- `storage/logs/laravel.log`
- Supervisor probe/general logs
- Container stdout/stderr
- PHP-FPM logs
- Nginx access/error logs
- MySQL slow query log
- Redis logs
- Kernel/container OOM events

## Troubleshooting

### `MaxAttemptsExceededException` in 1-2 ms

Meaning: Laravel rejected a re-delivered job before `handle()` ran. This is not an ICMP/TCP failure and not caused directly by chunk size.

Common cause: effective Redis `retry_after` is still 90 seconds or workers were not restarted after configuration changed, while jobs take 90-110 seconds.

Check:

```sh
php artisan tinker --execute='dump([
    "job_timeout" => (new \App\Jobs\ProbeTargetsChunk([], 0))->timeout,
    "job_tries" => (new \App\Jobs\ProbeTargetsChunk([], 0))->tries,
    "retry_after" => config("queue.connections.redis.retry_after"),
]);'
```

Expected: `110`, `3`, and `240`.

Fix:

```sh
php artisan optimize:clear
php artisan config:cache
php artisan queue:restart
```

Change external worker commands from `--tries=1` to `--tries=3`. Old queued payloads can retain old attempt metadata; do not retry known old failed payloads.

### `TimeoutExceededException` near 110 seconds

Meaning: the job exceeded its own timeout.

Inspect the exception stack:

- `SsrfProtection.php` / `dns_get_record`: cold or slow DNS dominates.
- `FpingDriver.php`: ICMP process duration or `fping` problem.
- `TcpConnectDriver.php`: repeated socket timeouts.
- Database/driver lines after probing: MySQL latency or lock contention.

Actions:

1. Keep or reduce chunk size.
2. Confirm staggered DNS-cache settings are deployed.
3. Reduce samples or timeouts when operationally acceptable.
4. Check resolver health.
5. Check MySQL latency.
6. Avoid raising the job timeout above the 120-second worker timeout.

### Log shows `Killed`

Possible causes:

- Laravel/worker timeout sent a signal.
- Supervisor/container stop grace was too short.
- Container/host out-of-memory killer terminated PHP.
- Platform process limit terminated the worker.

Check the failed-job exception, container exit reason, kernel logs, and duration. Do not assume every `Killed` line is an OOM event.

### Jobs take 1-2 minutes but finish

Cold hostname-heavy chunks can legitimately take 80-100 seconds. This is within the 110-second job limit but too slow to promise a one-minute interval across multiple waves.

Confirm whether later warm cycles get faster. If not, profile DNS, ICMP sample period, TCP timeouts, and MySQL separately.

### ICMP is Unknown while TCP works

Check:

```sh
command -v fping
ls -l "$(command -v fping)"
php artisan tinker --execute='dump(config("pingglass.fping_path"));'
"$(command -v fping)" -C 3 -q -p 500 -t 500 1.1.1.1
```

Then test as the exact worker user. Confirm raw-socket permission/capability and that the configured path matches the container/host.

### Queue depth continually grows

Calculate expected jobs and waves. Then check:

- Are all intended workers running?
- Are jobs warm or cold?
- Is `retry_after` correct?
- Are jobs failing/re-delivering?
- Are TCP endpoints timing out every round?
- Is DNS slow?
- Is MySQL write latency high?
- Is the requested interval shorter than total cycle capacity?

Do not immediately increase chunk size. More workers can help only while infrastructure has headroom.

### Scheduler warning

```sh
php artisan schedule:list
php artisan schedule:run -v
php artisan tinker --execute='dump(\App\Models\ProbeCycle::latest("id")->first()?->toArray());'
```

Ensure cron uses the correct PHP binary, working directory, user, `.env`, and filesystem permissions.

### Workers warning but jobs show DONE

The health worker check looks for a recently completed full cycle, not merely an individual DONE job. A large cold cycle can have many completed chunks while the full cycle remains running.

Check expected versus successful/failed probe counts and ensure the full-cycle timeout is 15 minutes.

### No public average graph

Run scheduled rollups and backfill:

```sh
php artisan pingglass:aggregate-five-minute
php artisan pingglass:aggregate-hourly
php artisan pingglass:backfill-scope-rollups --hours=24
```

Only enabled public targets in enabled public categories contribute to scope rollups.

### Times display in UTC instead of UTC+8

Set:

```env
PINGGLASS_DISPLAY_TIMEZONE=Asia/Kuala_Lumpur
```

Then:

```sh
php artisan optimize:clear
php artisan config:cache
```

Rebuild assets when deploying code containing the timezone formatter and hard-refresh the browser. Do not change stored timestamps to local time.

### Failed-job cleanup

Inspect first:

```sh
php artisan queue:failed
```

Delete failed-job records only after retaining any needed exception details:

```sh
php artisan queue:flush
```

Do not use `queue:retry all` for legacy or MaxAttempts payloads from an older release.

## Safe application updates

### Normal update with queue drain

1. Announce/enter a maintenance window if needed.
2. Pause the scheduler.
3. Allow the probe queue to drain.
4. Back up MySQL and `.env`.
5. Deploy code.
6. Install dependencies and build assets.
7. Run migrations.
8. Rebuild caches.
9. Restart workers.
10. Resume scheduler.
11. Verify health and one complete cycle.

Commands:

```sh
cd /var/www/pingglass

git pull --ff-only
composer install --no-dev --optimize-autoloader
npm install --no-audit --no-fund
npm run build

php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan schedule:clear-cache
php artisan queue:restart
```

If using Supervisor:

```sh
sudo supervisorctl restart pingglass-probes:*
sudo supervisorctl restart pingglass-default:*
```

If using the single-container shell, restart the container so PHP-FPM and every worker load the new code/configuration.

### When queue payload classes changed

PHP queue payloads serialize class/property information. If a release changes job structure or replaces old job types:

- Pause dispatch.
- Drain the queue if possible.
- Otherwise stop workers and clear only the affected queue.
- Release database claims/abandon cycles as documented in the existing-database upgrade section.
- Do not retry old failed payloads.

## Backups and restore preparation

### Full database backup

```sh
mysqldump --single-transaction --routines --triggers \
  -h 127.0.0.1 -u pingglass -p pingglass \
  | gzip > "pingglass-full-$(date +%Y%m%d-%H%M%S).sql.gz"
```

### Smaller configuration and history backup

If raw measurements are too large for frequent full backups:

```sh
mysqldump --single-transaction \
  -h 127.0.0.1 -u pingglass -p pingglass \
  users categories targets target_states monitor_settings incidents \
  measurement_rollups scope_measurement_rollups audit_logs migrations \
  | gzip > "pingglass-config-rollups-$(date +%Y%m%d-%H%M%S).sql.gz"
```

This smaller backup intentionally excludes raw `measurements`, queue tables, and sessions.

### Files to preserve

- `.env`
- `APP_KEY`
- Reverse-proxy configuration
- Supervisor/systemd/container definitions
- TLS certificate configuration
- Backup encryption keys and credentials
- Any custom branding or local code changes

### Restore rehearsal

Test restores on a separate database/server. A backup is not proven until it can be restored and the application can run migrations and load targets/rollups.

Example restore:

```sh
gunzip -c pingglass-full-YYYYMMDD-HHMMSS.sql.gz \
  | mysql -h 127.0.0.1 -u pingglass -p pingglass

php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
```

After restoring without Redis queue state, old active target claims may remain in MySQL. Let the cycle sweeper time them out or deliberately abandon running cycles while all workers are stopped.

## Rollback planning

Database migrations can be forward-only in operational practice even when Laravel provides `down()` methods. The target-state uniqueness migration intentionally retains a valid unique index during rollback to avoid reintroducing duplicate state rows.

Before deployment:

1. Record the current Git commit.
2. Take a database backup.
3. Preserve the old built assets and dependency lockfiles.
4. Know whether the release adds schema required by new queue payloads.
5. Drain incompatible jobs.

If a release must be rolled back:

- Stop scheduler/workers first.
- Restore compatible code and dependencies.
- Restore the database backup when schema/data compatibility is uncertain.
- Clear incompatible queued payloads.
- Rebuild config/route/view caches.
- Restart PHP-FPM and workers.
- Verify one full cycle before restoring normal traffic.

Do not use `git reset --hard`, `migrate:fresh`, or unreviewed manual index/table deletion as a production rollback method.


## 1Panel Deployment
To deploy PingGlass using 1Panel, follow these steps:

1. Ensure your PHP Docker Image is installed with the redis and phpredis extensions.
2. Configure your PingGlass PHP Docker container with the following entrypoint command:

```bash
# 1Panel PHP Docker Image using Alpine Linux
sh -c "
  set -e
  apk add --no-cache fping # Install fping 
  chmod u+s \$(which fping) # Assign permissions
  
  cd /www/sites/<pingglass_directory>/index

  # Start PHP-FPM
  exec php-fpm -F 
"
```
3. Configure Supervisor with 1Panel (Toolbox -> Supervisor) with two configuration below.
```ini
[program:pingglass-probes]
command                 = docker exec -i <PHPContainerRunningPingGlass> -c 'cd <DirectoryToPingGlassIndexInDocker> && exec php artisan queue:work redis --queue=probes --sleep=1 --tries=3 --timeout=180 --max-time=3600'
directory               = /
autorestart             = true
startsecs               = 3
stdout_logfile          = /opt/1panel/tools/supervisord/log/pingglass-probes.out.log
stderr_logfile          = /opt/1panel/tools/supervisord/log/pingglass-probes.err.log
stdout_logfile_maxbytes = 2MB
stderr_logfile_maxbytes = 2MB
user                    = root
priority                = 999
numprocs                = 4
process_name            = %(program_name)s_%(process_num)02d


[program:pingglass-default]
command                 = docker exec -i <PHPContainerRunningPingGlass> sh -c 'cd <DirectoryToPingGlassIndexInDocker> && exec php artisan queue:work redis --queue=default,maintenance --sleep=1 --tries=3 --timeout=60 --max-time=3600'
directory               = /
autorestart             = true
startsecs               = 3
stdout_logfile          = /opt/1panel/tools/supervisord/log/pingglass-default.out.log
stderr_logfile          = /opt/1panel/tools/supervisord/log/pingglass-default.err.log
stdout_logfile_maxbytes = 2MB
stderr_logfile_maxbytes = 2MB
user                    = root
priority                = 999
numprocs                = 2
process_name            = %(program_name)s_%(process_num)02d
```
5. Ensure you have a process to run the Laravel scheduler every minute. In 1Panel, create a Cron Job targeting the PingGlass Docker container with the following command:

```bash
cd /www/sites/<pingglass_directory>/index && php artisan schedule:run >> /dev/null 2>&1
```

5. Set the PINGGLASS_FPING_PATH environment variable to the path of fping (e.g., /usr/sbin/fping or /usr/bin/fping, depending on your PHP Docker image).

6. Set your web server root directory to `/www/sites/<pingglass_directory>/index/public` and ensure that your web server is configured to serve the application correctly, choose Laravel on the pseudo-static configuration.
