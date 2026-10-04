# Developer runbook — run DivaWaste locally

Everything below was executed on this machine (Windows, PowerShell, PHP 8.4 from Herd, MariaDB, Node 22) on 4 Oct 2026.

Two repositories:

- API: `C:\Projects\diva-waste`
- Angular application (desktop + workshop PWA): `C:\Projects\DIVA-WASTE-FRONTEND-LAB\frontend`

## The demo environment in one minute

It is separate from your normal development setup: its own database (`divawaste_demo`), its own ports (8010 / 4300), its
own session cookie. It never reads or writes `.env` values other than copying your database credentials and `APP_KEY`
once into `.env.demo`. It needs **no Redis, no queue worker, no mail server**.

### Start

Terminal 1 — API:

```powershell
cd C:\Projects\diva-waste
powershell -ExecutionPolicy Bypass -File .\scripts\demo.ps1 reset    # first time, or whenever you want clean data (35 s)
powershell -ExecutionPolicy Bypass -File .\scripts\demo.ps1 api      # leave this window open
```

Terminal 2 — application:

```powershell
cd C:\Projects\DIVA-WASTE-FRONTEND-LAB\frontend
npm run demo                                                          # leave this window open
```

Open **http://localhost:4300** and sign in with an account of [TEST_ACCOUNTS.md](TEST_ACCOUNTS.md)
(password `DivaWaste!2026`).

### Stop

`Ctrl+C` in both windows, or from any terminal:

```powershell
cd C:\Projects\diva-waste
powershell -ExecutionPolicy Bypass -File .\scripts\demo.ps1 stop
```

### Tomorrow

Same two commands, without `reset` (the data is kept):

```powershell
cd C:\Projects\diva-waste; powershell -ExecutionPolicy Bypass -File .\scripts\demo.ps1 api
cd C:\Projects\DIVA-WASTE-FRONTEND-LAB\frontend; npm run demo
```

## Quick reference — where to go

| What | URL | Sign in as |
|---|---|---|
| **The application (open this one)** | http://localhost:4300 | any account of [TEST_ACCOUNTS.md](TEST_ACCOUNTS.md), password `DivaWaste!2026` |
| **Back office (platform administrator)** | http://localhost:4300/platform/overview | `admin@divawaste.test` |
| Client workspace | http://localhost:4300/dashboard | `amira@capbon.test`, `sami@capbon.test` |
| Workshop / mobile application | http://localhost:4300/m | `leila@capbon.test` (sent there automatically) |
| Provider workspace | http://localhost:4300/provider/pickups | `karim@sahel-recyclage.test` |
| Registration (public) | http://localhost:4300/register | — |
| API (called by the application, not opened by hand) | http://127.0.0.1:8010/api/v1 | — |
| API health check | http://127.0.0.1:8010/up | — |
| API documentation | No generated site. Routes are listed in [API.md](API.md) | — |

The browser only ever talks to port 4300: the Angular development server forwards `/api` and `/sanctum` to port 8010
(`proxy.demo.conf.json`). That single origin is what makes the login cookie work.

| Area | Routes |
|---|---|
| Public | `/login`, `/register`, `/forgot-password`, `/reset-password`, `/verify-email`, `/invitation`, `/account-status` |
| Client | `/dashboard`, `/lots`, `/lots/:id`, `/stock`, `/pickups`, `/pickups/new`, `/pickups/:id`, `/providers`, `/sites`, `/sites/:id`, `/waste-types`, `/reports`, `/onboarding` |
| Compliance | `/compliance/register`, `/compliance/manifests`, `/compliance/declarations`, `/compliance/documents` |
| Administration | `/admin/users`, `/admin/subscription`, `/admin/audit`, `/admin/company` |
| Account | `/account/profile`, `/account/security`, `/account/notifications` |
| Workshop | `/m`, `/m/lots/new`, `/m/scan`, `/m/lots/:number`, `/m/loading`, `/m/sync` |
| Provider | `/provider/pickups`, `/provider/pickups/:id`, `/provider/manifests`, `/provider/documents`, `/provider/profile`, `/provider/accreditations` |
| Back office | `/platform/overview`, `/platform/companies`, `/platform/companies/:id`, `/platform/accreditations`, `/platform/plans`, `/platform/invoices`, `/platform/payments`, `/platform/catalog`, `/platform/roles`, `/platform/activity` |

What each screen does: [USER_MANUAL.md](USER_MANUAL.md) and [PLATFORM_ADMIN_GUIDE.md](PLATFORM_ADMIN_GUIDE.md).

## What each piece is

| Piece | In the demo | Command |
|---|---|---|
| API | PHP built-in server on 8010, environment `demo` (`.env.demo`) | `scripts\demo.ps1 api` |
| Application | `ng serve` on 4300 | `npm run demo` |
| Database | `divawaste_demo`, created and rebuilt by the reset | `scripts\demo.ps1 reset` |
| Queue worker | **Not needed**: `QUEUE_CONNECTION=sync`, reports are generated during the request | — |
| Scheduler | Not running. Run the two daily tasks by hand when you want their effect | `scripts\demo.ps1 scheduler` |
| Redis | **Not needed**: sessions and cache are files | — |
| Emails | Written to `storage\logs\laravel.log`. Print the latest links (verification, invitation, reset) | `scripts\demo.ps1 mail` |
| Workshop PWA | Same server, path `/m`. Installation and the service worker exist only in a production build (`npm run build`) | — |

`scripts\demo.ps1 scheduler` runs `accreditations:alert` (expiry warnings) and `subscriptions:run` (invoicing, end of
trial). In the demo data it produces the "agrément expire dans 20 jours" notification for Recyclage du Sahel.

## Prerequisites (once)

```powershell
cd C:\Projects\diva-waste
composer install
# .env must exist with working DB_HOST / DB_PORT / DB_USERNAME / DB_PASSWORD and an APP_KEY (docs/SETUP.md)

cd C:\Projects\DIVA-WASTE-FRONTEND-LAB\frontend
npm install
```

MariaDB must be running. PHP needs the `gd` extension (QR labels).

## Reset the demo data

```powershell
cd C:\Projects\diva-waste
powershell -ExecutionPolicy Bypass -File .\scripts\demo.ps1 reset
```

It drops and rebuilds `divawaste_demo` only. The command refuses to run unless the environment is `demo` and the database
name contains "demo". The API can stay running; sign in again afterwards.

Look into the database when you need to prove something:

```powershell
cd C:\Projects\diva-waste
php artisan tinker --env=demo --execute="echo DB::table('waste_lots')->count().' lots, '.DB::table('sync_operations')->count().' sync operations';"
```

## Automated tests (separate from the demo)

```powershell
cd C:\Projects\diva-waste
php artisan test                 # 162 tests, database divawaste_test, about 1 minute
vendor\bin\pint --test app database tests routes

cd C:\Projects\DIVA-WASTE-FRONTEND-LAB\frontend
npm run verify                   # lint + translation keys + 20 unit tests + production build
```

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| "Trop de tentatives. Patientez une minute" at sign-in | Login is limited to 5 attempts per minute | Wait one minute |
| A page of raw JSON, or "404" | You opened `http://127.0.0.1:8010` (the API) in the browser | Use `http://localhost:4300` only |
| "Connexion au serveur impossible" | The API window is closed | `scripts\demo.ps1 api` |
| Pages are slow on first load | The PHP built-in server handles one request at a time on Windows | Normal in the demo; not the production setup |
| A page stays blank or "Le chargement a échoué" right after `git pull` or `npm install` | The development server does not pick up new packages while running | Stop `npm run demo` (Ctrl+C) and start it again |
| `Access denied for user` during reset | `.env` has no valid database credentials, or `.env.demo` was created before you fixed them | Delete `.env.demo` and run reset again |
| Port already in use | A previous server is still running | `scripts\demo.ps1 stop` |
| `demo.ps1 cannot be loaded because running scripts is disabled` | PowerShell execution policy | Use the `powershell -ExecutionPolicy Bypass -File …` form shown above |
| You are signed in as someone else in a second tab | One cookie per browser profile | Use a private window for the second account |
| No verification / invitation email arrives | Emails are not sent in the demo | `scripts\demo.ps1 mail` prints the links |
| Camera does not start in "Scanner" | No webcam, or permission refused | Click "Saisir le code à la main" and type the lot number |
| Offline test: page is blank after pressing F5 offline | The development server has no service worker | Do not reload while offline (see the test guide), or test a production build |
| Testing on a real phone over Wi-Fi | Camera needs HTTPS except on `localhost`; the demo is HTTP | Not set up. Test on the PC with DevTools' device toolbar |

## Running against your normal development database instead

`php artisan serve` (port 8000) with `npm start` (port 4200) uses `.env` and the database it names. Add the demo data to
it with `php artisan db:seed --class=DemoSeeder` (it does nothing if the data is already there). The demo environment
above is the documented, verified path.
