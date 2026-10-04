# DivaWaste Textile — web and workshop application

Angular 20 (standalone components, signals, zoneless), Angular Material, Transloco, ECharts. One project, three areas
loaded on demand:

| Area | Path | For |
|---|---|---|
| Account flows | `/login`, `/register`, `/verify-email`, `/invitation`… | Everyone |
| Desktop workspace | `/dashboard`, `/lots`, `/pickups`, `/compliance/*`, `/admin/*` · `/provider/*` · `/platform/*` | Industrial companies · providers · Diva Software staff |
| Workshop application | `/m` | Operators, on a phone or tablet; installable, works offline |

There is no mock data: every screen reads and writes the Laravel API (`../../diva-waste`). The full documentation —
architecture, API, offline synchronisation, deployment, requirements matrix — is in that repository under `docs/`.

## Run

```bash
npm install
npm start          # http://localhost:4200 — /api and /sanctum are proxied to http://localhost:8000 (proxy.conf.json)
```

The API must be running (`php artisan serve`) with `FRONTEND_URL=http://localhost:4200` and
`SANCTUM_STATEFUL_DOMAINS=localhost:4200`. Demo accounts are listed in the API's `docs/SETUP.md`.

## Check

```bash
npm run lint       # ESLint (angular-eslint)
npm run i18n       # every translation key used in the code exists in every language file
npm test -- --watch=false   # Vitest unit tests
npm run build      # production build with the service worker → dist/frontend/browser
npm run verify     # all four
```

The service worker and installation only exist in the production build.

## Layout of the code

```
src/app/core       api client and contracts, session and guards, offline outbox, formatting, i18n
src/app/ui         design system: page header, data table, loading/empty/error state, status badge, confirm, charts
src/app/layout     shell and navigation per kind of account
src/app/features   one folder per area
public/i18n        translation files — the only place where user-facing text lives
src/styles.scss    design tokens (--dw-*) and utility classes (.dw-*)
```

## Rules of the house

- **Authorization is the API's job.** Guards and hidden buttons only avoid showing what the API would refuse.
- **No text in templates.** Add the key to `public/i18n/fr.json`; `npm run i18n` fails when one is missing.
- **Logical CSS properties** (`margin-inline`, `inset-inline-start`…) so a right-to-left language mirrors without rework.
- **Lists are server-driven** through `ListStore` (filters, sort, page); never filter a page of results in the browser.
- **Every screen has its loading, empty and error state** (`dw-state`, built into `dw-data-table`).
- A status is always text plus colour (`dw-status`), never colour alone.
- Workshop screens: one decision per screen, targets of at least `--dw-touch` (64 px), no dense table.
