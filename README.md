# Mailin Domain Checking Tools

Production-ready Laravel 13 + React (Inertia) app for Mailin's domain-checking technical task.

## What it includes

- Admin-only login (no public registration)
- Task 1: Blacklist + DNS checker (DNSBL, MX, SPF, DKIM, DMARC, A, AAAA, CNAME, NS, PTR)
- Task 2: Google Workspace / Microsoft 365 detection from MX and SPF
- Single checks that return immediately
- Bulk CSV/TXT upload with live per-row progress (`347 / 1,000 checked`)
- Queue jobs plus an authenticated processing tick so WAMP can run without Supervisor
- Filters, CSV export, DNS timeouts/retries, and rate limiting

## Admin login

- Email: `admin@mailin.test`
- Password: `Mailin@Admin123`

Change this after first login in production.

## MySQL

WAMP MySQL is required. Default `.env` values:

- Host: `127.0.0.1`
- Port: `3306`
- Database: `mailin`
- Username: `root`
- Password: empty

Create the database once, then migrate:

```bash
mysql -u root -e "CREATE DATABASE IF NOT EXISTS mailin CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
php artisan migrate --seed
```

If your MySQL root user has a password, set `DB_PASSWORD` in `.env`.

## MySQL

WAMP MySQL is required. Default `.env` values:

- Host: `127.0.0.1`
- Port: `3306`
- Database: `mailin`
- Username: `root`
- Password: empty

Create the database once, then migrate:

```bash
mysql -u root -e "CREATE DATABASE IF NOT EXISTS mailin CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
php artisan migrate --seed
```

If your MySQL root user has a password, set `DB_PASSWORD` in `.env`.

## Run locally (recommended)

From `C:\wamp64\www\mailin`:

```bash
composer install
npm install
php artisan migrate --seed
npm run build
php artisan serve
php artisan queue:work --tries=3 --timeout=90
```

Open http://127.0.0.1:8000

Bulk jobs still process if the queue worker is not running: the UI claims the next batch of domains on each poll (concurrency limit in `config/mailin.php`).

## WAMP virtual host

Point the document root at `C:\wamp64\www\mailin\public` and set `APP_URL` in `.env` to that host.

Do not serve the project from `/mailin` without pointing at `public/` — that exposes application files.

## Bulk file format

CSV or TXT, one domain or email per line. A header of `domain` / `email` is skipped.

```text
domain
gmail.com
user@outlook.com
```
