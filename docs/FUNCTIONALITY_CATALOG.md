# Functionality catalogue — what DivaWaste can do

One line per functionality: its scope (MVP or V2), who uses it, where it is in the application, the API behind it,
its state, and how to try it. API paths are relative to `/api/v1`. Requirement numbers (M0-01…) refer to the
specification; the detailed status of each is in [compliance/REQUIREMENTS_MATRIX.md](compliance/REQUIREMENTS_MATRIX.md).

**Status**: ✅ works and was exercised · 🟡 works with a stated limit · ⏳ V2, not built.

Accounts are in [TEST_ACCOUNTS.md](TEST_ACCOUNTS.md); step-by-step tests in [MANUAL_TEST_PLAN.md](MANUAL_TEST_PLAN.md).

## Onboarding

| Functionality | Scope | Role | Interface | API | Status | How to test |
|---|---|---|---|---|---|---|
| Company self-registration (industrial or provider) | MVP | Public | `/register` | `GET registration/options`, `POST auth/register` | ✅ | Test 01 |
| Email confirmation, link valid 24 h, resend | MVP | Applicant | `/verify-email`, `/account-status` | `POST auth/email/verify`, `auth/email/resend` | ✅ | Test 01 |
| Choice of plan at registration | MVP | Applicant | `/register` | `POST auth/register` | 🟡 intent only; every plan starts with the trial | Test 01 |
| Provider registration with accreditation PDFs | MVP | Provider | `/register` | `POST auth/register` (multipart) | ✅ | Test 08 |
| Acceptance of legal documents, dated and versioned | MVP | Applicant, invited user | `/register`, `/invitation` | `auth/register`, `auth/invitations/{token}/accept` | 🟡 texts are placeholders | Test 01 |
| Account status page (pending, rejected, suspended) | MVP | Applicant | `/account-status` | `GET me` | ✅ | Tests 01, 02 |
| Set-up wizard in 4 steps, resumable | MVP | Client admin | `/onboarding`, banner on `/dashboard` | `GET onboarding`, `PUT onboarding/steps/{step}` | ✅ | Test 03 |

## Authentication

| Functionality | Scope | Role | Interface | API | Status | How to test |
|---|---|---|---|---|---|---|
| Sign-in by email and password (cookie session) | MVP | All | `/login` | `POST auth/login`, `GET me`, `GET me/access` | ✅ | any test |
| Lockout after 5 failures (15 min), 5 attempts per minute | MVP | All | `/login` | `auth/login` | ✅ | type a wrong password 5 times |
| Password reset by email | MVP | All | `/forgot-password`, `/reset-password` | `POST auth/forgot-password`, `auth/reset-password` | ✅ | "Mot de passe oublié ?" then `demo.ps1 mail` |
| Two-factor authentication (TOTP) with recovery codes | MVP | All | `/account/security` | `POST/DELETE me/two-factor`, `me/two-factor/confirm` | 🟡 not re-clicked after the UI change | enable with an authenticator app |
| Several companies for one user, switch | MVP | All | account menu | `POST auth/switch-company` | ✅ | — |
| Sign-out | MVP | All | account menu | `POST auth/logout` | ✅ | — |
| SSO | V2 | | | | ⏳ | |

## SaaS administration (client side)

| Functionality | Scope | Role | Interface | API | Status | How to test |
|---|---|---|---|---|---|---|
| Company details and logo | MVP | Client admin, manager | `/admin/company` | `GET/PUT company` | ✅ | Test 03 |
| Invite a user with role and sites, link valid 7 days | MVP | Client admin | `/admin/users` → `/invitation` | `POST invitations`, `auth/invitations/{token}/accept` | ✅ | Test 03 |
| Change roles, suspend, remove a member | MVP | Client admin | `/admin/users` | `PUT users/{user}/roles`, `POST users/{user}/suspend`, `DELETE users/{user}` | ✅ | Test 03 |
| Custom roles | MVP | Client admin | `/admin/users` tab Rôles | `GET/POST/PUT/DELETE roles` | ✅ | create a role, give it to a user |
| Site-specific roles | MVP | Client admin | invite / edit roles | `PUT users/{user}/roles` | ✅ | Test 04 (Leila sees one site) |

## Sites and zones

| Functionality | Scope | Role | Interface | API | Status | How to test |
|---|---|---|---|---|---|---|
| Sites: create, edit, deactivate | MVP | Client admin, manager | `/sites` | `sites`, `sites/{site}/deactivate` | ✅ | Test 03 |
| Zones with type and capacity | MVP | Client admin, manager | `/sites/:id` | `POST sites/{site}/zones`, `PUT zones/{zone}` | ✅ | Test 03 |

## Waste catalogue

| Functionality | Scope | Role | Interface | API | Status | How to test |
|---|---|---|---|---|---|---|
| Reference catalogue managed by the platform | MVP | Platform admin | `/platform/catalog` | `platform/waste-catalog` | ✅ | Test 01 |
| Activate a reference type | MVP | Client admin, manager | `/waste-types` tab Catalogue | `GET waste-catalog`, `POST waste-types` | ✅ | Test 03 |
| Custom type with composition | MVP | Client admin, manager | `/waste-types` | `waste-types` | ✅ | Test 03 |
| Regulatory codes and hazardous flag | MVP | — | type forms | reference data | 🟡 code list to be validated by Diva | — |

## Lot traceability

| Functionality | Scope | Role | Interface | API | Status | How to test |
|---|---|---|---|---|---|---|
| Create a lot (desktop) | MVP | Manager | `/lots` → Nouveau lot | `POST lots` | ✅ | Test 05 |
| Create a lot (workshop) | MVP | Operator | `/m/lots/new` | `POST sync/push` | ✅ | Test 04 |
| Unique number and QR label 100 × 50 mm | MVP | Operator, manager | lot page, `/m` | `GET lots/{lot}/label` | 🟡 not tried on a thermal printer | Test 04 |
| Scan by camera or typed number | MVP | Operator | `/m/scan` | `GET lots/scan/{tag}` | 🟡 camera needs a webcam / HTTPS | Test 04 |
| Store, move | MVP | Operator, manager | lot page, `/m/lots/:id` | `POST lots/{lot}/store` | ✅ | Test 04 |
| Reweigh with history | MVP | Operator, manager | lot page | `POST lots/{lot}/weigh` | ✅ | Test 05 |
| Split, group | MVP | Operator, manager | lot page, `/lots` selection | `POST lots/{lot}/split`, `lots/group` | ✅ | Test 05 |
| Full history of a lot | MVP | All readers | lot page | `GET lots/{lot}`, `lots/{lot}/events` | ✅ | Test 05 |
| List with filters, sort, paging | MVP | All readers | `/lots` | `GET lots` | ✅ | Test 05 |

## Stock

| Functionality | Scope | Role | Interface | API | Status | How to test |
|---|---|---|---|---|---|---|
| Live stock by site, zone and type | MVP | Manager, direction | `/stock` | `GET stock` | ✅ | Test 05 |
| Alert thresholds (80 %, exceeded) with notification | MVP | Manager | `/stock` | `stock-thresholds` | ✅ | add a low threshold |

## Pickups

| Functionality | Scope | Role | Interface | API | Status | How to test |
|---|---|---|---|---|---|---|
| Request with lot selection and provider eligibility | MVP | Manager | `/pickups/new` | `POST pickups`, `pickups/{pickup}/submit` | ✅ | Test 06 |
| Confirm a date / refuse | MVP | Provider | `/provider/pickups/:id` | `POST pickups/{pickup}/confirm`, `refuse` | ✅ | Test 06 |
| Loading by scan, weight at departure | MVP | Operator | `/m/loading` | `POST sync/push` (or `pickups/{pickup}/collect`) | ✅ | Test 06 |
| Reception with received weights | MVP | Provider | `/provider/pickups/:id` | `POST pickups/{pickup}/receive` | ✅ | Test 06 |
| Discrepancy above 5 % (total or line) flagged, must be acknowledged | MVP | Manager | `/pickups/:id` | `POST pickups/{pickup}/acknowledge-variance` | ✅ | Test 06 |
| Treatment channel per lot | MVP | Provider | `/provider/pickups/:id` | `POST pickups/{pickup}/treat` | ✅ | Test 06 |
| Prices per line (sale or cost) | MVP | Manager | `/pickups/:id` | `PUT pickups/{pickup}/pricing` | ✅ | Test 06 |
| Close, cancel | MVP | Manager | `/pickups/:id` | `POST pickups/{pickup}/complete`, `cancel` | ✅ | Test 06 |
| Price lists | V2 | | | | ⏳ prices typed per pickup | |

## Providers

| Functionality | Scope | Role | Interface | API | Status | How to test |
|---|---|---|---|---|---|---|
| Directory with accreditations | MVP | Manager | `/providers` | `GET providers/directory` | ✅ | Test 08 |
| Partners | MVP | Manager | `/providers` | `provider-partnerships` | ✅ | Test 08 |
| Public profile and accepted waste | MVP | Provider | `/provider/profile` | `provider/profile` | ✅ | Test 08 |
| Accreditations: upload, review, expiry alerts | MVP | Provider, platform | `/provider/accreditations`, `/platform/accreditations` | `provider/accreditations`, `platform/accreditations` | ✅ | Test 08 |
| Expired provider cannot be chosen | MVP | — | `/providers`, `/pickups/new` | eligibility check | ✅ | Test 08 |

## Compliance

| Functionality | Scope | Role | Interface | API | Status | How to test |
|---|---|---|---|---|---|---|
| Chronological register, export PDF / Excel | MVP | Manager, auditor | `/compliance/register` | `GET register`, `POST reports` | ✅ | Test 07 |
| Hazardous-waste tracking slip, three electronic signatures | MVP | Manager, provider | `/compliance/manifests`, `/provider/manifests` | `GET manifests`, `POST manifests/{manifest}/sign` | 🟡 layout is not the official form | Test 07 |
| Certificates of recycling / destruction | MVP | Provider, manager | pickup page | `POST pickups/{pickup}/certificates` | ✅ | Test 07 |
| Annual declaration computed, validated, exported | MVP | Manager | `/compliance/declarations` | `POST sites/{site}/declarations`, `declarations/{declaration}/validate`, `…/export` | 🟡 layout is not the official ANGed form | Test 07 |
| Document archive | MVP | Manager, auditor | `/compliance/documents` | `GET documents` | ✅ | Test 07 |
| Personal-data export and erasure | MVP | — | — | — | ⏳ not built | |

## Reporting

| Functionality | Scope | Role | Interface | API | Status | How to test |
|---|---|---|---|---|---|---|
| Dashboard: 8 indicators, alerts, charts, period and site filters | MVP | Admin, manager, direction | `/dashboard` | `GET dashboard`, `GET stock` | ✅ | Test 09 |
| CSR report and exports, kept 30 days | MVP | Manager, direction | `/reports` | `reports` | ✅ | Test 09 |
| Production volumes (pieces) | MVP | Manager | `/reports` | `production-volumes` | ✅ | Test 09 |
| Benchmarks between companies, carbon footprint | V2 | | | | ⏳ | |

## Notifications

| Functionality | Scope | Role | Interface | API | Status | How to test |
|---|---|---|---|---|---|---|
| In-app notifications, bell, mark as read | MVP | Client, provider | top bar, `/account/notifications` | `me/notifications` | ✅ | send a pickup request, look at Karim's bell |
| Email for each notification type, per-user preferences | MVP | Client, provider | `/account/notifications` | `me/notification-preferences` | 🟡 demo writes emails to a log | `demo.ps1 mail` |
| SMS, WhatsApp | V2 | | | | ⏳ | |

## Audit

| Functionality | Scope | Role | Interface | API | Status | How to test |
|---|---|---|---|---|---|---|
| Company audit journal with before / after | MVP | Admin, manager, direction | `/admin/audit` | `GET audit-logs` | ✅ | change a zone, open the journal |
| Platform activity | MVP | Platform admin | `/platform/activity` | `GET platform/activity` | ✅ | Test 01 |

## Subscriptions

| Functionality | Scope | Role | Interface | API | Status | How to test |
|---|---|---|---|---|---|---|
| 30-day trial at approval, warnings, expiry | MVP | — | `/admin/subscription` | `GET subscription`, task `subscriptions:run` | ✅ | Test 01 |
| Plans and reference prices | MVP | Platform admin | `/platform/plans` | `platform/plans` | 🟡 price grid to be decided by Diva | Test 02 |
| Plan request by the client | MVP | Client admin | `/admin/subscription` | `POST subscription/change-request` | ✅ | Test 02 |
| Activation at the agreed price, first invoice | MVP | Platform admin | `/platform/companies/:id` | `POST platform/companies/{company}/subscription` | ✅ | Test 02 |
| Per-site billing | MVP | — | — | invoice lines | ✅ | Test 02 |
| Per-volume billing | MVP | — | — | invoice lines | 🟡 flat fee per period; tonnage not metered | — |
| Invoice with VAT and stamp duty, PDF | MVP | Client admin | `/admin/subscription` | `GET invoices`, `invoices/{invoice}/pdf` | ✅ | Test 02 |
| Bank transfer declared, validated by the platform | MVP | Client admin, platform | `/admin/subscription`, `/platform/payments` | `POST invoices/{invoice}/payments`, `platform/payments/{payment}/review` | ✅ | Test 02 |
| Automatic renewal, cancellation at period end | MVP | Client admin | `/admin/subscription` | `subscription/cancel`, `resume`, task | 🟡 renewal needs the scheduler | `demo.ps1 scheduler` |
| Card payment | V2 | | | | ⏳ | |

## Mobile and offline

| Functionality | Scope | Role | Interface | API | Status | How to test |
|---|---|---|---|---|---|---|
| Workshop application, large targets | MVP | Operator | `/m` | — | ✅ | Test 04 |
| Offline queue, automatic resynchronisation, idempotent | MVP | Operator | `/m`, `/m/sync` | `POST sync/push`, `GET sync/bootstrap` | ✅ | Test 04 |
| Installable (PWA), offline start | MVP | Operator | production build only | service worker | 🟡 not available with `ng serve`; never tried on a real phone | build and serve `dist/` |
| Native applications | V2 | | | | ⏳ | |

## Platform administration

| Functionality | Scope | Role | Interface | API | Status | How to test |
|---|---|---|---|---|---|---|
| Overview of what awaits a decision | MVP | Platform admin | `/platform/overview` | `GET platform/overview` | ✅ | Test 01 |
| Companies: search, filter, detail | MVP | Platform admin | `/platform/companies` | `GET platform/companies` | ✅ | Test 01 |
| Approve, reject with reason, suspend, reactivate | MVP | Platform admin | `/platform/companies/:id` | `POST platform/companies/{company}/approve`, `reject`, `suspend`, `reactivate` | ✅ | Tests 01, 02 |
| Accreditation review | MVP | Platform admin | `/platform/accreditations` | `platform/accreditations` | ✅ | Test 08 |
| Invoices of all clients | MVP | Platform admin | `/platform/invoices` | `GET platform/invoices` | ✅ | Test 02 |
| Role matrix | MVP | Platform admin | `/platform/roles` | `platform/roles` | ✅ | untick a permission, sign in as that role |
| Countries, taxes, legal documents | MVP | Platform admin | — | reference data only | ⏳ no screen | — |
| Create a company on behalf of a client | — | — | — | — | not planned: companies register themselves | [guide §7](PLATFORM_ADMIN_GUIDE.md#7-can-i-create-a-company-myself) |
