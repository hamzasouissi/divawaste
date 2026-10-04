# Frontend Architecture Standard

**Status:** Engineering Standard v1.0 — Stable Baseline
**Authority:** Source of truth for frontend architecture
**Architecture:** Feature-Sliced Design (FSD), adapted pragmatically to the application

---

## 1. Purpose

This standard defines how frontend applications must be structured, evolved, reviewed, and extended.

The objective is not to maximize abstraction or follow FSD mechanically. The objective is to produce software that is:

- easy to understand;
- easy to change;
- easy to test;
- difficult to misuse;
- consistent across the application;
- scalable without architectural degradation;
- pleasant for both users and developers.

Architecture decisions must optimize for **clarity, ownership, isolation, reuse, and changeability**.

---

## 2. Non-Negotiable Principles

### 2.1 Architecture follows responsibility

Code is placed according to **what it is responsible for**, not according to its file type.

Do not organize the application primarily as:

```text
components/
services/
models/
utils/
```

Business concepts and user actions must have explicit ownership.

### 2.2 Dependencies have a direction

The application follows this conceptual dependency direction:

```text
app
  ↓
pages
  ↓
widgets
  ↓
features
  ↓
entities
  ↓
shared
```

A lower layer must never depend on a higher layer.

### 2.3 Prefer composition over duplication

Compose existing capabilities before creating alternatives.

Do not create a second component, service, helper, store, or pattern when an existing one can be extended cleanly.

### 2.4 Reuse must be meaningful

Code does not belong in `shared` merely because it is used more than once.

Something is shared when it is **generic by nature** and has no ownership of a specific business concept.

### 2.5 Business ownership must be explicit

Every meaningful piece of business logic must have a clear owner.

When ownership is unclear, architecture is already degrading.

### 2.6 Abstraction must earn its existence

Do not introduce abstractions solely because they look architecturally sophisticated.

Create an abstraction when it provides meaningful isolation, reuse, extensibility, testability, or consistency.

### 2.7 Existing conventions are preferred

Before creating something new, inspect the existing codebase.

The agent must first identify established patterns and reuse them unless there is a documented reason to change them.

### 2.8 The smallest correct change is preferred

Do not refactor unrelated areas while implementing a feature.

Architecture improvements should be deliberate and separately justified when they materially expand scope.

---

# 3. Application Layers

## 3.1 `app/`

### Responsibility

Application-wide infrastructure and composition.

Typical responsibilities:

```text
app/
├── router/
├── providers/
├── config/
├── guards/
├── layouts/
├── initialization/
└── styles/
```

Examples:

- application bootstrap;
- routing configuration;
- global providers;
- authentication initialization;
- global configuration;
- application-wide error boundaries;
- global styles and theme initialization.

### Rules

- `app` may compose lower layers.
- `app` may depend on pages, widgets, features, entities, and shared where framework conventions require it.
- Business-specific implementation should not accumulate in `app`.
- `app` should configure systems, not become a dumping ground for global logic.

---

## 3.2 `pages/`

### Responsibility

Route-level screens and page composition.

Examples:

```text
pages/
├── dashboard/
├── users/
├── products/
└── orders/
```

A page answers:

> What does this route/screen contain?

### Rules

- Pages compose widgets, features, and entities.
- Pages may coordinate route-specific state and composition.
- Pages must not become large containers for domain logic.
- Reusable business behavior belongs lower in the architecture.
- If the same substantial page logic appears across routes, extract it into the appropriate lower layer.

A page should primarily express **composition**, not implementation detail.

---

## 3.3 `widgets/`

### Responsibility

Large, reusable interface sections composed from lower-level capabilities.

Examples:

```text
widgets/
├── user-table/
├── dashboard-statistics/
├── order-summary/
└── application-navigation/
```

A widget answers:

> What reusable section of the interface do we have?

### Rules

- Widgets may compose features and entities.
- Widgets should represent a meaningful interface block.
- Widgets should not become generic UI libraries.
- Widgets should not own unrelated business domains.
- A widget should be extractable when its composition is meaningful beyond a single tiny fragment.

---

## 3.4 `features/`

### Responsibility

User-facing actions, interactions, and business operations.

Examples:

```text
features/
├── create-user/
├── edit-user/
├── delete-user/
├── export-orders/
└── change-password/
```

A feature answers:

> What can the user do?

### Rules

A feature may own:

- interaction logic;
- feature-specific state;
- forms;
- validation for the action;
- API operations specific to the action;
- optimistic/pessimistic interaction behavior;
- user feedback for the action.

A feature must not become a general-purpose domain container.

For example:

```text
create-order       ✅ feature
edit-order         ✅ feature
calculate-order    ⚠️ depends on whether this is an action or domain rule
order              ✅ entity
```

Business rules that describe the entity itself should not be hidden inside arbitrary feature components.

---

## 3.5 `entities/`

### Responsibility

Core business concepts recognized by the application.

Examples:

```text
entities/
├── user/
├── product/
├── order/
└── customer/
```

An entity answers:

> What does the business know about?

Typical structure:

```text
entities/customer/
├── model/
├── api/
├── ui/
├── lib/
├── types/
└── index.ts
```

### Rules

- Entity code represents the business concept itself.
- Entity code must avoid knowledge of a specific page.
- Entity UI should be reusable across features/pages where practical.
- Entity API code should represent the entity's data access boundary, not arbitrary screen behavior.
- Domain transformations that belong inherently to the entity should remain with the entity.

---

## 3.6 `shared/`

### Responsibility

Generic infrastructure and reusable building blocks with no business ownership.

Typical structure:

```text
shared/
├── ui/
├── api/
├── lib/
├── hooks/
├── types/
├── constants/
└── assets/
```

Examples:

```text
Button
Modal
DataTable
DatePicker
HttpClient
formatDate()
formatCurrency()
```

### Hard rule

`shared` must not import or depend on business entities, features, widgets, or pages.

Bad:

```text
shared/ui/customer-status-badge
```

Good:

```text
entities/customer/ui/CustomerStatusBadge
```

unless the component is truly generic and knows nothing about Customer semantics.

---

# 4. Dependency Rules

## 4.1 Allowed direction

Conceptually:

```text
           app
            ↓
          pages
            ↓
         widgets
            ↓
         features
            ↓
         entities
            ↓
          shared
```

Allowed examples:

```text
pages     → features      ✅
features  → entities      ✅
entities  → shared        ✅
widgets   → entities      ✅
widgets   → features      ✅
app       → pages         ✅
```

Forbidden examples:

```text
shared    → entities      ❌
entities  → features      ❌
features  → pages         ❌
widgets   → pages         ❌
entities  → widgets       ❌
```

### Exception policy

Framework infrastructure may create unavoidable application-level wiring exceptions. Such exceptions must be explicit, minimal, and documented rather than silently becoming a general dependency pattern.

---

# 5. Slice Isolation

A slice is a business or responsibility boundary inside a layer.

Example:

```text
features/
├── create-user/
├── edit-user/
└── delete-user/
```

Slices should be isolated from each other.

Do not directly reach into another slice's private files.

Bad:

```text
features/create-user/
    imports
features/edit-user/ui/internal/EditUserForm.ts
```

Preferred:

```text
features/edit-user/index.ts
```

and import only its public API.

---

# 6. Public API Rule

Every meaningful slice should expose an explicit public API.

Example:

```text
features/create-user/
├── ui/
├── model/
├── api/
└── index.ts
```

External consumers import from:

```ts
import { CreateUserForm } from '@/features/create-user';
```

not from private implementation paths.

### Why

Public APIs:

- reduce coupling;
- allow internal refactoring;
- make ownership explicit;
- prevent accidental dependencies;
- improve discoverability for humans and agents.

---

# 7. Generic vs Business-Specific Code

Use this decision sequence before creating or moving code:

### Question 1
Does it represent a business concept?

If yes → `entities` or a higher business-owned layer.

### Question 2
Does it represent a user action or business interaction?

If yes → `features`.

### Question 3
Does it compose a meaningful interface section?

If yes → `widgets`.

### Question 4
Is it a route-specific screen composition?

If yes → `pages`.

### Question 5
Is it genuinely generic infrastructure/UI?

If yes → `shared`.

### Rule

Do not move code downward merely to make a dependency graph look cleaner. Move it to the layer that actually owns its responsibility.

---

# 8. State Management

State is owned by the **lowest reasonable architectural boundary**.

The goal is not to minimize the number of stores. The goal is to minimize unnecessary shared state and maintain a single clear source of truth.

## 8.1 State taxonomy

Every state value should be classified before deciding where it lives:

```text
UI state
    ↓
Feature state
    ↓
Entity/domain state
    ↓
Server state
    ↓
Application/session state
```

These categories must not be mixed casually.

### UI state

Temporary presentation state with no business ownership.

Examples:

```text
isDialogOpen
activeTab
hoveredRow
expandedSection
localInputDraft
```

Preferred owner: component or local UI composition.

### Feature state

State required to execute a specific user action or workflow.

Examples:

```text
create-user form
bulk-selection for export
multi-step checkout progress
wizard state
```

Preferred owner: feature.

### Entity/domain state

State representing application concepts whose lifecycle extends beyond one individual interaction.

Examples:

```text
current customer
current order
selected product
entity-specific presentation state
```

Preferred owner: entity or an explicitly higher domain owner when justified.

### Server state

Data whose authoritative source is the backend.

Examples:

```text
customers returned by API
orders
inventory quantities
permissions from backend
pagination results
```

Server state is **not the same thing as local application state**.

It should be treated with a server-state/data-fetching strategy that provides, as appropriate:

- caching;
- deduplication;
- stale/fresh handling;
- request cancellation;
- loading/error states;
- pagination/infinite loading;
- mutation lifecycle;
- cache invalidation or targeted updates.

Do not copy server responses into unrelated global stores merely to make them accessible.

### Application/session state

State legitimately required across unrelated routes or application lifecycle boundaries.

Examples:

```text
authenticated session
current tenant
application theme
locale
global capability/configuration
```

Global state requires explicit ownership and justification.

---

## 8.2 The state ownership decision

Before introducing state, answer:

```text
1. What is this state?
2. Who owns the behavior that changes it?
3. How long must it live?
4. Who actually needs to consume it?
5. Is it local state or server state?
6. Can it be derived instead of stored?
7. Is there already an authoritative source?
```

Then choose the smallest valid owner.

```text
One component            → local state
One feature              → feature state
One entity/domain        → entity state
Many unrelated areas     → application state, only if justified
Backend-owned data       → server-state mechanism
```

## 8.3 Single source of truth

Do not maintain multiple writable copies of the same conceptual state without a deliberate synchronization strategy.

Bad:

```text
API response
   ↓
GlobalStore.customer
FeatureStore.customer
Component.customer
```

Preferred:

```text
Authoritative source
       ↓
Consistent consumers
       ↓
Derived UI state where needed
```

## 8.4 Derived state

Prefer deriving values from authoritative state instead of synchronizing duplicate values.

Bad:

```ts
firstName
lastName
fullName
```

when `fullName` can be derived.

Good:

```ts
fullName = `${firstName} ${lastName}`
```

The same principle applies to totals, filtered collections, status labels, permission visibility, and other deterministic values.

## 8.5 Global state is expensive

Before creating global state, prove that:

- multiple independent consumers genuinely need it;
- its lifetime requires application-wide persistence;
- no lower owner can reasonably provide it;
- introducing it will not create hidden coupling.

Do not create a global store because the project already has a state-management library.

## 8.6 URL state

State that defines a navigable, shareable, or bookmarkable view should prefer URL/query parameters where appropriate.

Examples:

```text
search
filters
sort
page
selected entity identifier
view mode
```

The URL should not become a dumping ground either. Use it when the state meaningfully belongs to navigation or reproducible views.

## 8.7 Reactive state discipline

Whether the framework uses signals, observables, stores, or another reactive mechanism:

- keep state boundaries explicit;
- avoid unnecessary subscriptions;
- avoid duplicate synchronization chains;
- clean up subscriptions according to framework conventions;
- prefer declarative derivation over imperative synchronization;
- avoid triggering side effects from arbitrary computed/derived state.

---

# 9. API and Data Architecture

API access must be treated as a **data boundary**, not as an implementation detail scattered throughout UI code.

Conceptually:

```text
UI
 ↓
Feature / Entity capability
 ↓
Data-access boundary
 ↓
HTTP / transport
 ↓
Backend API
```

## 9.1 Transport is centralized

Application-wide transport concerns belong in a centralized infrastructure boundary, typically under `shared/api` and/or `app` according to the framework.

Centralize where appropriate:

- HTTP client configuration;
- base URL/environment configuration;
- authentication transport;
- request correlation;
- interceptors/middleware;
- serialization defaults;
- common headers;
- standardized transport error handling;
- cancellation/timeouts policy.

Do not duplicate these concerns inside every feature.

## 9.2 Components do not own HTTP transport

Presentation components should not contain ad-hoc raw HTTP calls.

Bad:

```ts
this.http.get('/api/customers')
```

inside a table component merely because the table needs customers.

Preferred:

```text
component
   ↓
entity/feature data capability
   ↓
API boundary
```

The component should consume a well-defined capability rather than know how transport works.

## 9.3 Ownership of data access

Use the business meaning of the operation to determine ownership.

### Entity query

Data describing an entity belongs with the entity when it represents reusable entity retrieval.

```text
entities/customer/api/getCustomer
entities/product/api/searchProducts
```

### Feature mutation

An operation representing a user action belongs with the feature.

```text
features/create-customer/api/createCustomer
features/archive-order/api/archiveOrder
```

### Shared transport

Generic infrastructure remains generic:

```text
shared/api/http-client
shared/api/interceptors
shared/api/request
```

## 9.4 API models are external contracts

Backend DTOs/API responses are external contracts.

Do not leak backend-specific representations into every component by default.

Where useful, define explicit mapping:

```text
API DTO
  ↓
Mapper / adapter
  ↓
Application model
  ↓
UI
```

The amount of mapping should be proportional to real complexity. Do not create needless duplicate models solely for ceremony.

## 9.5 Query architecture

Every server-state query should have:

- a clear owner;
- a stable query identity/key;
- explicit parameters;
- defined loading behavior;
- defined error behavior;
- cache policy appropriate to the data;
- cancellation behavior where relevant;
- a refresh/invalidation strategy.

Query identity must include every parameter that changes the returned data.

For example:

```text
customers + tenant + search + page + sort + filters
```

must not accidentally reuse a cache entry for a different query.

## 9.6 Mutations

Every mutation should define its post-mutation data strategy.

After a successful mutation, choose deliberately between:

```text
update cache directly
invalidate affected queries
refetch affected data
navigate to a new authoritative source
```

Never rely on accidental stale data behavior.

## 9.7 Request boundaries

A feature/entity API function should expose an application-friendly contract rather than force callers to understand transport details.

Bad:

```ts
createCustomer({
  headers,
  rawBody,
  serializedQueryString,
  retryConfig,
  url
})
```

Preferred:

```ts
createCustomer(customerInput)
```

Transport configuration remains inside the appropriate infrastructure boundary.

## 9.8 Authentication and authorization

Frontend API architecture must integrate with the application's security model.

Rules:

- authentication transport is centralized;
- protected requests follow the established session/token mechanism;
- authentication expiry is handled consistently;
- authorization failures produce consistent application behavior;
- frontend permission checks are UX controls, never the security boundary;
- backend authorization remains authoritative.

## 9.9 Input and output discipline

Before data crosses an API boundary:

- validate required client-side input for UX;
- normalize data where necessary;
- preserve backend contract semantics;
- never assume client validation makes data trustworthy.

When data returns:

- handle unexpected/malformed responses safely;
- map errors consistently;
- avoid spreading transport-specific structures throughout UI code.

## 9.10 Pagination, filtering and sorting

Large datasets must use explicit server/data strategies.

A data-heavy feature should define:

```text
page / cursor strategy
page size
filters
sort
search
loading behavior
empty behavior
error behavior
```

Do not load an unbounded dataset merely because the backend currently allows it.

## 9.11 Dependent requests

When one request depends on another, model that dependency explicitly.

Avoid accidental request chains caused by deeply nested components.

Prefer:

```text
known dependency
   ↓
controlled query
   ↓
derived result
```

rather than many components independently discovering the same prerequisite data.

## 9.12 Duplicate fetching

Avoid multiple unrelated consumers repeatedly requesting identical server data when a shared server-state mechanism can safely deduplicate or cache it.

Do not solve duplicate fetching by creating a giant global business store unless that store is genuinely the correct owner.

## 9.13 API error taxonomy

The API layer must distinguish at least conceptually between:

```text
401 / authentication failure
403 / authorization failure
400 / validation or malformed request
404 / missing resource
409 / business conflict
429 / rate limiting
5xx / server failure
network / connectivity failure
unexpected contract failure
```

The frontend may map these into user-facing behavior, but the classification should remain explicit.

## 9.14 No hidden network behavior

A developer should be able to understand where network requests originate.

Avoid hidden API requests triggered by generic UI components unless that behavior is explicitly part of the component's documented contract.

## 9.15 API definition of done

Before an API-backed feature is considered complete:

```text
□ Data ownership is explicit.
□ Server state is separated from local UI state.
□ Transport concerns are centralized.
□ No raw HTTP calls live in presentation components.
□ Query identity includes all meaningful parameters.
□ Cache/invalidation behavior is deliberate.
□ Mutation success behavior is deliberate.
□ Loading/empty/error states exist.
□ Authentication behavior follows application policy.
□ Authorization is never trusted solely to the frontend.
□ DTO/API contracts are handled explicitly.
□ Large datasets use appropriate pagination/filtering/sorting.
□ Duplicate fetching is avoided where practical.
□ Tests cover important success and failure behavior.
```

# 10. Forms and Validation

Forms are behavior, not merely UI markup.

### Rules

- Form ownership belongs to the feature or workflow that owns the action.
- Validation rules must have a clear source of truth.
- Reusable generic validators belong in shared only when they are truly domain-neutral.
- Business validation must not be hidden inside generic UI components.
- Validation errors must be mapped consistently to fields and user-visible feedback.
- Forms must support accessible labels, keyboard navigation, focus management, and meaningful error messages.

---

# 11. Routing

Routing belongs to application infrastructure.

### Rules

- Route configuration belongs in `app/router` or the framework-equivalent application layer.
- Pages represent route destinations.
- Route guards enforce access policy, not visual decisions.
- Feature/entity code should not hard-code unrelated route structure.
- Route-level data loading should compose lower-level data capabilities rather than duplicate them.

---

# 12. UI Architecture

Architecture and visual quality are related but distinct.

### Rules

- Generic UI primitives belong in `shared/ui`.
- Business UI belongs to its business owner.
- Reusable components must have a clear API and predictable behavior.
- Avoid "god components" that render multiple unrelated responsibilities.
- Prefer composition over dozens of boolean flags.
- Component APIs should make invalid combinations difficult where practical.
- A reusable component should be reusable because its abstraction is correct, not because it has many props.

### Component quality test

Before extracting a component, ask:

1. Does it have one coherent responsibility?
2. Is its API understandable?
3. Does extraction reduce complexity rather than relocate it?
4. Is its behavior predictable without reading its internals?
5. Does it belong to a clear architectural owner?

---

# 13. Business Logic Placement

Business logic must not be hidden randomly inside UI components.

### Prefer

```text
UI
 ↓
feature/entity model
 ↓
application/domain operation
 ↓
API/data boundary
```

### Avoid

```text
huge-component
├── API call
├── validation
├── business rules
├── transformation
├── state management
├── notifications
└── rendering
```

A component should coordinate presentation and interaction; specialized logic should live at the appropriate ownership boundary.

---

# 14. Error Handling

Errors must have an explicit classification.

At minimum distinguish conceptually between:

```text
validation error
business/domain error
authentication/authorization error
network/transport error
unexpected/system error
```

### Rules

- Do not silently swallow errors.
- Do not duplicate error mapping across unrelated components.
- User-facing messages should be understandable and actionable.
- Technical details must not be exposed unnecessarily to end users.
- Logging/telemetry must follow application policy.
- Error handling should be consistent across equivalent operations.

---

# 15. Security Architecture

Security is an architectural concern from the beginning of implementation. The frontend is part of the security boundary, but it is **never the final authority** for authorization or data integrity.

The standard follows defense-in-depth principles and uses OWASP guidance as a reference baseline.

## 15.1 Trust boundaries

Treat all data crossing into the application as untrusted unless its trust is explicitly established by the backend/security architecture.

This includes:

```text
URL parameters
query strings
route parameters
form input
API responses
user-generated content
uploaded files
browser storage
third-party content
postMessage data
configuration exposed to the browser
```

The frontend must never assume that because a value came from the API, it is safe to inject into an HTML, URL, DOM, CSS, or script-sensitive context.

## 15.2 Authentication

- Authentication must be centralized through the application's established authentication architecture.
- Protected API operations must go through the authenticated API boundary.
- The UI may reflect authentication state, but security must not depend on hidden buttons, route guards, or frontend checks alone.
- Handle `401` and `403` responses consistently.
- Do not duplicate token/session logic across features.
- Never hard-code credentials, API keys, client secrets, or private tokens in source code.
- Browser-visible configuration is **not a secret**.

## 15.3 Authorization

Authorization is enforced by the backend.

Frontend authorization exists to provide correct UX:

```text
hide unavailable action
↓
disable unavailable action where appropriate
↓
prevent unnecessary requests
↓
handle backend denial correctly
```

It must never be treated as proof that an operation is allowed.

## 15.4 Injection and XSS

The application must prefer framework-safe rendering and avoid bypassing security mechanisms without a documented reason.

### Forbidden by default

```text
arbitrary innerHTML / equivalent DOM injection
unsafe HTML rendering
javascript: URLs
unsanitized user-controlled HTML
unsafe DOM manipulation
runtime code generation / eval-like behavior
```

If raw HTML is genuinely required:

1. establish why normal rendering is insufficient;
2. validate/sanitize according to the application's approved mechanism;
3. constrain allowed content as tightly as possible;
4. document the trust boundary.

Framework escape hatches such as Angular `bypassSecurityTrust...` must be treated as security-sensitive operations, not convenience utilities. OWASP explicitly identifies framework escape hatches as areas where otherwise safer framework defaults can be bypassed. citeturn737267search0

## 15.5 URLs and navigation

Never blindly navigate to an untrusted URL.

For user-controlled or API-provided URLs:

- validate the protocol and allowed destination according to application policy;
- reject dangerous schemes such as `javascript:`;
- avoid open-redirect behavior;
- encode values correctly for their URL context.

## 15.6 CSRF and session security

The authentication mechanism determines the required browser protections.

For cookie-based authentication, state-changing requests must use the application's CSRF protection mechanism and the backend must validate it. OWASP recommends using framework-provided CSRF protections when available. citeturn737267search8

Do not invent a parallel authentication or CSRF mechanism inside an individual feature.

## 15.7 Sensitive data

Never unnecessarily place sensitive data in:

```text
localStorage
sessionStorage
URLs
query parameters
console logs
analytics events
error messages
client-side source/bundles
```

The correct storage mechanism depends on the authentication and threat model. The standard does **not** mandate one token-storage technique universally; it mandates that the choice be deliberate and documented.

## 15.8 API and data exposure

- Do not expose database structure unnecessarily to the browser.
- Do not allow frontend consumers to define arbitrary backend queries.
- Never embed SQL credentials or database connection information in frontend code.
- Treat API responses as untrusted input for rendering purposes.
- Do not assume frontend validation protects backend integrity.
- Backend parameterization and authorization remain mandatory for database operations.

The phrase **"no SQL injection" is a full-stack requirement**: frontend architecture must not create unsafe query construction paths, while backend code must enforce parameterized access and authorization.

## 15.9 File uploads and external content

For upload features:

- constrain accepted file types and sizes at the UI level for UX;
- rely on the backend for authoritative validation;
- never trust MIME type or filename supplied by the client;
- avoid rendering uploaded content directly unless the security model explicitly permits it.

Third-party scripts, widgets, embeds, fonts, and remote resources must have a documented reason to exist and should be minimized.

## 15.10 Security headers and defense in depth

Where controlled by the application deployment, use appropriate browser security headers and policies, including CSP where suitable.

CSP is a **defense-in-depth layer**, not a replacement for safe rendering and input handling. OWASP explicitly recommends CSP as an additional layer rather than the primary XSS defense. citeturn737267search1

## 15.11 Dependency security

- Prefer maintained dependencies.
- Minimize dependency count and privilege.
- Do not add a dependency for trivial functionality already available safely in the codebase/platform.
- Review security advisories for important dependencies.
- Avoid abandoned or unnecessary third-party packages in security-sensitive paths.

## 15.12 Security review questions

Before declaring security-sensitive frontend work complete:

```text
□ What are the trust boundaries?
□ Is authentication handled centrally?
□ Is authorization still enforced by the backend?
□ Can untrusted data reach an unsafe rendering context?
□ Can untrusted data control navigation?
□ Can sensitive information leak through storage, URLs, logs, or telemetry?
□ Does the authentication model require CSRF protection?
□ Are file uploads treated as untrusted?
□ Are third-party dependencies/resources justified?
□ Are framework security escape hatches justified and constrained?
```

---

# 16. Performance Architecture

Performance is a product requirement and an architectural property.

The goal is not to make every line of code theoretically optimal. The goal is to keep the application **responsive, efficient, and predictable under realistic usage**.

## 16.1 Performance principles

```text
Measure where possible.
Optimize user-visible bottlenecks first.
Prefer structural improvements over micro-optimizations.
Avoid work that does not contribute to the current task.
Do not trade maintainability for insignificant gains.
```

## 16.2 Bundle discipline

- Keep the initial JavaScript/CSS payload intentional.
- Lazy-load route-level functionality when appropriate.
- Avoid importing a large library when a small utility or existing platform API is sufficient.
- Remove unused dependencies and dead code.
- Prefer modern tree-shakable imports.
- Keep heavy editors, charts, maps, reporting engines, and similar features out of the critical path unless required immediately.

## 16.3 Network discipline

Every request has a cost.

The agent must consider:

```text
Why is this request needed?
When should it happen?
Can it be combined?
Can it be cached?
Can it be deferred?
Can existing server state be reused?
```

Avoid:

- duplicate requests caused by multiple components requesting identical state;
- request waterfalls that could be parallelized;
- fetching fields/data that the screen does not need;
- polling when event-driven updates are more appropriate;
- refetching unchanged data without a reason.

## 16.4 Rendering and state updates

Do not trigger broad UI updates for narrow state changes.

Prefer localized reactive updates and predictable change boundaries.

Framework-specific strategies such as Angular signals, OnPush/change-detection discipline, memoization, or equivalent mechanisms belong in the framework adapter of this standard rather than being blindly imposed on every project.

## 16.5 Data-heavy interfaces

ERP and business applications commonly contain large datasets.

For large collections:

- paginate or virtualize when appropriate;
- avoid rendering thousands of DOM nodes unnecessarily;
- keep filtering/sorting server-side when the dataset is large;
- debounce expensive user-driven queries where appropriate;
- preserve current results while fetching where that improves UX;
- prevent duplicate loading caused by overlapping state transitions.

A table should remain usable with realistic production-scale data, not merely a ten-row development fixture.

## 16.6 Images and static assets

- Use correctly sized assets.
- Avoid loading full-resolution assets when the displayed dimensions are small.
- Lazy-load non-critical images where appropriate.
- Avoid decorative assets that materially increase payload without meaningful UX value.

## 16.7 Caching

Cache server state deliberately.

Caching must have explicit rules for:

```text
freshness
invalidation
mutation effects
staleness tolerance
persistence
```

Never introduce caching merely to hide inefficient architecture.

## 16.8 Interaction performance

The user should not have to wait for unnecessary work before receiving feedback.

Use appropriate:

```text
instant local feedback
progressive loading
skeletons when layout stability benefits
optimistic updates where safe
background/deferred work
```

Do not animate or continuously update large portions of the interface without a UX reason.

## 16.9 Performance budgets

Projects should define practical budgets where the environment and product justify them.

At minimum consider budgets for:

```text
initial bundle size
route/chunk size
large asset size
API response size
rendered collection size
number of requests for critical screens
```

Budgets should be based on the actual application and target environment, not arbitrary universal numbers.

## 16.10 Measure real user experience

For web applications, performance evaluation should consider user-visible responsiveness rather than only build size or framework benchmark numbers.

Where relevant, monitor standard real-user performance signals such as loading, interaction responsiveness, and layout stability.

## 16.11 Performance anti-patterns

Avoid:

```text
premature memoization
random use of caching
unnecessary global state
large eager imports
duplicate API calls
massive DOM trees
unbounded client-side datasets
polling without justification
expensive work during initial render
```

## 16.12 Performance Definition of Done

```text
□ Initial load contains only necessary critical functionality.
□ Heavy features are deferred where appropriate.
□ No obvious duplicate requests exist.
□ Large datasets use an appropriate loading/rendering strategy.
□ Expensive work is not performed unnecessarily on every render.
□ API payloads are appropriate for the screen.
□ Images/assets are appropriately sized.
□ Loading behavior provides useful feedback.
□ Performance-sensitive decisions have a reason or measurement.
□ No premature optimization significantly harms clarity.
```

---

# 17. Accessibility

Accessibility is part of quality, not an optional enhancement.

### Rules

- All interactive controls must be keyboard accessible.
- Form controls must have meaningful labels.
- Focus state must remain visible.
- Dialogs, menus, tables, and dynamic content must behave predictably for assistive technologies.
- Color must not be the only signal for important state.
- Text and interactive targets must remain usable at relevant viewport sizes.
- Semantic HTML should be preferred before custom ARIA behavior.

---

# 18. Testing Architecture

Tests should follow ownership boundaries.

### Rules

- Test business behavior, not implementation trivia.
- Shared components should have reusable behavioral tests appropriate to their complexity.
- Features should test their important user workflows and business interaction behavior.
- Entities should test meaningful transformations and domain behavior.
- Pages should primarily be covered through composition/integration tests when needed.
- Critical paths should have end-to-end coverage.
- Architecture rules should be machine-checkable where feasible.

The architecture itself should be tested when possible:

```text
No forbidden imports.
No layer violations.
No accidental circular dependencies.
Public APIs respected.
```

---

# 19. Naming and Consistency

Naming must communicate responsibility.

Prefer:

```text
create-user
edit-user
customer-details
order-summary
```

over vague names such as:

```text
helpers
common2
misc
stuff
manager
utils2
```

### Rules

- Names should reflect business or architectural responsibility.
- Avoid unnecessary abbreviations.
- Follow one naming convention consistently within the project.
- Do not invent synonyms for established project concepts without a reason.
- Prefer the domain's existing vocabulary.

---

# 20. Documentation

Documentation should explain **decisions and contracts**, not obvious code.

Document:

- architectural decisions;
- non-obvious business rules;
- public APIs with complex behavior;
- important constraints;
- deliberate exceptions;
- migration/deprecation decisions.

Do not generate comments that merely restate the code.

Bad:

```ts
// Increment the counter
counter++;
```

Good:

```ts
// Keep the optimistic counter in sync until the server confirms the mutation.
```

---

# 21. Anti-Patterns

The following are architectural warning signs:

### God Component

One component owns UI, API, validation, business rules, state, formatting, and orchestration.

### God Store

A global store contains unrelated application state simply because a state-management library exists.

### Shared Dump

`shared/` becomes a collection of business-specific code with vague names.

### Feature Leakage

One feature reaches into another feature's internal files.

### Page Intelligence Explosion

Pages become containers for business rules that should have explicit ownership.

### Duplicate Truth

The same entity state exists in multiple unrelated stores/models without synchronization rules.

### Premature Abstraction

A complex abstraction is created before the actual reuse pattern is understood.

### Architecture by Folder

Files are moved between folders without clarifying who owns their responsibility.

### Framework Dictatorship

Framework convenience overrides sound boundaries without a documented reason.

---

# 22. Architecture Decision Process

Before introducing a new structure, determine:

```text
1. What responsibility does this code own?
2. Is the responsibility business-specific or generic?
3. Who consumes it?
4. What layer should own it?
5. What dependencies does it require?
6. Is the dependency direction valid?
7. Can an existing abstraction be reused?
8. Will this create a second source of truth?
9. Will this make future changes easier or harder?
10. Can the rule be enforced automatically?
```

When uncertain, prefer the simpler design that preserves clear ownership.

---

# 23. Definition of Done — Architecture

A frontend change is architecturally complete when:

```text
□ Responsibility has a clear owner.
□ The selected FSD layer is justified.
□ Dependency direction is valid.
□ Cross-slice boundaries are respected.
□ Public APIs are used instead of internal imports.
□ Business logic is not hidden in presentation code.
□ Shared code is genuinely generic.
□ State lives at the lowest reasonable ownership level.
□ API/data access has an explicit boundary.
□ Errors have a consistent handling strategy.
□ Security responsibilities are respected.
□ Accessibility requirements are considered.
□ Performance impact is reasonable.
□ Relevant tests exist.
□ Architecture checks pass.
□ No unrelated architectural refactor was introduced accidentally.
```

---

# 24. Agent Instructions

An AI agent implementing frontend work must not immediately start coding.

It must first:

### Step 1 — Inspect

Understand the existing project:

- framework;
- architecture;
- existing layers;
- conventions;
- reusable components;
- state strategy;
- API strategy;
- authentication strategy;
- testing strategy.

### Step 2 — Discover

Identify existing interfaces, entities, features, widgets, and patterns related to the requested work.

### Step 3 — Design

Before implementation, define:

```text
entities involved
features involved
widgets/pages involved
state ownership
API/data ownership
dependencies
reusable components required
```

### Step 4 — Validate the design

Check the design against this document.

Reject designs that violate dependency direction, ownership, or slice isolation without a documented architectural reason.

### Step 5 — Implement

Implement the smallest coherent architecture that satisfies the requirement.

Reuse existing project patterns wherever appropriate.

### Step 6 — Verify

Review:

```text
architecture
functionality
security
accessibility
performance
tests
consistency
```

### Step 7 — Summarize

The agent must report:

- what was implemented;
- architectural decisions;
- reused components/patterns;
- files/layers changed;
- tests/checks performed;
- known limitations or deviations from this standard.

---

# 25. Conflict Resolution

When requirements conflict, use this priority order:

```text
1. Security and data integrity
2. Correct business behavior
3. Accessibility
4. Architectural integrity
5. Maintainability
6. Performance
7. UX consistency
8. Visual preference
9. Convenience of implementation
```

A lower-priority concern must not silently override a higher-priority requirement.

When an exception is necessary, make the exception explicit and document the reason.

---

# 26. Final Principle

The purpose of this architecture is not to make the code look sophisticated.

The purpose is to make the system **predictable**.

A developer should be able to answer:

> Where does this code belong?
> Who owns it?
> Who is allowed to depend on it?
> What can I safely change?

And an AI agent should be able to answer the same questions before it modifies the application.


# 27. Form Architecture

Forms are interaction systems, not merely collections of inputs.

The implementation must make it obvious:

- what the user is editing;
- what is required;
- what is invalid;
- what will happen on submit;
- what has changed;
- how to recover from failure.

## 27.1 Ownership

A form belongs to the feature that performs the business action.

Examples:

```text
features/create-customer/
features/edit-customer/
features/change-password/
```

Entity models may provide reusable types, field metadata, formatting, and domain validation where appropriate, but the complete workflow belongs to the feature.

## 27.2 Validation

Validation should exist as close as possible to the business rule that owns it.

Use three distinct categories:

```text
UI validation
    formatting, required visual feedback, input constraints

Business validation
    rules that determine whether the operation is valid

Server validation
    authoritative backend constraints and conflicts
```

Do not assume client validation replaces server validation.

## 27.3 Form Rules

- Required fields must be obvious before submission.
- Validation feedback must be associated with the affected field.
- Avoid validating aggressively before the user has had a reasonable opportunity to complete the field.
- Preserve valid user input when an operation fails.
- Do not duplicate validation rules unnecessarily across components.
- Submission must have one clear primary action.
- Prevent accidental duplicate submissions while a mutation is in progress.
- Destructive operations require explicit intent.
- Unsaved changes must be handled deliberately when navigation can discard work.
- Disabled controls must not be used as the only explanation for why an action is unavailable; communicate the reason when useful.

## 27.4 Form State

Separate:

```text
initial value
current value
dirty state
validation state
submission state
server error state
```

Do not encode multiple states into one ambiguous boolean.

# 28. UX/UI Engineering Standard

The interface should be:

> minimal, premium, clear, information-dense, accessible, consistent, and effortless to operate.

Visual quality is not decoration. It is the result of good information architecture, hierarchy, interaction design, spacing, typography, and feedback.

## 28.1 UX before decoration

Before styling a screen, establish:

```text
Purpose
Primary user goal
Primary action
Secondary actions
Important information
Potential errors
Empty/loading states
Success state
Destructive actions
Navigation context
```

A visually attractive interface that makes the user's task difficult is considered a UX failure.

## 28.2 Information hierarchy

Every screen must have a clear hierarchy.

The user should be able to identify quickly:

1. Where they are.
2. What the screen is for.
3. What matters most.
4. What they can do.
5. What state the system is currently in.

Do not give every element equal visual weight.

## 28.3 Density

Prefer useful density over empty space.

For business applications:

- prioritize relevant information;
- avoid oversized cards and headings when they do not add value;
- group related information logically;
- use whitespace to establish hierarchy, not to hide lack of structure;
- provide powerful tables, filters, search, sorting, and shortcuts where the workflow benefits from them.

Dense does not mean crowded.

## 28.4 Consistency

The same interaction should behave the same way throughout the application.

Standardize:

```text
buttons
inputs
forms
modals
menus
notifications
badges
statuses
tables
pagination
filters
empty states
loading states
errors
```

Do not introduce a visually or behaviorally different component when an existing design-system component can satisfy the requirement.

## 28.5 Reusable design system

Reusable UI primitives belong in `shared/ui`.

Examples:

```text
Button
Input
Select
Dialog
Drawer
Tooltip
Badge
Tabs
DataTable
Pagination
CommandMenu
Toast
Skeleton
EmptyState
```

Business-specific compositions must remain in the appropriate business layer.

The design system should define semantic variants rather than arbitrary per-screen styling.

Prefer:

```text
variant="danger"
size="compact"
```

over ad-hoc styling that visually recreates an existing variant.

## 28.6 Color

Color must communicate meaning before decoration.

Use a restrained semantic palette for concepts such as:

```text
primary action
success
warning
error/destructive
informational
neutral
```

Do not use color as the sole carrier of meaning. Pair important states with text, icons, shape, or other accessible cues.

## 28.7 Typography

Typography must establish hierarchy through a controlled scale.

Avoid:

- excessive font sizes;
- many font weights with no semantic reason;
- inconsistent text styles for equivalent concepts;
- low-contrast secondary text that becomes difficult to read.

## 28.8 Interaction feedback

Every meaningful user action should have an understandable system response.

Examples:

```text
loading
success
failure
validation
progress
confirmation
```

Do not leave users guessing whether an action succeeded.

Feedback should be proportional to the importance of the action.

## 28.9 Loading states

Loading states must preserve layout stability whenever practical.

Prefer contextual skeletons or progressive loading for complex areas over replacing the entire application with a generic spinner.

Avoid blocking the whole page for data that does not prevent the user from interacting with unrelated content.

## 28.10 Empty states

An empty state must distinguish between:

```text
no data exists
no results match filters
user has no permission
system has not loaded yet
configuration is incomplete
```

Each case should provide the most useful next action when one exists.

## 28.11 Error states

Errors must answer, as applicable:

```text
What happened?
What was affected?
Can the user retry?
Can the user fix it?
What should they do next?
```

Do not expose raw technical errors to users unless the context explicitly requires diagnostic information.

## 28.12 Accessibility

Accessibility is a structural requirement, not a finishing step.

At minimum:

- all interactive elements must be keyboard accessible;
- focus must be visible and intentional;
- labels must be programmatically associated with controls;
- semantic HTML should be preferred over unnecessary ARIA;
- dialogs, menus, tabs, and other composite controls must have correct keyboard behavior;
- contrast must support readable text and state communication;
- status changes must be perceivable without relying only on color;
- icon-only actions require accessible names;
- touch/click targets must be practical to operate.

Do not use accessibility features as decorative metadata; they must reflect actual interaction semantics.

## 28.13 Responsive behavior

Responsiveness must preserve task completion, not merely resize elements.

For every important screen define behavior for at least:

```text
wide desktop
standard desktop/tablet
small mobile
```

When space becomes constrained, decide deliberately what:

```text
wraps
stacks
collapses
moves
becomes scrollable
becomes a menu
```

Do not allow responsive behavior to emerge accidentally from CSS alone.

## 28.14 Tables and business interfaces

For data-heavy applications, tables are first-class workflows.

When relevant, support:

```text
search
filtering
sorting
pagination
column visibility
column ordering
resizing
selection
bulk actions
row actions
keyboard interaction
empty/loading/error states
```

Do not overload tables with every possible action. Prioritize the user's most common operations and provide secondary actions through deliberate affordances.

# 29. UX/UI Anti-Patterns

The agent must actively reject:

- decoration without function;
- excessive gradients, shadows, colors, or animation;
- giant cards that reduce information density;
- inconsistent components for identical interactions;
- unclear primary actions;
- hidden destructive actions without appropriate confirmation;
- inaccessible custom controls;
- permanent spinners where contextual loading is possible;
- empty states that provide no useful direction;
- raw backend errors shown directly to users;
- mobile layouts that technically fit but are difficult to operate;
- introducing a new design pattern when the existing design system already solves the problem.

# 30. Interaction Design Rules

The interface must minimize unnecessary cognitive and operational cost.

## 30.1 Progressive disclosure

Show what is necessary first. Reveal advanced options when they are needed.

Do not force expert-only configuration into the default path.

## 30.2 Shortcuts

When a workflow is frequent or data-heavy, keyboard shortcuts may be provided.

Shortcuts must:

- be discoverable;
- avoid conflicting with browser/OS conventions without strong reason;
- never be the only way to perform an action;
- expose their availability consistently.

## 30.3 Destructive actions

Destructive actions must be visually distinguishable and require enough confirmation to prevent accidental execution.

Confirmation should communicate the consequence rather than merely asking an empty “Are you sure?”.

## 30.4 Optimistic interactions

Optimistic UI may be used only where failure can be handled safely and the user can understand the resulting state.

Do not use optimistic updates for operations where temporary inconsistency could cause material data confusion or destructive consequences.

# 31. Visual Verification Standard

An agent must not consider a frontend task complete merely because the code compiles.

For UI work, verification should include:

```text
structure
spacing
hierarchy
typography
responsive behavior
interaction states
loading states
empty states
error states
accessibility
consistency with existing screens
```

Where visual tooling is available, the agent should inspect the rendered result rather than relying only on source code.

# 32. UI Definition of Done

A frontend screen or component is not complete until:

```text
□ Primary task is immediately understandable.
□ Information hierarchy is clear.
□ Existing design-system primitives are reused.
□ Interaction states are implemented.
□ Loading state is intentional.
□ Empty state is intentional.
□ Error state is intentional.
□ Keyboard interaction works where applicable.
□ Accessibility semantics are correct.
□ Responsive behavior is deliberate.
□ Destructive actions are protected.
□ No unnecessary visual complexity was introduced.
□ The implementation matches existing application conventions.
```


---

# 33. Security and Performance Quality Gate

For every feature that introduces authentication, sensitive data, uploads, external content, large datasets, or performance-sensitive behavior, the implementation review must explicitly answer:

```text
SECURITY
□ Trust boundaries identified.
□ Backend remains the authorization authority.
□ No unsafe rendering/navigation path introduced.
□ Sensitive data exposure considered.
□ Authentication/session/CSRF behavior follows the established architecture.
□ Third-party resources and dependencies are justified.

PERFORMANCE
□ Critical path identified.
□ Network behavior understood.
□ Duplicate work eliminated.
□ Large data/rendering behavior considered.
□ Heavy code is deferred when appropriate.
□ User-visible responsiveness considered.
```

A feature that works functionally but violates a mandatory security rule is **not complete**.

A feature that works functionally but introduces avoidable, material performance degradation is **not complete**.

## Reference Baselines

Security guidance is aligned conceptually with OWASP's maintained guidance, especially its XSS prevention, CSRF prevention, CSP, and application-security verification materials. Project-specific security requirements must still be reviewed against the application's actual threat model and backend architecture.


# 34. Code Quality and Maintainability Standard

Code quality is not measured by how abstract, clever, or short the implementation looks.

The standard is:

> **Readable code with explicit responsibility, predictable behavior, strong typing, low coupling, and a cost of change that remains reasonable as the application grows.**

The code must be understandable by another competent engineer without requiring the author to explain its intent verbally.

---

## 34.1 Clarity over cleverness

Prefer the implementation that is easiest to understand and verify.

Do not use:

- clever one-liners when explicit code is clearer;
- unnecessary metaprogramming;
- opaque abstractions;
- excessive functional composition when it hides business intent;
- deeply nested control flow;
- magic behavior that depends on undocumented conventions.

A shorter implementation is not automatically a better implementation.

### Rule

> Optimize for **semantic clarity**, not minimum line count.

---

## 34.2 Single responsibility

Each module, component, function, service, store, and class must have a coherent responsibility.

A unit should not simultaneously become:

```text
UI rendering
+ API communication
+ domain rules
+ form orchestration
+ transformation
+ notification logic
+ analytics
```

When responsibilities naturally belong to different architectural owners, separate them.

Do not split code mechanically into dozens of tiny files merely to satisfy an abstract definition of single responsibility.

The goal is **coherent boundaries**, not fragmentation.

---

## 34.3 Component responsibility

Components should focus on rendering, interaction, and composition appropriate to their architectural layer.

A component should not become a hidden application service.

Prefer:

```text
Component
   ↓
Feature / Entity / Application model
   ↓
API / state / domain capability
```

over:

```text
Component
   ├── HTTP calls
   ├── transformation logic
   ├── authorization decisions
   ├── complex business rules
   └── unrelated state orchestration
```

Large components are not automatically bad, but a component with multiple unrelated responsibilities is an architectural warning sign.

---

## 34.4 Function and method design

Functions should have:

- one coherent purpose;
- explicit inputs;
- predictable outputs;
- minimal hidden side effects;
- names that communicate intent.

Prefer early returns over deeply nested branches where they improve readability.

Avoid boolean-heavy APIs when an explicit type or options object communicates intent more clearly.

Example:

```ts
createReport({
  includeArchived: true,
  format: 'pdf',
})
```

is generally clearer than:

```ts
createReport(true, false, true)
```

Do not introduce an abstraction solely because a function has reached an arbitrary line count. Complexity and responsibility matter more than raw size.

---

## 34.5 Naming standard

Names must communicate **meaning and ownership**.

Prefer:

```text
CreateOrderForm
OrderStatusBadge
loadCustomerOrders
customerQuery
isSubmitting
```

over vague names such as:

```text
Data
Manager
Helper
Handler
Processor
Thing
Utils
Temp
Common
```

unless the generic meaning is genuinely correct in context.

### Naming rules

- Use domain terminology consistently.
- Do not invent multiple names for the same business concept.
- Avoid abbreviations unless they are established project terminology.
- Boolean names should read naturally as predicates where practical: `isLoading`, `hasPermission`, `canEdit`.
- Event and command names should describe the action: `deleteOrder`, `submitForm`, `refreshOrders`.
- Types should describe data or concepts, not implementation accidents.

A naming change is an architectural decision when terminology represents a business concept. Do not casually rename domain concepts for stylistic preference.

---

## 34.6 TypeScript strictness

When using TypeScript, the project should use strict type checking unless a documented project constraint prevents it.

Avoid:

- `any` as an escape hatch;
- broad type assertions used to silence compiler errors;
- non-null assertions without a proven invariant;
- duplicate type definitions for the same concept;
- untyped API boundaries.

Prefer:

- explicit domain and DTO types;
- discriminated unions for state machines where appropriate;
- narrow types;
- type guards when data is genuinely uncertain;
- typed API clients;
- exhaustive handling of known variants.

### Escape-hatch rule

If `any`, `as`, `!`, or another type escape is necessary, the implementation should have a concrete reason. Do not use type escapes merely to make the compiler stop complaining.

---

## 34.7 Null, undefined, and optionality

Optionality must represent real uncertainty, not convenience.

Do not use nullable or optional values everywhere “just in case”.

Model states explicitly when multiple states have different behavior.

For example, avoid ambiguous combinations such as:

```ts
loading: boolean
error?: Error
data?: Order[]
```

when the application would benefit from a discriminated state model such as:

```ts
type QueryState<T> =
  | { status: 'loading' }
  | { status: 'error'; error: Error }
  | { status: 'success'; data: T }
```

The exact pattern should follow the project's state-management conventions.

---

## 34.8 Constants and magic values

Business-significant values must not be scattered throughout the code.

Avoid:

```ts
if (status === 7) { ... }
```

Prefer a named domain value:

```ts
if (status === OrderStatus.Cancelled) { ... }
```

Do not extract every string or number into a constant merely because it exists more than once. Extract values when the name provides semantic meaning, central control, or prevents meaningful inconsistency.

---

## 34.9 Comments and documentation

Code should be self-explanatory whenever possible.

Comments should explain **why**, not repeat **what** the code already says.

Bad:

```ts
// Increment i
 i++
```

Useful:

```ts
// API ordering is intentionally preserved because the export format depends on it.
```

Document:

- non-obvious architectural decisions;
- important invariants;
- security-sensitive assumptions;
- integration constraints;
- surprising browser/framework behavior;
- public APIs where usage or constraints are not obvious.

Do not write long comments to justify avoidable complexity. Simplify the code when possible.

---

## 34.10 Error handling

Errors must be handled at the layer that has enough context to make the correct decision.

Do not:

- silently swallow errors;
- catch an error merely to rethrow the same error;
- display raw technical errors to users;
- duplicate identical error mapping in many components.

Error handling should distinguish between:

```text
Expected business/application errors
Recoverable operational failures
Validation failures
Authentication/authorization failures
Unexpected programming/system errors
```

User-facing messaging must be safe, understandable, and appropriate to the context.

Unexpected errors should remain observable for debugging through the application's established logging/monitoring mechanism.

---

## 34.11 Side effects

Side effects should be visible from the abstraction that owns them.

Avoid hidden side effects inside:

- pure formatting helpers;
- selectors;
- computed/derived values;
- data transformation functions;
- generic utility functions.

Prefer explicit boundaries for:

- network requests;
- storage access;
- browser APIs;
- navigation;
- analytics/events;
- timers;
- subscriptions.

This improves testability and makes agent-generated code easier to reason about.

---

## 34.12 Reactive and asynchronous code

When using reactive programming, streams, signals, observables, promises, or equivalent mechanisms:

- define ownership of subscriptions/effects;
- clean up resources according to framework conventions;
- avoid unnecessary nested asynchronous flows;
- avoid duplicate subscriptions that perform the same work;
- prefer declarative composition when it remains understandable;
- keep side effects at explicit boundaries.

Do not introduce a reactive abstraction merely because the framework supports it. Use the project's established model consistently.

---

## 34.13 Dependency discipline

Dependencies should be intentional.

Before adding a package, evaluate:

```text
Does the project already solve this?
Does the framework already provide this?
Can the requirement be solved simply without a dependency?
Is the dependency maintained and appropriate?
Does it introduce meaningful security, bundle-size, licensing, or maintenance cost?
```

Do not add a dependency for trivial functionality that can be implemented clearly with existing capabilities.

Do not duplicate libraries that solve the same category of problem without a strong architectural reason.

---

## 34.14 Dependency direction is enforceable

Architectural rules should be checked automatically where practical.

Examples include:

- lint rules;
- import-boundary rules;
- TypeScript path conventions;
- architecture tests;
- CI checks.

A rule that exists only in documentation is weaker than a rule enforced by tooling.

The agent should prefer creating or updating automated checks when introducing a structural rule that can be machine-verified.

---

## 34.15 Duplication

Duplication must be evaluated by **meaning**, not by text alone.

Two pieces of code that look similar may intentionally evolve differently.

Do not create a generalized abstraction simply because two implementations currently resemble each other.

Refactor duplication when:

- behavior is genuinely identical;
- the same rule must remain synchronized;
- reuse is conceptually correct;
- the abstraction improves rather than obscures the design.

Prefer **semantic reuse** over forced code reuse.

---

## 34.16 Refactoring policy

Refactoring should preserve behavior unless the task explicitly changes behavior.

Before a significant refactor, the agent should understand:

```text
What is changing?
Why is it changing?
What behavior must remain unchanged?
What dependencies are affected?
How will correctness be verified?
```

Avoid opportunistic “cleanup” that expands the task substantially.

When the existing structure actively prevents the requested feature from being implemented safely, a focused refactor is justified and should be documented.

---

## 34.17 Dead code and stale code

Do not leave behind:

- unreachable branches;
- unused exports;
- abandoned components;
- duplicate implementations;
- commented-out legacy code;
- stale imports;
- obsolete configuration.

Delete code when its removal is safe and within the task's scope.

Do not preserve dead code merely because it might be useful someday. Version control is the recovery mechanism.

---

## 34.18 Generated and repetitive code

Generated code should be treated according to its source of truth.

Do not manually edit generated files when regeneration is the correct workflow.

Repeated structures should be generated or templated when repetition is deterministic and error-prone, but generators must not hide business decisions that developers need to understand.

---

## 34.19 Accessibility is part of code quality

Accessibility failures are implementation defects, not cosmetic issues.

Components must use:

- semantic HTML where appropriate;
- accessible names;
- correct labels and descriptions;
- keyboard-accessible interaction;
- meaningful focus behavior;
- correct states and ARIA only where required.

Do not use ARIA as a substitute for appropriate semantic HTML when native semantics already solve the problem.

---

## 34.20 Performance is part of code quality

Avoid abstractions and patterns that create unnecessary runtime cost.

Common review targets include:

- unnecessary change detection/re-rendering;
- repeated expensive computation;
- duplicate network requests;
- excessive DOM nodes;
- large bundles caused by avoidable imports;
- eager loading of rarely used features;
- unbounded lists or uncontrolled rendering of large datasets.

Performance optimizations must be justified by actual cost, expected scale, or a clearly performance-sensitive path. Do not complicate ordinary code for theoretical micro-optimizations.

---

# 35. Frontend Code Review Standard

Every meaningful code change should be reviewed against the following dimensions:

```text
ARCHITECTURE
□ Correct FSD layer and ownership.
□ Dependency direction preserved.
□ Public APIs respected.
□ No accidental coupling introduced.

CORRECTNESS
□ Requirements are satisfied.
□ Edge cases are handled.
□ Error behavior is intentional.
□ Existing behavior is preserved where required.

CODE QUALITY
□ Names communicate intent.
□ Responsibilities are coherent.
□ Types are strong and meaningful.
□ No unnecessary abstraction was introduced.
□ Duplication decisions are intentional.
□ Dead code was not introduced.

SECURITY
□ Trust boundaries remain intact.
□ Authorization is not weakened.
□ Unsafe data handling is not introduced.
□ Sensitive information is not unnecessarily exposed.

PERFORMANCE
□ No obvious duplicate work.
□ Data fetching is intentional.
□ Rendering cost is reasonable for expected scale.
□ Heavy functionality is loaded intentionally.

UX/UI
□ Existing design patterns are reused.
□ Loading, empty, error, and success states are handled.
□ Accessibility requirements are respected.
□ Responsive behavior is intentional.

TESTABILITY
□ Important behavior is covered appropriately.
□ Logic is testable at the correct boundary.
□ Tests assert behavior rather than implementation trivia.
```

---

# 36. Code Quality Anti-Patterns

The following are strong warning signs and require deliberate justification:

- giant components owning unrelated concerns;
- “god” services or stores that own an entire application area;
- generic `utils` or `helpers` folders becoming business-logic dumping grounds;
- `any` spreading through the application;
- repeated type assertions masking bad boundaries;
- duplicated API logic;
- components directly manipulating global state without ownership;
- API calls scattered across arbitrary UI components;
- business rules embedded in templates;
- deeply nested conditional rendering that hides workflow logic;
- copy-pasted versions of the same business capability;
- abstractions with a single trivial consumer and no clear isolation value;
- catch-and-ignore error handling;
- comments containing disabled legacy code;
- architectural violations hidden behind aliases or barrel files;
- adding a library where existing project capabilities are sufficient.

An anti-pattern is not automatically forbidden in every context. The standard is to recognize it, assess the trade-off, and require a concrete reason when it is retained.

---

# 37. Maintainability Principles

The codebase should remain understandable after repeated modification by different developers and AI agents.

### 37.1 Predictability

A developer should be able to predict where a new piece of functionality belongs by looking at similar existing functionality.

### 37.2 Local reasoning

A developer should be able to understand most behavior by examining the owning slice and its declared dependencies rather than searching the entire application.

### 37.3 Explicitness

Important behavior should be visible through naming, types, architecture, and explicit control flow rather than hidden in conventions that are difficult to discover.

### 37.4 Consistency

Once a project chooses a sound pattern, new code should follow it unless there is a strong reason to evolve the pattern.

### 37.5 Evolvability

Architecture should make common changes cheap and understandable.

Optimize especially for changes that are expected to happen repeatedly:

```text
adding a feature
adding a screen
adding a business field
changing an API contract
changing a reusable UI pattern
adding permissions
adding a new state/flow
```

---

# 38. Definition of Done — Code Quality

A frontend implementation is not considered complete when it merely works on the happy path.

It is complete when:

```text
□ Responsibility and ownership are clear.
□ FSD boundaries are respected.
□ Dependency direction is preserved.
□ Naming and terminology are consistent.
□ Types accurately model important states.
□ Side effects have explicit ownership.
□ Error handling is intentional.
□ Accessibility is considered.
□ Performance implications are considered.
□ No unnecessary abstraction was introduced.
□ No avoidable duplication or dead code was introduced.
□ Relevant tests or verification exist.
□ Automated architecture/lint/type checks pass where configured.
□ The implementation follows existing project conventions.
□ Another engineer can reasonably understand the implementation without private knowledge from the author.
```

---

# 39. Agent Rule — Quality Before Completion

An AI agent must not interpret:

```text
"The feature works"
```

as equivalent to:

```text
"The task is complete"
```

Before declaring completion, the agent must review its own implementation for:

```text
architecture
correctness
security
performance
accessibility
maintainability
consistency
testability
```

If a quality issue was introduced by the implementation, the agent should fix it before completion when the fix is within scope.

If the issue is outside scope but materially affects the solution, the agent must explicitly report it rather than silently ignoring it.

The agent should prefer **a slightly smaller, clean implementation** over a larger implementation that technically satisfies the request while violating established engineering standards.


# 40. Testing Philosophy

Testing is not a final activity performed after implementation. Testing is part of the architecture and development process.

The objective is not maximum test count. The objective is **high confidence at the right boundaries** with tests that remain useful as the code evolves.

Testing decisions must optimize for:

- confidence;
- meaningful coverage;
- fast feedback;
- stable tests;
- clear failure diagnosis;
- protection of important business behavior.

Tests should primarily verify **observable behavior and contracts**, not implementation trivia.

---

# 41. Testing Pyramid and Test Selection

Use the smallest effective test that gives sufficient confidence.

Conceptually:

```text
          E2E
        /      \\
   Integration  
      /      \\
    Unit / component
```

Prefer lower-level tests for deterministic local behavior and higher-level tests for critical cross-boundary workflows.

### 41.1 Unit tests

Use unit tests for:

- pure functions;
- formatting/parsing rules;
- deterministic business calculations;
- complex transformations;
- state transitions;
- validation logic where behavior is substantial;
- isolated utilities with meaningful logic.

Do not create trivial tests whose only purpose is to increase coverage percentage.

### 41.2 Component tests

Use component-level tests for behavior such as:

- user interaction;
- rendered states;
- validation feedback;
- conditional UI;
- emitted events or commands;
- accessibility-critical interaction;
- important keyboard behavior.

Do not couple tests to incidental DOM structure when a semantic interaction or user-visible behavior can be asserted instead.

### 41.3 Integration tests

Use integration tests when multiple parts must cooperate correctly:

- component + feature state;
- feature + API/data layer;
- routing + guards;
- data loading + rendering states;
- mutation + cache invalidation;
- permission-aware UI flows.

Integration tests should protect architectural contracts that unit tests cannot reasonably verify.

### 41.4 End-to-end tests

Use E2E tests for important business workflows where failure would materially affect users.

Examples:

```text
login
create entity
edit entity
complete critical workflow
permission-sensitive action
search/filter/paginate a business dataset
```

Do not attempt to cover every component with E2E tests. E2E is for workflow confidence, not exhaustive implementation coverage.

---

# 42. What Must Be Tested

The agent should identify the risk profile of each change before choosing tests.

At minimum, evaluate:

```text
□ Happy path
□ Important edge cases
□ Validation failures
□ Loading behavior
□ Empty behavior
□ Error behavior
□ Permission/authorization behavior where applicable
□ State transitions
□ Data transformation boundaries
□ User-critical interactions
```

For a simple presentational change, this may require little or no additional automated test code.

For a business action, data mutation, permission boundary, or complex state flow, meaningful automated verification is expected.

---

# 43. Test Behavior, Not Implementation

Tests should answer:

> Does the software behave correctly?

Not:

> Did the developer write the code in this exact way?

Prefer assertions about:

- visible states;
- returned values;
- emitted events/commands;
- navigation outcomes;
- API contracts;
- state transitions;
- user-observable results.

Avoid unnecessary assertions about:

- private methods;
- internal variable names;
- exact component nesting;
- incidental CSS classes;
- implementation-specific call counts unless the count is part of the contract.

A refactor that preserves behavior should not cause large numbers of tests to fail.

---

# 44. Test Ownership and Location

Tests should live near the code or workflow they protect, following the project's established conventions.

The location must make ownership obvious.

Examples:

```text
entities/product/
  ui/
  model/
  api/
  product.spec.ts

features/create-product/
  ui/
  model/
  api/
  create-product.spec.ts
```

Do not create a giant unrelated test directory that obscures ownership unless the framework or repository convention intentionally requires it.

---

# 45. Mocking Standard

Mocking exists to isolate a test boundary, not to recreate the entire implementation inside the test.

### Rules

- Mock external systems when direct access would make the test slow, unstable, destructive, or environment-dependent.
- Keep mocks minimal.
- Prefer realistic contracts over large hand-written mock object graphs.
- Do not mock the unit under test.
- Do not mock internal implementation details merely to make a test easier.
- Avoid mocks that silently diverge from the real API/data contract.

When possible, use test fixtures/builders that produce valid domain-shaped data.

---

# 46. Test Data Standard

Test data should be:

- deterministic;
- readable;
- minimal for the scenario;
- representative of real states;
- safe and non-sensitive.

Prefer named factories/builders for repeated complex structures.

Bad:

```ts
const data = { a: 1, b: false, c: 'x', d: null };
```

Better:

```ts
const product = buildProduct({
  status: 'active',
  stock: 12,
});
```

Tests should make important scenario assumptions obvious.

---

# 47. Edge Case Standard

The agent must look beyond the happy path whenever the feature has meaningful state or data complexity.

Evaluate cases such as:

```text
empty collection
single item
large collection
missing optional value
invalid input
slow request
failed request
partial data
expired/invalid session
insufficient permission
duplicate action
rapid repeated action
boundary numeric/date values
```

Not every case needs a test in every feature. The agent must use risk and business impact to determine which cases matter.

---

# 48. Loading, Empty, Error and Success Verification

Data-driven UI must not be considered complete if it only works when data arrives successfully.

Where applicable, verify:

```text
initial/loading
empty
success
error
retry/recovery
partial/disabled states
```

These states should remain visually coherent and functionally usable.

Tests should cover state transitions when they contain meaningful behavior.

---

# 49. Form Testing

Forms with meaningful behavior should verify:

```text
□ Required-field behavior
□ Invalid input
□ Valid input
□ Submission behavior
□ Submission failure
□ Submission success
□ Disabled/submitting state
□ Important field dependencies
□ Keyboard/accessibility behavior where relevant
```

Do not test every framework validator implementation. Test the application's validation behavior and user-facing contract.

---

# 50. API and Data Contract Testing

When frontend code depends on an API contract, tests should protect assumptions that are important to correctness.

Verify where appropriate:

- request shape;
- response interpretation;
- error mapping;
- pagination behavior;
- sorting/filtering behavior;
- mutation outcomes;
- cache invalidation or refresh behavior.

Do not duplicate the backend test suite in the frontend.

The frontend should verify **its interpretation and usage of the contract**.

---

# 51. Architecture Tests

Architecture rules that can be checked mechanically should be checked mechanically.

Examples:

```text
□ Forbidden imports between FSD layers
□ Forbidden cross-slice internal imports
□ Shared code does not depend on business entities
□ Feature boundaries remain intact
□ Circular dependencies are absent
□ Public APIs are respected
```

Architecture tests are especially valuable because AI agents can accidentally violate boundaries while still producing functionally correct software.

Prefer automated enforcement over relying solely on documentation.

---

# 52. Accessibility Testing

Accessibility is part of functional quality.

Where tooling exists, automated accessibility checks should be included for important components and flows.

Automated checks do not replace manual reasoning.

Verify as appropriate:

- accessible names;
- labels/descriptions;
- keyboard navigation;
- focus behavior;
- semantic structure;
- state announcements;
- contrast and visual clarity;
- dialog/menu/table interaction.

Critical keyboard and focus workflows should be manually or E2E verified when automation alone is insufficient.

---

# 53. Responsive Testing

Responsive behavior should be tested according to supported breakpoints and interaction modes.

The agent should consider:

```text
□ desktop
□ tablet where supported
□ mobile where supported
□ narrow content
□ long text
□ localization expansion
□ dense data layouts
```

A layout that is technically responsive but unusable at an important viewport is not complete.

---

# 54. Visual Verification

For meaningful UI work, visual verification is expected before completion when the project provides practical tooling for it.

Compare the implementation against:

- established design-system patterns;
- surrounding screens;
- expected spacing and hierarchy;
- states and responsive behavior.

For visual changes, screenshot-based inspection is preferred over trusting code alone.

Do not treat a page as correct merely because it compiles.

---

# 55. Test Quality Rules

A good test should be:

- deterministic;
- readable;
- focused;
- reasonably fast;
- independently diagnosable;
- resilient to unrelated refactoring;
- explicit about its scenario.

Avoid:

- arbitrary sleeps;
- hidden global state;
- order-dependent tests;
- giant setup blocks unrelated to the scenario;
- assertions with unclear intent;
- brittle selectors;
- snapshot-heavy testing without a strong reason;
- duplicated test logic that obscures the behavior being verified.

---

# 56. Coverage Philosophy

Coverage percentage is a diagnostic signal, not the definition of quality.

High coverage does not prove that important behavior is correctly tested.

Prioritize coverage of:

```text
business rules
state transitions
critical user workflows
permission boundaries
API interpretation
error/recovery paths
complex transformations
```

Do not distort production code merely to improve an arbitrary coverage number.

---

# 57. Regression Protection

When fixing a bug, prefer to create a regression test when the bug represents behavior that could plausibly return.

The preferred workflow is:

```text
bug
 ↓
reproduce
 ↓
write/adjust failing test
 ↓
fix
 ↓
verify test
 ↓
run relevant regression suite
```

This is particularly important for:

- business logic;
- permission logic;
- state bugs;
- API mapping bugs;
- date/currency/number handling;
- complex UI interactions.

---

# 58. Quality Gates

A change must pass all applicable quality gates before being considered complete.

```text
GATE 1 — Architecture
□ FSD boundaries respected
□ No forbidden dependencies
□ Ownership is clear

GATE 2 — Type Safety
□ TypeScript/type checks pass
□ No unjustified `any`
□ Important states are represented correctly

GATE 3 — Static Quality
□ Lint passes
□ Formatting passes where configured
□ No new obvious dead code

GATE 4 — Automated Tests
□ Relevant unit/component/integration tests pass
□ New meaningful behavior has appropriate coverage
□ Regression tests added when warranted

GATE 5 — UI/UX
□ Loading/empty/error/success states addressed
□ Accessibility reviewed
□ Responsive behavior reviewed
□ Existing design patterns respected

GATE 6 — Security
□ Authentication/authorization assumptions preserved
□ Unsafe data handling not introduced
□ No sensitive data leakage

GATE 7 — Performance
□ No obvious duplicate network work
□ Rendering/data handling appropriate for expected scale
□ No unnecessary heavy dependency or loading introduced

GATE 8 — Final Review
□ Requirements satisfied
□ Scope respected
□ Implementation understandable
□ No known material defect silently ignored
```

---

# 59. Definition of Done — Testing

A task is test-complete when:

```text
□ The relevant risk has been identified.
□ The appropriate test level has been selected.
□ Important behavior is verified.
□ Important failure and edge states are considered.
□ Existing relevant tests pass.
□ New tests are stable and meaningful when needed.
□ Architecture checks pass where applicable.
□ UI changes have been visually reviewed where practical.
□ Accessibility has been considered.
□ The final implementation passes applicable quality gates.
```

A task must not be marked complete solely because:

```text
□ the code compiles;
□ the page opens;
□ the happy path works;
□ a manual click-through succeeded once.
```

---

# 60. Agent Testing Workflow

The AI agent must treat testing as an explicit phase of implementation.

Preferred workflow:

```text
Understand requirement
      ↓
Inspect existing patterns and tests
      ↓
Identify risk and affected boundaries
      ↓
Implement smallest correct change
      ↓
Add/update relevant tests
      ↓
Run targeted checks
      ↓
Run broader checks when appropriate
      ↓
Inspect failures
      ↓
Fix root causes
      ↓
Review architecture / UX / security / performance
      ↓
Run final quality gates
      ↓
Report what was verified
```

The agent should not:

- skip tests simply because the change appears small;
- claim tests passed without actually running them;
- claim visual verification without inspecting the result when visual review is required;
- hide failed or unavailable checks;
- weaken or delete tests merely to make the suite pass;
- modify production behavior solely to satisfy a brittle test when the test is the wrong abstraction.

---

# 61. Agent Verification Reporting

When completing a task, the agent should provide a concise verification summary containing:

```text
IMPLEMENTED
What changed.

VERIFIED
What checks/tests were actually run.

NOT VERIFIED
Checks that could not be run and why.

KNOWN ISSUES
Material remaining limitations or unrelated failures encountered.
```

The agent must distinguish:

```text
passed
not run
blocked
failed
```

These states must never be conflated.

---

# 62. Agent Trust Rule

The agent must never manufacture confidence.

It must not claim:

```text
"all tests pass"
"security is verified"
"the UI is responsive"
"the architecture is correct"
```

unless the corresponding evidence exists.

When a check cannot be performed, the agent must state that it was not performed.

Evidence is preferable to assertion.

---

# 63. Final Quality Standard

The frontend engineering standard is satisfied only when the implementation is:

```text
ARCHITECTURALLY SOUND
        +
FUNCTIONALLY CORRECT
        +
SECURE
        +
PERFORMANT
        +
ACCESSIBLE
        +
MAINTAINABLE
        +
TESTED AT THE RIGHT BOUNDARIES
        +
CONSISTENT WITH THE EXISTING SYSTEM
```

A professional frontend is not merely code that works.

It is code that **works, fits, survives change, and can be trusted**.


---

# 64. Internationalization Philosophy

Internationalization (i18n) is an architectural concern, not a final translation step.

The frontend must be designed so that changing language, text length, writing direction, date conventions, number formats, and locale does not require restructuring the interface.

The baseline multilingual model is:

```text
language
locale
writing direction
formatting rules
translations
content
```

These concerns must remain explicit and separately manageable.

The default language must never be baked into component logic when the application is intended to support multiple languages.

---

# 65. Translation Rules

All user-facing text must come from the application's localization mechanism when i18n is enabled.

Avoid hard-coded UI text such as:

```ts
label = 'Save';
```

Prefer a translation key owned by the appropriate feature/page/entity or shared design system when genuinely generic.

### Rules

- Translation keys must be stable and descriptive.
- Translation keys should describe meaning, not current wording.
- Do not use raw translated text as a programmatic identifier.
- Do not concatenate sentence fragments to construct natural-language sentences.
- Prefer complete translation messages with interpolation parameters.
- Pluralization must use the localization system rather than manual conditionals when supported.
- User-entered data must never be translated as though it were application copy.
- Developer-facing logs may remain in a controlled technical language unless product requirements state otherwise.
- Validation and error messages visible to users must be localized.
- Accessibility labels, tooltips, aria text, and empty states are user-facing content and must be localized.

### Anti-pattern

```text
'You have ' + count + ' orders'
```

This can break grammar, pluralization, word order, and translation.

Prefer one localized message with a `count` parameter.

---

# 66. Translation-Key Ownership

Translation keys must have ownership.

Preferred organization:

```text
common.actions.save
common.actions.cancel

users.list.title
users.list.empty
users.form.email.required

orders.list.title
orders.filters.status
```

### Rules

- Generic concepts belong to shared/common namespaces.
- Feature-specific messages belong to the feature.
- Entity-specific terminology belongs to the entity when appropriate.
- Page composition must not become the owner of every string used by its descendants.
- Avoid one giant global translation namespace with no domain structure.
- Avoid duplicate keys for identical concepts unless the wording or context intentionally differs.

A translation system should remain navigable as the application grows.

---

# 67. Missing Translation Policy

Missing translations must be detectable.

The application must define a predictable fallback policy, such as:

```text
requested locale
      ↓
configured fallback locale
      ↓
visible missing-key handling
```

The system must not silently display broken UI such as raw internal keys in production unless that behavior is an intentional diagnostic mechanism.

During development and CI, missing translation keys should be surfaced as failures or explicit warnings according to project policy.

The agent must not invent translations for legally, financially, medically, or operationally sensitive wording without appropriate source material.

---

# 68. Locale and Formatting Standard

Never manually format locale-sensitive values with ad-hoc string manipulation.

This includes:

- dates;
- times;
- numbers;
- decimal separators;
- thousands separators;
- currencies;
- percentages;
- measurement units;
- relative dates/times.

Use the application's locale-aware formatting utilities or the platform's internationalization APIs.

### Rules

```text
Raw domain value
      ↓
formatting boundary
      ↓
localized presentation
```

Domain state should store semantic values, not presentation strings, whenever practical.

For example:

```text
amount: 1250.5
currency: TND
```

is preferable to:

```text
amount: "1 250,500 TND"
```

for application state.

The agent must consider timezone explicitly for timestamps. A timestamp must not be displayed using implicit browser-local assumptions when the product has a defined business timezone.

---

# 69. Language Metadata and Document Semantics

The active human language must be programmatically identifiable.

For web applications, the document should expose the appropriate `lang` information and update it when the active document language changes. This supports user agents and assistive technologies and aligns with WCAG's language requirements. citeturn750170search0turn750170search11

When different passages use a different human language, the implementation should mark the affected content appropriately rather than assuming the page language applies to every phrase. citeturn750170search11turn750170search12

The agent must treat language metadata as part of the interface contract, not optional decoration.

---

# 70. RTL / Bidirectional Text Standard

RTL support must be designed at the layout-system level, not patched page by page.

When the active writing direction is RTL, the application must establish the base direction using semantic direction metadata. For web applications, `dir="rtl"` on the document root is the standard baseline for an RTL document. citeturn750170search3turn750170search5

### Rules

- Use logical CSS properties whenever practical:
  - `margin-inline-start/end`
  - `padding-inline-start/end`
  - `inset-inline-start/end`
  - `border-start-start-radius`, etc.
- Avoid unnecessary hard-coded `left`/`right` assumptions.
- Do not assume that language alone determines direction; direction is a property of the script/content context. citeturn750170search2
- Icons whose meaning depends on direction may need mirroring.
- Icons with direction-independent meaning must not be mirrored blindly.
- Tables, forms, navigation, menus, breadcrumbs, drawers, and pagination must be reviewed in both LTR and RTL.
- Numeric values, codes, email addresses, URLs, identifiers, and mixed-script content may require explicit bidi handling.
- Use directional markup where necessary to isolate opposite-direction text. citeturn750170search4turn750170search13

### RTL quality gate

A feature is not RTL-complete merely because the page technically switches direction.

The agent must verify:

```text
□ Layout order is correct
□ Alignment is correct
□ Icons behave correctly
□ Forms remain understandable
□ Tables remain usable
□ Numeric/code content remains readable
□ Overlays and drawers open from the intended side
□ Keyboard navigation remains coherent
```

---

# 71. Accessibility Baseline

Accessibility is part of the definition of a high-quality interface.

The project's baseline should target **WCAG 2.2** where applicable, with the exact conformance target documented by the product/project. W3C recommends using the latest WCAG 2 version and identifies WCAG 2.2 as the current version in its WCAG overview. citeturn750170search7turn750170search1

The goal is not to sprinkle ARIA attributes onto inaccessible components.

The preferred hierarchy is:

```text
semantic HTML
      ↓
correct native behavior
      ↓
keyboard support
      ↓
visible focus
      ↓
accessible names/descriptions
      ↓
ARIA only when needed
```

---

# 72. Semantic HTML Standard

Use semantic elements according to meaning.

Prefer:

```html
<button>
<a>
<nav>
<main>
<header>
<footer>
<form>
<label>
<table>
```

over generic containers with manually recreated behavior.

### Rules

- A clickable action must generally be a button.
- Navigation must generally use links.
- A form control must have an accessible label.
- Headings must communicate document hierarchy.
- Tables must use table semantics when the data is genuinely tabular.
- Do not use a `div` as a button merely for visual convenience.
- Do not rely on color alone to communicate meaning.

Native browser behavior is preferred over custom behavior when native behavior already satisfies the requirement.

---

# 73. Accessible Names and States

Every interactive control must have an understandable accessible name.

The accessible name should communicate purpose, not implementation details.

Examples:

```text
"Open filters"
"Delete customer"
"Next page"
```

is preferable to:

```text
"button"
"action"
"icon"
```

For icon-only actions, provide an accessible name even when the visual design intentionally shows no text.

Dynamic controls must expose their relevant state where appropriate:

```text
expanded / collapsed
selected / not selected
checked / unchecked
busy / not busy
invalid / valid
```

---

# 74. Keyboard and Focus Standard

All essential functionality must be operable without a mouse.

### Rules

- Every interactive control must be reachable by keyboard where applicable.
- Focus order must follow a meaningful interaction sequence.
- Focus must remain visible.
- Dialogs and overlays must have deliberate focus management.
- Opening a modal should move focus appropriately.
- Closing a modal should return focus to the invoking control when practical.
- Escape behavior must be predictable for dismissible overlays.
- Do not create keyboard traps except where a component's interaction model explicitly requires a managed focus scope.
- Do not remove native focus indicators without providing an equivalent visible focus treatment.

Keyboard accessibility is a functional requirement, not a cosmetic enhancement.

---

# 75. Focus, Hover, and Touch Independence

Important information must not exist only on hover.

The interface must account for:

```text
mouse
keyboard
touch
assistive technology
```

Tooltips are supplementary. They must not be the only way to discover a critical action or essential information.

Hover-only interactions should have a keyboard/touch equivalent when the underlying action is required.

---

# 76. Color and Visual Communication

Color is a communication mechanism, not the sole carrier of meaning.

Examples:

```text
success → color + icon/status/text
warning → color + icon/status/text
error   → color + icon/message
```

The interface should preserve meaning when color perception is limited.

Focus, validation, selected state, disabled state, and status must have non-color indicators where necessary.

The visual design system must define semantic tokens rather than arbitrary per-component colors.

Prefer:

```text
--color-success
--color-warning
--color-danger
--color-info
```

over:

```text
component-green-1
component-green-2
random-red
```

---

# 77. Text, Zoom, and Reflow

The interface must tolerate reasonable increases in text size and viewport changes without destroying task usability.

### Rules

- Do not assume every label fits in one line.
- Avoid fixed heights around variable text unless overflow behavior is deliberate.
- Long translated strings must not break the layout.
- Tables must have a deliberate responsive strategy.
- Dialogs and forms must remain usable at reduced viewport sizes.
- Do not solve overflow by silently clipping important content.
- Truncation must be intentional and provide a method to access the complete value when necessary.

The agent must consider text expansion during implementation, because translated text can be substantially longer or structurally different from the source language.

---

# 78. Forms and Accessibility Integration

Every form control must connect these concepts:

```text
label
input
help text
validation state
error message
value
focus state
```

Validation errors must:

- identify the affected field;
- explain what is wrong in understandable language;
- remain accessible to assistive technology;
- not rely only on color;
- avoid losing user-entered information unnecessarily.

Required fields, optional fields, formatting rules, and constraints should be communicated clearly.

The agent must not create visually elegant forms that are semantically inaccessible.

---

# 79. Dynamic Content and Assistive Technology

Important asynchronous changes must be communicated appropriately.

Examples:

```text
save completed
record deleted
filter applied
validation failed
background operation failed
```

Use the application's established live-region, notification, or status-message pattern rather than inventing one per feature.

Do not announce every minor UI change. Notifications should communicate changes that matter to the user's task.

Loading indicators must not be the only signal that an operation is occurring when the context requires an accessible status message.

---

# 80. Motion and Animation Standard

Animation should communicate state, hierarchy, or spatial relationships.

Do not animate merely because animation is possible.

### Rules

- Motion must not block essential interaction.
- Transitions should be short and purposeful.
- Respect reduced-motion preferences where applicable.
- Avoid excessive parallax, looping motion, flashing, or attention-stealing effects.
- Loading animation must not replace useful progress/status information.
- Important content must remain understandable when motion is reduced or disabled.

The visual identity should feel premium through **clarity and restraint**, not through animation volume.

---

# 81. Responsive Architecture

Responsive behavior must be defined by content and interaction needs, not by blindly following a fixed list of device widths.

### Rules

- Components should adapt to available space.
- Breakpoints should exist because the design requires a behavior change.
- Avoid creating breakpoints for individual device models unless truly necessary.
- Navigation, tables, filters, forms, dialogs, and action bars must have deliberate narrow-screen behavior.
- Essential actions must remain discoverable on smaller screens.
- Avoid horizontal scrolling for ordinary content unless the content genuinely requires a wide canvas, such as a complex data table.

The agent should inspect the smallest practical viewport for every meaningful UI feature it changes.

---

# 82. Localization-Aware Component Design

Reusable components must not assume:

- English word length;
- a fixed label width;
- fixed sentence order;
- LTR layout;
- Western date formats;
- one currency;
- one decimal separator;
- one plural rule.

Component APIs should express semantic concepts rather than hard-coded presentation assumptions.

Bad:

```text
leftIcon
rightIcon
```

Prefer semantic concepts when the framework/design system allows:

```text
leadingIcon
trailingIcon
```

This makes components naturally adaptable to direction and layout changes.

---

# 83. Accessibility of Data-Dense Interfaces

Data-heavy ERP interfaces must optimize both density and accessibility.

A dense table is acceptable.

An inaccessible dense table is not.

### Rules

- Column headers must communicate meaning.
- Sortable columns must expose their sort state.
- Filter controls must have accessible names.
- Pagination must communicate current position and available actions.
- Row actions must identify the row they operate on where context is otherwise ambiguous.
- Selection state must be perceivable without relying only on background color.
- Sticky headers/columns must not obscure focused or selected content.
- Keyboard interaction must remain practical for common table workflows.

Premium ERP UX means **high information density without high cognitive friction**.

---

# 84. Accessibility and i18n Component Checklist

Every significant reusable component should be checked for:

```text
□ Semantic structure
□ Accessible name
□ Keyboard operation
□ Visible focus
□ Correct state exposure
□ Validation/error accessibility
□ Reduced-motion behavior where relevant
□ Text expansion tolerance
□ LTR behavior
□ RTL behavior
□ Long-content behavior
□ Locale-aware formatting
□ Translation coverage
```

---

# 85. Internationalization and Accessibility Agent Workflow

When implementing a UI feature, the agent must explicitly consider localization and accessibility before declaring the work complete.

Preferred workflow:

```text
Identify user-facing content
        ↓
Identify locale-sensitive values
        ↓
Identify accessibility semantics
        ↓
Identify LTR/RTL implications
        ↓
Implement using existing design-system patterns
        ↓
Test keyboard + focus
        ↓
Test validation/status states
        ↓
Test narrow viewport
        ↓
Test alternate locale / long text
        ↓
Test RTL when supported
        ↓
Fix discovered issues
        ↓
Report verification
```

The agent must not assume:

```text
"It is in English and LTR, so it is finished."
```

when the product supports multiple languages or directions.

---

# 86. Definition of Done — i18n & Accessibility

A UI task is complete only when applicable requirements are satisfied:

```text
□ User-facing text is localized.
□ Missing translations are handled according to project policy.
□ Locale-sensitive formatting is correct.
□ Document language metadata is correct.
□ RTL behavior is intentionally handled when supported.
□ Semantic HTML is used where applicable.
□ Interactive controls have accessible names.
□ Keyboard interaction works.
□ Focus behavior is usable.
□ Errors and dynamic status changes are accessible.
□ Meaning does not rely on color alone.
□ Responsive behavior is intentional.
□ Long text does not destroy the layout.
□ Relevant accessibility tests/checks pass.
□ Visual inspection was performed where appropriate.
```

---

# 87. Agent Anti-Shortcut Rules — i18n & Accessibility

The agent must not:

- hard-code user-facing strings when localization is enabled;
- solve RTL by creating a second independent UI implementation without architectural justification;
- use CSS direction hacks where semantic direction metadata is required;
- add ARIA attributes to compensate for incorrect native semantics when native semantics would solve the problem;
- make icon-only buttons without accessible names;
- use color as the only status indicator;
- assume English text length;
- assume LTR layout;
- validate only the happy-path visual state;
- mark accessibility as complete because one automated tool reports no errors.

Automated accessibility tooling is valuable, but it does not replace human-oriented interaction and usability verification.

---

# 88. Reference Standards

This standard uses the following public standards/guidance as external references for accessibility and internationalization decisions:

- W3C Web Content Accessibility Guidelines (WCAG) 2.2: https://www.w3.org/TR/WCAG22/
- W3C WCAG Overview: https://www.w3.org/WAI/standards-guidelines/wcag/
- W3C Language of Page: https://www.w3.org/WAI/WCAG22/Understanding/language-of-page.html
- W3C Internationalization Quick Tips: https://www.w3.org/International/quicktips/
- W3C HTML bidirectional text guidance: https://www.w3.org/International/docs/bp-html-bidi/

These references inform the standard; project-specific constraints and product requirements may impose additional requirements.

---

# 89. Final Principle — Inclusive Premium UX

The frontend standard does not define accessibility and internationalization as separate compliance work.

They are part of premium engineering.

A high-end interface must remain:

```text
clean
      +
fast
      +
predictable
      +
accessible
      +
localizable
      +
RTL-capable when required
      +
usable with keyboard and assistive technology
      +
resilient to real-world content
```

The objective is not to create a beautiful interface for an ideal user.

The objective is to create an interface that remains **clear, efficient, trustworthy, and usable under real-world conditions**.


---

# 90. Observability & Error Handling

## 90.1 Purpose

A production frontend must not only work when everything succeeds. It must make failures:

- understandable to the user;
- actionable for the application;
- diagnosable for developers;
- observable in production;
- safe with respect to sensitive information.

Errors are part of the product architecture, not an afterthought.

The frontend must distinguish between **expected business/application outcomes** and **unexpected technical failures**.

---

## 90.2 Error ownership

Every error must have a clear owner.

Typical ownership:

```text
HTTP / transport failure
        ↓
shared/api infrastructure

Authentication / session failure
        ↓
app authentication boundary

Entity data loading failure
        ↓
entity/data layer

Feature operation failure
        ↓
feature

Page-level composition failure
        ↓
page / application boundary

Unexpected rendering/runtime failure
        ↓
application error boundary
```

Do not make every component invent its own error interpretation.

---

## 90.3 Error taxonomy

The application should classify failures consistently.

At minimum distinguish:

```text
Validation / user input
Business rule violation
Authentication failure
Authorization failure
Not found
Conflict / concurrency
Rate limiting
Network / transport failure
Server failure
Timeout
Unexpected client/runtime failure
```

The exact implementation may vary by framework, but the semantic distinction must remain stable.

Example:

```text
401 → session/authentication problem
403 → authenticated but not authorized
404 → resource not found
409 → conflict / stale state / duplicate operation
422 → validation or business validation
429 → rate limiting
5xx → server-side failure
network/timeout → transport problem
```

These mappings are defaults, not excuses to ignore a backend contract. Follow the actual API contract when one exists.

---

## 90.4 User-facing error messages

User-facing messages must answer, when practical:

```text
What happened?
What can the user do now?
```

Prefer:

```text
"The order could not be saved. Check the highlighted fields and try again."
```

over vague messages such as:

```text
"Something went wrong."
```

However, do not expose implementation details, stack traces, SQL errors, internal identifiers, server paths, or security-sensitive information.

Technical details belong in diagnostics, not in normal user messaging.

---

## 90.5 Error normalization

API and infrastructure errors must be normalized at a stable boundary before being consumed by UI code.

Do not scatter raw transport-specific checks throughout components:

```text
component → checks axios/fetch/http-library internals
component → inspects arbitrary backend JSON
component → guesses status semantics
```

Prefer:

```text
transport error
      ↓
error normalization
      ↓
application error model
      ↓
feature/page handling
      ↓
user feedback + diagnostics
```

This protects the application from coupling every screen to one HTTP implementation.

---

## 90.6 Expected errors vs unexpected errors

Expected failures are part of normal application behavior.

Examples:

```text
invalid form
insufficient permission
duplicate business key
stale version
resource unavailable
session expired
```

Unexpected failures include:

```text
unhandled exception
invariant violation
unexpected response shape
rendering failure
unknown state transition
programming defect
```

Expected failures should be handled close to the feature that can provide useful recovery.

Unexpected failures should be captured centrally and must not require every component to implement custom defensive handling.

---

## 90.7 Error boundaries

Application-level error boundaries should protect major UI regions where the framework supports them.

A recoverable failure in one region should not unnecessarily destroy the entire application.

Where technically appropriate, support:

```text
isolated failure
fallback UI
retry
navigation away
reset/reload of the failed region
```

Do not hide persistent bugs behind endless automatic retries or generic fallback screens.

---

## 90.8 Retry policy

Retries must be intentional.

Safe candidates may include transient read operations where retrying is unlikely to duplicate a side effect.

Mutations must not be blindly retried unless idempotency and API behavior make the retry safe.

The agent must explicitly consider:

```text
Is the operation read-only?
Can retry duplicate a side effect?
Is the request idempotent?
Is the failure likely transient?
Would retry increase user harm or server load?
```

Automatic retry behavior must have a bounded policy.

---

## 90.9 Loading, error, empty, and partial states

Every asynchronous feature must intentionally define applicable states:

```text
idle
loading
success with data
success with empty data
error
retrying
partial/degraded
```

Do not design only the successful state.

For data-heavy applications, distinguish:

```text
initial loading
background refresh
mutation in progress
stale-but-visible data
failed refresh with existing data
```

A background refresh failure should not unnecessarily replace usable existing data with a full-screen error.

---

## 90.10 Stale data and concurrency

The UI must not silently overwrite newer user data with older responses.

Async flows should account for:

- request cancellation where appropriate;
- stale response prevention;
- query invalidation;
- optimistic update rollback;
- version/conflict handling;
- navigation away during requests.

When the backend exposes versioning or concurrency tokens, the frontend must preserve and respect them.

---

## 90.11 Notifications and feedback

Use the least disruptive feedback mechanism appropriate to the situation.

```text
Field problem       → inline feedback
Action failure      → contextual message/toast
Major operation     → prominent status
Session expiration  → authentication flow
Unexpected failure  → safe fallback + diagnostic capture
```

Do not use a global toast for every error by default.

Important failures must remain visible long enough to understand and, where appropriate, actionable by keyboard and assistive technology.

Do not make success/failure feedback depend on color alone.

---

## 90.12 Logging principles

Logs and diagnostics must be:

- structured where possible;
- contextual;
- useful for reconstruction of the failure;
- free of secrets and sensitive payloads;
- consistent across the application.

Useful context may include:

```text
operation name
route/screen
feature
entity/resource type
request/correlation identifier
application version
environment
error category
HTTP status
```

Avoid dumping entire request/response bodies by default.

---

## 90.13 Correlation and traceability

When the backend supports correlation or request identifiers, the frontend should preserve and surface them in diagnostics.

The objective is to make this path traceable:

```text
User action
    ↓
Frontend operation
    ↓
HTTP request
    ↓
Backend operation
    ↓
Server logs
```

A production failure should ideally be traceable without requiring the user to reproduce the problem while a developer watches the browser console.

---

## 90.14 Telemetry boundaries

Telemetry must be intentional.

Capture enough information to diagnose product failures, but do not collect arbitrary data merely because it is available.

Never intentionally send:

```text
passwords
access tokens
refresh tokens
session secrets
full payment secrets
sensitive form contents
raw authentication headers
```

Redact sensitive fields before logging or sending diagnostics.

Telemetry behavior must respect the project's privacy, consent, retention, and compliance requirements.

---

## 90.15 Security-sensitive failures

Authentication and authorization errors must not leak unnecessary information.

Examples:

- do not expose privileged resource details to unauthorized users;
- do not reveal whether protected records exist when the security model forbids that distinction;
- do not put credentials into URLs or logs;
- do not expose backend stack traces to users.

The frontend is not the final authorization boundary. Backend authorization remains authoritative.

The frontend's job is to represent permissions correctly and provide a predictable experience when access is denied.

---

## 90.16 Error handling and architecture boundaries

Error handling must respect FSD ownership.

Examples:

```text
shared/api
  → transport normalization

entities/order
  → order-specific loading/data semantics

features/create-order
  → create-order validation/business feedback

pages/orders
  → page-level composition and recovery

app
  → global runtime/error boundary
```

Do not move every error into `shared` merely for convenience.

Likewise, do not duplicate the same infrastructure normalization in every entity.

---

# 91. Observability Contract

Every significant asynchronous operation should make these concepts explicit where applicable:

```text
Operation
Owner
Loading state
Success state
Empty state
Expected failures
Unexpected failures
Recovery action
User feedback
Diagnostic context
```

For a complex mutation, document at minimum:

```text
What can fail?
What does the user see?
Can it be retried?
Can retry duplicate the operation?
What is logged?
What is intentionally not logged?
```

---

# 92. Agent Workflow — Observability & Errors

Before implementing an asynchronous or failure-prone feature, the agent must:

```text
Understand the API contract
        ↓
Identify operation ownership
        ↓
Enumerate relevant states
        ↓
Classify expected failures
        ↓
Define recovery behavior
        ↓
Define user feedback
        ↓
Implement normalized error handling
        ↓
Add appropriate diagnostics
        ↓
Verify sensitive data is not logged
        ↓
Test failure paths
        ↓
Test retry/concurrency behavior where applicable
        ↓
Report verification
```

The agent must not consider the task complete merely because the happy path works.

---

# 93. Definition of Done — Observability & Error Handling

A relevant feature is complete only when:

```text
□ Async states are intentionally handled.
□ Expected and unexpected failures are distinguished.
□ Errors have clear ownership.
□ Raw transport details are not scattered through UI components.
□ User-facing messages are useful and safe.
□ Recovery actions are defined where applicable.
□ Retry behavior is intentional and bounded.
□ Mutation retries are safe or explicitly disabled.
□ Existing/stale data behavior is intentional.
□ Unexpected runtime failures can reach the appropriate error boundary.
□ Diagnostics contain useful context.
□ Sensitive data is not logged or transmitted accidentally.
□ Authentication/authorization failures are represented correctly.
□ Relevant failure, retry, and concurrency paths are tested.
```

---

# 94. Anti-Patterns — Observability & Errors

The following are prohibited unless there is a documented exception:

```text
❌ console.log of secrets or sensitive payloads
❌ raw backend errors displayed to users
❌ one generic error handler for every business situation
❌ duplicated HTTP/status parsing across components
❌ endless automatic retries
❌ blind mutation retries
❌ swallowing errors without recovery or diagnostics
❌ full-page failure for an isolated widget failure
❌ treating every 4xx/5xx response as the same error
❌ replacing useful stale data with an unnecessary empty error screen
❌ logging complete request/response bodies by default
❌ relying only on browser console output for production diagnosis
```

---

# 95. Final Principle — Observable UX

A premium frontend is not defined by how it behaves when everything works.

It is defined by how calmly and intelligently it behaves when reality happens:

```text
network failure
slow server
expired session
invalid input
concurrent edits
permission denial
partial data
unexpected exception
```

The interface should remain:

```text
clear
      +
truthful
      +
recoverable
      +
secure
      +
observable
      +
respectful of user work
```

Errors are not merely technical events.
They are part of the user experience and must be designed with the same care as the successful path.


---

# 96. Agent Engineering Operating System

## 96.1 Purpose

This section defines the default operating protocol for any AI agent implementing, modifying, reviewing, or refactoring frontend code under this standard.

The agent is not treated as a code generator that receives a request and immediately edits files.

The agent is treated as an **engineering executor** that must first understand the system, then make a bounded design decision, then implement, then verify.

The objective is:

```text
understand
   ↓
model
   ↓
plan
   ↓
implement
   ↓
verify
   ↓
review
   ↓
report
```

Skipping a stage is allowed only when the task is genuinely trivial and the skipped stage would add no meaningful engineering value.

The agent must never skip verification merely because implementation appears correct.

---

## 96.2 Non-negotiable agent principles

The agent must follow these principles on every meaningful task:

1. **Inspect before changing.**
2. **Understand before abstracting.**
3. **Reuse before creating.**
4. **Prefer the smallest correct change.**
5. **Follow the existing architecture unless there is a justified improvement.**
6. **Never invent contracts, behavior, or domain rules without evidence.**
7. **Keep business ownership explicit.**
8. **Treat UX, security, accessibility, and performance as engineering requirements, not polish.**
9. **Verify the actual result rather than trusting generated code.**
10. **Report assumptions, changes, verification, and unresolved risks honestly.**

A task is not complete because files were modified.

A task is complete when the implementation and its verification satisfy the relevant quality gates of this standard.

---

# 97. Agent Task Lifecycle

Every non-trivial task must follow this lifecycle.

```text
PHASE 1  Understand the request
        ↓
PHASE 2  Inspect the repository
        ↓
PHASE 3  Build the domain / interface model
        ↓
PHASE 4  Find existing patterns and reusable capabilities
        ↓
PHASE 5  Define the implementation plan
        ↓
PHASE 6  Implement in architectural order
        ↓
PHASE 7  Self-review against the standard
        ↓
PHASE 8  Run tests and quality gates
        ↓
PHASE 9  Perform visual / UX verification when applicable
        ↓
PHASE 10 Document decisions and report the result
```

The depth of each phase scales with task complexity, but the reasoning sequence remains stable.

---

# 98. Phase 1 — Understand the Request

Before editing code, the agent must translate the request into an engineering objective.

### The agent must identify

```text
What is being requested?
Who is affected?
Which user workflow changes?
Which entities are involved?
Which existing screens/features are affected?
What must remain unchanged?
What are the explicit constraints?
What are the likely acceptance criteria?
```

### Requirements classification

The agent should separate:

```text
Functional requirements
UX/UI requirements
Data/API requirements
Security requirements
Accessibility requirements
Performance requirements
Testing requirements
Compatibility/regression constraints
```

### Ambiguity rule

When requirements are incomplete, the agent must:

1. inspect the existing code and conventions;
2. inspect related features and API contracts;
3. infer only what is strongly supported by evidence;
4. choose the smallest conservative behavior when safe;
5. explicitly record material assumptions.

The agent must not silently invent important business behavior.

---

# 99. Phase 2 — Repository Reconnaissance

The agent must inspect the codebase before deciding where or how to implement the task.

At minimum, inspect the relevant:

```text
project structure
routing
affected page
related feature(s)
related entity/entities
shared UI/components
API/data layer
state ownership
validation
existing tests
existing i18n
existing permission/security patterns
```

The agent should search for the closest existing implementation before creating a new one.

### Pattern discovery priority

Search in this order:

```text
same feature
same entity
same page type
same interaction type
same UI component
shared architecture
```

The goal is to discover **how this project already solves the problem**.

### Existing-pattern rule

If an existing pattern satisfies the requirement, reuse it.

If the existing pattern is materially flawed, the agent may improve it, but must explain why the change is justified and keep unrelated changes out of scope.

---

# 100. Phase 3 — Build the Implementation Model

Before writing implementation code, the agent should model the change.

For a typical business feature, the model should identify:

```text
Route / page
   ↓
Widget(s)
   ↓
Feature(s)
   ↓
Entity/entities
   ↓
API / server state
   ↓
Shared infrastructure
```

The agent must answer:

```text
What is the business entity?
What action is the user performing?
What UI surfaces are required?
Which state belongs where?
What data enters the frontend?
What data leaves the frontend?
What validation exists?
What permissions apply?
What loading/error/empty states exist?
What should be reusable?
```

### Interface inventory

For UI work, create an explicit inventory before implementation when the screen is non-trivial:

```text
Page purpose
Primary action
Secondary actions
Filters / search
Main data presentation
Detail surfaces
Forms
Destructive actions
Loading states
Empty states
Error states
Success feedback
Responsive states
Accessibility requirements
Keyboard / shortcut requirements
```

This prevents the agent from designing only the happy-path visual surface.

---

# 101. Phase 4 — Reuse and Dependency Analysis

Before adding a new component, service, helper, store, hook, API wrapper, or abstraction, the agent must check whether an existing capability already provides the required behavior.

### Reuse decision tree

```text
Does an existing capability already solve it?
        │
       YES → reuse it
        │
       NO
        ↓
Can an existing capability be extended cleanly?
        │
       YES → extend it
        │
       NO
        ↓
Is the new capability clearly owned?
        │
       YES → create it in the correct layer
        │
       NO → stop and resolve ownership before implementation
```

### Duplication rule

Do not duplicate a pattern merely to move faster.

Do not prematurely create generic abstractions merely to eliminate a tiny amount of duplication.

The preferred sequence is:

```text
understand repetition
      ↓
confirm common semantics
      ↓
extract when justified
```

---

# 102. Phase 5 — Planning Contract

Before implementing a non-trivial change, the agent must produce an internal implementation plan that identifies:

```text
Files to create
Files to modify
Files to leave untouched
Layer / ownership of each change
State ownership
API/data flow
UI composition
Validation
Security considerations
Tests to add/update
Verification commands/checks
```

The plan must map directly to the standard.

### Plan quality rules

A good plan is:

- small enough to reason about;
- explicit about ownership;
- explicit about dependencies;
- explicit about verification;
- free of unrelated refactoring.

### No speculative architecture

Do not create infrastructure for hypothetical future requirements.

Build the current requirement using architecture that remains extensible without inventing an abstraction system the product does not need.

---

# 103. Phase 6 — Implementation Order

The agent should implement from **foundations toward composition**.

Preferred order for a business-oriented feature:

```text
1. Types / domain contracts
2. API / data access
3. Entity behavior or model integration
4. Feature state / mutations / queries
5. Forms / validation
6. Reusable UI pieces
7. Widgets
8. Page composition
9. Route integration
10. i18n / accessibility / responsive details
11. Tests
```

The exact order may change when framework conventions require it, but responsibility boundaries must remain intact.

### Implementation rule

Do not start by building the page shell and then push everything else into the page just because it is convenient.

Build ownership first, composition second.

---

# 104. Agent Decision Hierarchy

When multiple implementation options appear valid, the agent should evaluate them in this order:

```text
1. Correctness
2. Security
3. Existing architectural consistency
4. Clear ownership
5. Accessibility
6. UX clarity
7. Maintainability
8. Performance
9. Reuse
10. Implementation convenience
```

Convenience must never override correctness or architectural integrity.

The agent must not choose a locally convenient solution that creates long-term coupling merely because it requires fewer lines of code.

---

# 105. Agent Rules for Existing Systems

The agent must treat an existing application as a system with history and constraints.

Before changing architecture, inspect:

```text
existing conventions
shared components
existing API clients
state patterns
routing patterns
testing patterns
build tooling
linting
formatting
CI checks
```

### Legacy code rule

Legacy code should not automatically be rewritten.

When working in an imperfect area:

```text
understand current behavior
      ↓
change only what the task requires
      ↓
preserve stable behavior
      ↓
refactor only when justified
```

Architecture modernization should be incremental and evidence-driven.

---

# 106. Agent Rules for Scope Control

The agent must maintain a clear distinction between:

```text
Required change
Useful adjacent improvement
Unrelated cleanup
```

### Required change

Must be completed.

### Useful adjacent improvement

May be included only when it is small, directly related, and reduces meaningful risk.

### Unrelated cleanup

Must be excluded from the task.

If a larger architectural problem is discovered, record it as a follow-up rather than silently expanding scope.

### Change budget

The agent should prefer the smallest coherent change that satisfies the requirement.

A small diff is not automatically better, but unnecessary churn is considered a quality defect.

---

# 107. Phase 7 — Mandatory Self-Review

Before running tests, the agent must review its own implementation against the architecture standard.

The self-review should check:

```text
Architecture
State ownership
API/data flow
UX/UI
Forms/validation
Security
Accessibility
Performance
i18n
Error handling
Code quality
Tests
```

### Self-review questions

```text
Did I put each responsibility in the correct layer?
Did I create unnecessary coupling?
Did I duplicate an existing capability?
Did I place business logic in UI code?
Did I create state that could be derived?
Did I create abstractions without clear ownership?
Did I handle loading/error/empty/success states?
Did I cover permission and security boundaries?
Did I preserve keyboard and accessibility behavior?
Did I account for localization and RTL where applicable?
Did I introduce unnecessary network requests?
Did I test important failure paths?
Did I modify unrelated code?
```

The agent must fix discovered defects before declaring completion when practical.

---

# 108. Phase 8 — Verification and Quality Gates

Verification must be evidence-based.

The agent should use the project's actual tooling where available:

```text
format
lint
type-check
unit tests
component/integration tests
E2E tests
architecture tests
build
accessibility checks
visual verification
```

Do not claim a check passed unless it was actually executed or otherwise independently verified.

### Verification hierarchy

```text
Static correctness
      ↓
Unit / component behavior
      ↓
Integration behavior
      ↓
End-to-end workflow
      ↓
Visual / UX verification
```

Not every task requires every layer, but the agent must deliberately determine which layers are relevant.

---

# 109. Test Selection by Change Type

The agent should choose verification based on what changed.

| Change | Minimum relevant verification |
|---|---|
| Pure utility | Unit test + type check |
| Component UI | Component test + accessibility check |
| Form | Validation tests + UI states + submission behavior |
| API/data flow | Contract/integration test + error path |
| Feature mutation | Success + failure + concurrency/idempotency where applicable |
| Routing/auth | Navigation/guard test + permission scenarios |
| Shared component | Existing consumers + regression tests |
| Architecture | Architecture/boundary checks |
| Major page redesign | Functional tests + visual verification + responsive checks |
| Cross-cutting infrastructure | Broader regression suite + build/type/lint |

The table is guidance, not permission to skip clearly relevant tests.

---

# 110. Phase 9 — Visual and UX Verification

For meaningful interface work, the agent must verify the rendered result rather than relying exclusively on source code inspection.

When visual verification is possible, inspect:

```text
Hierarchy
Spacing
Alignment
Density
Typography
Contrast
Interactive states
Loading states
Empty states
Error states
Responsive behavior
Keyboard focus
Overflow
Long text
RTL where applicable
```

The agent should compare the implementation with the project's established design language rather than introducing a new visual language accidentally.

### Screenshot discipline

When screenshots or rendered previews are available, the agent should use them as evidence.

A visual mismatch is a real defect when it affects usability, hierarchy, consistency, accessibility, or intended design.

---

# 111. Phase 10 — Completion Report

Every non-trivial task must end with an accurate engineering report.

The report should contain:

```text
Implemented
- what changed

Architecture
- where responsibilities were placed

Verification
- checks actually executed
- result of each check

Key decisions
- important trade-offs or assumptions

Remaining risks
- known limitations or unresolved issues

Follow-ups
- useful work intentionally left out of scope
```

### Reporting rules

The agent must distinguish clearly between:

```text
Verified
Assumed
Not tested
Blocked
```

Never use vague language such as:

```text
"looks good"
"should work"
"probably secure"
"tests are fine"
```

unless the statement is explicitly framed as an unverified assumption.

---

# 112. Evidence-Based Agent Behavior

The agent must prefer evidence over memory or convention when working in an existing repository.

Evidence includes:

```text
source code
API schemas
existing tests
configuration
build output
lint output
design system components
established feature patterns
```

When the repository contradicts a generic framework tutorial or the agent's preferred pattern, the repository's documented and intentionally established convention should normally take precedence.

If the existing convention is clearly unsafe or architecturally invalid, the agent should explain the conflict and make the smallest justified correction.

---

# 113. Agent Handling of Uncertainty

AI agents frequently encounter incomplete information.

The correct response is not to guess aggressively.

Use this hierarchy:

```text
Known from requirements
      ↓
Known from repository
      ↓
Strongly implied by established patterns
      ↓
Safe conservative assumption
      ↓
Explicitly unresolved
```

Never promote an assumption into a fact merely because it makes implementation easier.

### Critical uncertainty

If uncertainty concerns any of the following, the agent must not silently invent a decision:

```text
security boundary
authorization behavior
financial/business semantics
data deletion
irreversible operations
external API contract
migration compatibility
privacy behavior
```

The agent must preserve the safest known behavior and explicitly report the unresolved decision.

---

# 114. Agent Anti-Hallucination Rules

The agent must never claim that it:

```text
ran a command it did not run;
read a file it did not inspect;
verified an endpoint it did not test;
checked a screenshot it did not see;
confirmed a backend behavior it did not observe;
validated a security property it did not verify;
passed a test it did not execute.
```

Generated reasoning is not evidence.

The final report must reflect actual actions and actual results.

---

# 115. Agent Anti-Shortcut Rules

The following shortcuts are prohibited on non-trivial work unless explicitly justified:

```text
❌ edit first, inspect later
❌ create a duplicate component because discovery takes longer
❌ place all logic in the page component
❌ put business logic in shared
❌ bypass established API/data patterns
❌ ignore existing tests
❌ remove failing tests instead of understanding them
❌ disable lint/type checks to force a green result
❌ swallow errors to make tests pass
❌ weaken security controls to simplify implementation
❌ skip accessibility because the feature is "internal"
❌ skip error states because the happy path works
❌ rewrite unrelated files for style preference
❌ declare completion without verification
```

---

# 116. Agent Refactoring Protocol

When the agent discovers code that should be improved, it must first determine whether the improvement is required for the requested task.

### Required refactor

Perform it when leaving the current code untouched would create a correctness, security, architecture, or maintainability problem directly relevant to the change.

### Optional refactor

Do not silently include broad cleanup.

Record it as a follow-up when it does not materially affect the current task.

### Safe refactoring sequence

```text
understand current behavior
      ↓
add / confirm regression protection
      ↓
refactor
      ↓
run verification
      ↓
compare behavior
```

Refactoring must preserve externally observable behavior unless the task explicitly changes it.

---

# 117. Agent Architecture Escalation Rules

The agent should escalate its own reasoning depth when a change crosses architectural boundaries.

### Low complexity

Examples:

```text
copy change
small text change
isolated styling
small pure utility
```

Use a lightweight inspection and verification path.

### Medium complexity

Examples:

```text
new form
new feature
new API operation
new entity UI
new reusable component
```

Use the complete task lifecycle.

### High complexity

Examples:

```text
new module
cross-cutting state changes
architecture changes
authentication/authorization changes
major data-flow changes
large redesign
shared infrastructure changes
```

The agent must explicitly map boundaries, dependencies, affected consumers, regression risk, and verification scope before implementation.

---

# 118. Agent Review Checklist — Final Gate

Before declaring a non-trivial frontend task complete, verify:

```text
ARCHITECTURE
□ Correct FSD layer
□ Correct ownership
□ Valid dependency direction
□ No forbidden cross-slice imports
□ Public APIs respected

DATA / STATE
□ State has a clear owner
□ No unnecessary duplicated state
□ Server state follows the established data layer
□ API contracts are respected
□ Mutations have defined invalidation/update behavior

UX / UI
□ Primary task is obvious
□ Information hierarchy is clear
□ Existing design system is reused
□ Loading/empty/error/success states exist
□ Responsive behavior is intentional
□ No unnecessary interaction complexity

FORMS
□ Validation is explicit
□ Errors are actionable
□ Submission state is handled
□ Dirty/unsaved behavior is intentional where applicable

SECURITY
□ Authentication/authorization boundaries respected
□ No sensitive data leakage
□ Safe rendering
□ No unsafe HTML without justification and sanitization
□ Security-sensitive failures handled correctly

ACCESSIBILITY / I18N
□ Semantic structure
□ Keyboard access
□ Visible focus
□ Accessible names/states
□ Locale-aware formatting
□ Translation keys are correct
□ RTL behavior considered where applicable

PERFORMANCE
□ No unnecessary requests
□ No obvious rendering loops
□ Large collections handled appropriately
□ Heavy work is controlled
□ Bundle impact is reasonable

QUALITY
□ Types are sound
□ No unnecessary duplication
□ Naming is clear
□ No unexplained dead code
□ Documentation added where the reasoning is non-obvious

VERIFICATION
□ Relevant tests run
□ Lint/type/build checks run as applicable
□ Failure paths tested where relevant
□ Visual verification performed when applicable
□ Results reported honestly
```

---

# 119. Standard Agent Response Contract

For non-trivial implementation tasks, the agent's final response should be structured around four concise sections:

```text
## What changed
Concrete implementation summary.

## Architecture
Where the change lives and why.

## Verification
Exact checks executed and their outcomes.

## Notes
Material assumptions, limitations, or follow-ups.
```

The report should be concise, factual, and traceable to actual work.

The agent should avoid repeating the entire implementation plan after completion.

---

# 120. Final Principle — Engineer, Don't Generate

The objective of this standard is not to make an AI produce more code.

It is to make the AI behave more like a disciplined software engineer:

```text
Observe before acting.

Understand before designing.

Design before coding.

Reuse before duplicating.

Compose before coupling.

Verify before claiming.

Report evidence, not confidence.
```

The desired result is not merely code that works once.

It is a frontend system that remains:

```text
clean
predictable
accessible
secure
performant
maintainable
consistent
and pleasant to use
```

The agent is successful when it preserves and improves those properties while delivering the requested product behavior.

---

# 121. Standard Governance & Evolution

This document is the canonical frontend engineering standard. It is intended to be stable enough for repeated agent use while remaining versioned and evidence-driven.

## 121.1 Rule precedence

When instructions conflict, apply this order:

```text
1. Explicit product / task requirements
2. Security, platform, framework, and runtime constraints
3. Existing project contracts that must remain compatible
4. This engineering standard
5. Agent preference or convenience
```

The agent must not silently violate a higher-priority constraint. Material conflicts must be surfaced in the completion report.

## 121.2 Exceptions

Exceptions are allowed when a rule does not fit a real project constraint. An exception must be:

- explicit;
- localized;
- justified by a concrete constraint or measurable benefit;
- documented when it affects future maintenance;
- prevented from spreading without deliberate adoption.

The agent must never treat “it was easier” as sufficient justification for violating a non-negotiable architectural or security rule.

## 121.3 Existing-system adaptation

This standard is an engineering target, not a license for uncontrolled rewrites. In an existing system, the agent should improve boundaries incrementally and preserve working contracts unless the task explicitly requires migration.

Prefer:

```text
new correct boundary
        ↓
adapter / compatibility layer when needed
        ↓
progressive migration
```

over a broad rewrite with unclear regression boundaries.

## 121.4 Evidence over convention

Rules should evolve from:

- repeated implementation failures;
- production incidents;
- accessibility findings;
- security findings;
- performance measurements;
- developer friction;
- user feedback;
- successful patterns validated across projects.

Do not add rules merely because they sound sophisticated.

## 121.5 Versioning

Use semantic intent for the standard:

- **major:** architectural philosophy or compatibility-breaking rules change;
- **minor:** meaningful new engineering rules or domains are added;
- **patch:** clarification, correction, or wording improvement without changing behavior.

Every material revision should record what changed and why.

## 121.6 Standard quality gate

A new rule should answer at least one of these questions:

```text
What failure does this prevent?
What consistency does this create?
What risk does this reduce?
What capability does this enable?
How will an agent verify it?
```

If a rule cannot be applied or verified in practice, it should remain guidance rather than a mandatory gate.

---

# 122. Final Standard Contract

Every frontend implementation performed under this standard should optimize for the following outcome:

```text
PRODUCT
  correct behavior

+

ENGINEERING
  clear ownership
  correct boundaries
  safe data flow
  maintainable code

+

EXPERIENCE
  clear UX
  premium visual hierarchy
  accessibility
  responsive behavior
  localization readiness

+

OPERABILITY
  observable failures
  controlled performance
  meaningful tests
  evidence-based verification

=

A frontend system worth maintaining.
```

The standard is successful when agents can apply it consistently without requiring the author to manually re-explain the same engineering decisions on every task.

