# Mailin — Domain Checking Tools

Laravel + React (Inertia) app for blacklist/DNS checks and Google Workspace / Microsoft 365 detection.

Use this file if you are setting the project up on a new local machine.

Design notes for reviewers (architecture, stack, bulk/concurrency, DNS retries, provider detection, production follow-ups) are at the **end of this file**.

---

## Required software versions

This project was built and verified with the versions below. Newer patch versions in the same major line should work.

| Tool | Required | Verified on this project |
| --- | --- | --- |
| PHP | **8.3 or 8.4** (`composer.json` requires `^8.3`) | PHP 8.3.28 |
| Composer | **2.x** | Composer 2.8.5 |
| Laravel | **13.x** | Laravel 13.34 |
| MySQL | **8.0+** (MySQL only — not SQLite/Postgres) | MySQL 8.4.7 |
| Node.js | **20 LTS or newer** (22 or 24 is fine) | Node 24.19.0 |
| npm | **10+** | npm 10.9.0 |
| React | 19.x (installed via npm) | React 19.3 |
| Inertia.js | Laravel adapter 3.x + `@inertiajs/react` 3.x | — |

### PHP extensions

Enable these in your PHP install (WAMP: click the PHP version → PHP extensions):

- `pdo_mysql`
- `mysqli`
- `mbstring`
- `openssl`
- `tokenizer`
- `xml`
- `ctype`
- `json`
- `bcmath`
- `fileinfo`
- `curl`
- `intl` (recommended, for IDN domains)

Confirm from a terminal:

```bash
php -v
composer -V
node -v
npm -v
php -m
```

You should see `pdo_mysql` in `php -m`.

---

## 1. Get the code

Clone or copy the project, then open a terminal in the project root:

```bash
cd C:\wamp64\www\mailin
```

On another machine, use whatever path you cloned into. All commands below are run from that root (the folder that contains `artisan`, `composer.json`, and `package.json`).

---

## 2. Create the MySQL database

This app uses **MySQL only**.

Default local credentials (typical WAMP):

- Host: `127.0.0.1`
- Port: `3306`
- Database: `mailin`
- Username: `root`
- Password: empty

Create the database:

```bash
mysql -u root -e "CREATE DATABASE IF NOT EXISTS mailin CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

On WAMP Windows you can also use the full client path, for example:

```bash
C:\wamp64\bin\mysql\mysql8.4.7\bin\mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS mailin CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

Or create `mailin` in phpMyAdmin.

If root has a password, add `-p` and put the same password in `.env` as `DB_PASSWORD`.

PHPUnit uses a separate MySQL database named `mailin_testing`. Create that too if you will run tests:

```bash
mysql -u root -e "CREATE DATABASE IF NOT EXISTS mailin_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

---

## 3. Environment file

```bash
copy .env.example .env
```

On macOS/Linux:

```bash
cp .env.example .env
```

Edit `.env` and set at least:

```env
APP_NAME=Mailin
APP_URL=http://127.0.0.1:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=mailin
DB_USERNAME=root
DB_PASSWORD=

QUEUE_CONNECTION=database
SESSION_DRIVER=database
CACHE_STORE=database
```

If MySQL root is not empty, set `DB_PASSWORD`. Do not switch `DB_CONNECTION` away from `mysql`.

Generate the application key:

```bash
php artisan key:generate
```

---

## 4. PHP / Composer (Laravel backend)

```bash
composer install
php artisan migrate --seed
```

`migrate --seed` creates tables and the admin user:

- Email: `admin@mailin.test`
- Password: `Mailin@Admin123`

Change this password before any shared or production use.

Useful Laravel commands:

```bash
php artisan migrate
php artisan db:seed
php artisan config:clear
php artisan cache:clear
php artisan route:list
php artisan tinker
```

---

## 5. Node / npm (React + Vite frontend)

```bash
npm install
npm run build
```

- `npm run build` — production assets in `public/build` (use this with `php artisan serve` alone).
- `npm run dev` — Vite HMR while you change React files. Keep this running in a second terminal.

If you use `npm run dev`, also keep `php artisan serve` running and open the URL Artisan prints.

---

## 6. Run the app

You need at least the Laravel server. A queue worker is recommended for bulk jobs.

**Terminal 1 — web server**

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

Open http://127.0.0.1:8000

**Terminal 2 — queue worker (bulk checks)**

```bash
php artisan queue:work --tries=3 --timeout=90
```

**Terminal 3 (optional) — frontend while developing**

```bash
npm run dev
```

Sign in with `admin@mailin.test` / `Mailin@Admin123`. There is no public registration; only logged-in admins can run checks.

---

## How checks keep running after the browser window is closed

Bulk (and unfinished) domain checks are **not tied to the open tab**. If the user closes the window, refreshes, or navigates away, remaining domains still finish in the background and are waiting when they log in again.

### While the tab is open

1. The React workbench polls `POST /batches/{id}/tick`.
2. Each tick starts or finishes **one domain** (`queued` → `checking` → `completed` / `failed`) so the table can update row by row.
3. Progress text and the pipeline pill stay in sync (`347 / 1,000 checked`).
4. The server stores `last_tick_at` on the batch so it knows the browser is still driving the job.

Single checks run immediately in that request. Bulk lists use the tick loop so a large CSV does not freeze the page.

### What happens when the window closes

The frontend calls `POST /batches/{id}/handoff` when:

- the tab or window is closed (`pagehide` / `beforeunload`, via `navigator.sendBeacon` so the request is not cancelled)
- the user opens a different tool or leaves the page (Inertia navigation)
- a new check replaces the current batch

That **handoff**:

1. Marks the batch with `queue_handoff_at` (live ticks stop processing new rows; they only return progress).
2. Dispatches `ProcessCheckBatchRemainderJob` onto the **MySQL `jobs` table** (`QUEUE_CONNECTION=database`).
3. The remainder job processes one leftover domain, then queues itself again until every row is `completed` or `failed`.
4. `QueueWorkerLauncher` starts a short-lived `php artisan queue:work --stop-when-empty` process so those jobs actually run even if nobody has a worker terminal open (useful on WAMP).

The UI shows **finishing in the background** once handoff has happened.

### Safety net if the browser dies without sending handoff

If the laptop sleeps, the process is killed, or `sendBeacon` never fires, ticks stop updating `last_tick_at`.

Laravel’s scheduler runs:

```bash
php artisan mailin:handoff-stale
```

every minute (`routes/console.php`). Any **running** batch that has not been ticked for **45 seconds** is handed to the queue the same way as a window close.

### After the user comes back

- Open **Blacklist + DNS** or **Google Workspace / Microsoft 365**.
- **Previous jobs** lists saved batches. Open one to see domains that finished after the tab closed.
- **Export CSV** still includes completed rows.
- Filters (Clean, Blacklisted, Google Workspace, Microsoft 365, Other, Failed) work on stored results.

Nothing is lost as long as MySQL is up and a queue worker (or the auto-spawned worker) can run PHP.

### Local vs live: what must be running

| Environment | Tick loop (tab open) | After window close |
| --- | --- | --- |
| Local WAMP / `artisan serve` | Browser polls `/tick` | Handoff + auto-spawned `queue:work --stop-when-empty` |
| Live server | Same | **Always-on** `queue:work` plus **scheduler** (see live server section) |

Do not rely only on the auto-spawned worker in production. Shared hosts and hardened Linux often block `popen` / `nohup`. Run a persistent worker and the scheduler.

---

## 7. WAMP (optional instead of `artisan serve`)

1. Start WAMP (Apache + MySQL).
2. Point the virtual host **document root** at `...\mailin\public` (not the project root).
3. Set `APP_URL` in `.env` to that host (for example `http://mailin.test`).
4. Restart Apache.

Do not browse the app as `http://localhost/mailin` unless Apache is serving `public/`. Serving the project folder exposes `.env` and application files.

---

## 8. Live / production server

Use the same MySQL app (not SQLite). After deploy, window-close completion **depends on the queue and the scheduler**.

### `.env` on live

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=mailin
DB_USERNAME=your_user
DB_PASSWORD=your_password

QUEUE_CONNECTION=database
SESSION_DRIVER=database
CACHE_STORE=database
```

Then:

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan key:generate
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Point the web server document root at `public/`. Keep `.env` outside the public root.

### Persistent queue worker (required on live)

Jobs live in the MySQL `jobs` table. A worker must be running **all the time**, not only when someone has a browser open.

```bash
php artisan queue:work --tries=3 --timeout=120 --sleep=1
```

Keep that process alive with Supervisor (Linux), for example `/etc/supervisor/conf.d/mailin-worker.conf`:

```ini
[program:mailin-worker]
process_name=%(program_name)s
command=php /var/www/mailin/artisan queue:work --tries=3 --timeout=120 --sleep=1
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/mailin/storage/logs/worker.log
```

Then:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start mailin-worker
```

`--timeout=120` must be higher than a slow DNSBL check. `--tries=3` retries a failed domain job.

Without this worker, handed-off batches sit in `jobs` and **do not finish** after the user closes the window (unless the auto-spawned worker is allowed, which production usually should not depend on).

### Scheduler (required on live)

This runs `mailin:handoff-stale` every minute so crashed tabs still get handed to the queue.

**Linux cron** (one line):

```bash
* * * * * cd /var/www/mailin && php artisan schedule:run >> /dev/null 2>&1
```

Or a second Supervisor program: `php artisan schedule:work`.

Confirm:

```bash
php artisan schedule:list
```

You should see `mailin:handoff-stale` every minute.

### How a live request flows after the user leaves

1. User uploads 1,000 domains and closes the laptop.
2. Browser sends `/handoff` (or 45 seconds later the scheduler does the same).
3. Remainder jobs are inserted into MySQL `jobs`.
4. Supervisor’s `queue:work` picks them up, one domain at a time.
5. User returns later, opens **Previous jobs**, and sees completed rows and can export CSV.

### Checklist before calling the deploy “done”

- [ ] MySQL `mailin` exists and `.env` uses `DB_CONNECTION=mysql`
- [ ] `php artisan migrate --force` has been run
- [ ] `QUEUE_CONNECTION=database`
- [ ] `php artisan queue:work` is supervised and stays up after reboot
- [ ] Cron or Supervisor runs `php artisan schedule:run` every minute
- [ ] Document root is `public/`
- [ ] `APP_DEBUG=false` and a unique `APP_KEY`
- [ ] Admin password changed from the seeder default
- [ ] PHP CLI used by Supervisor is 8.3+ with `pdo_mysql` and `intl`

---

## 9. Tests

```bash
php artisan test
```

Tests use MySQL database `mailin_testing` (see `phpunit.xml`). Create that database first (step 2).

---

## Daily commands cheat sheet

```bash
composer install
npm install
php artisan key:generate
php artisan migrate --seed
npm run build
php artisan serve
php artisan queue:work --tries=3 --timeout=90
php artisan schedule:work
npm run dev
php artisan test
```

---

## What the app includes

- Admin-only login
- Blacklist + DNS checker (DNSBL, MX, SPF, DKIM, DMARC, A, AAAA, CNAME, NS, PTR)
- Google Workspace / Microsoft 365 detection from MX and SPF
- Single check and bulk CSV/TXT
- Live per-row bulk progress, filters, CSV export
- If the browser window is closed, leftover domains finish on the queue (see the handoff section)
- DNS timeouts/retries and rate limiting

Bulk file: CSV or TXT, one domain or email per line. A header of `domain` / `email` is skipped.

```text
domain
gmail.com
user@outlook.com
```

---

## Overall architecture

Mailin is a **monolith**: one Laravel app serves HTML (Inertia) and JSON APIs. There is no separate React SPA or Node API.

```text
Browser (React + Inertia)
    │  login, tool pages
    │  POST /checks/single  (one domain, wait for result)
    │  POST /checks/bulk    (parse file, create batch + rows)
    │  POST /batches/{id}/tick     (process one domain, return table)
    │  POST /batches/{id}/handoff  (tab closed → queue)
    ▼
Laravel (PHP 8.3, auth, validation, MySQL)
    │
    ├─ DomainNormalizer     emails/URLs → host
    ├─ DnsLookupService     MX, SPF, DKIM, DMARC, A/AAAA, NS, PTR, TXT
    ├─ BlacklistService     IP DNSBLs + domain/URI lists
    ├─ MailProviderDetector Google Workspace / Microsoft 365 / Other / Not Detected
    └─ BulkCheckService     batches, ticks, queue handoff
    ▼
MySQL
    users
    check_batches   (job: type, totals, last_tick_at, queue_handoff_at)
    check_items     (one row per domain: queued → checking → completed|failed + JSON payload)
    jobs            (Laravel queue)
```

**Request flow**

1. Guest hits `/` and is sent to login. Only an authenticated admin can open tools or call check endpoints.
2. A **single check** creates a one-row batch and runs the lookup in that HTTP request so the result drawer can open immediately.
3. A **bulk upload** stores every line as `check_items` with status `queued`, returns the batch JSON, and lets the browser drive progress with `/tick`.
4. Each tick claims **one** domain, runs DNS/blacklist or provider detection, writes the JSON payload, updates counters, and returns the full batch so the table can paint that row.
5. Closing the tab calls `/handoff`. Remaining rows are processed by `ProcessCheckBatchRemainderJob` on the database queue. If the beacon never fires, `mailin:handoff-stale` (every minute) does the same after 45 seconds without ticks.

Results are durable in MySQL. Reloading the tool or opening **Previous jobs** shows stored payloads (filters, details, CSV export).

---

## Technology choices and why

| Choice | Why |
| --- | --- |
| **Laravel 13** | Auth, validation (Form Requests), queues, scheduler, migrations, and rate limiting are first-class. Fits an admin tool that must persist bulk jobs. |
| **PHP 8.3 + `dns_get_record`** | Domain checks are DNS-heavy. PHP can query MX/TXT/RBL without a second language or paid DNS API. Timeouts map to `default_socket_timeout`. |
| **Inertia + React** | One repo, one session cookie, no CORS/JWT SPA. Pages are React; mutations that need live tables use `fetch` + JSON. |
| **Tailwind CSS 4** | Fast UI for tables, filters, and the domain-themed layout without a separate design system. |
| **MySQL 8** | Required for this project. Batches, items, sessions, cache, and the `jobs` table stay in one engine. SQLite is not used for the app. |
| **Database queue** | `QUEUE_CONNECTION=database` needs no Redis for WAMP. Same MySQL the app already has. Live servers can keep this or move to Redis later. |
| **HTTP ticks for bulk while the tab is open** | The spec asks for rows to appear as each domain finishes. A long `queue:work` job would complete many domains before the UI saw them. Ticks return after **one** lookup. |
| **Queue after tab close** | Browsers cannot keep ticking. Jobs + worker (and a scheduler fallback) finish the list without the user watching. |
| **No public registration** | Spec is an internal operator tool. Seeded admin + session login is enough. |

Alternatives considered and not used for the first version: a split Laravel API + Vite SPA (more auth/CORS work), Redis queues (extra service on WAMP), and a hosted DNS API (cost, keys, and less control over DNSBL queries).

---

## How bulk processing and concurrency are handled

**Cap:** `MAILIN_BULK_MAX_ITEMS` (default **5000**) in `config/mailin.php`. Duplicate lines are collapsed. Invalid lines become `failed` with an error; they do not stop the batch.

**Concurrency while the tab is open**

- `MAILIN_BULK_CONCURRENCY` is **1**.
- `/tick` calls `advanceOne()` / `processNext()` so only one domain is in `checking` at a time.
- The React loop waits for that HTTP response, updates the table, then ticks again. Slow DNS cannot overlap and freeze the UI behind several in-flight lookups.
- Rate limit: `MAILIN_RATE_LIMIT` (default 60 checks/minute per user; bulk uploads use a tighter cap).

This meets “do not wait for the entire file” and “show Queued → Checking → Completed/Failed” without opening dozens of DNS sockets at once (which would overload PHP and upstream resolvers).

**Concurrency after the tab closes**

- Handoff dispatches `ProcessCheckBatchRemainderJob`.
- That job processes **one** leftover item, then dispatches itself again until none remain.
- A live server should run a **persistent** `queue:work` (Supervisor). Locally, `QueueWorkerLauncher` may spawn `queue:work --stop-when-empty`.
- Job timeout is 90–120 seconds so one hung DNSBL does not kill the worker; `tries=3` retries the job.

**Why not unbounded parallel DNS?** A 1,000-row CSV with 12 RBLs per IP would stampede the resolver and the app. One-at-a-time is the safe default. Production could raise concurrency with multiple queue workers (`numprocs`) **if** DNS rate limits are respected.

---

## How DNS timeouts and retries are handled

Configured in `.env` / `config/mailin.php`:

- `MAILIN_DNS_TIMEOUT` — default **3** seconds (`ini_set('default_socket_timeout')` around lookups).
- `MAILIN_DNS_RETRIES` — default **2** attempts per query.

In `DnsLookupService`:

- `dns_get_record` returning **`false`** is treated as timeout/resolver failure. Empty array `[]` means “no records of that type” (not an error).
- On `false`, the code waits `150ms * attempt` and retries up to `dns_retries`.
- If all attempts fail, the error is stored on the result (`errors` / “Errors / timeouts” in the UI) and that record type is returned as empty. The rest of the check still runs (MX can succeed even if one RBL times out).
- DKIM uses a small selector list (`google`, `selector1`, …) unless the operator supplies a selector. Provider detection **skips DKIM** so bulk MX classification stays faster.
- Blacklist IP checks use at most **two** A records and a 2-second socket timeout so one domain does not sit on 12 lists × many IPs.

Failed domains are marked `failed` with the exception message; timeouts inside a successful payload stay in `payload.errors` so the row can still be **Completed** with partial data.

---

## How Google Workspace / Microsoft 365 detection works

Detection is **public DNS only** (no Google/Microsoft login, no Admin SDK). Implemented in `MailProviderDetector` after `DnsLookupService` loads MX and TXT/SPF.

**Signals**

| Signal | Treated as |
| --- | --- |
| MX host is `google.com` / `googlemail.com` or a subdomain (e.g. `aspmx.l.google.com`) | Google Workspace |
| MX host is `mail.protection.outlook.com` or `protection.outlook.com` (or a subdomain) | Microsoft 365 |
| SPF/TXT contains `_spf.google.com` | Supporting Google evidence |
| SPF/TXT contains `include:spf.protection.outlook.com` | Supporting Microsoft evidence |

**Decision order**

1. Google MX, or Google SPF when there is **no** Microsoft MX → **Google Workspace**, status **Active/Detected**.
2. Else Microsoft MX or Microsoft SPF → **Microsoft 365**, status **Active/Detected**.
3. Else any other MX → **Other**, status **Active/Detected**, evidence that MX exists but is not Google/Microsoft.
4. Else no MX → **Not Detected**, status **Not Detected**.
5. If **both** Google and Microsoft MX exist → **Other** (conflict), do not guess.

The UI and CSV show exactly: **Domain**, **Provider**, **MX records found** (host + priority), **Detection evidence/reason**, **Status** (`Active/Detected` or `Not Detected`).

This is an **appearance** check from MX/SPF. It cannot prove a Workspace licence is paid or that mailboxes exist. Consumer `gmail.com` still classifies as Google because MX points at Google.

---

## What we would improve or change for a production deployment

These are intentional follow-ups, not blockers for the task:

1. **Always-on queue + scheduler** — Supervisor `queue:work` and cron `schedule:run` (already documented above). Do not depend on spawning PHP from the web request.
2. **Redis (or SQS) queue** — Better than the `jobs` table under large bulk load; still keep MySQL for domain results.
3. **Parallel workers with a global DNS budget** — `numprocs=2–4` plus a Redis rate limiter so RBLs are not flooded.
4. **Dedicated DNS resolver** — Unbound/Bind or a DNS-over-HTTPS library instead of the server’s recursive resolver (Spamhaus and others often block open resolvers).
5. **Auth** — SSO (Google/Microsoft), 2FA, password reset, hashed admin invite, audit log of who ran which bulk file.
6. **Observability** — Horizon or a simple failed-job dashboard, metrics for tick duration, RBL timeouts, and batch age.
7. **Caching** — Short TTL cache of DNS/RBL for the same domain (e.g. 15 minutes) to make re-checks cheap.
8. **Richer provider signals** — Autodiscover CNAME, `verify.zoho.com`, SPF includes for Fastmail/Proofpoint; optional BIMI. Keep MX as the primary rule.
9. **HTTPS, `APP_DEBUG=false`, hardened headers**, backups of MySQL, and a staging clone of `mailin`.
10. **Front-end build in CI** — `npm run build` in the pipeline; never run Vite in production.

The current design (ticks for live UI, queue for closed tabs, MySQL as source of truth) should stay. Production work is mostly **ops** (workers, DNS path, auth) and **scale** (cache, Redis, more workers), not a rewrite.


## How the application run locally:

see above after this head line ## Required software versions you will get version and installation info.
