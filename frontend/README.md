# DivaWaste Textile — web and workshop application

Angular 20 (standalone components, signals, zoneless), PrimeNG 20 with the DivaWaste theme, Transloco, ECharts. One
project, three areas loaded on demand:

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

To try the product with ready-made data and accounts, use the demo environment instead: `npm run demo` (port 4300)
with the API started by `scripts\demo.ps1 api` — see `docs/DEVELOPER_RUNBOOK.md` and `docs/USER_TEST_GUIDE.md` in the API
repository.

For plain development the API must be running (`php artisan serve`) with `FRONTEND_URL=http://localhost:4200` and
`SANCTUM_STATEFUL_DOMAINS=localhost:4200`. Demo accounts are listed in the API's `docs/TEST_ACCOUNTS.md`.

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
src/app/ui         design system built on PrimeNG: theme, field, select, date, checkbox, dialog, menu, filter bar,
                   data table, page header, status tag, loading/empty/error state, confirm, charts
src/app/layout     shell and navigation per kind of account
src/app/features   one folder per area
public/i18n        translation files — the only place where user-facing text lives
src/styles.scss    design tokens (--dw-*), the DivaWaste look of the PrimeNG components, utility classes (.dw-*)
```

## Design system

PrimeNG provides the behaviour; the look is DivaWaste's. `src/app/ui/theme.ts` is the PrimeNG preset (teal brand scale,
cool neutral surfaces, 6 px radius, hairlines instead of shadows) and `src/styles.scss` holds the tokens and the rules
that tokens cannot express. PrimeNG styles live in the `primeng` cascade layer, so the product stylesheet always wins
without `!important`.

| Need | Use | Built on |
|---|---|---|
| A form field (label, required mark, help, error) | `<dw-field [label]>` around the control | — reads the control's state, shows API messages |
| Text, number, long text | `pInputText`, `p-inputnumber`, `pTextarea` | PrimeNG |
| A choice in a list | `<dw-select>` with `<dw-option>`; add `multiple` for several | Select / MultiSelect (search appears above 8 options) |
| A date | `<dw-date>` — the value stays an ISO string | DatePicker |
| A checkbox, a switch | `<dw-checkbox>`, `p-toggleswitch` | Checkbox, ToggleSwitch |
| A button | `pButton` (primary) · `severity="secondary" outlined` (normal) · `text` (low emphasis) · `severity="danger"` | Button |
| An icon-only button | `pButton text severity="secondary" class="dw-icon-btn"` with an `aria-label` | Button |
| Filters above a list | `<dw-filter-bar [active] (cleared)>` with `dw-search`, `dw-select`, `dw-date`, `dw-checkbox` inside | — |
| A server-driven list | `<dw-data-table>` with `ListStore` | Table, Paginator, Skeleton |
| A small static table | `<table class="dw-table">` | same look as the data table |
| A status | `<dw-status kind value>` | Tag |
| A dialog | `Dialogs.open(Component, { data })`; inside: `dw-dialog-title`, `.dw-dialog-body`, `.dw-dialog-foot` | DynamicDialog |
| A yes/no question, a reason, a password | `Confirm.ask({...})` | ConfirmDialog / DynamicDialog |
| Details next to a list | `p-drawer styleClass="dw-drawer"` | Drawer |
| Actions of a row | `<dw-menu #menu />` and `menu.open($event, actions)` | Menu |
| A message after an action | `Toast.success / info / error` | Toast |
| Figures | `<dw-kpi>` inside `.dw-stats` | — |
| A region of the page | `.dw-panel` with `.dw-panel-head` / `.dw-panel-body` | — |

Controls are 32 px high on the desktop (`--dw-control`), 40 px on the public screens and on touch devices, 56 px in the
workshop application (`.dw-touch-scale`). Screens do not set their own heights, radii or colours.

## Rules of the house

- **Authorization is the API's job.** Guards and hidden buttons only avoid showing what the API would refuse.
- **No text in templates.** Add the key to `public/i18n/fr.json`; `npm run i18n` fails when one is missing.
- **Logical CSS properties** (`margin-inline`, `inset-inline-start`…) so a right-to-left language mirrors without rework.
- **Lists are server-driven** through `ListStore` (filters, sort, page); never filter a page of results in the browser.
- **Every screen has its loading, empty and error state** (`dw-state`, built into `dw-data-table`).
- A status is always text plus colour (`dw-status`), never colour alone.
- **One primary button per screen or dialog**, placed last; cancel is secondary and comes before it.
- **Filters go in a `dw-filter-bar`**, never in a card of labelled fields; a set filter is tinted and counted.
- After adding an npm dependency, restart `ng serve`: the development server does not pick up new packages on the fly.
- Workshop screens: one decision per screen, targets of at least `--dw-touch` (64 px), no dense table.
