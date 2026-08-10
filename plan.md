# Detailed Development Plan — Modern SmokePing-like Monitor in One Laravel App

Yes. I would design this as **one Laravel application** containing the admin panel, public monitoring site, probing engine, scheduler, queue jobs, aggregation, incident detection, API, and authentication.

The only external infrastructure is MySQL, Redis, and system utilities such as `fping`. Laravel itself already provides scheduling, queues, external-process execution, authentication/authorization, and modern Vue/Livewire frontend options. ([Laravel][1])

---

# 1. Project Goal

The system should be a modern replacement for your SmokePing use case.

The core hierarchy is:

```text
Category / Section
       │
       ├── Target
       ├── Target
       ├── Target
       └── Target
```

Example:

```text
上海
├── 上海电信
├── 上海联通
└── 上海移动

北京
├── 北京电信
├── 北京联通
└── 北京移动

广东
├── 广州电信
├── 广州联通
└── 广州移动
```

Each target can independently support:

```text
ICMP only

TCP only

ICMP + TCP
```

For TCP:

```text
one target
→ one TCP port
```

Example:

```text
上海电信

Host:       124.74.52.254
ICMP:       YES
TCP:        YES
TCP Port:   65499

Public:     YES
Show IP:    NO
```

---

# 2. Final Technology Choice

| Component                 | Technology                |
| ------------------------- | ------------------------- |
| Application               | Laravel                   |
| Database                  | **MySQL**                 |
| Queue                     | Redis                     |
| Queue dashboard           | Laravel Horizon, optional |
| Frontend                  | Vue + Inertia + Tailwind  |
| ICMP probe                | `fping`                   |
| TCP probe                 | PHP TCP connection driver |
| Scheduler                 | Laravel Scheduler         |
| Authentication            | Laravel authentication    |
| Charts                    | ECharts                   |
| Web server                | Nginx                     |
| PHP                       | PHP-FPM                   |
| Process management        | Supervisor/systemd        |
| Number of probe locations | **1**                     |

I would choose **Vue + Inertia** for this particular system because the monitoring dashboard is heavily interactive: charts, target filtering, live status, date-range selectors, admin forms, and dynamic tables. Laravel officially provides a Vue/Inertia starter path, while still keeping the frontend and backend within the same Laravel project. ([Laravel][2])

---

# 3. What "One Laravel App" Means

There is only one code repository:

```text
network-monitor/
│
├── app/
├── bootstrap/
├── config/
├── database/
├── public/
├── resources/
├── routes/
├── storage/
├── tests/
├── artisan
└── composer.json
```

But internally, responsibilities are separated.

```text
                    Laravel Application
                           │
        ┌──────────────────┼───────────────────┐
        │                  │                   │
        ▼                  ▼                   ▼
   Admin Panel        Public Monitor      Probe Engine
        │                  │                   │
        ▼                  ▼                   ▼
     CRUD/API            Charts          ICMP / TCP
        │                  │                   │
        └──────────────────┼───────────────────┘
                           ▼
                         MySQL
```

You do **not** need:

```text
Laravel backend
+
Python ping service
+
Node.js dashboard
+
separate probe API
```

Everything stays in Laravel.

---

# 4. Main Application Modules

I would divide the application logically into these domains.

| Module              | Responsibility                           |
| ------------------- | ---------------------------------------- |
| Authentication      | Admin login                              |
| Category Management | Sections/categories                      |
| Target Management   | Host/probe CRUD                          |
| Probe Engine        | ICMP/TCP measurement                     |
| Probe Scheduling    | Start measurement cycles                 |
| Measurement Storage | Save results                             |
| Status Engine       | Online/degraded/down                     |
| Incident Engine     | Detect outages                           |
| Aggregation Engine  | Long-term data                           |
| Public Dashboard    | Public status/graphs                     |
| Admin Dashboard     | Internal management                      |
| Settings            | Global monitoring parameters             |
| Housekeeping        | Delete/downsample old data               |
| System Health       | Ensure probe/scheduler/queue are running |

---

# 5. Database Design

I recommend approximately these tables:

```text
users

categories
targets

monitor_settings

probe_cycles
measurements
measurement_rollups

incidents

audit_logs
```

Relationship:

```text
Category
   │
   └── hasMany
        │
        ▼
      Target
        │
        ├──────────────┐
        ▼              ▼
 Measurements      Incidents
        │
        ▼
 Measurement Rollups
```

---

# 6. `categories`

This represents your SmokePing "Section".

Example:

```text
上海
新疆
甘肃
北京
江苏
广西
江西
...
```

Recommended fields:

| Column        | Type          | Purpose         |
| ------------- | ------------- | --------------- |
| `id`          | BIGINT        | Primary key     |
| `name`        | VARCHAR       | Display name    |
| `slug`        | VARCHAR       | Public URL      |
| `description` | TEXT nullable | Optional        |
| `is_public`   | BOOLEAN       | Show publicly   |
| `is_enabled`  | BOOLEAN       | Enable section  |
| `sort_order`  | INT           | Manual ordering |
| `created_at`  | timestamp     | Laravel         |
| `updated_at`  | timestamp     | Laravel         |

Example:

```text
id:          1
name:        上海
slug:        shanghai
is_public:   true
is_enabled:  true
sort_order:  10
```

Public URL could become:

```text
/monitor/shanghai
```

---

# 7. `targets`

This is the central configuration table.

I recommend **two probe booleans rather than an enum**.

Instead of:

```text
probe_mode = icmp_tcp
```

use:

```text
icmp_enabled = true
tcp_enabled  = true
```

That is much easier to extend later.

Recommended schema:

| Column               | Purpose                 |
| -------------------- | ----------------------- |
| `id`                 | Primary ID              |
| `category_id`        | Category                |
| `name`               | Public target name      |
| `slug`               | URL identifier          |
| `description`        | Optional description    |
| `host`               | IP or hostname          |
| `show_host_publicly` | Display host publicly   |
| `is_public`          | Target visible publicly |
| `is_enabled`         | Monitoring enabled      |
| `icmp_enabled`       | ICMP monitoring         |
| `tcp_enabled`        | TCP monitoring          |
| `tcp_port`           | One TCP port            |
| `sort_order`         | Ordering                |
| `created_at`         | Timestamp               |
| `updated_at`         | Timestamp               |

Example:

```text
id                  5
category_id         1

name                上海电信
slug                shanghai-telecom

host                124.74.52.254

show_host_publicly  false
is_public            true
is_enabled           true

icmp_enabled         true
tcp_enabled          true

tcp_port             65499
```

---

# 8. Target Validation Rules

The backend should enforce these combinations.

| ICMP | TCP | TCP Port | Valid?                         |
| ---- | --- | -------: | ------------------------------ |
| ✓    | ✗   |     NULL | ✓                              |
| ✗    | ✓   |      443 | ✓                              |
| ✓    | ✓   |      443 | ✓                              |
| ✗    | ✗   |     NULL | ✗                              |
| ✗    | ✓   |     NULL | ✗                              |
| ✓    | ✗   |      443 | Prefer rejecting/clearing port |

Laravel Form Requests are appropriate for keeping validation and authorization separate from controllers. ([Laravel][3])

---

# 9. Public IP Hiding

This needs to be implemented at the backend level.

Suppose:

```text
host = 124.74.52.254

show_host_publicly = false
```

Admin receives:

```json
{
    "name": "上海电信",
    "host": "124.74.52.254"
}
```

Public API receives:

```json
{
    "name": "上海电信"
}
```

Not:

```json
{
    "host": null
}
```

Prefer to **omit the property entirely**.

Also make sure it does not appear in:

```text
HTML source
Vue props
public API
chart API
JavaScript variables
page metadata
URLs
logs returned publicly
```

Public URLs should use:

```text
/targets/shanghai-telecom
```

not:

```text
/targets/124.74.52.254
```

---

# 10. Global Monitoring Settings

I would initially keep monitoring frequency global.

Table:

```text
monitor_settings
```

Example configuration:

| Setting               | Initial value |
| --------------------- | ------------: |
| Probe interval        |    60 seconds |
| ICMP samples          |            10 |
| TCP samples           |            10 |
| ICMP timeout          |       2000 ms |
| TCP timeout           |       2000 ms |
| Raw retention         |       30 days |
| 5-min retention       |      180 days |
| Hourly retention      |    indefinite |
| Down confirmation     |      3 cycles |
| Recovery confirmation |      2 cycles |

Admin page:

```text
Monitoring Settings

Probe Interval
[ 60 ] seconds

ICMP Samples
[ 10 ]

TCP Samples
[ 10 ]

ICMP Timeout
[ 2000 ] ms

TCP Timeout
[ 2000 ] ms

Raw Retention
[ 30 ] days

5 Minute Retention
[ 180 ] days

                         [ Save ]
```

For V1, I would **not allow arbitrary intervals per target**.

Otherwise you immediately complicate scheduling:

```text
Target A → 20 seconds
Target B → 30 seconds
Target C → 60 seconds
Target D → 120 seconds
```

There is no real need initially.

---

# 11. Probe Cycle

Every 60 seconds:

```text
00:00
  ↓
Laravel Scheduler
  ↓
Start Probe Cycle #12345
  ↓
Read enabled targets
  ↓
Queue probe jobs
  ↓
Perform ICMP/TCP tests
  ↓
Store measurements
  ↓
Evaluate target status
  ↓
Check incidents
```

Laravel's scheduler is designed so the server only needs a single scheduler cron entry while the application owns the actual schedules. ([Laravel][1])

Conceptually:

```text
* * * * *
    ↓
php artisan schedule:run
```

---

# 12. `probe_cycles`

I recommend adding this table.

It gives you visibility into whether the **monitor itself is healthy**.

Example:

```text
probe_cycles

id
started_at
completed_at

target_count
expected_probe_count

successful_probe_count
failed_probe_count

status
```

Example:

```text
Cycle #843293

Started:    19:34:00
Completed:  19:34:07

Targets:    86
Probes:     154

Completed:  154
Errors:     0

Status:     completed
```

This becomes extremely useful when debugging.

Without it:

```text
graph has no data
```

and you won't immediately know whether:

```text
Target died

or

queue died

or

scheduler died

or

database died

or

probe software failed
```

---

# 13. Laravel Queue Design

Do not make the scheduler perform every ping itself.

Instead:

```text
Scheduler
    ↓
RunProbeCycle
    ↓
Dispatch Jobs
    ↓
Redis Queue
    ↓
Probe Workers
```

Laravel's queue system is intended for moving potentially slow work out of the main request/scheduler path. ([Laravel][4])

Use a dedicated queue:

```text
probes
```

Other maintenance jobs could use:

```text
default
aggregation
maintenance
```

So:

```text
Redis
├── probes
├── aggregation
├── maintenance
└── default
```

---

# 14. Why Redis

Technically Laravel's database queue can work.

I would still choose:

```text
Redis
```

because your application continuously creates probe jobs.

You don't want:

```text
MySQL
├── target configuration
├── millions of measurements
├── graph queries
├── rollups
└── queue workload
```

all competing unnecessarily.

Laravel Horizon can also monitor Redis queue throughput, runtime, and failed jobs. ([Laravel][5])

---

# 15. ICMP Probe Engine

Create:

```text
IcmpProbeService
```

Internally:

```text
Laravel
   ↓
Process
   ↓
fping
```

Laravel has an official Process API for invoking and testing external processes, so you can wrap `fping` without raw `exec()` calls scattered through your application. ([Laravel][6])

Conceptually:

```text
IcmpProbeService

probe(Target $target)

→ execute fping
→ parse output
→ produce ProbeResult
```

The service should return something like:

```text
ProbeResult

protocol       icmp
sent           10
successful     9
failed         1

samples
31.2
31.5
31.0
timeout
32.0
31.3
31.5
31.4
31.8
31.1
```

---

# 16. TCP Probe Engine

Create a separate service:

```text
TcpProbeService
```

Define TCP latency as:

> Time required to establish a TCP connection to the configured port.

Example:

```text
Start timer

124.74.52.254:65499
        ↓
TCP SYN
        ↓
SYN/ACK
        ↓
connection succeeds

Stop timer
```

Result:

```text
34.73 ms
```

Then immediately close the socket.

The implementation can initially use PHP's TCP socket functionality.

This has a major advantage:

```text
No additional tcping binary required.
```

But architect it behind an interface:

```text
TcpProbeDriver
```

so later you could replace:

```text
PHP socket
```

with:

```text
tcping binary
```

without changing the rest of the application.

---

# 17. Probe Services Structure

I recommend:

```text
app/
└── Services/
    └── Monitoring/
        ├── Contracts/
        │   └── ProbeDriver.php
        │
        ├── Drivers/
        │   ├── FpingDriver.php
        │   └── TcpConnectDriver.php
        │
        ├── ProbeResult.php
        ├── ProbeStatistics.php
        ├── StatusEvaluator.php
        └── IncidentEvaluator.php
```

Interface concept:

```text
ProbeDriver
      │
      ├── FpingDriver
      │
      └── TcpConnectDriver
```

Both return the same:

```text
ProbeResult
```

That keeps the architecture very clean.

---

# 18. Measurement Record

The critical table is:

```text
measurements
```

I recommend one row:

```text
per target
per protocol
per measurement cycle
```

So ICMP+TCP generates:

```text
Target 5
19:30
ICMP

Target 5
19:30
TCP
```

Not one giant combined record.

---

# 19. `measurements` Schema

Recommended fields:

| Field            | Purpose                 |
| ---------------- | ----------------------- |
| `id`             | BIGINT                  |
| `probe_cycle_id` | Probe cycle             |
| `target_id`      | Target                  |
| `protocol`       | icmp/tcp                |
| `measured_at`    | Cycle timestamp         |
| `sent`           | Number attempted        |
| `received`       | Number successful       |
| `loss_percent`   | Failure rate            |
| `min_ms`         | Minimum                 |
| `max_ms`         | Maximum                 |
| `avg_ms`         | Mean                    |
| `median_ms`      | Median                  |
| `p10_ms`         | 10th percentile         |
| `p25_ms`         | 25th percentile         |
| `p75_ms`         | 75th percentile         |
| `p90_ms`         | 90th percentile         |
| `p95_ms`         | 95th percentile         |
| `stddev_ms`      | Variation               |
| `samples`        | JSON                    |
| `status`         | success/partial/failed  |
| `error`          | Internal error nullable |

Example:

```text
target_id      5
protocol       icmp
measured_at    2026-08-10 19:30:00

sent            10
received         9
loss_percent    10

min_ms          30.82
max_ms          38.91

avg_ms          32.43
median_ms       31.94

p10_ms          30.95
p25_ms          31.23
p75_ms          32.62
p90_ms          35.11
p95_ms          37.08

stddev_ms       2.28
```

---

# 20. Samples

Example JSON:

```json
[
    31.04,
    31.84,
    31.49,
    null,
    32.11,
    30.82,
    31.27,
    38.91,
    31.83,
    32.53
]
```

`null` means:

```text
timeout / failure
```

MySQL has a native JSON type, which is suitable here. We don't actually need to index the individual JSON sample values; our important indexes will be normal relational columns such as target, protocol and time. MySQL JSON fields themselves aren't directly indexed like ordinary scalar columns without generated/extracted indexing, but that doesn't hurt this design because sample arrays aren't search keys. ([MySQL Developer Zone][7])

---

# 21. Why Store Individual Samples?

Because:

```text
Average alone ≠ connection quality
```

Example A:

```text
30
31
30
31
30
31
30
31
30
31
```

Example B:

```text
10
12
16
21
28
34
39
44
49
52
```

They could potentially have similar averages.

But their distributions are completely different.

SmokePing's biggest advantage is showing this variation visually.

Therefore retain:

```text
raw samples
+
percentiles
```

---

# 22. Modern "Smoke" Graph

Instead of exactly reproducing SmokePing's old graphics, use percentile bands.

For each minute:

```text
P90 ───────────────────────
       ░░░░░░░░░░░░
P75 ───▒▒▒▒▒▒▒▒▒▒▒▒────────
       ▓▓▓▓▓▓▓▓▓▓▓▓
P50 ───████████████──────── Median
       ▓▓▓▓▓▓▓▓▓▓▓▓
P25 ───▒▒▒▒▒▒▒▒▒▒▒▒────────
       ░░░░░░░░░░░░
P10 ───────────────────────
```

Thin smoke:

```text
stable latency
```

Wide smoke:

```text
high latency variation
```

This gives you the same idea as SmokePing with a much more modern graph.

---

# 23. ICMP + TCP Comparison Graph

When both are enabled:

```text
100ms │
      │
 80ms │
      │            TCP
 60ms │       ╭───────╮
      │───────╯       ╰────────
 40ms │
      │       ICMP
 20ms │────────────────────────
      │
      └──────────────────────────
        12:00   14:00   16:00
```

Controls:

```text
[ ICMP ✓ ]
[ TCP  ✓ ]

[ 1H ]
[ 6H ]
[ 24H ]
[ 7D ]
[ 30D ]
[ 6M ]
[ 1Y ]
```

---

# 24. Status Engine

I recommend four states:

```text
ONLINE
DEGRADED
DOWN
UNKNOWN
```

### ONLINE

Recent measurements successful and within configured thresholds.

### DEGRADED

Examples:

```text
packet loss elevated
latency threshold exceeded
TCP failures
ICMP failure while TCP still works
```

### DOWN

Confirmed complete failure.

Don't mark DOWN from one failed measurement.

For example:

```text
Cycle 1   fail
Cycle 2   fail
Cycle 3   fail

→ DOWN
```

This avoids brief packet loss creating incidents.

### UNKNOWN

Very important.

Use UNKNOWN when:

```text
no measurements arriving
queue worker stopped
probe process error
scheduler stopped
measurement stale
```

Otherwise your monitor can incorrectly tell you:

```text
Target down
```

when actually:

```text
monitor itself is broken.
```

---

# 25. ICMP + TCP Status Logic

This becomes quite useful.

| ICMP    | TCP     | Overall interpretation             |
| ------- | ------- | ---------------------------------- |
| OK      | OK      | Online                             |
| FAIL    | OK      | Degraded / ICMP unavailable        |
| OK      | FAIL    | Degraded / TCP service unavailable |
| FAIL    | FAIL    | Down                               |
| UNKNOWN | UNKNOWN | Monitoring issue                   |

Example public display:

```text
上海电信

● DEGRADED

ICMP       unavailable
TCP 65499  34.8 ms

Possible ICMP filtering
```

---

# 26. Incidents

Create:

```text
incidents
```

Suggested columns:

```text
id

target_id

type
protocol

started_at
ended_at

status

initial_reason
last_reason

duration_seconds
```

Types might be:

```text
target_down
icmp_down
tcp_down
high_packet_loss
high_latency
monitoring_stale
```

---

# 27. Incident Lifecycle

Example:

```text
19:01   Healthy
19:02   fail
19:03   fail
19:04   fail

        ↓

Incident created

Shanghai Telecom
DOWN
Started 19:02
```

Then:

```text
19:07   success
19:08   success

        ↓

Incident closed
```

Result:

```text
Shanghai Telecom outage

Start:      19:02
Recovered:  19:08
Duration:   6 minutes
```

---

# 28. Public Incident History

Target page:

```text
Recent Incidents
──────────────────────────────────────

Aug 10
18:21 → 18:28
TCP unavailable
7 minutes

Aug 8
03:14 → 03:17
100% packet loss
3 minutes

Aug 3
22:51 → 22:54
High latency
3 minutes
```

This is an excellent improvement over just showing graphs.

---

# 29. Current Target State

Don't calculate the latest status by repeatedly searching millions of measurements.

Denormalize the current state onto the `targets` table or a `target_states` table.

I prefer:

```text
target_states
```

Fields:

```text
target_id

overall_status

icmp_status
icmp_latency_ms
icmp_loss_percent

tcp_status
tcp_latency_ms
tcp_loss_percent

last_measured_at
last_status_change_at
```

Example:

```text
Shanghai Telecom

overall_status       online

icmp_status          online
icmp_latency         31.8
icmp_loss            0

tcp_status           online
tcp_latency          35.4
tcp_loss             0

last_measured        19:43:00
```

Public dashboard becomes extremely fast.

---

# 30. Long-Term Data Problem

Suppose:

```text
100 targets
×
2 protocols
×
1 measurement/minute
```

Maximum:

```text
200 measurement rows/minute
```

Therefore:

```text
288,000/day
8,640,000/30 days
≈105 million/year
```

So storing every minute forever would be wasteful.

---

# 31. Retention Architecture

Use three levels:

```text
RAW / MINUTE
│
│ retain 30 days
│
▼
5-MINUTE ROLLUP
│
│ retain 180 days
│
▼
HOURLY ROLLUP
│
└── retain indefinitely
```

---

# 32. `measurement_rollups`

You don't necessarily need separate tables for every granularity.

One table:

```text
measurement_rollups
```

with:

```text
granularity
```

Values:

```text
5m
1h
```

Schema:

```text
target_id
protocol
granularity
period_start

sent
received
loss_percent

min_ms
max_ms
avg_ms
median_ms

p10_ms
p25_ms
p75_ms
p90_ms
p95_ms

stddev_ms
```

---

# 33. Correct Aggregation

Do **not** do:

```text
loss =
(loss1 + loss2 + loss3) / 3
```

Instead:

```text
total_sent =
SUM(sent)

total_received =
SUM(received)

loss =
(total_sent - total_received)
/ total_sent
```

For latency percentiles, take the successful raw samples from that aggregation period and calculate the distribution.

For example, five one-minute records:

```text
10 samples
10 samples
10 samples
10 samples
10 samples

→ up to 50 samples

→ calculate 5-minute statistics
```

---

# 34. Hourly Rollups

Every hour:

```text
60 minute records
×
10 samples

≈ 600 samples/protocol/target
```

Calculate:

```text
min
max
average
median
P10
P25
P75
P90
P95
stddev
loss
```

Then write one hourly row.

Because raw minute samples are kept for 30 days, the hourly aggregator can calculate this accurately before those records are removed.

---

# 35. Database Indexing

Critical index:

```text
measurements

(target_id, protocol, measured_at)
```

Also:

```text
(measured_at)
```

Rollups:

```text
(target_id, protocol, granularity, period_start)
```

Target:

```text
(category_id, is_enabled)
```

Incident:

```text
(target_id, status, started_at)
```

Add a unique constraint preventing duplicate measurements for the same:

```text
target
protocol
cycle
```

---

# 36. Don't Partition MySQL Initially

I would **not start with MySQL table partitioning**.

You probably don't need it at this scale if retention is handled correctly.

There's also an architectural complication: MySQL 8.4 does not support foreign keys on user-partitioned InnoDB tables, so blindly introducing time partitioning would change how referential integrity is handled. ([MySQL Developer Zone][8])

Start with:

```text
normal InnoDB
+
good indexes
+
30-day raw retention
+
rollups
```

Much simpler.

---

# 37. Admin Panel Structure

Navigation:

```text
Dashboard

Monitoring
├── Categories
├── Targets
├── Incidents
└── Measurements

System
├── Monitoring Settings
├── Probe Health
├── Queue Health
└── Audit Logs

Account
└── Users
```

---

# 38. Admin Dashboard

Example:

```text
Network Monitoring Dashboard

Targets
86

Online
81

Degraded
3

Down
2

Unknown
0


Probe Health
● Healthy

Last Cycle
19:46:00

Cycle Duration
7.2 seconds


Recent Incidents
───────────────────────────────────

上海联通        DOWN       3m
新疆电信        DEGRADED   12m
广州移动        TCP DOWN   5m
```

---

# 39. Categories CRUD

Category page:

```text
Categories

Name          Targets   Public   Enabled
─────────────────────────────────────────
上海            3         Yes      Yes
北京            3         Yes      Yes
广东            8         Yes      Yes
新疆            3         Yes      Yes
```

Actions:

```text
Create
Edit
Reorder
Enable/disable
Public/private
Delete
```

Prevent deleting a category containing targets unless targets are reassigned or explicitly deleted.

---

# 40. Target CRUD

Create/edit screen:

```text
General
─────────────────────────────

Name
[ 上海电信 ]

Category
[ 上海 ▼ ]

Host
[ 124.74.52.254 ]

Description
[                       ]


Visibility
─────────────────────────────

Public target
[✓]

Show IP / Host publicly
[ ]


Monitoring
─────────────────────────────

ICMP
[✓]

TCP
[✓]

TCP Port
[ 65499 ]


Status
─────────────────────────────

Monitoring enabled
[✓]


Display
─────────────────────────────

Sort Order
[ 10 ]


             [ Save Target ]
```

---

# 41. Test Target Before Saving

I strongly recommend this feature.

Admin enters:

```text
124.74.52.254

ICMP ✓
TCP ✓
65499
```

Then clicks:

```text
[Test Connection]
```

Laravel immediately performs a probe.

Display:

```text
ICMP

✓ Reachable
Median: 31.5 ms
Loss: 0%


TCP :65499

✓ Reachable
Latency: 35.8 ms
```

This prevents accidentally creating broken monitoring targets.

---

# 42. Public Homepage

Example:

```text
Network Monitor

Last updated 19:48:04

Overall
● Operational


上海
──────────────────────────────────────────

上海电信
● Online

ICMP      31.8 ms      0%
TCP       35.3 ms      0%

[ mini graph ]


上海联通
● Online

ICMP      42.1 ms      0%
TCP       46.8 ms      0%

[ mini graph ]
```

---

# 43. Hidden IP Behaviour

If:

```text
show_host_publicly = false
```

show:

```text
上海电信
```

If enabled:

```text
上海电信
124.74.52.254
```

Could optionally display:

```text
上海电信
124.74.xxx.xxx
```

later, but that should be a separate feature if wanted.

---

# 44. Target Details Page

Something like:

```text
上海电信
● ONLINE

Last checked 12 seconds ago

─────────────────────────────────────

ICMP

Median       31.82 ms
Average      32.10 ms
Min          30.91 ms
Max          36.42 ms
Loss         0%
Variation    1.21 ms


TCP :65499

Median       35.49 ms
Average      35.92 ms
Min          34.82 ms
Max          39.01 ms
Loss         0%

─────────────────────────────────────

Latency

[1H] [6H] [24H] [7D] [30D] [6M] [1Y]

            GRAPH

─────────────────────────────────────

Packet Loss

            GRAPH

─────────────────────────────────────

Recent Incidents
```

---

# 45. Graph API

Don't send all graph data inside the initial HTML.

Use an endpoint like:

```text
GET /api/public/targets/{slug}/metrics
```

Parameters:

```text
range=24h
protocol=icmp
```

Or:

```text
protocol=all
```

The backend decides which resolution to use.

```text
1H
6H
24H
7D
        → raw minute

30D
90D
        → 5 minute

6M
1Y
5Y
        → hourly
```

Frontend shouldn't need to know storage details.

---

# 46. API Response

Example:

```json
{
    "target": {
        "name": "上海电信"
    },
    "range": "24h",
    "resolution": "1m",
    "series": {
        "icmp": [],
        "tcp": []
    }
}
```

Again:

```text
host
```

must not leak if hidden.

---

# 47. Probe Concurrency

Single probe means:

> All measurements originate from one server/location.

It **doesn't mean only one target may be tested at a time**.

Your one probe server can have several workers.

For example:

```text
Probe Server

Worker 1 ─ Target A
Worker 2 ─ Target B
Worker 3 ─ Target C
Worker 4 ─ Target D
Worker 5 ─ Target E
```

Still:

```text
ONE geographical probe
```

This prevents 100 targets from taking too long to finish.

---

# 48. Prevent Probe Overlap

Suppose cycle:

```text
19:00
```

takes unusually long.

Then:

```text
19:01
```

starts.

You need protection against accidental duplicates.

Use:

```text
cycle IDs
unique DB constraints
Laravel cache locks
target/protocol locks
```

Laravel's scheduler and cache provide mechanisms you can use to prevent overlapping operations. ([Laravel][1])

---

# 49. Probe Failure vs Target Failure

This distinction must exist.

### Target failure

Example:

```text
fping executed correctly
response = timeout
```

Store:

```text
measurement failure
```

### Probe system failure

Example:

```text
fping executable missing
permission denied
PHP process exception
database error
```

Do **not** call the target DOWN.

Store:

```text
probe error
```

Target eventually becomes:

```text
UNKNOWN
```

This distinction is essential.

---

# 50. Probe Health Page

Admin:

```text
Probe Health

Probe Server
● Healthy

Scheduler
● Healthy
Last execution: 19:55:00

Queue
● Healthy
Pending: 2

fping
● Available

TCP Probe
● Available

Database
● Healthy

Redis
● Healthy

Last Completed Cycle
19:55:08

Cycle Duration
8.1s
```

---

# 51. Laravel Folder Structure

I would structure it approximately like:

```text
app/

├── Console/
│   └── Commands/
│       ├── RunProbeCycle.php
│       ├── AggregateMeasurements.php
│       └── CleanupMeasurements.php
│
├── Http/
│   ├── Controllers/
│   │   ├── Admin/
│   │   │   ├── CategoryController.php
│   │   │   ├── TargetController.php
│   │   │   ├── IncidentController.php
│   │   │   └── SettingsController.php
│   │   │
│   │   └── Public/
│   │       ├── MonitorController.php
│   │       └── TargetController.php
│   │
│   ├── Requests/
│   │   ├── StoreCategoryRequest.php
│   │   ├── StoreTargetRequest.php
│   │   └── UpdateTargetRequest.php
│   │
│   └── Resources/
│       ├── PublicTargetResource.php
│       └── AdminTargetResource.php
│
├── Jobs/
│   ├── ProbeTarget.php
│   ├── AggregateFiveMinute.php
│   ├── AggregateHourly.php
│   └── CleanupOldMeasurements.php
│
├── Models/
│   ├── Category.php
│   ├── Target.php
│   ├── TargetState.php
│   ├── ProbeCycle.php
│   ├── Measurement.php
│   ├── MeasurementRollup.php
│   ├── Incident.php
│   └── MonitorSetting.php
│
├── Services/
│   └── Monitoring/
│       ├── Contracts/
│       │   └── ProbeDriver.php
│       │
│       ├── Drivers/
│       │   ├── FpingDriver.php
│       │   └── TcpConnectDriver.php
│       │
│       ├── ProbeResult.php
│       ├── StatisticsCalculator.php
│       ├── StatusEvaluator.php
│       ├── IncidentEvaluator.php
│       └── RollupService.php
│
└── Policies/
    ├── CategoryPolicy.php
    └── TargetPolicy.php
```

That's still **one Laravel application**.

---

# 52. Security

Admin routes:

```text
/admin/*
```

must require authentication.

Public:

```text
/
/monitor
/monitor/{category}
/target/{slug}
/api/public/*
```

No authentication.

Admin:

```text
/api/admin/*
```

authenticated.

Laravel provides built-in browser authentication and authorization mechanisms appropriate for separating authenticated management actions from public access. ([Laravel][9])

---

# 53. Very Important Security Issue: SSRF

Because administrators can enter:

```text
host
```

your monitoring server becomes capable of connecting to addresses.

If an admin account is compromised, someone could create:

```text
127.0.0.1
169.254.x.x
10.x.x.x
192.168.x.x
```

and use your monitor to investigate internal services.

You should decide whether private/internal IP monitoring is required.

If not, block:

```text
loopback
link-local
RFC1918
multicast
unspecified addresses
```

If private IP monitoring **is** intentionally required, restrict target management strongly to trusted administrators.

---

# 54. Input Safety

Never construct commands like:

```text
"fping " . $target->host
```

and pass them through a shell unchecked.

A malicious value such as:

```text
8.8.8.8; malicious-command
```

must never become executable shell syntax.

Validate hosts strictly and use Laravel's process APIs safely.

---

# 55. Deployment

Production server:

```text
Ubuntu
│
├── Nginx
├── PHP-FPM
├── Laravel
├── MySQL
├── Redis
├── fping
├── Scheduler
└── Queue Workers
```

Still one application.

The process topology:

```text
                    Internet
                       │
                       ▼
                    Nginx
                       │
                       ▼
                  Laravel Web
                       │
          ┌────────────┼────────────┐
          │            │            │
          ▼            ▼            ▼
       MySQL         Redis        Public UI
                       │
                       ▼
                 Queue Workers
                       │
               ┌───────┴────────┐
               ▼                ▼
             fping          TCP Socket
               │                │
               └───────┬────────┘
                       ▼
                  Target Hosts
```

---

# 56. Backup

Back up:

```text
categories
targets
settings
users
incidents
hourly rollups
```

Raw minute measurements are less critical.

If you ever lose:

```text
30 days of raw samples
```

it's unfortunate.

If you lose:

```text
all target configuration
```

it's much worse.

So database backup policy should reflect that.

---

# 57. Logging

Every probe job should have contextual logging:

```text
cycle_id
target_id
target_name
protocol
started_at
duration
success
error
```

Example:

```text
cycle=83472
target=15
protocol=tcp
host=[hidden in public logs]
port=65499
result=success
latency=35.42
```

Laravel also has context facilities designed to carry contextual information through requests, jobs, commands, and logs. ([Laravel][10])

---

# 58. Testing Strategy

Testing should happen at several levels.

| Test        | Example                     |
| ----------- | --------------------------- |
| Unit        | Statistics calculation      |
| Unit        | Status evaluation           |
| Unit        | Incident opening            |
| Unit        | Incident recovery           |
| Unit        | TCP result parser           |
| Unit        | fping output parser         |
| Feature     | Create category             |
| Feature     | Create target               |
| Feature     | Hidden IP not exposed       |
| Feature     | Public target endpoint      |
| Feature     | Private target inaccessible |
| Queue       | Probe job dispatch          |
| Process     | Mock fping                  |
| Integration | Real ICMP                   |
| Integration | Real TCP                    |
| Retention   | Old data removal            |
| Rollup      | Minute → 5m                 |
| Rollup      | Minute → hourly             |

Laravel's Process facade can be faked in tests, which is particularly useful for testing `fping` handling without actually sending network probes in every automated test. ([Laravel][6])

---

# 59. Critical Test: IP Privacy

Create target:

```text
name = Secret Test
host = 1.2.3.4
show_host_publicly = false
```

Then test:

```text
GET public target

assert response does NOT contain:

1.2.3.4
```

Also test:

```text
dashboard
API
chart response
target page
category page
```

This deserves automated tests.

---

# 60. Development Phases

I would build it in this exact order:

1. **Foundation** — Laravel application, MySQL, Redis, authentication, Vue/Inertia layout.
2. **Core CRUD** — categories, targets, validation, public/private visibility, host hiding.
3. **ICMP Engine** — `ProbeDriver`, `FpingDriver`, `ProbeResult`, statistics parser, manual test.
4. **TCP Engine** — TCP connect driver, timeout handling, port validation, manual test.
5. **Measurement Engine** — probe cycles, queued jobs, measurements, 60-second scheduling.
6. **Current Status Engine** — `target_states`, online/degraded/down/unknown rules.
7. **Public Dashboard** — categories, target cards, latest ICMP/TCP metrics.
8. **Graphs** — time-range APIs, latency, loss, percentile/smoke visualization.
9. **Incident Engine** — detection, consecutive-failure rules, recovery, incident history.
10. **Aggregation** — 5-minute and hourly rollups, retention cleanup.
11. **System Health** — scheduler health, queue health, probe-cycle status, fping availability.
12. **Security Hardening** — SSRF controls, command validation, authorization, rate limiting.
13. **Testing & Optimization** — indexes, query profiling, queue concurrency, retention verification.
14. **Production Deployment** — Nginx, PHP-FPM, Redis, MySQL, Supervisor/systemd, scheduler, backups.

---

# 61. What I Would Consider V1

Don't try to build everything before the first usable release.

**V1 should contain:**

```text
Login

Category CRUD

Target CRUD

ICMP
TCP
ICMP + TCP

One TCP port

Public/private target

Show/hide IP

60-second probing

10 samples

Current status

24-hour graph

7-day graph

Packet loss

Latency distribution

Basic incidents

MySQL

Redis queue

Single probe

30-day / 1-year graphs
5m/hour rollups

Latency thresholds

Packet-loss thresholds

Per-target thresholds
Target search/filter

Dashboard summary

CSV export

Dark mode


```

That is already a complete usable SmokePing replacement.



---

# 62. Final Data Flow

The whole system ultimately looks like this:

```text
                         ADMIN
                           │
                           ▼
                    ┌─────────────┐
                    │   Laravel   │
                    │    CRUD     │
                    └──────┬──────┘
                           │
                           ▼
                      Categories
                           │
                           ▼
                        Targets
                           │
           ┌───────────────┴───────────────┐
           │                               │
           ▼                               ▼
        ICMP ✓                           TCP ✓
           │                               │
           └───────────────┬───────────────┘
                           │
                           ▼

                  EVERY 60 SECONDS

                           │
                           ▼
                  Laravel Scheduler
                           │
                           ▼
                    Probe Cycle
                           │
                           ▼
                     Redis Queue
                           │
                 ┌─────────┴─────────┐
                 │                   │
                 ▼                   ▼
             FpingDriver      TcpConnectDriver
                 │                   │
            10 samples          10 samples
                 │                   │
                 └─────────┬─────────┘
                           │
                           ▼
                    ProbeResult
                           │
               ┌───────────┼───────────┐
               │           │           │
               ▼           ▼           ▼
          Statistics     Status     Incidents
               │           │           │
               └───────────┼───────────┘
                           ▼
                         MySQL
                           │
                ┌──────────┼──────────┐
                │          │          │
                ▼          ▼          ▼
             Minute       5 min      Hourly
              Raw        Rollup      Rollup
                │          │          │
                └──────────┼──────────┘
                           ▼
                       Graph API
                           │
                           ▼
                  Public Vue Dashboard
                           │
              ┌────────────┼────────────┐
              ▼            ▼            ▼
           Status       Latency       Loss
                         Smoke
```
# Project Name
PingGlass

## Project Desc
PingGlass is an open-source network latency and packet-loss monitoring platform designed as a modern alternative to traditional tools such as SmokePing.

Built as a single Laravel application, PingGlass continuously monitors network targets using both ICMP and TCP probes, collecting repeated latency samples to provide a detailed view of network performance, stability, packet loss, and reachability over time.

Targets are organised into configurable categories and can be managed through a modern web-based administration panel. Each target can independently use ICMP, TCP, or both ICMP and TCP monitoring, with TCP monitoring supporting a configurable destination port.

PingGlass records individual probe samples together with statistics such as median, average, minimum and maximum latency, latency variation, percentiles, and packet loss. Historical measurements are visualised through interactive graphs, allowing short-term network instability and long-term performance trends to be easily identified.

[1]: https://laravel.com/docs/13.x/scheduling?utm_source=chatgpt.com "Task Scheduling | Laravel 13.x - The clean stack for ..."
[2]: https://laravel.com/docs/13.x/starter-kits?utm_source=chatgpt.com "Starter Kits | Laravel 13.x - The clean stack for Artisans and ..."
[3]: https://laravel.com/docs/13.x/validation?utm_source=chatgpt.com "Validation | Laravel 13.x - The clean stack for Artisans and ..."
[4]: https://laravel.com/docs/13.x/queues?utm_source=chatgpt.com "Queues | Laravel 13.x - The clean stack for Artisans and ..."
[5]: https://laravel.com/docs/13.x/horizon?utm_source=chatgpt.com "Laravel Horizon | Laravel 13.x - The clean stack for ..."
[6]: https://laravel.com/docs/13.x/processes?utm_source=chatgpt.com "Processes | Laravel 13.x - The clean stack for Artisans and ..."
[7]: https://dev.mysql.com/doc/refman/8.4/en/json.html?utm_source=chatgpt.com "MySQL 8.4 Reference Manual :: 13.5 The JSON Data Type"
[8]: https://dev.mysql.com/doc/refman/8.4/en/create-table-foreign-keys.html?utm_source=chatgpt.com "MySQL 8.4 Reference Manual :: 15.1.20.5 FOREIGN KEY Constraints"
[9]: https://laravel.com/docs/13.x/authentication?utm_source=chatgpt.com "Authentication | Laravel 13.x - The clean stack for Artisans ..."
[10]: https://laravel.com/docs/13.x/context?utm_source=chatgpt.com "Context | Laravel 13.x - The clean stack for Artisans and ..."
