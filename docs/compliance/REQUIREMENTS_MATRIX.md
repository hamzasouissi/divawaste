# Requirements traceability matrix — DivaWaste Textile

Source of truth: *Cahier des charges — DivaWaste Textile* (28 Sep 2026). Requirement IDs are the cahier's.

**State recorded here: 5 Oct 2026, after the MVP acceptance pass and the technical-compliance pass (API documentation, Larastan, workshop sign-out, email trace)** — backend branch `takeover/mvp-alignment`, frontend repository
`DIVA-WASTE-FRONTEND-LAB` branch `main`. The state found at the start is kept in
[BASELINE_AUDIT_2026-10-04.md](BASELINE_AUDIT_2026-10-04.md).

## How to read it

Status: ✅ done and verified · 🟡 done with a stated limit · 🔴 not done · V2 out of MVP scope (cahier).
A requirement is ✅ only when its real workflow was completed in the application, not because an endpoint or a
screen exists. The classification of every remaining gap is in [MVP_ACCEPTANCE.md](../MVP_ACCEPTANCE.md).

"Verified by" says what was actually run, nothing more:

- **T** — automated backend feature test (the file is named; suite: 163 tests, all passing).
- **B** — driven through the real browser against the real API (headless Chrome, demo database), by clicks. **B5** = clicked again on 5 Oct 2026 on the current (PrimeNG) interface.
- **S** — screen built on the real API and loaded in the browser without error; the action itself was exercised through the API, not by clicks.

Nothing in the product uses mock data.

## M0 — Inscription et onboarding

| ID | Requirement | Status | Where | Verified by | Limit / note |
|---|---|---|---|---|---|
| M0-01 | Company self-registration | ✅ | `POST auth/register`, screen `/register` | T `RegistrationAndReviewTest` · **B5** | |
| M0-02 | Email link valid 24 h | ✅ | signed link → `/verify-email`, resend | T `RegistrationAndReviewTest` · **B5** | Mail goes to the configured mailer (`log` locally). SMS/WhatsApp = V2 |
| M0-03 | Plan choice (trial, per site, per volume) | 🟡 | plans seeded, chosen at registration | T `SubscriptionTest`, `PlatformBackOfficeTest` · **B5** | **Price grid not decided**: reference prices are managed in `/platform/plans` and proposed at activation; the invoiced price is the one confirmed for each client. Per-volume is a flat fee (tonnage not metered). Every plan starts with the 30-day trial |
| M0-04 | Bank transfer validated by super-admin | ✅ | declare → `/platform/payments` | T `SubscriptionTest`, `BillingTest` · **B** | Card payment = V2 |
| M0-05 | Provider registration with accreditation PDFs | ✅ | `/register` (provider), `/provider/accreditations` | T `RegistrationAndReviewTest` · S | |
| M0-06 | Approve / reject with reason / suspend, applicant emailed | ✅ | `/platform/companies/:id` | T `RegistrationAndReviewTest`, `EmailDeliveryTest` (real transport, recipient, content) · **B5** (approve, reject, suspend, reactivate; emails read in the outbox and sent through SMTP to a local test server) | Delivery to a real mailbox not tested |
| M0-07 | 4-step wizard, skippable, resumable | ✅ | `/onboarding` + banner on the dashboard | T `RegistrationAndReviewTest` · **B5** (site, zone, type, invitation) | Each step opens the real screen; the wizard tracks progress, it does not duplicate forms |
| M0-08 | Invitation by email with role and sites, 7 days | ✅ | `/admin/users`, `/invitation` | T `UsersAndRolesTest` · **B5** (sent, accepted, invited user signed in) | |
| M0-09 | Password ≥ 12, reset, optional TOTP, lockout 5 | ✅ | `/login`, `/forgot-password`, `/account/security` | T `AccountSecurityTest` · **B5** (2FA enabled with a real code, sign-in with the code) | Lockout: 5 failures → 15 minutes, email sent |
| M0-10 | Timestamped acceptance of terms, versioned | 🟡 | recorded at registration and invitation | T `RegistrationAndReviewTest` | **Legal texts are placeholders** (`0.1-provisoire`): Diva must supply the real documents |
| M0-11 | "Mon abonnement" | ✅ | `/admin/subscription` | T `SubscriptionTest` · **B** | Plan change is a request, activated by the platform at the agreed price |
| M0-12 | SSO | V2 | | | |

## M1 — Référentiel et classification

| ID | Requirement | Status | Where | Verified by | Limit / note |
|---|---|---|---|---|---|
| M1-01 | Sites and zones | ✅ | `/sites`, `/sites/:id` | T `SiteApiTest`, `CatalogAndAlertsTest` · S | A site or zone holding stock cannot be closed |
| M1-02 | Reference catalogue by super-admin; client activates / creates | ✅ | `/platform/catalog`, `/waste-types` | T `CatalogAndAlertsTest` · S | |
| M1-03 | 14 default types | 🟡 | seeded (15 items) | T | **Provisional list and waste codes** — to be validated by Diva |
| M1-04 | Composition %, colour, grammage, hazard, regulatory code | ✅ | waste type form, lot detail | T `CatalogAndAlertsTest`, `LotOperationsTest` · S | Code, family and hazard are frozen after creation |
| M1-05 | kg by default, conversion t / m³ / piece | ✅ | `UnitConversion`, density and unit weight on the type | T `CatalogAndAlertsTest` | Display in tonnes is automatic from 1 t |

## M2 — Collecte et traçabilité

| ID | Requirement | Status | Where | Verified by | Limit / note |
|---|---|---|---|---|---|
| M2-01 | Operator creates a lot on mobile | ✅ | workshop app `/m/lots/new` | T `LotOperationsTest`, `OfflineSyncTest` · **B** (phone viewport) | One decision per screen, on-screen keypad, 64 px targets |
| M2-02 | QR label 100 × 50 mm | ✅ | `GET lots/:id/label` (PDF), `/m/scan` | T `LotOperationsTest` (single page asserted) · S | Not printed on a physical thermal printer. Camera scanning could not be exercised headless: the typed-code fallback was |
| M2-03 | RFID | V2 | | | |
| M2-04 | Manual weight | ✅ | lot form, weigh action | T `LotOperationsTest` · B | Connected scale = V2 |
| M2-05 | Lifecycle with timestamp and author | ✅ | lot detail timeline | T `PickupScenarioTest`, `LotOperationsTest` · S | |
| M2-06 | Group / split | ✅ | lot dialogs | T `LotOperationsTest` · **B5** (split 42,5 → 20 + 22,5; grouped back) | 2 % weight tolerance, comment required beyond |
| M2-07 | Real-time stock, threshold alert | ✅ | `/stock` | T `CatalogAndAlertsTest` · S | Stock is computed from lots, never stored |
| M2-08 | Offline with sync, no loss, no duplicate | ✅ | IndexedDB outbox → `POST sync/push` | T `OfflineSyncTest` · **B** (network cut, lot created, network restored, lot numbered) | Offline covers lot creation and storing. Loading a pickup needs the network (it commits both parties) |

## M3 — Lien avec la production — V2 (M3-01 … M3-04)

## M4 — Prestataires et valorisation

| ID | Requirement | Status | Where | Verified by | Limit / note |
|---|---|---|---|---|---|
| M4-01 | Provider directory | ✅ | `/providers` | T `PickupScenarioTest` · S | |
| M4-02 | Alert 30 days before expiry; expired not selectable | ✅ | daily `accreditations:alert`; refused at submit and confirm | T `PickupScenarioTest`, `CatalogAndAlertsTest` | Needs the scheduler running |
| M4-03 | Request to provider, confirm or refuse | ✅ | `/pickups/new`, `/provider/pickups` | T `PickupScenarioTest` · B (provider list) · S | |
| M4-04 | Scan at pickup, received weight, variance > 5 % | ✅ | `/m/loading`, pickup dialogs | T `PickupScenarioTest` · **B** | Fixed during verification: a gap on one lot now flags the pickup even when the total is within tolerance |
| M4-05 | Price or cost per lot, net balance | ✅ | pricing dialog, dashboard, `economic_balance` report | T `PickupScenarioTest`, `ReportingTest` · **B5** (prices entered, net balance, pickup closed) | |
| M4-06 | Marketplace | V2 | | | |

## M5 — Conformité réglementaire

| ID | Requirement | Status | Where | Verified by | Limit / note |
|---|---|---|---|---|---|
| M5-01 | Chronological register, PDF and Excel | ✅ | `/compliance/register` | T `ReportingTest`, `ComplianceApiTest` · **B5** (PDF and Excel exports) | |
| M5-02 | Hazardous slip, pre-filled, signed by each party | 🟡 | `/compliance/manifests`, `/provider/manifests` | T `ComplianceApiTest` · **B** (three signatures by clicks) | Signature = authenticated user + password re-entry + timestamp + IP, recorded in sequence. **Not a qualified electronic signature**; legal value to be confirmed by Diva. Layout is ours, not the official form |
| M5-03 | ANGed annual declaration | 🟡 | `/compliance/declarations` | T `ComplianceApiTest` · **B5** (prepared, validated, exported PDF and Excel) | Figures are computed and frozen at validation. **The layout is a structured declaration, not the official ANGed form** — to align once Diva supplies it |
| M5-04 | Certificates archived 10 years, linked to lots | ✅ | `/compliance/documents`, upload from the pickup | T `ComplianceApiTest` · **B5** (certificate uploaded by the provider, listed) | No deletion endpoint exists for a regulated document |

## M6 — Reporting RSE

| ID | Requirement | Status | Where | Verified by | Limit / note |
|---|---|---|---|---|---|
| M6-01 | Dashboard | 🟡 | `/dashboard` | T `ReportingTest` · S | Computed live with a 60 s cache. **Not measured at 5 M lots**: the pre-aggregated `waste_daily_stats` table exists but is not used |
| M6-02 | kg per piece produced | ✅ | pieces entered in `/reports` | T `ReportingTest` · S | |
| M6-03 | CO₂ avoided | V2 | | | |
| M6-04 | RSE export PDF / Excel with logo | ✅ | `/reports`, logo in `/admin/company` | T `ReportingTest` · **B5** (PDF and Excel generated and downloaded) | Generated by a queued job; needs a queue worker in production |
| M6-05 | Brand portal | V2 | | | |

## M7 — Transverse

| ID | Requirement | Status | Where | Verified by | Limit / note |
|---|---|---|---|---|---|
| M7-01 | Email and in-app notifications | ✅ | bell, `/account/notifications`, preferences per event | T several · S · B5 | WhatsApp = V2. The inbox polls every 60 s (no push). Every email is traced (`notification_logs`: transport, sent or failed). **Delivery to a real mailbox was not tested**: it needs the SMTP account of a mail provider ([runbook](../DEVELOPER_RUNBOOK.md#emails)) |
| M7-02 | Audit log readable by client admin | ✅ | `/admin/audit` | T `PickupScenarioTest` · **B** | 20 models audited, actor company and source recorded |
| M7-03 | Public API with keys | V2 | | | |
| M7-04 | Automatic invoicing, tax ID, VAT by country | 🟡 | daily `subscriptions:run`, invoice PDF | T `SubscriptionTest` · **B** (300 + 57 VAT + 1 stamp = 358 TND) | **Tunisian VAT 19 % and 1 TND stamp duty are provisional values.** Seller tax identifiers must be set in `.env` |

## Non-functional requirements

| Domain | Status | Evidence | Limit |
|---|---|---|---|
| Multi-tenant isolation | ✅ | fail-closed `TenantScope`; `TenantScopeTest`, `TenantContextMiddlewareTest`, cross-company assertions in every scenario test | |
| Security | 🟡 | Argon2id, cookie session + CSRF for the web, device tokens for sync, global rate limiter, upload MIME checks, framework storage routes disabled | No external penetration test. HTTPS, headers and WAF are deployment concerns ([OPERATIONS.md](../OPERATIONS.md)) |
| Authorization | ✅ | every route checks a permission server-side; the frontend only hides what the API would refuse | |
| **Personal data export / erasure** | 🔴 | — | **Not implemented.** Must be handled manually by Diva until an endpoint exists |
| Performance | 🟡 | scan lookup is an indexed read; lists are paginated server-side | Not load-tested. No measurement at 200 companies / 5 M lots |
| Availability / backup | — | documented in [OPERATIONS.md](../OPERATIONS.md) | Infrastructure, outside the code |
| Mobile PWA | 🟡 | installable manifest, service worker in production build, Dexie outbox, ZXing | iOS home-screen icon needs PNG files (only SVG provided). Not tried on a real device or with gloves |
| Ergonomics | 🟡 | responsive tables and forms checked at 1440 and 390 px | No session with real users yet |
| Accessibility | 🟡 | labels, focus order, status never by colour alone, keyboard-activable rows | No audit with a screen reader |
| i18n | ✅ | Transloco, one file per language, every key checked in each language by `npm run i18n`, logical CSS properties, `dir` switch | French and English ship (switch on every screen, saved to the profile; the API answers validation messages in the same language). Generated PDF / Excel documents stay in French. Arabic (right-to-left) is prepared, not translated |
| Traceability | ✅ | no force delete on lots; regulated documents have no delete route | |

## Stack conformance (cahier §5)

| Layer | Required | State |
|---|---|---|
| Framework | Laravel API-only, MariaDB | ✅ Laravel 13, MariaDB |
| Auth | Sanctum SPA session (web), tokens (mobile), Fortify 2FA, spatie/laravel-permission | ✅ |
| Documents | laravel-dompdf, **simple-qrcode**, Laravel Excel | 🟡 dompdf ✅, Excel ✅. **simple-qrcode cannot be installed next to Fortify** (it pins bacon-qr-code v2, Fortify needs v3): QR codes are generated with bacon/bacon-qr-code directly. Needs Diva's written agreement |
| Background | Redis, queues with **Horizon**, scheduler | 🟡 jobs and schedule ✅. Horizon not installed (no Windows support on the development machine); Redis not run locally — tests use the sync queue |
| API docs | Scribe / L5-Swagger | ✅ Scribe 5.11. `php artisan api:docs` → [api/openapi.yaml](../api/openapi.yaml) (OpenAPI 3), Postman collection, HTML at `/docs`. 155 routes, checked against the application by the command and by a test |
| Quality (API) | Pest/PHPUnit, Larastan, Pint | ✅ PHPUnit (169 tests) · Pint · Larastan 3.12 level 5 on the whole project, 0 error, no baseline |
| Front-end | Angular latest, standalone, Material or PrimeNG, ECharts | 🟡 Angular **20.3** (kept, not upgraded to the latest major), standalone, zoneless, **PrimeNG 20**, ECharts |
| Mobile | Angular PWA, Dexie, ZXing | ✅ |
| i18n | Transloco | ✅ |
| Quality (front) | Vitest, ESLint | ✅ 20 unit tests, lint clean |

## Open points that need Diva's decision

1. Price grid of the plans (M0-03).
2. Real legal documents: terms, privacy policy, provider terms (M0-10).
3. Reference data: accreditation types, waste codes, 14 default waste types, VAT rate and stamp duty (M1-03, M7-04).
4. Official layouts of the tracking slip and of the ANGed declaration; legal standing of the in-app signature (M5-02, M5-03).
5. simple-qrcode replaced by bacon/bacon-qr-code (stack §5).
6. Personal data export and erasure: procedure until the endpoint exists.
7. How the per-volume plan is billed (flat fee today, tonnage not metered).
8. An external penetration test and a load test before production.

Every gap is classified (must fix, business decision, V2, hardware test, polish) in [MVP_ACCEPTANCE.md](../MVP_ACCEPTANCE.md).
