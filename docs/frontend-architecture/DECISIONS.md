# Architectural decisions

## D1 — Use pragmatic FSD with public slice APIs

- **Decision:** Structure the future Angular client as `app/pages/widgets/features/entities/shared`, with downward-only dependencies and barrelled public APIs.
- **Context:** The frontend is new, while the backend has several real domain workflows.
- **Reasoning:** This follows the required frontend standard and assigns entities, actions, screen regions, and infrastructure clear owners without a file-type dump.
- **Alternatives considered:** A technical `components/services/models` hierarchy; a global feature-module hierarchy; both obscure business ownership or encourage coupling.
- **Consequences:** Add import-boundary and circular-dependency CI checks at bootstrap. `shared` remains domain-free.

## D2 — Defer a shared server-state dependency until its needs are concrete

- **Decision:** Define a query-adapter contract now; choose its implementation after the Angular version, existing platform constraints, and dependency policy are known.
- **Context:** The Angular 20 project now exists, but current server reads are small, route-scoped surfaces and there is no established query library. The current implementation does not yet provide cross-route caching, deduplication, or generalized invalidation.
- **Reasoning:** Avoid adding a dependency before usage justifies it, while treating the missing cache/query lifecycle as an explicit implementation gap rather than a solved concern.
- **Alternatives considered:** Add a server-state library immediately; build a global cache by hand. The first needs a focused compatibility evaluation; the second risks duplicate ownership and stale state.
- **Consequences:** Before multi-screen mutation workflows depend on shared server data, select or implement a query adapter with typed keys, cancellation, deduplication, stale handling, and targeted invalidation. Current local signals are not a substitute for that capability.

## D3 — Use the backend bearer login and keep its token ephemeral

- **Decision:** Use `POST /api/v1/auth/login`, support its optional company-ULID selection round-trip, and keep the returned PAT in memory only; clear it on 401.
- **Context:** The source/runtime route accepts email/password/company and returns either a company-selection response or a Sanctum bearer token with the selected company bound. No logout/revocation, refresh, password-reset, MFA-challenge, or standalone company-switch route exists.
- **Reasoning:** The frontend now has a source-backed login flow without creating credentials endpoints or persisting a long-lived bearer secret in browser storage.
- **Alternatives considered:** Fake login; store token in local/session storage; retain the obsolete external login link. The first and third are incorrect now; persistent browser storage unnecessarily expands token exposure.
- **Consequences:** A full page reload requires another login. No logout control is claimed without revocation semantics. Backend remains authoritative for tenant access; `/me` and `/company` bootstrap still follow token issuance.

## D4 — No list/navigation experience for lots or pickups

- **Decision:** Provide only deep-link/contextual detail and workflow composition for lots/pickups until collection routes exist.
- **Context:** `GET /lots` and `GET /pickups` are not registered.
- **Reasoning:** A polished invented index would be a fake API requirement, conceal a backend gap, and create rework.
- **Alternatives considered:** Client-side aggregation from other endpoints; placeholder/mock lists; neither is evidenced.
- **Consequences:** Navigation labels and empty states must state unavailable discovery honestly; a future list contract is a prerequisite for list pages.

## D5 — Prefer pessimistic lifecycle mutations

- **Decision:** Submit lifecycle/compliance actions once, disable duplicate submit, then invalidate/refetch authoritative data; no automatic mutation retries.
- **Context:** Lot/pickup transitions, certificate issuance, and financial declaration cause consequential server changes and conflict outcomes.
- **Reasoning:** Optimistic state could misrepresent compliance or material movement. Backend remains the source of truth.
- **Alternatives considered:** Broad optimistic cache update; blind retry. Both risk false state or duplicate action.
- **Consequences:** Feature UX must make progress and recoverable errors clear, and keep valid form data after failure.

## D6 — Endpoint-specific DTO mapping, not a global identifier/decimal abstraction

- **Decision:** Type route DTOs per endpoint and map only real irregularities at entity boundaries.
- **Context:** Most paths use ULIDs, some filters/requests/responses use numeric IDs, and decimal wire representation is not globally uniform. Notification IDs are opaque strings: the handoff says UUID, the migration uses `CHAR(36)`, and an unreferenced dispatch action writes ULIDs.
- **Reasoning:** A generic `id`/number mapper would silently corrupt contract meaning.
- **Alternatives considered:** Normalize every `_id` to ULID or JavaScript number. Both contradict the handoff.
- **Consequences:** Forms document numeric manifest/filter requirements; notification route IDs are not parsed or normalized; decimal presentation avoids floating-point business calculations.

## D7 — Treat lack of effective-grant API as an authorization UX limitation

- **Decision:** Use only session/tenant guards globally; feature visibility based on actual tenant permissions waits for a real capability contract. Always handle endpoint 403.
- **Context:** `/me` exposes platform roles only; tenant permission grants/site scopes are not exposed. Audit/report-create permission names also mismatch the seed catalog.
- **Reasoning:** Client role inference is neither reliable nor a security boundary.
- **Alternatives considered:** Hard-code seeded role matrix; show every action as permitted. The first is stale/incorrectable; the second is noisy but may be used only when no safe discovery alternative exists.
- **Consequences:** Product/backend must decide a capability endpoint or trusted bootstrap payload before permission-aware navigation is complete.

## D8 — Build i18n/RTL and accessible dense workflows into foundations

- **Decision:** Translation ownership, logical CSS, document language/direction, semantic controls, keyboard/focus, and WCAG 2.2 AA target are foundation requirements.
- **Context:** French, English, Arabic, and RTL are explicit requirements; the frontend standard requires accessible premium data-dense UX.
- **Reasoning:** Retrofits create duplicated layouts and broken semantics.
- **Alternatives considered:** Translate after UI build or create separate RTL pages. Both increase coupling and regressions.
- **Consequences:** Every shared primitive has RTL/a11y/text-expansion checks; tests cover Arabic/RTL on key workflows.

## D9 — Defer notification preference editing without a read contract

- **Decision:** Do not present a notification-preference editor until current preferences or reliable defaults can be loaded.
- **Context:** The backend registers `PUT /api/v1/me/notification-preferences` as an upsert but registers no read endpoint. The request accepts a required list of type, channel, and enabled values. The migration defaults new rows to enabled, but that does not reveal a user's existing overrides.
- **Reasoning:** A form initialized with guessed values could overwrite user preferences or imply that all available settings are represented.
- **Alternatives considered:** Initialize all controls to the database default; provide a write-only form; infer types from notification payloads. None establishes the caller's current preference state, and saving guessed values could overwrite overrides.
- **Consequences:** Ship notification browsing and mark-as-read only. Revisit settings when a read/default-state contract is documented. Backend evidence: `app/Modules/Notifications/Routes/api.php`, `app/Modules/Notifications/Requests/SetNotificationPreferencesRequest.php`; verified with Laravel runtime routes.

