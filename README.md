# PingGlass

Network latency and packet loss monitoring. Think SmokePing, but modern.

PingGlass pings your targets every 60 seconds using ICMP (fping) and TCP connection timing, stores the raw samples, and renders interactive charts with percentile bands so you can see exactly when your network went sideways.

## What it does

- Pings targets via ICMP and/or TCP on whatever port you want
- Stores every individual sample, not just averages — you get the full distribution
- Shows percentile smoke graphs (P10/P25/P50/P75/P90) so jitter is visible at a glance
- Tracks packet loss separately from latency
- Auto-detects outages and recovers, with configurable confirmation cycles so a single dropped packet doesn't trigger an incident
- Organizes targets into categories (regions, ISPs, whatever makes sense for you)
- Has a public status page and an admin panel
- Hides target IPs from the public side if you want
- Dark mode

## Stack

- **Backend:** Laravel 11, PHP 8.2+
- **Frontend:** Vue 3, Inertia, Tailwind CSS, shadcn-vue, ECharts
- **Database:** MySQL 8+
- **Queue:** Redis
- **Probing:** fping (ICMP), PHP sockets (TCP)
- **Scheduler:** Laravel's built-in scheduler (one cron entry)
- **Workers:** Supervisor

Everything runs in one Laravel app. No separate microservices, no Node backend, no Python ping daemon.

## Quick start

```bash
git clone <repo-url> pingglass
cd pingglass

composer install
npm install

cp .env.example .env
php artisan key:generate

# Edit .env — set your DB credentials, Redis host, and APP_URL

# Option A: import the included SQL (has sample targets already)
mysql -u root -p pingglass < database/pingglass_sample.sql

# Option B: run migrations from scratch
php artisan migrate
php artisan db:seed

npm run build
php artisan storage:link

# Set up the scheduler
crontab -e
# Add this line:
# * * * * * cd /path/to/pingglass && php artisan schedule:run >> /dev/null 2>&1
```

Log in at `/login` with `admin@pingglass.local` / `password`. Change the password right away.

For a proper production setup with queue workers, fping, Nginx, and Supervisor, see [DEPLOYMENT.md](DEPLOYMENT.md).

## How the monitoring works

Every 60 seconds, the scheduler kicks off a probe cycle:

1. Reads all enabled targets from the database
2. Dispatches a queue job for each target
3. Each job runs ICMP and/or TCP probes, collects samples, calculates statistics
4. Results are stored in the `measurements` table
5. Status is evaluated (online/degraded/down) with confirmation logic
6. Incidents are opened or closed as needed

ICMP probing uses `fping -C N -q` which sends N pings and reports back the individual round-trip times. TCP probing measures how long it takes to establish a connection to the configured port, then closes the socket immediately.

## Target hierarchy

```
Category
├── Target  (ICMP + TCP:65499)
├── Target  (ICMP only)
└── Target  (TCP:443 only)
```

Categories are things like regions or ISPs. Targets are the actual hosts you're monitoring. Each target can use ICMP, TCP, or both. If you enable TCP, you pick one port.

## Status logic

Each target gets an overall status based on its protocol results:

| ICMP    | TCP     | Overall   |
|---------|---------|-----------|
| OK      | OK      | Online    |
| FAIL    | OK      | Degraded  |
| OK      | FAIL    | Degraded  |
| FAIL    | FAIL    | Down      |
| —       | —       | Unknown   |

A target doesn't flip to "down" after one bad measurement. By default it takes 3 consecutive failed cycles. Recovery requires 2 consecutive good cycles. Both are configurable.

**Unknown** means the monitor itself hasn't received data recently — could be a queue worker issue, a broken fping install, or the scheduler not running. This is important: you don't want the dashboard saying "everything's fine" when actually the monitor is dead.

## Data retention

Raw per-minute measurements are kept for 30 days. Every 5 minutes they get rolled up into 5-minute aggregates (kept for 180 days). Hourly rollups are kept indefinitely.

The rollup statistics are calculated from the actual raw samples, not averaged from the 5-minute averages. So your hourly percentiles are accurate.

## Graphs

The latency chart shows percentile bands:
- **Outer band (light):** P10 to P90
- **Inner band (medium):** P25 to P75
- **Line:** P50 (median)

Thin bands = stable latency. Wide bands = high jitter. This gives you the same visual intuition as SmokePing's smoke graphs but rendered with ECharts.

Available time ranges: 1h, 6h, 24h, 7d, 30d, 6m, 1y. The backend automatically picks the right resolution (raw, 5-minute, or hourly) based on the range.

You can toggle ICMP/TCP visibility on the chart and export data as CSV.

## Public API

| Endpoint | Description |
|----------|-------------|
| `GET /api/public/status` | Overall status + all categories and targets |
| `GET /api/public/categories` | List of public categories |
| `GET /api/public/targets/{slug}/metrics?range=24h&protocol=all` | Chart data for a target |
| `GET /api/public/targets/{slug}/csv?range=7d&protocol=icmp` | CSV export |

The metrics endpoint returns different resolutions depending on the range. The CSV endpoint respects the same visibility rules — hidden hosts never appear.

## Configuration

All settings live in `.env`. The probe engine, status evaluator, and cleanup jobs all read from a cached settings service backed by the database, so you can change most values from the admin UI without restarting workers.

```env
PINGGLASS_FPING_PATH=/usr/bin/fping
PINGGLASS_ICMP_SAMPLES=10
PINGGLASS_TCP_SAMPLES=10
PINGGLASS_ICMP_TIMEOUT=2000
PINGGLASS_TCP_TIMEOUT=2000
PINGGLASS_RAW_RETENTION_DAYS=30
PINGGLASS_ROLLUP5M_RETENTION_DAYS=180
PINGGLASS_DOWN_CONFIRMATION_CYCLES=3
PINGGLASS_RECOVERY_CONFIRMATION_CYCLES=2
PINGGLASS_ALLOW_PRIVATE_TARGETS=false
```

The probe interval is fixed at 60 seconds for now. Supporting arbitrary intervals would need a different scheduling approach.

## Security

- Admin routes require authentication
- Public pages and APIs never expose target IPs unless `show_host_publicly` is explicitly enabled
- SSRF protection: hostnames are resolved and validated before every probe — loopback, link-local, private, and multicast addresses are rejected by default
- If you need to monitor internal IPs (10.x, 192.168.x), set `PINGGLASS_ALLOW_PRIVATE_TARGETS=true` in `.env`
- Login is rate-limited to 5 attempts per minute
- Public API is rate-limited to 60 requests per minute

## Project structure

```
app/
├── Console/Commands/          # Artisan commands (probe cycle, aggregation, cleanup)
├── Http/
│   ├── Controllers/Admin/     # Admin panel (CRUD, settings, health)
│   ├── Controllers/Public/    # Public dashboard, target pages, API
│   ├── Controllers/Auth/      # Login
│   ├── Middleware/             # Auth, Inertia, throttle
│   ├── Requests/              # Validation
│   └── Resources/             # API resources
├── Jobs/                      # ProbeTarget queue job
├── Models/                    # Eloquent models
├── Services/Monitoring/       # Probe drivers, status/inincident/rollup/staleness logic
│   ├── Drivers/               # FpingDriver, TcpConnectDriver
│   ├── Contracts/             # ProbeDriver interface
│   └── ...                    # SettingsService, SsrfProtection, etc.

resources/js/
├── Components/                # Vue components (shadcn-vue UI kit, charts, status dots)
├── Layouts/                   # PublicLayout, AdminLayout
└── Pages/                     # Inertia pages (Public + Admin)

database/
├── migrations/                # All table definitions
├── seeders/                   # Default admin user + sample targets
└── pingglass_sample.sql       # Complete schema + sample data for direct import
```

## License

MIT
