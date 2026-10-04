# Frontend implementation plan

Phase 2 implementation is underway (2026-10-02). The Angular 20 foundation now includes a source-verified bearer login with company selection, in-memory token handling, 401 recovery, lazy routes, shared transport/error/session boundaries, and French/English/Arabic login states. API workspaces exist for sites, stock thresholds, waste types, providers, documents, reports, notifications, and deep-link lot/pickup detail. Notifications use cursor pagination and mark-as-read; paginated collections use server metadata. URL synchronization, documented filters, remaining mutations, logout/refresh, capability discovery, and real-backend E2E remain pending.

The sibling Laravel checkout is available in this environment. Notification and login route/request/response behavior were verified against source and runtime routes. Other endpoint contracts still require source-level verification as they are implemented. Notification preferences are deliberately deferred: the backend exposes only an upsert, not a read endpoint. No backend login feature tests were found; login behavior currently has frontend unit coverage only. A synthetic invalid-credentials request reached the local route but received 500 because the DB connection was refused; successful backend auth remains unverified until that environment dependency is available.

## Phase 0 — Resolve backend and platform prerequisites

- **Goal:** Make the client consumable without inventing security or discovery behavior.
- **Areas:** logout/refresh/MFA, tenant switching, effective grants/capabilities, lot/pickup collection endpoints, reference-data lookup, document/report download and report completion policy.
- **Dependencies:** backend/platform/product owners for remaining auth lifecycle, grants, discovery, and reference-data contracts; verify each remaining endpoint against the available sibling backend source before implementation.
- **Deliverables:** explicit deferrals; auth/token storage policy; capability source; route/schema fixtures; updated handoff if behavior differs.
- **Tests/verification:** contract tests against real backend; security review of auth/CSRF; verify each decision against source/runtime routes.

## Phase 1 — Angular foundation and architecture enforcement (partially implemented)

- **Goal:** Establish the smallest secure, typed, testable Angular foundation.
- **Areas:** standalone bootstrap, strict TypeScript, FSD aliases/lint boundaries, router/lazy chunks, shared UI tokens/primitives, i18n/RTL locale service, app error boundary, test tooling.
- **Dependencies:** Phase 0 auth integration decision; selected supported Angular version and query adapter.
- **Deliverables:** `app/pages/widgets/features/entities/shared` skeleton; public APIs; CI type/lint/build/cycle checks; translation namespace policy; a11y test setup.
- **Tests/verification:** architecture import tests, unit tests for locale/direction/formatting, accessibility tests for primitives, build and bundle baseline.

## Phase 2 — Transport, session, errors, and server-state foundation (in progress)

- **Goal:** Centralize real API consumption before any business screen.
- **Areas:** typed transport, problem-details normalization, request-ID diagnostics, query adapter, `/me`/`/company` bootstrap, guards, 401/403 states.
- **Dependencies:** Phases 0–1.
- **Deliverables:** no raw HTTP in components; endpoint-aware DTO/decimal/date helpers; session/tenant shell; safe retry/invalidation policy.
- **Tests/verification:** transport integration tests for JSON, problem+json, 401/403/404/409/422/5xx/network; request-ID redaction; guard tests; real-backend bootstrap test.

## Phase 3 — Foundational supported workspaces (read surfaces partially implemented)

- **Goal:** Implement the low-coupling paginated domains.
- **Areas:** sites, waste types, stock thresholds, provider directory/partnership; create forms; documented page filters/pagination.
- **Dependencies:** reference-value contract for create forms; session/tenant and query foundation.
- **Deliverables:** entity slices, create features, dense accessible workspaces, exact pagination/filter handling, localized error/loading/empty states. Read workspaces exist for sites, waste types, stock thresholds, provider directory, documents, and reports; page controls are implemented for paginated lists; create-site is implemented. URL page state, filters, remaining create forms, and richer server error states remain open.
- **Tests/verification:** form/422/conflict tests, page pagination tests, permissions/403 tests, real-backend E2E create site/type/threshold and partnership.

## Phase 4 — Lot and pickup workflows

- **Goal:** Implement only exposed material-movement actions safely.
- **Areas:** lot detail/events/create/store/split/group; pickup detail/create/submit/cancel/collect/confirm/refuse/receive.
- **Dependencies:** known context/deep-link strategy; Phase 0 list contract if list navigation is desired.
- **Deliverables:** discrete action features with pessimistic submit/refetch; timeline and line-snapshot widgets; conflict-specific recovery; no client eligibility calculator.
- **Tests/verification:** real-backend E2E for valid path and invalid transitions, split >2% note conflict, group mismatch, pickup cancellation/refusal, omission/not-loaded and receipt variance; keyboard/RTL/responsive reviews.

## Phase 5 — Compliance, notifications, reporting, and payment action

- **Goal:** Deliver the remaining live API surfaces without promising absent workflows.
- **Areas:** documents/issue, manifests/create, declarations/create, certificate upload, notifications (read implemented; preferences only if a safe read/default contract is established), reports, audit, bank transfer from valid invoice context.
- **Dependencies:** numeric-ID/reference contracts; file/download/report-completion decisions for any retrieval UI; audit/report permission mismatch resolution; notification preference read/default semantics.
- **Deliverables:** compliance workspaces and exact multipart form; cursor notification UI with read action; report state that does not poll; contextual payment feature. Report pagination controls exist; report URL/filter state remains open.
- **Tests/verification:** certificate type/size/party/status conflicts, document issue conflicts/idempotence, declaration uniqueness, notification cursor/read/preference, report 202 behavior, audit/report 403 handling, real-backend E2E where grants allow.

## Phase 6 — Hardening and release readiness

- **Goal:** Prove cross-cutting quality at realistic scale and locales.
- **Areas:** test suites, accessibility, RTL, performance, diagnostics, security/privacy, responsive dense tables, supported browser/device policy.
- **Dependencies:** implemented routes and production-like backend/test data.
- **Deliverables:** E2E critical-path suite, a11y and visual regression coverage, request/bundle/render budgets from measurements, telemetry/privacy configuration if approved, support runbook using request IDs.
- **Tests/verification:** full type/lint/build; import/cycle checks; real-backend E2E; French/English/Arabic RTL manual plus automated checks; keyboard/focus/zoom/reduced-motion; load/performance measurements; security review.

## Sequence guardrails

- Do not begin a screen for a schema-only or absent API capability.
- Add queries/types before features, features before widgets/pages, and routes last.
- After each mutation, verify its cache/refetch, 422, 403, 409, and duplicate-submit behavior.
- Reconcile this plan whenever actual backend behavior contradicts the handoff; record the contradiction in `DECISIONS.md` and update the relevant architecture/domain statement.
