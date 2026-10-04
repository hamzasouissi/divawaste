# DivaWaste frontend architecture

## Status, scope, and evidence

Phase 1 architecture was approved; Phase 2 implementation is underway. An Angular 20 standalone application now exists under `frontend/`, including lazy routes, shared API/session/error boundaries, locale/direction switching, read workspaces, and the notification cursor/read workflow. No mock APIs or backend code have been added.

Backend facts are drawn from `docs/frontend-handoff/` (updated 2026-10-02). The sibling backend checkout is available in this environment; notification contracts and the new login route were verified against source and Laravel's runtime registry. The runtime registry now reports 43 application routes; other endpoint contracts still require source verification as implemented.

The client must consume only the 42 documented `/api/v1` application routes. A page, button, route, or automated refresh must never imply an unavailable backend operation.

## Principles

- Use pragmatic FSD: `app -> pages -> widgets -> features -> entities -> shared`; lower layers never import higher ones. Each slice exposes an `index.ts` public API; no cross-slice deep imports.
- Model backend-owned data as server state, keep temporary interaction state local, and put shareable navigable state in the URL.
- Use standalone Angular 20 components, strict TypeScript, signals for local state, `OnPush`, reactive forms for actions, and native semantic HTML.
- Keep HTTP transport, auth attachment, request IDs, error normalization, parsing, and retry policy centralized. Components do not call `HttpClient` directly.
- Permission-aware UI improves usability only. Sanctum, tenant middleware, and endpoint authorization remain authoritative.
- Translate all user copy; format semantic date/number/money values at presentation time; set document `lang` and `dir` for French, English, and Arabic.

Backend evidence: `frontend-handoff/00-overview.md`, `02-api-inventory.md`, `03-auth-and-permissions.md`, and `04-data-contracts.md`.

## Proposed application structure

```text
src/
  app/                 bootstrap, providers, router, guards, shell, global error boundary
  pages/               route compositions only
  widgets/             application-navigation, list workspaces, detail panels, workflow sections
  features/            explicit user operations and their forms
  entities/            business concepts, DTOs, entity queries, presentation helpers
  shared/              UI primitives, HTTP/query infrastructure, i18n, formats, types, assets
```

`shared` knows no DivaWaste term. Examples: `DataTable`, `Dialog`, `FieldError`, `Pagination`, `HttpTransport`, `ProblemError`, `formatDate`, `formatDecimal`, `LocaleService`. A `LotStatusBadge` belongs to `entities/waste-lot`, not `shared`.

### Entities

Create slices only for concepts with live API consumption or reusable frontend meaning:

| Entity slice | Owns | Does not own |
|---|---|---|
| `session` | typed `/me` and `/company` read models; session/tenant display model | credential acquisition, role/grant invention |
| `site`, `waste-type`, `stock-threshold` | DTOs, list/create query contracts, semantic display | zones or reference lookups without APIs |
| `waste-lot` | lot DTO, detail/events queries, status/weight display and invariant-safe parsing | create/store/split/group interaction |
| `pickup` | pickup DTO/line snapshots, detail query and state display | lifecycle actions and eligibility simulation |
| `provider` | directory row and partnership DTO | provider profile/accreditation CRUD |
| `document`, `manifest`, `declaration` | read models, list/detail queries, status display | issue/create/upload forms |
| `payment`, `report`, `notification`, `audit-log`, `mobile-device` | only their exposed DTO/query contracts | screens or actions not backed by routes |

Do not create entities for every database table. Zone, membership, roles, invoices, stored files, reference rows, and sync operations have no sufficient live API surface for independent web slices. They may be types nested within a supported request only when necessary.

### Features

Feature slices own a user action, reactive form, client-side UX validation, mutation state, confirmation, error mapping, and targeted invalidation:

- `create-site`, `create-waste-type`, `create-stock-threshold`
- `create-lot`, `store-lot`, `split-lot`, `group-lots`
- `create-pickup`, `submit-pickup`, `cancel-pickup`, `collect-pickup`, `confirm-pickup`, `refuse-pickup`, `receive-pickup`
- `create-provider-partnership`
- `issue-document`, `create-manifest`, `create-declaration`, `upload-certificate`
- `declare-bank-transfer`, `create-report`, `mark-notification-read`, `set-notification-preferences`, `register-mobile-device`

Features must submit endpoint-specific shapes (including numeric manifest IDs where required), not a fabricated generic repository API. They may show backend conflict codes but must not reproduce eligibility, lifecycle, price, or variance rules as authority.

### Slice catalogue and public APIs

Every named slice uses the same public contract: entity slices expose types, read/query capabilities and domain presentation components; feature slices expose one action component/facade plus its input/output types; widgets expose one composed region; pages expose a route component only. External code imports only these exports.

| Slice group | Why it exists | Owns/exposes | May depend on | Must remain outside |
|---|---|---|---|---|
| `entities/session` | Session and current tenant are cross-route prerequisites. | `/me`/`/company` query types and display model. | `shared`. | credential acquisition, global routing policy. |
| `entities/site`, `waste-type`, `stock-threshold` | Each has reusable live read models and related create actions. | endpoint DTOs, list queries, cards/labels. | `shared`. | create forms and page composition. |
| `entities/waste-lot`, `pickup` | Their detail data and presentation are reused by multiple lifecycle actions. | detail/event queries, typed status/weight/line UI. | `shared`. | mutation forms, client rule enforcement, list discovery. |
| `entities/provider` | Directory response and partnership identity have stable business meaning. | directory/partnership types and provider display. | `shared`. | profile/accreditation management. |
| `entities/document`, `manifest`, `declaration` | They have distinct response/filter/type irregularities and shared compliance display. | list/detail query types and status UI. | `shared`. | issue/create/upload actions and unavailable signatures/submission. |
| `entities/payment`, `report`, `notification`, `audit-log`, `mobile-device` | Each maps a live endpoint contract without forcing a broader unsupported module. | their specific DTO/query helpers. | `shared`. | nonexistent billing, sync, realtime, or device-management systems. |
| Lot/pickup feature groups | Mutations are separate consequential user intents with different payloads/conflicts. | one typed form/facade per named action and success invalidation. | corresponding entity + `shared`. | page routing, global server state, backend rule duplication. |
| Catalog/site/provider feature groups | Create/idempotent actions need their own form/error lifecycles. | create-site/type/threshold and partnership APIs/forms. | relevant entities + `shared`. | generic reference-data service unless contract arrives. |
| Compliance/billing/report/notification/device feature groups | Endpoint operations have distinct security and recovery semantics. | exact action form/facade per named action. | relevant entities + `shared`. | a generic mutation manager or unsupported follow-on operations. |
| Named widgets | Reuse only meaningful interface regions across route compositions. | public workspace/panel composition. | features, entities, `shared`. | route parsing, generic primitive ownership. |
| Named pages | One route’s composition and URL interpretation needs a clear owner. | route component and route-local URL parser. | widgets/features/entities/`shared`. | raw HTTP, reusable business action logic. |

The public API naming is intentionally action/domain based, for example `WasteLotDetailQuery`, `CreateLotForm`, `SubmitPickupAction`, `DocumentsWorkspace`, and `DocumentsPage`; it avoids a generic `services` or `helpers` export.

### Widgets and pages

Widgets are meaningful reusable sections: `application-shell`, `primary-navigation`, `sites-workspace`, `catalog-workspace`, `documents-workspace`, `reports-workspace`, `notifications-panel`, `lot-detail-workspace`, `pickup-detail-workspace`, and workflow action panels. Pages compose them and own route/query-param parsing only.

Initial routable screens may exist only where the API supports the task: session bootstrap; sites; waste types; stock thresholds; provider directory; documents and document detail; manifests; declarations; reports and report detail; notifications/preferences; audit logs; lot detail/events; pickup detail; and creation/action routes or dialogs that are reachable from known IDs/context.

There must be no lot-list, pickup-list, invoice-list/detail, file-download/preview, provider-accreditation, user/role, dashboard-statistics, production-volume, sync, declaration-submit, manifest-sign, payment-review, or report-download screen until a real route exists. In particular, a detail route alone does not solve discovery of its ULID; the navigation/shell must not promise a list that the API cannot provide.

## Dependency and public-API rules

- `app` configures providers/router and composes pages; business workflow code stays out.
- `pages` compose public APIs from widgets/features/entities; no raw transport.
- `widgets` compose lower slices; they own a coherent screen region, not generic controls.
- `features` may depend on entities/shared and expose one action-oriented API.
- `entities` may depend only on shared; entity API functions represent reusable retrieval/display contracts.
- `shared` imports no other FSD layer.

Enforce aliases and import-boundary/cycle checks in CI when the project is created. A barrel is a slice’s public contract, not permission to expose internals.

## State architecture

| Class | Owner/source of truth | Policy |
|---|---|---|
| Server state | endpoint-specific entity query adapter | Cached by endpoint + every response-changing parameter + tenant identity; cancellation/deduplication; no copies in global stores. |
| URL state | page route/query params | `page`, backend-supported filters, and selected tab/detail ULID where navigable. Do not put tokens, raw error bodies, form drafts, or unsupported sort/search in URLs. |
| Feature state | owning feature | Reactive form values, dirty/submitting state, dialog step, selected lots for one action. Destroy on close/navigation unless an explicit user-preservation policy is introduced. |
| Entity presentation state | entity/widget where justified | Derived status labels, parsed decimal values, event grouping; never a writable mirror of server records. |
| Local UI state | component | expanded sections, temporary menu/dialog visibility, column preferences. |
| Application state | `app` | session/tenant bootstrap status, locale/direction, theme only if product requires it, and normalized authentication-expired state. |

Lists use backend pagination: default 25/max 100 for page pagination and server-returned cursor links for notifications (fixed 20). Use only documented filters, fixed backend ordering, and no fictional free-text search/client-selectable sort. Preserve current data during a background refresh; distinguish initial loading, empty, filtered-empty, permission denial, and failed refresh.

Mutations are pessimistic by default because lots/pickups/documents are lifecycle- and compliance-sensitive. Disable duplicate submission. On success invalidate/refetch the specific detail plus affected supported list queries: e.g. lot actions invalidate its detail/events; pickup transitions invalidate pickup detail and known lot details; certificate upload invalidates pickup detail and document lists if mounted. No automatic mutation retry. `issue-document` is backend-idempotent, but UI still submits once.

## API/data architecture

`shared/api` owns a typed transport adapter, base URL/environment, `Accept: application/json`, authenticated-request integration, request-ID capture, timeout/cancellation, and a normalized `AppError`. It must preserve `X-Request-Id` for diagnostics and accept RFC 9457 `application/problem+json`.

Entity `api/` functions own reusable reads; feature `api/` functions own writes. DTOs remain at the boundary. Map only where it removes a real wire irregularity: decimal strings become a decimal-safe presentation value (never JS floating-point business arithmetic); date-only strings remain date-only values; endpoint-specific numeric IDs/ULIDs are explicitly typed; optional `whenLoaded` fields are optional. Do not flatten inconsistent IDs into a false universal `EntityId`.

The query adapter must expose keys, data/error/loading state, cancellation, stale policy, invalidation, and refetch without prescribing a library before Angular/runtime selection. Read retry: bounded and only transient network/5xx failures; never retry 401/403/404/409/422 automatically. Mutations: no automatic retry absent documented idempotency. A response arriving after changed route/parameters must not overwrite current state.

Certificate upload uses `FormData` only for the documented endpoint, maintains selected file locally, enforces PDF/JPEG/PNG and 20 MiB for immediate UX feedback, and relies on backend validation. No upload/download abstraction is created for absent generic file APIs.

## Authentication, tenancy, and authorization UX

At application start, `AuthBootstrap` calls `/me`; after identity is established it calls `/company`. The public sign-in feature posts email/password and optional company ULID to `POST /auth/login`. It handles the backend's company-selection response by repeating login with the selected ULID, keeps the returned Sanctum bearer token in memory only, and retries `/me` then `/company`. A protected-request 401 clears the token and returns to login. There is no logout/revocation, refresh, password reset, MFA challenge, or standalone company-switch endpoint; do not promise those workflows. Memory-only token handling means a full reload requires sign-in again. Backend evidence: `app/Modules/Identity/Routes/api.php`; runtime registry includes API throttle `5/minute`.

Because `/me` exposes platform roles, not effective tenant grants, feature/action visibility cannot be reliably derived for tenant permissions. Route guards protect only known session/tenant prerequisites. Navigation should show only routes the client can safely present, while endpoint 403 remains normal feedback. Permission-gating requires a future backend capability contract or an approved injected capability source; until then, do not claim `can()` is authoritative. Never send a tenant/company header to select context.

## Routing, forms, and UX system

Route configuration belongs to `app/router`; route chunks are lazy loaded. Page routes use ULIDs only where routes bind ULIDs. Document/manifest filters retain their documented numeric-FK semantics and labels must not tell users they accept ULIDs.

Feature-owned typed reactive forms separate initial/current/dirty/validation/submission/server-error states. UI validation gives early feedback; server 422 `errors` maps to fields and preserves valid inputs. Backend business conflicts (409) become contextual, localized messages; they do not become copied validation rules. Warn before discarding a dirty multi-field action. Confirm destructive or consequential lifecycle operations with consequence-specific copy.

The shared design system uses semantic tokens and a restrained visual language: dense task-oriented layouts, readable type scale, logical spacing, semantic colors, compact reusable form/table/dialog/toast/skeleton/empty-state primitives, and one visible primary action. Tables retain semantic table behavior; on narrow screens choose a deliberate horizontal-scroll, condensed, or details pattern per workflow rather than accidental collapse. Advanced filters progressively disclose. Empty state text distinguishes no data, no results, no permission, unsupported discovery, and failed loading.

## i18n, RTL, accessibility

Organize translations by ownership: `common.*` for generic primitives, `entities.<entity>.*`, `features.<action>.*`, and `pages.<route>.*`. French, English, and Arabic translations include labels, validation, notifications, error recovery, titles, and accessible names. Components receive semantic labels/slots, never concatenated sentence fragments.

Locale service controls active locale, `document.lang`, and `document.dir`; Arabic uses `rtl`, French/English `ltr`. Use logical CSS properties and semantic leading/trailing APIs. Review tables, column actions, pagination, breadcrumbs, drawers, dialogs, icons with directional meaning, and mixed identifiers/URLs in both directions. Store values semantically; use `Intl`/Angular locale formatting for dates, times, decimals, currency, percentages, and units. Display UTC datetimes deliberately using a product-selected timezone; `/company.timezone` is available, but a definitive display-timezone policy is a product decision.

Target WCAG 2.2 AA unless product governance specifies otherwise. Use native elements first; associate labels/help/errors; expose visible focus and keyboard operation; manage dialog focus; provide accessible status announcements; never rely on color alone; give icon-only controls names; respect reduced motion; test text expansion, zoom, RTL, and narrow layouts.

## Error and observability architecture

Normalize API outcomes as `unauthenticated`, `forbidden`, `notFound`, `validation`, `conflict(code)`, `rateLimited`, `network`, `server`, and `unexpectedContract`. Feature code owns recovery with user context; `app` owns session-expiry flow and unexpected runtime boundary; `shared/api` owns parsing. Never show raw `detail`, stack trace, storage path, headers, token, full request body, or API response body to users.

Inline feedback is for field validation; contextual action errors/rollback guidance for mutations; stable page/widget recovery for reads; global safe fallback for unexpected runtime error. Capture a redacted diagnostic containing operation, route, feature, HTTP category/status, request ID, app version, and non-sensitive correlation context. Do not log credentials, auth headers, device token, files, complete form payloads, or user-entered sensitive content. The handoff proves `X-Request-Id`, but does not prove a telemetry endpoint; telemetry vendor/consent/retention are **UNKNOWN — NOT FOUND IN BACKEND** and require product/platform decision.

## Testing and performance architecture

Use unit tests for mappers, decimal/date parsing, query-key construction, and form state; component tests for semantic controls, error/loading/empty states and keyboard behavior; integration tests for transport normalization, 422 mapping, cache invalidation, guards, and permission-denial handling; E2E against a real controlled backend for critical available paths. Add FSD import and circular-dependency checks, strict typecheck, lint, build, and automated accessibility scanning; supplement with keyboard, responsive, French/English/Arabic/RTL manual review.

Highest-value real-backend E2E workflows: credential login including multi-company selection and session/tenant bootstrap (once a DB-backed test environment is available); create site/type/threshold; create/store/split/group lot; pickup create-submit-confirm/receive plus cancel/refuse; certificate upload and issued document; declaration creation uniqueness conflict; bank transfer declaration; notification read/preference upsert; document issue conflicts. Do not E2E nonexistent logout/revocation, pickup/lot list, download, report completion, or signature workflows.

Lazy-load pages/workspaces; do not fetch data from generic presentation controls; cache/deduplicate identical reads; respect server pagination; debounce only a future supported search; never render unbounded data. Do not poll reports because completion behavior is unestablished. Defer optional mobile/device and heavy file code. Establish bundle/request/render budgets during implementation from target devices and measured baselines, rather than inventing numeric limits now.

## Known blockers and contradictions

- **UNKNOWN — NOT FOUND IN BACKEND:** logout/revocation, refresh, password reset, MFA challenge, and standalone company switching. Login is available through the source-verified bearer-token endpoint.
- **UNKNOWN — NOT FOUND IN BACKEND:** lot and pickup collection APIs; no navigation design may masquerade as a list.
- **UNKNOWN — NOT FOUND IN BACKEND:** reference lookup APIs, attachment download/preview/delete/scanning, report generation/completion/download, and several schema-only workflows.
- Audit uses `audit_logs.view` while the catalog seeds `audit.view`; report creation uses `reports.create`, absent from the catalog. Treat both as potential 403 and do not expose them based on catalog roles alone.
- The backend source checkout is available outside this workspace. Contracts are reverified feature-by-feature; do not assume routes not yet inspected have received source-level verification.
- Notification preference writes are available, but no endpoint returns current preferences or their defaults. Do not present editable preference controls until current-state/default semantics are established. Backend evidence: `app/Modules/Notifications/Routes/api.php` and `app/Modules/Notifications/Requests/SetNotificationPreferencesRequest.php`.
- A synthetic invalid-credentials request to the real local login route returned 500 `SERVER_ERROR` because the backend reported its database connection was refused. No real credentials were used; successful backend login remains unverified until the DB service is available.
