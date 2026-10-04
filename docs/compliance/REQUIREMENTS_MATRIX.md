# Requirements traceability matrix — DivaWaste Textile

Source of truth: *Cahier des charges — DivaWaste Textile* (28 Sep 2026). Requirement IDs are the cahier's.

Legend: ✅ complete · 🟡 partial · 🔴 missing · ⚠️ incorrect · 🧪 implemented but unverified · V2 = out of MVP scope.

A requirement is ✅ only when the whole business flow works database → API → frontend and is covered by a test.

**State recorded here: audit baseline of 4 Oct 2026** (backend commit `67c234d`, frontend prototype in
`DIVA-WASTE-FRONTEND-LAB/frontend`). The "Action" column is the work plan; statuses are updated as work lands.

## Summary of the baseline

| Area | Finding |
|---|---|
| Schema | Sound. 71 tables, composite tenant FKs, explicit names, soft deletes where required. Already models most of M0–M7. |
| Tenancy | Sound. Fail-closed `TenantScope`, `company_id` stamped and immutable, company taken from token/session only. |
| Authorization | Sound core (site-aware `AccessResolver`, editable matrix in DB). Two endpoints check permission names that do not exist (`audit_logs.view`, `reports.create`) and are therefore unreachable. |
| API surface | 42 routes. No registration, verification, onboarding, invitations, user management, logout, password reset, 2FA, zones, lot list, stock, pickup list, labels, exports, dashboards, platform administration. |
| Cross-cutting | `Auditable` trait is attached to no model; `DispatchNotification` is never called; no mail is ever sent; no scheduled task; no queued job. |
| Documents | No PDF, Excel or QR library installed. Register, manifest, declaration and label are records without generated files. |
| Frontend | Prototype: 10 list/detail pages over the existing routes, no UI library, no PWA, no offline storage, no QR scanning, no charts, no role-based navigation. |

## M0 — Inscription et onboarding

| Req | Expected behaviour | Backend | Frontend | Integration | Tests | Action |
|---|---|---|---|---|---|---|
| M0-01 | Company self-registration: legal name, tax ID, country, textile activity, main contact | 🔴 tables exist (`companies`, `company_textile_activities`), no endpoint | 🔴 | 🔴 | 🔴 | Registration action + public endpoint + form |
| M0-02 | Email verified by link valid 24 h (SMS/WhatsApp = V2) | 🔴 `email_verified_at` column only | 🔴 | 🔴 | 🔴 | Signed verification link, resend, mail |
| M0-03 | Plan choice: 30-day trial, per site, per volume | 🟡 `StartTrial` action and plan tables; only `trial` plan seeded; no endpoint | 🔴 | 🔴 | 🟡 | Plans endpoint, plan selection at registration, seed per-site/per-volume plans |
| M0-04 | Bank-transfer billing request validated by super-admin (card = V2) | 🟡 declare payment endpoint + `ReviewPayment` action; no platform route to validate | 🔴 | 🔴 | 🟡 | Platform payment review endpoints + screens |
| M0-05 | Provider registration form with accreditation PDFs, subject to validation | 🔴 tables exist, no endpoint | 🔴 | 🔴 | 🔴 | Provider registration + accreditation upload |
| M0-06 | Super-admin approves / rejects (with reason) / suspends; applicant emailed | 🔴 columns exist, no endpoint, no mail | 🔴 | 🔴 | 🔴 | Platform review endpoints, mails, back-office screens |
| M0-07 | 4-step onboarding wizard (sites, waste types, users, providers), skippable and resumable | 🔴 `onboarding_state` column only | 🔴 | 🔴 | 🔴 | Onboarding state endpoints + wizard |
| M0-08 | Admin invites users by email with role and sites; link expires after 7 days | 🔴 table and model only | 🔴 | 🔴 | 🟡 schema only | Invite, resend, revoke, accept endpoints + screens |
| M0-09 | Password ≥ 12 chars, forgotten password, optional TOTP, lockout after 5 failures | ⚠️ login only. Lock state is read but `failed_login_attempts` is never incremented, so lockout cannot happen. No reset, no TOTP, no logout | 🟡 sign-in form | 🟡 | 🟡 | Lockout, reset, TOTP (Fortify), logout |
| M0-10 | Timestamped acceptance of CGU and data policy with version | 🔴 tables exist, `legal_documents` unseeded | 🔴 | 🔴 | 🔴 | Legal documents seed + acceptance at registration/invitation |
| M0-11 | "Mon abonnement": invoice PDFs, plan change, add sites, cancellation | 🔴 no read endpoint for subscription or invoices; no invoice PDF | 🔴 | 🔴 | 🔴 | Subscription/invoice endpoints, PDF, screens |
| M0-12 | SSO SAML / Microsoft / Google | V2 | V2 | — | — | None |

## M1 — Référentiel et classification

| Req | Expected behaviour | Backend | Frontend | Integration | Tests | Action |
|---|---|---|---|---|---|---|
| M1-01 | Sites and their zones | 🟡 site list/create; no site read/update/deactivate; **no zone endpoint at all** | 🟡 site list + create form | 🟡 | 🟡 | Site show/update, zone CRUD, screens |
| M1-02 | Reference catalogue maintained by super-admin; client activates types and creates its own | 🟡 create from catalogue or custom; no catalogue listing, no update/deactivate, no platform catalogue management | 🟡 read-only list | 🟡 | ✅ for create | Catalogue endpoint, update/activate, platform management |
| M1-03 | 14 default types delivered | ✅ seeded in `waste_catalog_items` | — | 🧪 | ✅ | Verify through UI |
| M1-04 | Composition with percentages, colour, grammage, hazardous flag, regulatory code | 🟡 model and validation complete; `regulatory_waste_codes` unseeded so no code can be chosen | 🔴 | 🔴 | 🟡 | Seed codes, reference-data endpoint, form |
| M1-05 | kg by default, conversion to t, m³, piece | 🟡 units seeded, density / unit weight on type; no conversion exposed | 🔴 | 🔴 | 🔴 | Conversion in resources and reports |

## M2 — Collecte et traçabilité

| Req | Expected behaviour | Backend | Frontend | Integration | Tests | Action |
|---|---|---|---|---|---|---|
| M2-01 | Operator creates a lot from mobile: site, zone, type, weight | 🟡 `POST /lots` correct and authorised; resource runs 4 queries per lot | 🔴 no creation screen | 🔴 | ✅ | Mobile creation flow, resource eager loading |
| M2-02 | Label with unique QR, thermal 100 × 50 mm | 🔴 QR value stored (lot ULID); no label, no QR image, no scan lookup endpoint | 🔴 | 🔴 | 🔴 | Label PDF (dompdf + simple-qrcode), `GET /lots/scan/{tag}` |
| M2-03 | RFID UHF tags | V2 (schema ready: `lot_tags.tag_type`) | V2 | — | — | None |
| M2-04 | Manual weight (connected scale = V2) | ✅ | 🔴 | 🔴 | ✅ | Frontend |
| M2-05 | Status lifecycle, each change timestamped with user | 🟡 `TransitionLot` enforces transitions and records events; no route to mark treated / closed | 🟡 read-only timeline | 🟡 | ✅ | Treated/closed endpoints, timeline with actor |
| M2-06 | Group lots into a shipment lot; split a lot | ✅ lineage, weight conservation, new QR per output | 🔴 | 🔴 | ✅ | Frontend |
| M2-07 | Real-time stock by site, zone, type; alert on threshold | 🔴 thresholds are stored but **no stock query exists and nothing evaluates a threshold** | 🟡 threshold list | 🔴 | 🟡 | Stock endpoint computed from lots, threshold evaluation + alert |
| M2-08 | Mobile offline with sync, no loss, no duplicate | 🟡 device registration and `sync_operations` table; no push/pull endpoint | 🔴 no PWA, no IndexedDB | 🔴 | 🟡 | Idempotent sync endpoint, Dexie outbox, service worker |

## M3 — Lien avec la production

| Req | Expected behaviour | Status | Action |
|---|---|---|---|
| M3-01 | Import of manufacturing / cutting orders | V2 | None |
| M3-02 | Lot linked to cutting order, model, customer | V2 | None |
| M3-03 | Theoretical vs real scrap rate | V2 | None |
| M3-04 | Waste cost per piece and per customer | V2 | None |

## M4 — Prestataires et valorisation

| Req | Expected behaviour | Backend | Frontend | Integration | Tests | Action |
|---|---|---|---|---|---|---|
| M4-01 | Provider directory: contacts, accepted waste, channel, accreditations and expiry | 🟡 directory returns names and roles only; no accepted waste, channel, accreditation or contact; provider cannot manage its profile | 🟡 bare list | 🟡 | 🟡 | Rich directory, provider profile and accreditation endpoints, screens |
| M4-02 | Alert 30 days before accreditation expiry; expired provider not selectable | 🟡 eligibility enforced at submit/confirm; **no alert, no scheduler**; directory does not flag expiry | 🔴 | 🔴 | ✅ eligibility | Scheduled alert, eligibility flag in directory |
| M4-03 | Pickup request sent to provider (email + provider space); confirm date or refuse | 🟡 workflow correct; no pickup list (provider cannot find its requests); no email | 🟡 detail by URL only | 🔴 | ✅ workflow | Lists, notifications, provider space |
| M4-04 | Scan lots at pickup, provider enters received weight, variance > 5 % flagged | ✅ | 🔴 | 🔴 | ✅ | Frontend |
| M4-05 | Sale price or disposal cost per lot; net economic balance per period | 🔴 columns exist on pickup, no endpoint, no balance | 🔴 | 🔴 | 🔴 | Pricing endpoint + balance report |
| M4-06 | Marketplace | V2 | V2 | — | — | None |

## M5 — Conformité réglementaire

| Req | Expected behaviour | Backend | Frontend | Integration | Tests | Action |
|---|---|---|---|---|---|---|
| M5-01 | Chronological waste register, automatic, PDF and Excel | 🔴 | 🔴 | 🔴 | 🔴 | Register query + PDF/Excel export |
| M5-02 | Hazardous waste tracking slip, pre-filled, e-signed by each party | ⚠️ manifest record can be created but takes raw database IDs from the client, is not pre-filled from the pickup, produces no PDF and has no signing endpoint | 🔴 | 🔴 | 🟡 | Pre-fill from pickup, PDF, signature endpoints |
| M5-03 | ANGed annual declaration (Tunisia) | 🟡 declaration record; data is whatever the client posts, nothing is computed | 🔴 | 🔴 | 🟡 | Compute from lots/pickups, PDF/Excel, validation step |
| M5-04 | Certificates archived, linked to lots, kept 10 years | 🟡 upload correct with retention; no download endpoint | 🔴 | 🔴 | ✅ upload | Authorised download endpoint, screens |

Also incorrect in this module: list filters use `->when($request->filled(...), fn ($query, $value) => …)`, so `$value`
is the boolean `true`, not the filter value. Every document / manifest / declaration / report filter is broken.

## M6 — Reporting RSE

| Req | Expected behaviour | Backend | Frontend | Integration | Tests | Action |
|---|---|---|---|---|---|---|
| M6-01 | Dashboard: tonnage by type, channel, site, month; recovery rate; landfill share | 🔴 `waste_daily_stats` table is never written; no dashboard endpoint | 🔴 | 🔴 | 🔴 | Aggregation + dashboard endpoint + ECharts |
| M6-02 | kg of waste per piece produced (manual piece count) | 🔴 `production_volumes` table only | 🔴 | 🔴 | 🔴 | Production volume endpoints + indicator |
| M6-03 | CO₂ avoided | V2 | V2 | — | — | None |
| M6-04 | RSE report export PDF and Excel with client logo | ⚠️ `POST /reports` stores a row with status `queued` forever (no job), and requires a permission that does not exist | 🟡 list of rows | 🔴 | 🔴 | Queued generation job, files, download, logo upload |
| M6-05 | Brand portal | V2 | V2 | — | — | None |

## M7 — Transverse

| Req | Expected behaviour | Backend | Frontend | Integration | Tests | Action |
|---|---|---|---|---|---|---|
| M7-01 | Email and in-app notifications (WhatsApp = V2) | 🔴 read endpoints exist but nothing ever creates a notification or sends a mail | 🟡 notification list | 🔴 | 🔴 | Real notifications on business events, mail channel, preferences honoured |
| M7-02 | Audit log of every create / update / delete, readable by client admin | ⚠️ logger exists but is attached to no model; endpoint guarded by non-existent permission; filter takes a raw user id | 🔴 | 🔴 | 🟡 logger only | Attach to models, fix permission, actor and filters, screen |
| M7-03 | Documented REST API with per-client keys | V2 | — | — | — | None (OpenAPI generation kept in mind for route structure) |
| M7-04 | Automatic subscription invoicing with tax ID and VAT by country | 🟡 `IssueInvoice` computes lines and totals; nothing triggers it; `tax_rates` unseeded; no PDF | 🔴 | 🔴 | 🟡 | Scheduled invoicing, tax seed, PDF |

## Non-functional requirements

| Domain | Requirement | Baseline | Action |
|---|---|---|---|
| Multi-tenant | Single DB, company id on every table, filter at data-access layer, tested | ✅ scope and stamping; 🟡 tests cover the scope, not each endpoint | Cross-tenant test per resource endpoint |
| Security | HTTPS, hashed passwords, CSRF/XSS/SQLi, OWASP Top 10, rate limit per IP | 🟡 Argon2id; throttle on login only; no global API limiter; local disk `serve: true` registers a framework `PUT /storage/{path}` route | Global limiter, disable framework storage routes, upload hardening |
| Personal data | Export and erasure on request | 🔴 columns only | Company export / erasure request endpoints (platform-processed) |
| Performance | Pages < 2 s, scan < 1 s, 3-year dashboards < 5 s | 🧪 | Indexed scan lookup, aggregated stats table |
| Volume | 200 companies, 500 sites, 5 M lots | 🧪 indexes present on lots | Review query plans for list/stock/dashboard |
| Availability / backup | 99.5 %, daily encrypted backup, monthly restore test | Infrastructure — not in application code | Document in deployment guide |
| Mobile | Installable PWA, glove-friendly, camera QR | 🔴 | Operator PWA |
| Ergonomics | Responsive, usable after a 10-minute demo | 🔴 | Rebuild |
| i18n | All strings in language files, per-country formats, RTL-ready | ⚠️ frontend strings in a hand-written 17 KB TypeScript map, not Transloco | Transloco, logical CSS properties |
| Traceability | No physical deletion of lots and regulatory documents | ✅ force delete blocked on lots; soft deletes on documents | Extend check to all regulated models |

## Stack conformance (cahier §5)

| Layer | Required | Baseline |
|---|---|---|
| Auth | Sanctum SPA session for web, tokens for mobile/API; Fortify for 2FA | ⚠️ bearer token only, stored by the web client; Fortify not installed |
| Documents | laravel-dompdf, simple-qrcode, Laravel Excel | 🔴 none installed |
| Background | Redis, queues with Horizon, scheduler | 🔴 no job, no schedule; Horizon not installed |
| API docs | Scribe or L5-Swagger | 🔴 (V2 deliverable) |
| Quality (API) | Pest/PHPUnit, Larastan, Pint | 🟡 PHPUnit + Pint; no Larastan |
| Front-end | Angular latest, standalone, Material or PrimeNG, ECharts | 🟡 Angular 20 standalone; no UI library, no charts |
| Mobile | Angular PWA, Dexie, ZXing | 🔴 |
| i18n | Transloco | 🔴 |
| Quality (front) | Vitest, ESLint | ⚠️ Karma/Jasmine, no ESLint |
