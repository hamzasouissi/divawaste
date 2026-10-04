# Domain map: backend facts to frontend concepts

## Evidence convention

**BACKEND FACT** means stated in the checked-in verified handoff, with source/runtime verification identified where performed. **FRONTEND ARCHITECTURAL DECISION** is a client boundary, not backend behavior. The sibling backend checkout is available; notification and login contracts were source/runtime reverified during Phase 2. Other contracts remain handoff-sourced until implementation verifies them.

## API-facing domains

| Domain | BACKEND FACT | FRONTEND ARCHITECTURAL DECISION |
|---|---|---|
| Session and tenant | `POST /auth/login` accepts email/password and optional company ULID, returns a company-selection list when needed or issues a Sanctum bearer PAT bound to the selected company. `/me` returns user/profile/platform roles; `/company` returns current active tenant. No logout/revocation/refresh/MFA or standalone company-switch route. Evidence: updated handoff `02-api-inventory`, `03-auth-and-permissions`, and `04-data-contracts`; backend `app/Modules/Identity/Routes/api.php`; runtime route registry. | `entities/session` plus app bootstrap; `features/sign-in` owns credentials, company choice, and token handoff. Keep PAT in memory only; full reload requires another login. Tenant/action authorization remains backend-authoritative. |
| Site | Tenant site, code unique per company; paginated list and create API; site-granted visibility. Evidence: `01-domain-model`, `02-api-inventory`. | `entities/site`; sites workspace plus create-site feature. |
| Waste type | Tenant code unique; paginated list/create; composition, code references, and aggregate percentage checks. Evidence: `02-api-inventory`, `05-workflows-and-business-rules`. | `entities/waste-type`; catalog workspace/create feature. Reference selection must be supplied only by an approved future source because no lookup API exists. |
| Stock threshold | Tenant/site-scoped list/create; optional zone/type; target uniqueness. Evidence: `01-domain-model`, `02-api-inventory`. | `entities/stock-threshold`; thresholds widget and create feature, not a generic inventory engine. |
| Waste lot | Site/zone/type material lot, append-only events, versioned lifecycle. Exposed create, detail, events, store, split, group; no collection endpoint. Evidence: `02-api-inventory`, `05-workflows-and-business-rules`. | `entities/waste-lot`; detail/events workspace; action features. No lot index route/screen. |
| Pickup | Owner/site/provider pickup with immutable line snapshots; party visibility; create/detail/transition routes, no list. Draft -> requested -> confirmed -> collected -> received is the exposed workflow. Evidence: `01-domain-model`, `02-api-inventory`, `05-workflows-and-business-rules`. | `entities/pickup`; detail workspace and discrete action features. Do not replicate eligibility/variance calculation or create a pickup inbox/list. |
| Provider | Published directory has direct unpaginated rows; partnership `firstOrCreate`; profiles/accreditations lack CRUD routes. Evidence: `02-api-inventory`. | `entities/provider`; directory and partnership feature. No provider-management module. |
| Documents/compliance | Documents list/detail/issue; manifests list/create; declarations list/create; certificates upload from collected/received pickup. IDs are inconsistent between endpoints. Evidence: `02-api-inventory`, `04-data-contracts`, `05-workflows-and-business-rules`, `09-files-and-attachments`. | Three entity slices with compliance widgets/features. No upload/download framework beyond certificate upload; no signature/validation/submit UI. |
| Billing | Only declare bank transfer under invoice path; invoice/payment list/detail routes absent. Invoice must be issued/partially paid/overdue; payment is pending. Evidence: `02-api-inventory`, `05-workflows-and-business-rules`. | `features/declare-bank-transfer` is callable from a future supported invoice context; no billing page. |
| Reports | List/detail/create APIs; create returns 202 but action proves no generation worker/file completion. Evidence: `02-api-inventory`, `05-workflows-and-business-rules`. | `entities/report`, reports workspace/create feature. No polling, download, or completed-file UX. |
| Notifications | Current-user list uses fixed-size-20 cursor pagination; a caller-owned notification can be marked read; `PUT` upserts a required array of `{ notification_type, channel, is_enabled }` preferences, with channels `mail|database|whatsapp`; key is serialized as a string. Migration declares `CHAR(36)`, handoff describes UUID, and `DispatchNotification` writes ULIDs; no call site for that action was found under `app/` or `database/`, so the runtime key representation remains unresolved. No preference-read route is registered. Evidence: handoff `02-api-inventory`, `04-data-contracts`; source `app/Modules/Notifications/Routes/api.php`, `Requests/SetNotificationPreferencesRequest.php`, `Resources/NotificationResource.php`, `Resources/NotificationPreferenceResource.php`, `database/migrations/2026_10_01_001400_create_notifications_table.php`, `app/Modules/Notifications/Actions/DispatchNotification.php`; Laravel runtime route registry. | `entities/notification` treats IDs as opaque strings; `pages/notifications` owns cursor navigation and `features/mark-notification-read` owns the mutation. Defer preference editing because existing values cannot be loaded. |
| Audit | Paginated audit list with filters; request permission is `audit_logs.view`, mismatching seeded `audit.view`. Evidence: `02-api-inventory`, `03-auth-and-permissions`, `06-errors-and-edge-cases`. | `entities/audit-log`; route is conditional on actual successful authorization, with 403 state. |
| Device registration | Registers device and returns one-time 30-day plaintext token. No sync routes. Evidence: `02-api-inventory`, `05-workflows-and-business-rules`. | Isolated, security-reviewed `register-mobile-device` feature; never log/persist its returned secret indiscriminately. Not a default web shell page. |

## Relationships and lifecycle evidence

- **BACKEND FACT:** Company is the tenant root. Sites, waste types, lots, pickups, documents, reports and much more are tenant-owned. Lots belong to site/zone/type; pickups own line snapshots and are shared with addressed provider/transporter; documents may relate to site/pickup/lots/files. Evidence: `01-domain-model.md`.
- **BACKEND FACT:** Lot transitions write append-only events; split closes one input into multiple children and grouping closes compatible inputs into a new stored output. Evidence: `05-workflows-and-business-rules.md`.
- **BACKEND FACT:** Pickup submission requires partnership/profile/company/accreditation eligibility; collect/receive alter lot/line states; backend calculates variance. Evidence: `05-workflows-and-business-rules.md`.
- **BACKEND FACT:** Certificate action checks party and status, associates pickup lots, issues a document, and retains file metadata for ten years. Evidence: `05-workflows-and-business-rules.md`, `09-files-and-attachments.md`.
- **FRONTEND ARCHITECTURAL DECISION:** Relationship display is contextual: lot detail presents timeline/actions; pickup detail presents immutable lines/actions; document detail presents available metadata. It does not assert all schema relationships can be loaded by the API.

## Workflow-to-feature map

| Available workflow | Supported frontend behavior | Explicit non-behavior |
|---|---|---|
| Create/store/split/group lot | Forms submit exact endpoint payloads, explain server validation/conflicts, refetch authoritative detail/events. | No client lifecycle enforcement, delete UI, list, or unexposed treated/close transition. |
| Create/submit/cancel/collect/confirm/refuse/receive pickup | State-aware action availability is advisory; each action handles 409/403 and refreshes pickup. | No eligibility pre-approval, price/complete UI, or list. |
| Provider partnership | Provider directory selection and idempotent create action. | No profile/accreditation admin. |
| Issue/create compliance/certificate | Document/manifest/declaration lists where routed; precise form contracts; multipart certificate validation. | No file preview/download/sign/declaration submission. |
| Payment declaration | Exact request form from a supplied invoice ULID context. | No balance calculation, payment review, invoice discoverability. |
| Reports | List/detail/create record with honest queued/unknown completion state. | No polling/download promise. |
| Notifications | Cursor navigation and mark-as-read, using backend response state. | No realtime claim; no preference editor until current values/defaults can be read. |

## Page inventory implied by live routes

**FRONTEND ARCHITECTURAL DECISION:** The current build includes session bootstrap, sites, catalog, provider directory, documents, reports, notifications with cursor/read behavior, and deep-linkable lot/pickup details. Remaining route-backed surfaces include thresholds, manifests/declarations, audit, and supported create/action features. Notification preferences remain deferred because only an upsert is exposed. Create/action surfaces are features, not automatically separate pages.

**BACKEND FACT:** There is no API-backed list for lots/pickups and no API for several schema concepts. Therefore anything beyond the inventory is **UNKNOWN — NOT FOUND IN BACKEND**, not a planned screen.

