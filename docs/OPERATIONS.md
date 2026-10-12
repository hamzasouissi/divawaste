# Operations: deployment and running

Nothing here has been executed on a production server: it is what the application needs, derived from its code.

## Topology

Serve the Angular build and the API **under one host** so the browser session cookie is first-party:

```
https://app.example.tn/            → static files of frontend/dist/frontend/browser (fallback to index.html)
https://app.example.tn/api/…       → Laravel (public/index.php)
https://app.example.tn/sanctum/…   → Laravel
```

`.env` in production:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://app.example.tn
FRONTEND_URL=https://app.example.tn
SANCTUM_STATEFUL_DOMAINS=app.example.tn
SESSION_DRIVER=redis
SESSION_SECURE_COOKIE=true
CACHE_STORE=redis
QUEUE_CONNECTION=redis
MAIL_MAILER=smtp        # with real credentials: verification, invitations, lockout and business emails depend on it
BILLING_SELLER_*        # seller identity on invoices
```

Deploy steps: `composer install --no-dev --optimize-autoloader`, `php artisan migrate --force`,
`php artisan db:seed --class=ReferenceDataSeeder --force`, `--class=AuthorizationSeeder`,
`--class=ProvisionalReferenceSeeder` (all idempotent), `php artisan config:cache route:cache`, restart the queue workers.
Never run `DemoSeeder` in production.

Static files: `ngsw-worker.js`, `ngsw.json` and `index.html` must be served with `Cache-Control: no-cache`; hashed files
can be cached for a year.

## Processes that must run

| Process | Command | Without it |
|---|---|---|
| Queue worker | `php artisan queue:work --tries=3` under a supervisor (the cahier asks for Horizon — install it on the Linux server: `composer require laravel/horizon`) | Reports stay "en file d'attente"; emails are not sent |
| Scheduler | cron every minute: `php artisan schedule:run` | No accreditation alert, no invoicing, no trial warning |

Scheduled tasks (`routes/console.php`):

| Time | Command | Does |
|---|---|---|
| 05:00 | `subscriptions:run` | Bills the periods that start, warns before the end of trials, marks overdue invoices and expired subscriptions |
| 06:00 | `accreditations:alert` | Warns 30 days before an accreditation expires and when it has expired (M4-02) |
| 06:20 | `limits:evaluate` | Compares every site with its limits (zone room and load, site weight, stock limits) and announces the states nobody was told about yet. Run it once by hand after a deployment that introduces limits (`php artisan limits:evaluate`); running it again announces nothing twice |

Both are declared `withoutOverlapping()` and `onOneServer()`.

## Storage

Uploads and generated files are written to the `local` disk (`storage/app/private`), under a directory per company.
They are never served directly: every download goes through an authorised route.

- Regulated documents must be kept **10 years** (M5-04): this directory is part of the backup, and must not be purged.
- For several application servers, switch the disk to S3-compatible object storage (`FILESYSTEM_DISK=s3`).
- Generated reports expire after 30 days (the row is kept, the file can be removed).

## Backups (cahier: daily, encrypted, monthly restore test)

Not provided by the application. To set up on the infrastructure:

1. Daily dump of MariaDB (`mariadb-dump --single-transaction`) and copy of `storage/app/private`.
2. Encryption before leaving the server; storage in a second location.
3. A monthly restore into an empty environment, followed by `php artisan test` against it or a manual check of the
   dashboard and of one archived document.

## Security checklist before opening to clients

- HTTPS only, HSTS, `SESSION_SECURE_COOKIE=true`.
- `APP_DEBUG=false`; `APP_KEY` set once and backed up (it encrypts the 2FA secrets).
- Real SMTP with SPF/DKIM for the sending domain.
- Security headers at the web server (`X-Content-Type-Options`, `Referrer-Policy`, a Content-Security-Policy for the
  Angular application).
- The API rate limiter is per user or per IP; put the reverse proxy's address in the trusted proxies so the real client
  IP is used.
- An external penetration test has not been done.
- Personal data export and erasure requests have no endpoint yet: handle them by hand and log them.

## Monitoring

- `GET /up` is the health endpoint.
- Every response carries `X-Request-Id`; the same id is in the log line and in the error shown to the user.
- Watch: failed jobs (`php artisan queue:failed`), reports in status `failed`, the age of the oldest queued job.

## Known limits at scale

The dashboard is computed live from the lots (cached 60 seconds per company, period and site set). It has not been
measured at the cahier's target volume (5 million lots). The table `waste_daily_stats` exists for a pre-aggregated
version; it is not fed yet. Measure before onboarding large clients.
