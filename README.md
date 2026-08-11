# PingGlass

PingGlass is a self-hosted network latency, packet-loss, reachability, and status-page platform. It combines SmokePing-style latency distribution charts with batched ICMP probing, concurrent TCP connection timing, incident tracking, configurable probe intervals, category/global aggregate graphs, and an authenticated administration panel.

The current probe architecture is designed for both small installations and installations with 10,000 or more targets. Targets are claimed when due, grouped into bounded queue jobs, probed in batches, and written with idempotent database operations.

Repository: [github.com/samleong123/pingglass](https://github.com/samleong123/pingglass)

## Highlights

- ICMP probing through `fping`, including individual sample values and packet loss.
- TCP connection timing using non-blocking PHP sockets.
- Concurrent TCP probing across every target in a chunk for each sample round.
- Per-target ICMP, TCP, or combined ICMP and TCP monitoring.
- Global probe interval plus optional per-target interval overrides.
- Supported intervals: 1, 2, 5, 10, 15, 30, or 60 minutes.
- Median, average, minimum, maximum, P10, P25, P75, P90, P95, standard deviation, and packet-loss statistics.
- Smoke-style percentile bands rendered with ECharts.
- Public global and per-category 24-hour average-latency graphs.
- Raw, five-minute, and hourly data resolutions.
- Online, Degraded, Down, and Unknown states with failure and recovery confirmation.
- Automatic incident opening and recovery.
- Public category, target, chart, and CSV endpoints.
- Public host hiding and category/target visibility controls.
- CSV bulk target import.
- Admin health dashboard for database, Redis, queues, workers, `fping`, TCP, scheduler, and failed jobs.
- Administrator creation and password changes from the web UI.
- Shared UTC storage with a configurable display timezone.
- Dark mode and responsive public/admin interfaces.
- A GitHub-linked "Powered by PingGlass" footer on public, login, and admin pages.

## Technology stack

| Layer | Technology |
|---|---|
| Application | Laravel 11, PHP 8.2 or later |
| Web UI | Vue 3, Inertia.js, Tailwind CSS, shadcn-vue components |
| Charts | Apache ECharts |
| Database | MySQL 8.0 or later |
| Queue and cache | Redis with the PHP Redis extension |
| ICMP | `fping` |
| TCP | PHP non-blocking streams and `stream_select` |
| Scheduling | Laravel scheduler |
| Process supervision | Supervisor, systemd, Docker, or another process manager |

PingGlass is one Laravel application. It does not require a separate Node.js server or a separate probe daemon after the frontend assets have been built.

## Requirements

- PHP 8.2+
- Composer 2
- MySQL 8+
- Redis
- PHP extensions required by Laravel plus `pdo_mysql`, `redis`, and `sockets`
- `fping`
- Node.js 18+ and npm for building frontend assets
- A scheduler invocation every minute
- At least one worker listening to the `probes` queue
- At least one worker listening to `default,maintenance`

For the complete production procedure, including Ubuntu, Nginx, Supervisor, Docker, upgrades, existing databases, and 10k-target tuning, read [DEPLOYMENT.md](DEPLOYMENT.md).

## Quick start for development

```sh
git clone https://github.com/samleong123/pingglass.git
cd pingglass

composer install
npm install

cp .env.example .env
php artisan key:generate
```

Edit `.env` and configure MySQL, Redis, `APP_URL`, and the local `fping` path. Then initialize the database:

```sh
php artisan migrate
php artisan db:seed
php artisan storage:link
npm run build
```

For local development, start the web application, scheduler, and workers in separate terminals:

```sh
php artisan serve
```

```sh
php artisan schedule:work
```

```sh
php artisan queue:work redis --queue=probes --sleep=1 --tries=3 --timeout=120
```

```sh
php artisan queue:work redis --queue=default,maintenance --sleep=1 --tries=3 --timeout=60
```

The development seeder creates a bootstrap administrator and sample targets. Treat the seeded account as development-only. After signing in, open **Admin -> Administrators** to set a strong password and create named accounts for other administrators.

Do not expose a seeded installation publicly before replacing the bootstrap password.

## Application areas

### Public site

The public site provides:

- An overall service status banner.
- A 24-hour average-latency graph across all enabled public targets.
- A category summary with public target counts and current states.
- A separate 24-hour average-latency graph for every public category.
- Per-target latency and packet-loss charts.
- ICMP and TCP visibility toggles.
- Ranges for 1 hour, 6 hours, 24 hours, 7 days, 30 days, 6 months, and 1 year.
- Incident history.
- CSV export.
- Relative last-check times and absolute timestamps in the configured display timezone.

Only categories and targets that are both enabled and public are included. A target host is shown only when `show_host_publicly` is enabled.

### Admin panel

Authenticated administrators can manage:

- Dashboard summaries and recent incidents.
- Categories, visibility, enablement, and ordering.
- Targets, hostnames/IPs, protocols, TCP ports, visibility, intervals, and thresholds.
- Target connection tests.
- CSV target imports.
- Open and closed incidents.
- Global monitoring settings.
- Administrator accounts and passwords.
- System health and active runtime configuration.

Every authenticated account is currently an administrator with full access. Create a separate named account for each operator instead of sharing credentials.

## Monitoring architecture

The scheduler runs `pingglass:probe-cycle` every minute. The command does not automatically probe every target each minute. It selects only targets whose effective interval is due.

The dispatch flow is:

1. Acquire a 55-second dispatch lock so two scheduler invocations cannot dispatch the same minute concurrently.
2. Select enabled targets with at least one usable protocol configuration.
3. Select only targets whose `next_probe_at` is null or due.
4. Exclude targets already claimed by an active probe cycle.
5. Create one `probe_cycles` record.
6. Set each target's next due time and `active_probe_cycle_id` claim.
7. Split the selected IDs into bounded chunks.
8. Dispatch one `ProbeTargetsChunk` job per chunk to the `probes` queue.

For 10,914 due targets with a chunk size of 100, PingGlass creates 110 jobs rather than more than 10,000 jobs.

### Chunk job safety

Each `ProbeTargetsChunk` job:

- Has a 110-second application timeout.
- Allows up to three attempts.
- Uses a per-cycle/per-chunk Redis execution lock.
- Defers a duplicate delivery for 30 seconds if the original is still active.
- Loads only targets still claimed by that cycle.
- Performs batched ICMP and concurrent TCP probing.
- Bulk-upserts measurements and current state.
- Uses a unique target/protocol/cycle key so measurement retries are idempotent.
- Updates cycle counters once per chunk.
- Releases target claims after completion or final failure.

The required timeout order is:

```text
job timeout (110s) < worker timeout (120s) < Redis retry_after (240s)
```

If `retry_after` is shorter than real job duration, Redis can re-deliver a job that is still running. The Admin System Health page displays the effective job timeout and Redis retry-after value so this mismatch is visible.

### ICMP behavior

The ICMP driver:

- Resolves and validates hostnames before probing.
- Groups targets by resolved IP so duplicate IPs are probed once.
- Passes the IP list to one `fping` process over standard input.
- Uses `-C` for sample count, `-i` for the global packet interval, `-p` for the per-target period, and `-t` for timeout.
- Parses every returned sample, including losses represented as null values.

The `fping` process duration is primarily determined by sample count and timeout/period, not by multiplying the timeout by every target. DNS resolution, however, is blocking in PHP and occurs before `fping` starts.

### DNS caching and SSRF validation

Successful hostname resolutions are shared through the configured Laravel cache. By default:

- Successful records live for a base 3,600 seconds plus 0-3,600 seconds of deterministic jitter.
- Failed records live for a base 300 seconds plus 0-300 seconds of jitter.
- Cache jitter spreads refreshes across time instead of expiring thousands of records in one cycle.
- Old short-lived cache entries are upgraded when read.

Every newly resolved IP is checked against SSRF restrictions. Private, reserved, loopback, link-local, unspecified, and multicast destinations are rejected unless private targets are explicitly enabled.

### TCP behavior

For every configured sample round, the TCP driver:

1. Opens asynchronous non-blocking connection attempts for every endpoint in the chunk.
2. Waits on the streams together with `stream_select`.
3. Records connection-establishment latency for successful sockets.
4. Records null for failures/timeouts.
5. Closes all sockets before the next sample round.

A chunk size of 100 therefore allows up to 100 TCP connections at once per worker, not one host at a time. With 30 workers, the theoretical instantaneous upper bound is roughly 3,000 sockets per sample round.

## Probe intervals

The global interval is stored in `monitor_settings` and can be changed from **Admin -> Settings**. A target can inherit the global value or override it with one of these values:

| UI value | Seconds |
|---|---:|
| 1 minute | 60 |
| 2 minutes | 120 |
| 5 minutes | 300 |
| 10 minutes | 600 |
| 15 minutes | 900 |
| 30 minutes | 1800 |
| 60 minutes | 3600 |

Changing the global interval makes targets that inherit it eligible for rescheduling. Changing a target's host, protocols, port, interval, or enabled state also clears its next due time so the new configuration is probed promptly.

The requested interval is a scheduling target, not a guarantee. If total probe work takes longer than the interval, the active-cycle claim prevents the same target from being queued repeatedly, but the effective measurement interval becomes longer.

## Status definitions

PingGlass first evaluates ICMP and TCP separately, then calculates an overall state.

| State | Meaning |
|---|---|
| Online | Every enabled protocol is reachable and below the configured loss and median-latency thresholds. |
| Degraded | A reachable protocol exceeds a loss/latency threshold, enabled protocols have mixed results, or total loss is still awaiting Down confirmation. |
| Down | Every enabled protocol has 100% loss for the configured number of consecutive complete evaluations. |
| Unknown | DNS, tooling, or another probe error produced no trustworthy result, or data became stale because probing stopped. |

Important details:

- Partial loss counts as Degraded only when it meets or exceeds the configured loss threshold.
- Median latency above the configured threshold counts as Degraded.
- Degraded does not increment the Down confirmation counter.
- A DNS or tool error is Unknown, not Down.
- Down requires the configured number of consecutive total-failure cycles.
- Recovery from Down or Degraded requires the configured number of consecutive Online evaluations.
- Per-target loss and latency thresholds override the global thresholds.
- If one enabled protocol is Online while another is Down or Unknown, the overall state is Degraded.

### Staleness

Every three minutes, `pingglass:evaluate-staleness` checks enabled targets. A target is stale when its last usable measurement is older than:

```text
(effective probe interval x down confirmation cycles) + 120 seconds
```

Stale targets become Unknown. This prevents an old Online or Down state from being presented as current when the scheduler or workers stop.

## Incidents

Incidents are evaluated from the confirmed target state. PingGlass records the affected target, type, optional protocol, start/end timestamps, current status, reason, and duration.

Administrators can inspect all incidents and manually close an open incident. Public target pages show recent incident history for public targets.

## Measurements, rollups, and graphs

### Raw measurements

Each target/protocol/cycle measurement stores:

- Sent and received counts.
- Packet-loss percentage.
- Minimum, maximum, average, and median latency.
- P10, P25, P75, P90, and P95.
- Standard deviation.
- The raw sample array.
- Success, partial, failed, or error status.
- A diagnostic error string when applicable.

### Target rollups

- Raw measurements are aggregated into five-minute target/protocol buckets.
- Measurements are also aggregated into hourly target/protocol buckets.
- Statistics are recalculated from the underlying sample values instead of averaging already-averaged percentiles.

### Scope rollups

`scope_measurement_rollups` stores precomputed global and category averages for public graphs. These rows include contributing target count, total sent/received values, loss, and latency statistics for each protocol.

After upgrading an existing installation, build historical public graph data with:

```sh
php artisan pingglass:backfill-scope-rollups --hours=24
```

### Retention

By default:

- Raw measurements are retained for 30 days.
- Five-minute target and scope rollups are retained for 180 days.
- Hourly rollups are retained indefinitely.

Retention settings are editable from the admin UI. Cleanup runs daily at 03:00 according to the application scheduler.

## Target CSV import

Open **Admin -> Targets** and use the import function. The uploaded file must be CSV or text and no larger than 2 MB.

Each non-header row is:

```csv
name,host,tcp_port,slug
Example HTTPS,example.com,443,example-https
Example ICMP,1.1.1.1,,example-icmp
```

Rules:

- `name` and `host` are required.
- `tcp_port` and `slug` are optional.
- A slug is generated when omitted and made unique.
- Import-level checkboxes control public, enabled, ICMP, TCP, and host visibility defaults.
- TCP is enabled for a row only when the import enables TCP and the row supplies a port.
- Every host passes SSRF validation.
- Invalid rows are skipped and counted.

## Public HTTP API

All public API endpoints are rate-limited to 60 requests per minute.

| Method and path | Purpose |
|---|---|
| `GET /api/public/status` | Overall public status, categories, and targets. |
| `GET /api/public/categories` | Public category listing. |
| `GET /api/public/targets/{slug}/metrics?range=24h&protocol=all` | Target chart data. |
| `GET /api/public/targets/{slug}/csv?range=7d&protocol=icmp` | Target CSV export. |
| `GET /up` | Laravel application health endpoint. |

Supported metrics ranges are `1h`, `6h`, `24h`, `7d`, `30d`, `6m`, and `1y`. Protocol can be `icmp`, `tcp`, or `all`.

## Artisan commands

| Command | Purpose |
|---|---|
| `php artisan pingglass:probe-cycle` | Claim due targets and dispatch bounded probe jobs. |
| `php artisan pingglass:complete-cycles` | Complete fully measured cycles and time out abandoned cycles. |
| `php artisan pingglass:evaluate-staleness` | Mark targets Unknown when measurements are stale. |
| `php artisan pingglass:aggregate-five-minute` | Build five-minute target and scope rollups. |
| `php artisan pingglass:aggregate-hourly` | Build hourly target and scope rollups. |
| `php artisan pingglass:aggregate` | Run both rollup aggregations. |
| `php artisan pingglass:backfill-scope-rollups --hours=24` | Build public scope rollups from existing target rollups. |
| `php artisan pingglass:cleanup` | Apply raw and five-minute retention. |

## Configuration model

PingGlass has two configuration layers.

### Environment-only settings

These affect process safety or infrastructure and require configuration reloads and usually worker restarts.

```env
PINGGLASS_FPING_PATH=/usr/bin/fping
PINGGLASS_DISPLAY_TIMEZONE=Asia/Kuala_Lumpur
PINGGLASS_CYCLE_TIMEOUT_MINUTES=15
PINGGLASS_PROBE_CHUNK_SIZE=200
PINGGLASS_FPING_INTERVAL_MS=1
PINGGLASS_DNS_CACHE_TTL=3600
PINGGLASS_DNS_CACHE_JITTER=3600
PINGGLASS_DNS_FAILURE_CACHE_TTL=300
PINGGLASS_DNS_FAILURE_CACHE_JITTER=300
REDIS_QUEUE_RETRY_AFTER=240
PINGGLASS_ALLOW_PRIVATE_TARGETS=false
```

After changing an environment-only value:

```sh
php artisan optimize:clear
php artisan config:cache
php artisan queue:restart
```

### Database-managed monitoring settings

The following values use `.env` as initial fallback values but are normally stored in `monitor_settings` and edited from **Admin -> Settings**:

```env
PINGGLASS_PROBE_INTERVAL=60
PINGGLASS_ICMP_SAMPLES=10
PINGGLASS_TCP_SAMPLES=10
PINGGLASS_ICMP_TIMEOUT=2000
PINGGLASS_TCP_TIMEOUT=2000
PINGGLASS_LOSS_THRESHOLD_PERCENT=10
PINGGLASS_LATENCY_THRESHOLD_MS=200
PINGGLASS_RAW_RETENTION_DAYS=30
PINGGLASS_ROLLUP5M_RETENTION_DAYS=180
PINGGLASS_DOWN_CONFIRMATION_CYCLES=3
PINGGLASS_RECOVERY_CONFIRMATION_CYCLES=2
```

These settings are cached in Redis for five minutes. Saving the Settings page explicitly clears that shared cache, so long-running workers see changes on the next probe cycle.

See [DEPLOYMENT.md](DEPLOYMENT.md#environment-reference) for a complete variable-by-variable table.

## Scheduling

The Laravel scheduler contains:

| Frequency | Command |
|---|---|
| Every minute | `pingglass:probe-cycle` |
| Every 2 minutes | `pingglass:complete-cycles` |
| Every 3 minutes | `pingglass:evaluate-staleness` |
| Every 5 minutes | `pingglass:aggregate-five-minute` |
| Hourly | `pingglass:aggregate-hourly` |
| Daily at 03:00 | `pingglass:cleanup` |

Run exactly one scheduler mechanism:

```cron
* * * * * cd /path/to/pingglass && php artisan schedule:run >> /dev/null 2>&1
```

Alternatively, supervise `php artisan schedule:work`. Do not run both cron and `schedule:work` for the same installation.

## Capacity planning for large installations

### Measurement volume

Use this formula:

```text
rows per day = targets x enabled protocols per target x (86400 / interval seconds)
```

For 10,000 targets at a one-minute interval:

- One protocol produces 14.4 million raw rows per day.
- Two protocols produce 28.8 million raw rows per day.
- Thirty days of two-protocol raw retention can approach 864 million rows.

Use five-minute or longer intervals where one-minute detail is unnecessary, reduce raw retention according to storage capacity, and monitor MySQL I/O, table size, cleanup time, backup duration, and binlog volume.

### Queue sizing

```text
jobs per cycle = ceiling(due targets / chunk size)
worker waves = ceiling(jobs per cycle / probe worker count)
estimated cycle duration = worker waves x observed p95 chunk duration
```

Example: 10,914 due targets, chunk size 100, 30 workers, and 90-second p95 jobs:

```text
jobs = 110
waves = 4
estimated cold-cycle duration = about 360 seconds
```

That workload cannot provide true one-minute measurements even though dispatch runs every minute. Reduce sample counts, use a longer interval, improve DNS/database/network performance, or add carefully measured capacity.

### Chunk-size guidance

- Chunk size is clamped to 25-250.
- Start with 100 for a large hostname-heavy installation.
- Do not increase it while 100-target jobs already take 60 seconds or more.
- Increasing the chunk size reduces queue overhead but increases DNS work, sockets, memory, and database work inside each job.
- Consider 150 and then 200 only after warm-job p95 duration is consistently below 45-60 seconds with no timeouts or re-deliveries.
- Check per-process file-descriptor limits and host/container connection tracking before increasing TCP concurrency.

### Worker guidance

Worker count is not a universal constant. Increase it only while Redis, CPU, memory, DNS, network sockets, and MySQL all have headroom. Thirty workers with chunk size 100 can attempt roughly 3,000 TCP sockets concurrently per sample round.

The detailed tuning and diagnostic workflow is in [DEPLOYMENT.md](DEPLOYMENT.md#large-installations-10000-targets).

## Security model

- Admin routes require an authenticated account.
- All authenticated accounts are full administrators.
- Login is limited to five attempts per minute.
- User creation and password updates are limited to ten requests per minute.
- New and changed passwords require confirmation, at least 12 characters, mixed case, and a number.
- User creation and password changes create audit-log entries; password values are never logged.
- Public APIs are limited to 60 requests per minute.
- Public host values are hidden unless explicitly enabled per target.
- Hostnames and resolved addresses are validated against SSRF restrictions.
- Private targets are disabled by default.
- The GitHub footer link uses `noopener noreferrer` when opening a new tab.

If `PINGGLASS_ALLOW_PRIVATE_TARGETS=true`, every administrator can configure probes to internal destinations. Enable it only when you trust all administrator accounts and intentionally operate PingGlass as an internal network monitor.

## System Health interpretation

The Admin System Health page checks:

- Database connectivity.
- Redis connectivity.
- Probe queue depth.
- Recently completed cycles as a worker heartbeat.
- Failed job count.
- `fping` path, executable bit, and a localhost probe.
- PHP TCP socket connectivity.
- Scheduler freshness.
- Last completed cycle totals and duration.
- Effective interval, chunk size, job timeout, Redis retry-after, ICMP settings, and TCP settings.

A warning is not always a root cause. Read the accompanying message and inspect failed-job exceptions before changing worker or chunk counts.

## Project structure

```text
app/
  Console/Commands/              Scheduled and manual PingGlass commands
  Http/Controllers/Admin/        Admin dashboard and management endpoints
  Http/Controllers/Auth/         Login/logout
  Http/Controllers/Public/       Public pages and APIs
  Jobs/                          Current chunk job and legacy payload classes
  Models/                        Eloquent models
  Services/Monitoring/           Drivers, evaluation, incidents, rollups, cleanup
    Drivers/FpingDriver.php      Batched ICMP implementation
    Drivers/TcpConnectDriver.php Concurrent TCP implementation
    SsrfProtection.php           DNS cache and destination validation

database/
  migrations/                    Schema history and high-scale upgrades
  seeders/                       Development bootstrap data
  pingglass_sample.sql           Complete sample schema/data snapshot

resources/js/
  Components/                    Charts, status UI, footer, reusable controls
  Layouts/                       Public and admin shells
  Pages/Admin/                   Admin pages, including Administrators
  Pages/Auth/                    Login
  Pages/Public/                  Public dashboard, category, and target pages
```

The legacy `ProbeTarget` and `BatchIcmpProbe` classes remain so older serialized queue payloads can be inspected safely. The current scheduler dispatches only `ProbeTargetsChunk`.

## Production deployment and upgrades

Use [DEPLOYMENT.md](DEPLOYMENT.md) for:

- Fresh Ubuntu/Nginx/Supervisor installation.
- Alpine/Docker worker and `fping` setup.
- Existing-database migration without losing targets.
- Scope-rollup backfill.
- Queue draining and old-payload cleanup.
- Timeout hierarchy verification.
- 10k-target tuning.
- Failure signatures and recovery commands.
- Backups, restore, updates, and rollback preparation.

## License

PingGlass is released under the MIT License.
