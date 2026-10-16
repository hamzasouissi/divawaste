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

### Upgrading an existing database (rehearsed on 12–14 Oct 2026 on copies of the demo database, MySQL 8.0.44 and MariaDB 10.11.10)

The copy was 8 migrations behind (2026_10_17 … 2026_10_24). Steps, in this order:

1. Back up: `mysqldump` (or `mariadb-dump`) `--single-transaction --routines --triggers --hex-blob <db> > backup.sql`, then
   check it — the file ends with "Dump completed", and a restore into an empty database gives the same structure and data
   for every table. Keep `storage/app/private` with it.
2. `php artisan migrate --force` (4.9 s on MySQL, 2.3 s on MariaDB, on the copy: 82 lots, 7 pickups, 561 audit rows).
3. `php artisan db:seed --class=AuthorizationSeeder --force` — adds `pickups.override_provider_requirements` to client
   administrator and environment manager; roles edited by hand and every assignment are left as they are.
4. `php artisan limits:evaluate --dry-run`, read who would be told what, then `php artisan limits:evaluate`. Stock and
   capacity emails go to the people who manage limits or zones (F101); the others see the alerts in the application.
5. On the waste types page, complete the code of any hazardous type flagged "Code réglementaire manquant" (F102), then
   issue the slips the pickups list as missing.

What the migrations change in existing data: a 0 kg that was a flagged placeholder (counted waste nobody weighed)
becomes empty on the lot and on its pickup line; every packaged lot gets a count of one marked as a default; every zone
is measured in packages; existing stock limits become kilogram limits with the same figure; requirements and pickups get
empty new columns (`adds_to_family`, `waste_type_id`, `loading_requirements`). Issued slips, signatures, documents,
audit rows and notifications are not touched.

Added on 16 Oct 2026 (a ninth migration, `2026_10_25_000100_transporter_confirms_pickup`, F104–F106): three empty
columns on `pickups` (`transporter_confirmed_at`, `transporter_confirmed_by_user_id`, `refused_by_party`). Pickups already
confirmed are not touched. A request still waiting for its answer whose receiver of the platform neither collects nor
transports and that names no transporter is refused at confirmation (`TRANSPORTER_REQUIRED`): cancel it and request it
again with a transporter, or complete the receiver's profile if it does fetch the waste. A request waiting for a
transporter of the platform is confirmed only once that transporter confirms. With this migration, roll back with
`--step=9`.

Going back: `php artisan migrate:rollback --step=8` restores the previous structure (columns and indexes) and data, except
that a placeholder turned empty in a pickup's departure weight stays empty. **The exact way back is the backup.**

MariaDB: a MySQL 8 dump of the copy imported into MariaDB 10.11 without change (same collations, same rows in all 93
tables); the upgrade gave the same records, permissions and roles as on MySQL; the full test suite passes on both. When a
table with a stored generated column (`user_role_assignments.site_scope_key`) is restored from a dump of the other engine,
`CHECKSUM TABLE` differs although every value is the same: compare the rows, not the checksum.
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
| 06:20 | `limits:evaluate` | Compares every site with its limits (zone room and load, site weight, stock limits) and announces the states nobody was told about yet. Run it once by hand after a deployment that introduces limits (`php artisan limits:evaluate`); running it again announces nothing twice. `php artisan limits:evaluate --dry-run` lists first what it would announce, to whom and whether by email, and stores and sends nothing |

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
- The API rate limiter is per user or per IP, and sign-in is limited to 5 attempts a minute per account and address
  (60 a minute per address in all). Behind Nginx or Cloudflare set `TRUSTED_PROXIES` in `.env` (the proxy's addresses,
  or `*` when the server is reachable only through it): without it every request carries the proxy's address, so the
  limits, the audit log and the signatures would record one address for everybody.
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
