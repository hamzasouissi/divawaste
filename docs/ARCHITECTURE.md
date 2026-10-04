# Architecture

## Shape

- **API**: Laravel 13, API-only, MariaDB. One module per business area under `app/Modules/<Module>` (Actions, Models,
  Resources, Requests, Support, `Routes/api.php`). Route files are loaded automatically under `/api/v1`.
- **Web and workshop application**: one Angular 20 project (standalone components, signals, zoneless), three lazily loaded
  areas — account flows, desktop workspace (industrial, provider, platform), workshop PWA under `/m`.

| Module | Owns |
|---|---|
| Tenancy | Companies, registration, platform review, onboarding, tenant context |
| Identity | Users, login, 2FA, invitations, members, roles and permissions |
| Sites | Sites, zones, stock (computed), thresholds |
| Catalog | Reference vocabularies, waste catalogue, a company's waste types |
| Waste | Lots, lifecycle events, labels, split and group |
| Providers | Provider profile, accreditations, directory, partnerships |
| Pickups | Pickup requests and their workflow on both sides |
| Documents | Certificates, hazardous-waste slips, annual declarations, register |
| Reporting | Dashboard statistics, generated reports, production volumes |
| Billing | Plans, subscriptions, invoices, payments |
| Notifications | In-app and email notifications, preferences |
| Audit | Audit trail |
| Sync | Devices and offline operations |
| Common | Files, reference codes, problem responses, shared helpers |

## Domain model (main objects)

```
Company ─┬─ Site ── Zone
         ├─ WasteType (from a catalogue item, or custom)
         ├─ WasteLot ── events, tags (QR), lineage (split / group)
         ├─ Pickup ── lines (one per lot, with snapshots) ── provider Company [, transporter Company]
         ├─ Document (certificate | slip | declaration) ── StoredFile
         ├─ HazardousWasteManifest ── signatures (producer, transporter, receiver)
         ├─ AnnualDeclaration (per site and year)
         └─ Subscription ── Invoice ── Payment
Provider Company ── ProviderProfile, accepted wastes, accreditations ── partnerships with industrial companies
```

Rules that shape the code:

- **A lot's status only moves through `TransitionLot`**: created → stored → awaiting pickup → collected → treated → closed.
  Every change writes an event with its time and author.
- **Stock is never stored.** It is the sum of lots present on a site (`StockQuery`). No counter can drift.
- **A pickup line keeps snapshots** (lot number, label, waste type, code, weights) so the provider and the documents never
  depend on later edits, and the provider never reads the industrial's lots.
- **Regulated data is not deleted.** Lots cannot be force-deleted; documents have no delete route; a withdrawn
  accreditation is soft-deleted with its reason.
- Public identifiers are ULIDs. Numeric ids never leave the API. Decimals travel as strings.

## Tenancy

Single database, `company_id` on every tenant table.

- `EstablishTenantContext` (middleware `tenant`) resolves the company from the session or the token — never from the
  request body or URL — and refuses companies that are not active (a lapsed subscription makes an industrial account
  read-only: writes answer 402).
- `BelongsToCompany` adds `TenantScope` to the model: **without a context a query returns nothing** (fail closed) and
  `company_id` is stamped on creation and cannot be changed.
- Shared objects (a pickup seen by its provider, a slip by its three parties) declare `partyColumns`: the scope then also
  matches rows where the current company is a named party. Nothing else crosses the boundary.
- Platform staff act through `TenantContext::runAs($company, …)` for the one operation they perform; `runAsSystem` is
  reserved for scheduled tasks.

## Authentication

| Client | Mechanism |
|---|---|
| Web application | Sanctum cookie session. `GET /sanctum/csrf-cookie` then `POST /api/v1/auth/login` with `mode: session`. CSRF token on every write |
| Workshop sync | Device bearer token (`POST mobile/devices`), sent without cookies, valid for `sync/push` only as a device |
| Scripts, tests | Bearer token (`mode: token`), 12 hours |

Login enforces email verification, lockout (5 failures → 15 minutes, email to the owner), optional TOTP (Fortify) with
recovery codes, and company selection when the user belongs to several.

## Authorization

- Permissions are data (`PermissionCatalog`): each has a scope (platform, company or site) and an audience.
- A user holds roles per company, optionally limited to a site. `AccessResolver` answers `can(permission, siteId)` and
  `siteIdsFor(permission)` — lists are always filtered by the sites the user may see.
- System roles are shared by all companies and edited by the platform (`/platform/roles`); a company can add its own roles.
- **Every route checks a permission on the server.** The Angular guards and hidden buttons are comfort only.

## Main workflows

**Pickup** (both parties act on the same object)

```
draft ──submit──▶ requested ──confirm──▶ confirmed ──collect──▶ collected ──receive──▶ received ──complete──▶ completed
   └──cancel             └──refuse──▶ refused                      (provider: treat)   (industrial: acknowledge variance, price)
```

- Submit and confirm refuse a provider that is not an active partner or has no approved accreditation valid on the date
  (hazardous lots need a hazardous accreditation).
- Reception compares received and departure weights; beyond the company threshold (5 % by default) on the total **or on
  any lot**, the pickup is flagged and cannot be closed until acknowledged.
- Closing requires the treatment of every received lot.

**Hazardous-waste slip**: generated from a confirmed pickup, one per hazardous waste code; signed in order producer →
transporter → receiver, each with password re-entry; the completed slip is archived as a document.

**Annual declaration**: computed per site and year from lots and pickups; recomputable while a draft; validation freezes
the figures and archives the PDF for ten years.

**Subscription**: registration starts a 30-day trial → the client requests a plan → the platform activates it at the
agreed price (first invoice) → the client declares its bank transfer → the platform validates it. `subscriptions:run`
(daily) bills the following periods, warns before the end of the trial and marks overdue or expired subscriptions.

## Files

`FileStorage` stores every upload outside the public directory, under a path that starts with the company id, with its
checksum and MIME type verified from content. Downloads always go through an authorised route. Generated PDFs (label,
slip, declaration, register, reports, invoice) are rendered with dompdf; Excel with Laravel Excel.

## Errors

All errors are RFC 9457 `application/problem+json` with a stable `code` (translated by the client), `detail`, `errors`
for validation and `request_id` (also in the `X-Request-Id` header and in the logs).

## Front-end structure

```
src/app/core      api client and models, session and guards, offline outbox, formatting pipes, i18n
src/app/ui        design system: page header, data table, state (loading/empty/error), status badge, confirm, charts
src/app/layout    shell and navigation per kind of account
src/app/features  one folder per area (lots, stock, pickups, compliance, reports, admin, provider, platform, mobile…)
public/i18n       translation files (fr.json)
```

Lists are server-driven (`ListStore`: filters, sort and page in one place; a newer request cancels the previous one).
Texts live in translation files only; layout uses logical CSS properties so a right-to-left language mirrors correctly.
