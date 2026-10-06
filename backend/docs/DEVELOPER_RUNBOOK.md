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
| Emails | **Generated, not delivered** (`MAIL_MAILER=log`). List them with their links, or send them for real: see [Emails](#emails) | `scripts\demo.ps1 mail` |
| API documentation | Generated from the routes; served at `http://127.0.0.1:8010/docs`: see [API documentation](#api-documentation-openapi) | `scripts\demo.ps1 apidocs` |
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
php artisan test                                  # 169 tests, database divawaste_test, about 1.5 minutes
vendor\bin\pint --test app config database lang routes tests
vendor\bin\phpstan analyse --memory-limit=2G      # Larastan, level 5, whole project: must report no error
php artisan api:docs --env=demo                   # regenerates the API documentation and checks it covers every route

cd C:\Projects\DIVA-WASTE-FRONTEND-LAB\frontend
npm run verify                   # lint + translation keys + 21 unit tests + production build
npx tsc --noEmit -p tsconfig.app.json   # TypeScript check alone
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
| No email arrives in your mailbox | The demo generates emails without delivering them (`MAIL_MAILER=log`) | `scripts\demo.ps1 mail` lists them with their links. To receive them for real: [Emails](#emails) |
| `demo.ps1 mail` shows an email as `FAILED` | The mail server of `.env.demo` refused the connection or the message | The reason is printed under the list. Check it alone with `scripts\demo.ps1 mailtest you@example.com` |
| `http://127.0.0.1:8010/docs` answers an error | The documentation was never generated on this computer | `scripts\demo.ps1 apidocs` |
| Camera does not start in "Scanner" | No webcam, or permission refused | Click "Saisir le code à la main" and type the lot number |
| Offline test: page is blank after pressing F5 offline | The development server has no service worker | Do not reload while offline (see the test guide), or test a production build |
| Testing on a real phone over Wi-Fi | Camera needs HTTPS except on `localhost`; the demo is HTTP | Not set up. Test on the PC with DevTools' device toolbar |

## Emails

### What sends an email

| Action | Email (subject) | Recipient |
|---|---|---|
| A company registers | "Confirmez votre adresse email" | The contact who registered |
| The contact confirms the address | "Inscription à valider — …" | Platform staff who may review companies |
| Platform: **Valider l'inscription** | "Votre compte DivaWaste est validé" | The company's main contact |
| Platform: **Rejeter** / **Suspendre** / **Réactiver** | "…n'a pas été retenue" / "…est suspendu" / "…est réactivé", with the reason | The company's main contact |
| Platform: **Activer une formule** | "Votre abonnement DivaWaste est activé": plan, first invoice number, amount, due date | Users of the company who may see invoices (client administrator) |
| Automatic renewal (daily task) | "Nouvelle facture …" | Same |
| Client declares a transfer / platform reviews it | "Virement déclaré — …" / "Virement validé — facture …" | Platform staff / the client |
| Trial ends in 7 days, in 1 day (daily task) | "Votre essai gratuit se termine le …" | Client administrator |
| Invitation, password reset, account locked | "… vous invite sur DivaWaste", "Réinitialisation…", "…temporairement verrouillé" | The person concerned |
| Pickups, tracking slips, accreditations, stock thresholds | One email per event | The users whose role includes the matching permission |

The approval email and the subscription email are two different emails, sent at two different moments: approving a
company starts its 30-day trial; the subscription email is sent when the platform activates a paid plan, together with
the first invoice. Each user can switch off the email of a business event in **Mon compte → Notifications**.

### "Generated" is not "delivered"

Every email leaves a line in the table `notification_logs`: recipient, subject, transport, and `sent` or `failed`.

| What you see | What it means |
|---|---|
| `generated, not delivered` | Transport `log`: the email was built and written on this computer. **It never left it.** This is the demo default |
| `accepted by smtp` | The mail server of `MAIL_HOST` accepted the message. It is now that server's job; it can still bounce it or file it as spam. This is not proof it reached the mailbox |
| `FAILED` | The mail server refused the connection or the message. The reason is stored and printed |

A refused email never undoes the action that triggered it (the company stays approved). With `QUEUE_CONNECTION=sync`
(demo, local) the failure is written to `notification_logs` and to the log; with a real queue the worker retries and the
job ends in `failed_jobs` (`php artisan queue:failed`).

### Local demo — reading the emails without a mail server

```powershell
cd C:\Projects\diva-waste
powershell -ExecutionPolicy Bypass -File .\scripts\demo.ps1 mail        # the last 15 emails, with the link each one contains
powershell -ExecutionPolicy Bypass -File .\scripts\demo.ps1 mail 25     # email number 25: text in the console, HTML opened in the browser
php artisan mail:outbox --env=demo --to=rania --limit=50               # filter by recipient
```

The HTML of each email is kept in `storage\app\mail-outbox\<number>.html` (outside production only; emptied by
`demo.ps1 reset`). The links (`verify-email`, `invitation`, `reset-password`) are printed under the list: copy them
into the browser.

### Receiving the emails in a real mailbox

1. In `C:\Projects\diva-waste\.env.demo` replace `MAIL_MAILER=log` by the SMTP account of a mail provider:

   ```ini
   MAIL_MAILER=smtp
   MAIL_HOST=smtp.example.com
   MAIL_PORT=587
   MAIL_USERNAME=your-account
   MAIL_PASSWORD=your-password
   MAIL_FROM_ADDRESS="an-address-that-account-may-send-from@example.com"
   ```

   With Gmail: `MAIL_HOST=smtp.gmail.com`, port 587, your address as user name, and an *app password* (Google account →
   Security → 2-step verification → App passwords), not your normal password. `.env.demo` is not committed.
2. Restart the API window (`scripts\demo.ps1 api`).
3. Check the connection alone, before blaming the application:

   ```powershell
   powershell -ExecutionPolicy Bypass -File .\scripts\demo.ps1 mailtest you@example.com
   ```

   It prints either "Accepted by the smtp server …" (then look in the inbox **and in spam**) or the exact refusal of the
   server (wrong password, sender not allowed, port blocked).
4. Emails go to the address of the account concerned. The demo accounts end in `.test`, which no mail server delivers:
   **register a new company with your own email address** to receive the confirmation, approval and subscription emails.

Production uses the same `MAIL_*` variables with `QUEUE_CONNECTION=redis` and a queue worker ([OPERATIONS.md](OPERATIONS.md)).

## API documentation (OpenAPI)

Generated by [Scribe](https://scribe.knuckles.wtf) from the route definitions; nothing is written by hand.

```powershell
cd C:\Projects\diva-waste
powershell -ExecutionPolicy Bypass -File .\scripts\demo.ps1 apidocs     # = php artisan api:docs --env=demo
```

| Output | Where |
|---|---|
| OpenAPI 3.0 | `docs\api\openapi.yaml` (committed) |
| Postman collection | `docs\api\postman.json` (committed) |
| HTML, with "try it out" | `http://127.0.0.1:8010/docs` while the API runs (files in `resources\views\scribe`, not committed) |

What is read from the code, for each of the 155 routes: its module (group), the comment written above it, whether it
needs authentication (its middleware), the permission it checks, its URL, query and body parameters (from the
validation rules, including allowed values), and the errors it can answer (401, 403, 404, 409 with the business codes it
raises, 422). With `--env=demo` the read routes are also **called** on the demo dataset, as demo accounts, each call
rolled back: 58 of the 64 `GET` routes carry a real answer as example. The six others have nothing to show in the demo
data (no report, no declaration, no logo, no pending invitation). Routes that write are never called.

The command ends by comparing the generated file with the routes of the application and fails if one is missing or
unknown. The same comparison runs in the test suite (`ApiDocumentationTest`): adding, renaming or removing a route
without regenerating the documentation fails the tests.

A production server does not publish `/docs` (`API_DOCS_ENABLED` defaults to off there): the API is internal in the MVP.

## Static analysis (Larastan)

```powershell
cd C:\Projects\diva-waste
vendor\bin\phpstan analyse --memory-limit=2G
```

Larastan 3.12 (PHPStan 2.2), level 5, on `app`, `config`, `database`, `routes` and `tests` — configuration in
`phpstan.neon`. No baseline, no ignored error, no excluded path. Two settings explain themselves in that file: the
models declare their casts in `casts()` (`parseModelCastsMethod`), and one stub corrects Sanctum's PHPDoc, which says
the current token is always a personal access token although a cookie session carries another type.

## Workshop application: signing out

An operator who only has the workshop application never sees the desktop menu. On `/m`:

- the round **account button** at the top right, and the tile with the operator's name at the bottom of the home
  screen, both open **Mon compte** (`/m/account`): name, email, company, and a large **Se déconnecter** tile;
- signing out first sends whatever is waiting on the device. If something cannot be sent (no network), a confirmation
  says how many records stay on the device; they are kept under the operator's name and leave as soon as the network
  is back, with that operator's device token, even after someone else signed in on the same tablet;
- without network the sign-out is applied on the device at once and confirmed to the server as soon as the network is
  back (or at the next opening of the application): the server session never survives it;
- the device registration is kept, so signing in again does not create a second device or send anything twice.

## Running against your normal development database instead

`php artisan serve` (port 8000) with `npm start` (port 4200) uses `.env` and the database it names. Add the demo data to
it with `php artisan db:seed --class=DemoSeeder` (it does nothing if the data is already there). The demo environment
above is the documented, verified path.
