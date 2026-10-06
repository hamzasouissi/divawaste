# Overview

## Purpose And Domains

DivaWaste is an API-only Laravel backend for textile-waste operations. The architecture blueprint describes company tenancy, textile production and waste tracking, provider collection/treatment, compliance documents, billing, notifications, reporting, and mobile device support. The implemented consumer surface is the runtime route inventory in [02-api-inventory.md](02-api-inventory.md); do not infer that blueprint features without routes are available.

Implemented route domains: health; current user/company; audit logs; invoices/payments; waste catalog; sites/stock thresholds; waste lots and lot transformations; pickups; provider directory/partnerships; documents/manifests/declarations/certificates; notifications/preferences; generated reports; and mobile device registration. There is no pickup or lot collection/list route, no sync push/pull/bootstrap route, and no CRUD API for most identity/provider/reference-data entities.

## API Base Paths

- Application API: `/api/v1` (42 runtime-registered routes, including `GET /api/v1/health`). The module provider groups files under this prefix.
- Laravel health route: `GET /up`.
- Sanctum package route: `GET /sanctum/csrf-cookie` (session/CSRF middleware).
- Local-filesystem package routes: `GET|HEAD /storage/{path}` and `PUT /storage/{path}` are registered because the local disk has `serve: true`; these are not application API resources. Details/limits are described in [09-files-and-attachments.md](09-files-and-attachments.md).

Source: [ModuleServiceProvider](../../app/Providers/ModuleServiceProvider.php), [bootstrap/app.php](../../bootstrap/app.php), [web.php](../../routes/web.php), runtime `php artisan route:list`.

## Authentication

Protected API routes use Laravel Sanctum `auth:sanctum`; most also require the `tenant` middleware. The current-user endpoint is `GET /api/v1/me`. Tenant context is resolved from the authenticated personal access token's `company_id` or the session's `current_company_id`, after checking an active company membership and an allowed company status. A client-supplied company header is not used.

`POST /api/v1/auth/login` validates email/password, rejects inactive or locked users, and issues a Sanctum bearer token. Tokens are bound to an active company membership when selected or uniquely determined. Multiple memberships without a usable last-company selection return a company-selection challenge with no token; no active membership yields an unbound token usable for `/me` but not tenant routes. No logout/token revocation, refresh, invitation acceptance, or company-switch route was found. A Sanctum CSRF-cookie endpoint is present, but the web route file says application web routes are reserved for an SPA endpoint to be added with authentication. See [03-auth-and-permissions.md](03-auth-and-permissions.md).

## Consumer Conventions

- `HasPublicUlid` models route-bind by ULID; most resources expose `ulid`, not database `id`. Exceptions and numeric IDs appear in resources and some request fields; inspect each contract.
- JSON resources serialize under `data`. Paginated resource collections include Laravel pagination metadata; direct route arrays have endpoint-specific shapes.
- API exceptions are RFC 9457-style `application/problem+json`; responses include `X-Request-Id`.
- Dates are generally emitted with ISO 8601 strings; date-only fields use `YYYY-MM-DD`. Migrations generally use `dateTime`/`datetimes`; the Laravel notifications table is an exception and uses `$table->timestamps()`. Exact resource formatting is endpoint-specific.
- Mass, money, percentages, and rates use decimal database columns. Lot and pickup resource decimal casts are strings with fixed scale; API representation of decimal fields without an explicit model cast is not normalized globally.
- Tenant-owned data is fail-closed and scoped. Provider/transporter access to party-shared pickups differs from industrial owner access.

Sources: [HasPublicUlid](../../app/Modules/Common/Models/Concerns/HasPublicUlid.php), [TenantScope](../../app/Modules/Tenancy/Scopes/TenantScope.php), [EstablishTenantContext](../../app/Modules/Tenancy/Http/Middleware/EstablishTenantContext.php), [ProblemDetails](../../app/Modules/Common/Http/ProblemDetails.php), [AssignRequestId](../../app/Modules/Common/Http/Middleware/AssignRequestId.php).

## Major Entities

The 42 module Eloquent model classes span users/companies/memberships, sites/zones, waste types/lots/events/composition/lineage, providers/partnerships/accreditations, pickups/lines, billing, documents/manifests/declarations/signatures, stored files, reports/production statistics, notifications, audit, and mobile devices/sync operations. [01-domain-model.md](01-domain-model.md) distinguishes API-facing entities from support records and states the count basis.

## Constraints And Unknowns

Tenant authorization is site-aware for many permissions. No general capability endpoint exposes effective tenant permissions; `GET /me` only returns platform role names. Permission-name mismatches exist in audit/report endpoints. Some payloads use raw database IDs where the conventions call for ULIDs. These conditions are documented in [03-auth-and-permissions.md](03-auth-and-permissions.md) and [04-data-contracts.md](04-data-contracts.md).

`UNKNOWN — NOT FOUND IN BACKEND`: logout/token revocation and refresh contracts; tenant switching endpoint; global rate-limit policy; API version negotiation/deprecation policy; a stable API schema/OpenAPI document; download/preview contract; implementation of background report completion; and several blueprint-described routes without runtime declarations.