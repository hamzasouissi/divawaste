# Setup

Two repositories: this one (Laravel API) and `DIVA-WASTE-FRONTEND-LAB/frontend` (Angular application, desktop and workshop PWA).

## Requirements

- PHP 8.4 with `pdo_mysql`, `bcmath`, `intl`, `mbstring`, **`gd`** (QR label and logo in PDF), `zip` (Excel)
- Composer 2, MariaDB 10.11+, Node 20+ and npm
- Redis for sessions, cache and queues in production (optional locally, see below)

## API

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Edit `.env`:

| Key | Meaning |
|---|---|
| `DB_*` | MariaDB database. Create it empty (`utf8mb4`) |
| `FRONTEND_URL` | Origin of the Angular application. Links in emails point there |
| `SANCTUM_STATEFUL_DOMAINS` | Hosts allowed a cookie session: the same origin, without scheme |
| `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` | `redis` in production. Without Redis locally: `file`, `file`, `sync` |
| `MAIL_*` | `MAIL_MAILER=log` writes emails to `storage/logs/laravel.log` (verification and invitation links are there) |
| `BILLING_SELLER_*` | Seller identity printed on invoices |

```bash
php artisan migrate --seed          # schema + reference data + provisional data + permissions
php artisan db:seed --class=DemoSeeder   # optional: two demo companies with three months of activity
php artisan platform:create-admin you@example.com   # first platform administrator; prints a generated password once
php artisan serve                   # http://localhost:8000
```

With `QUEUE_CONNECTION=redis`, also run `php artisan queue:work` (report generation, emails) and, for the daily tasks,
`php artisan schedule:work`.

### Demo accounts (after `DemoSeeder`) — password `DivaWaste!2026`

| Email | Role |
|---|---|
| admin@divawaste.test | Platform administrator |
| amira@capbon.test | Client administrator, Textile du Cap Bon |
| sami@capbon.test | Environment manager |
| leila@capbon.test | Workshop operator (Nabeul) — lands on the workshop application |
| direction@capbon.test | Management, read-only |
| audit@capbon.test | Auditor |
| karim@sahel-recyclage.test | Provider administrator, Recyclage du Sahel |

Never run `DemoSeeder` on a production database.

## Angular application

```bash
cd frontend
npm install
npm start            # http://localhost:4200, /api and /sanctum proxied to http://localhost:8000
```

The proxy (`proxy.conf.json`) makes the browser see one origin, which the cookie session requires. In production the same
is achieved by serving both behind one host (see [OPERATIONS.md](OPERATIONS.md)).

The service worker and installation as an application only exist in the production build:

```bash
npm run build        # dist/frontend/browser
```

## Tests and quality gates

API:

```bash
php artisan test                 # 162 tests. Needs a database named by DB_DATABASE in phpunit.xml (divawaste_test)
vendor/bin/pint --test
```

The suite reuses the test schema between runs when the migrations have not changed (first run about 7 minutes on Windows,
then about 1 minute). `TEST_DB_FRESH=1 php artisan test` forces a rebuild. Do not run two suites at once on the same
database.

Angular:

```bash
npm run lint         # ESLint
npm run i18n         # every translation key used in the code exists in every language file
npm test -- --watch=false   # Vitest
npm run verify       # all of the above + production build
```

## Checking by hand

1. Sign in as `leila@capbon.test` on a phone-sized window: create a lot, cut the network (DevTools → Offline), create
   another, restore the network — the second lot receives its number.
2. Sign in as `amira@capbon.test`: Enlèvements → Nouvelle demande, choose lots and Recyclage du Sahel, send.
3. Sign in as `karim@sahel-recyclage.test`: confirm the request.
4. Continue with loading (workshop app), reception and treatment (provider), price and closure (industrial).
