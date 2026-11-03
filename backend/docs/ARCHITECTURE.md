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
| Sites | Sites, zones, stock (computed), stock limits in a unit, zone room (packages or volume) and load, capacity alerts |
| Catalog | Reference vocabularies, waste catalogue, a company's waste types, colours, tolerance rules |
| Waste | Lots, lifecycle events, labels, package counts, split and group |
| Providers | Provider profile, accreditations, directory, partnerships, partners without an account and their requirements |
| Pickups | Pickup requests and their workflow on all sides (industrial, receiver, transporter) |
| Documents | Certificates, tracking slips (every waste), waste authorisations, annual declarations, register |
| Reporting | Dashboard statistics, generated reports, production volumes |
| Production | Reference to attribute waste: clients, suppliers, materials and received lots, articles, orders, OFs, produced lots, costs and gain, import (F26–F49) |
| Platform | Back office of Diva Software: overview, plans, invoices, activity |
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
         ├─ HazardousWasteManifest (tracking slip of every waste; the name is historical) ── signatures (producer, transporter, receiver)
         ├─ AnnualDeclaration (per site and year)
         └─ Subscription ── Invoice ── Payment
Provider Company ── ProviderProfile, accepted wastes, accreditations ── partnerships with industrial companies
```

Rules that shape the code:

- **A lot's status only moves through `TransitionLot`** (`LotStatus::canTransitionTo`): created → stored | awaiting
  pickup | closed; stored → awaiting pickup | closed; awaiting pickup → stored (refused, cancelled or not loaded) |
  collected; collected → treated; treated → closed. A move between zones of a stored lot is an event, not a status
  change. Every change writes an event with its time and author.
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
- `BelongsToCompany` adds `TenantScope` to the model: **without a context a query throws**
  (`MissingTenantContextException` — fail closed) and `company_id` is stamped on creation and cannot be changed. Reads
  written with the query builder (`DB::table`) are not covered by the scope and filter the company themselves.
- Shared objects (a pickup seen by its provider, a slip by its three parties) declare `partyColumns`: the scope then also
  matches rows where the current company is a named party. Nothing else crosses the boundary.
- Platform staff act through `TenantContext::runAs($company, …)` for the one operation they perform; `runAsSystem` is
  reserved for scheduled tasks.

## Authentication

| Client          | Mechanism                                                                                                                         |
| --------------- | --------------------------------------------------------------------------------------------------------------------------------- |
| Web application | Sanctum cookie session. `GET /sanctum/csrf-cookie` then `POST /api/v1/auth/login` with `mode: session`. CSRF token on every write |
| Workshop sync   | Device bearer token (`POST mobile/devices`), sent without cookies, valid for `sync/push` only as a device                         |
| Scripts, tests  | Bearer token (`mode: token`), 12 hours                                                                                            |
|                 |                                                                                                                                   |

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
draft ──submit──▶ requested ──confirm (receiver sets the date)──▶ confirmed ──collect──▶ collected ──receive──▶ received ──complete──▶ completed
   │                 │  └─ with a transporter of the platform other than the receiver: stays requested,
   │                 │     awaiting the transporter ──transport/confirm──▶ confirmed
   │                 └──refuse / transport/refuse──▶ refused (refused_by: receiver | transporter)
   └──cancel (from draft, requested or confirmed)                (receiver: treat)    (industrial: acknowledge gap, price)
```

- Submit and confirm refuse a receiver or transporter that is not an active partner, not published, or has no
  approved accreditation valid on the date (hazardous lots need a hazardous accreditation); each company must do what
  its profile says (`PARTNER_ROLE_MISMATCH`, `TRANSPORTER_REQUIRED`, F104). Submit also checks the receiver's
  requirements (F83–F99) and the waste authorisations (F23–F73).
- The receiver's stages (confirm, refuse, receive, treat) belong to the receiver; a transporter reaching the pickup
  can only confirm or refuse its transport and sign the slip as transporter (F105, F106). For a partner without an
  account, the industrial records the receiver's stages.
- Reception judges each lot in its own unit (weight, pieces, litres) by the most precise tolerance rule (5 % warning
  by default, F50–F55); a gap to review flags the pickup, which cannot be closed until it is explained (and accepted by
  `pickups.authorize_variance` above a blocking limit).
- Closing requires the treatment of every received lot. It does not wait for signed slips: the documents state of the
  pickup is followed apart (F100).
- **Open point**: a lot that was loaded and is never received has no defined outcome — it stays `collected` after the
  pickup is closed (see `docs/platform-intelligence` in the frontend repository, gap `gap.pickups.loaded-not-received-lot`).

**Tracking slip**: every waste that leaves has one, issued automatically at loading — one per regulatory waste code,
or per waste type for a non-hazardous waste without a code; a hazardous lot without a code waits until the code is
completed (F21, F92, F102). Signed in order producer → transporter → receiver, electronically with password re-entry or
by hand with the scan of the signed copy (F9, F22); the completed slip is archived as a document.

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
