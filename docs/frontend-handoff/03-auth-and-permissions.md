# Authentication And Permissions

## Authentication

- Protected routes use `auth:sanctum`. The current-user route `GET /api/v1/me` needs authentication but not tenant context.
- Sanctum supports personal access tokens. `personal_access_tokens` stores token hash, abilities, optional `company_id`/`device_id`, last-used and expiry. Do not send token secrets in documentation/logs.
- Device registration (`POST /api/v1/mobile/devices`) returns a plain-text token with `mobile:*` ability, bound to the current company/device and 30-day expiry.
- The tenant middleware resolves current company from the authenticated `PersonalAccessToken.company_id`; if there is not a PAT, it reads session `current_company_id`. It then requires an active company membership and, unless explicit middleware statuses are provided, company `active` status. It does not read a client-supplied company header. Tenant model queries fail closed without context.
- `POST /api/v1/auth/login` is the credential entry point. It accepts email/password and optional company ULID, validates an active user, and creates a Sanctum personal access token bound to the selected active company. When multiple active memberships need a choice, it returns company ULIDs/legal names without issuing a token; repeat login with the selected ULID.
- The returned token is a bearer token. Tenant middleware reads its `company_id`; do not send a client company header. The route is API+throttle middleware only and does not use the CSRF-cookie flow.
- `GET /api/v1/me` exposes user ULID/name/email/locale, boolean two-factor-enabled, and **platform role names only**. Effective tenant roles/permissions are not returned by this endpoint.
- `GET /sanctum/csrf-cookie` remains package-provided for a potential session-auth SPA; it is not required by the current bearer login route.

`UNKNOWN — NOT FOUND IN BACKEND`: logout/revocation endpoint, token refresh/rotation endpoint, password reset HTTP flow, MFA challenge flow, standalone current-company switching endpoint, token ability policy for general API routes. Backend feature tests for the new login route were not found during this update.

Sources: [auth config](../../config/auth.php), [User](../../app/Modules/Identity/Models/User.php), [tenant middleware](../../app/Modules/Tenancy/Http/Middleware/EstablishTenantContext.php), [tenant context](../../app/Modules/Tenancy/TenantContext.php), [PAT schema](../../database/migrations/2026_10_01_000340_create_identity_tenant_tables.php), [device action](../../app/Modules/Sync/Actions/RegisterDevice.php), [runtime routes](../../bootstrap/app.php).

## Authenticated Versus Authorized

**Authenticated** means Sanctum resolved a user. It does not prove the user can act in a tenant. **Tenant-authorized** additionally requires the tenant middleware's company binding, active membership and company status. **Resource-authorized** is an endpoint-specific permission/site/party check. A tenant-scoped record outside the current company/party context generally binds as not found (404); visible record without a required permission generally yields 403.

Tenant permissions use `PermissionCatalog` and `AccessResolver` through the authorization integration. Site-scoped permission grants can be assigned to one site or company-wide (`site_id = NULL`, covers future sites); company-scoped permissions require company-wide assignment. `siteIdsFor()` returns null for all sites. Platform permissions use Spatie model role assignments. Roles/permissions are checked by permission names, not role names.

## Seeded Roles

`AuthorizationSeeder` creates these nine system roles, filling their default permission matrix only when a role is first created; the matrix remains editable. Tenant roles are linked through `user_role_assignments`. Names are exact backend values:

- `super_admin` (platform), `platform_support` (platform)
- `client_admin` (industrial), `environment_manager` (industrial), `workshop_operator` (industrial), `management_viewer` (industrial), `auditor` (industrial)
- `provider_admin` (provider), `provider_operator` (provider)

The seeder matrix is in [PermissionCatalog](../../app/Modules/Identity/Authorization/PermissionCatalog.php); there is no endpoint to retrieve role definitions or effective grants. The PermissionCatalog includes audience `brand`, but no system role is declared for brand in this catalog.

## Permission Catalog

Exact seeded permission names grouped by prefix/domain:

- App/company/users: `app.web.access`, `app.mobile.access`, `company.view`, `company.update`, `users.view`, `users.invite`, `users.manage`, `roles.manage`, `audit.view`, `subscription.view`, `subscription.manage`, `invoices.view`, `payments.declare`.
- Industrial sites/catalog/waste: `sites.view`, `sites.manage`, `zones.manage`, `waste_types.view`, `waste_types.manage`, `lots.view`, `lots.create`, `lots.update`, `lots.weigh`, `lots.move`, `lots.split`, `lots.group`, `lots.print_label`, `lots.delete`, `stock.view`, `stock.thresholds.manage`.
- Industrial pickups/providers/compliance: `pickups.view`, `pickups.request`, `pickups.cancel`, `pickups.load`, `pickups.price`, `pickups.complete`, `pickups.override_eligibility`, `providers.view`, `providers.partnerships.manage`, `documents.view`, `documents.generate`, `documents.upload`, `documents.sign`, `documents.delete`, `declarations.prepare`, `declarations.validate`.
- Industrial reporting/operations: `dashboard.view`, `reports.view`, `reports.export`, `production_volumes.manage`, `sync_conflicts.resolve`.
- Provider: `provider.profile.manage`, `provider.accreditations.manage`, `provider.pickups.view`, `provider.pickups.respond`, `provider.pickups.receive`, `provider.certificates.upload`, `provider.manifests.sign`.
- Platform: `platform.support.view`, `platform.companies.review`, `platform.companies.suspend`, `platform.providers.review`, `platform.catalog.manage`, `platform.countries.manage`, `platform.plans.manage`, `platform.invoices.manage`, `platform.payments.validate`, `platform.roles.manage`, `platform.legal.manage`.

Total from source catalog: 68 permissions. Scope and audience for each exact name are defined in [PermissionCatalog](../../app/Modules/Identity/Authorization/PermissionCatalog.php); this list groups names only. Role matrix is code-defined and seeded by [AuthorizationSeeder](../../database/seeders/AuthorizationSeeder.php).

## Endpoint Authorization Map

- `GET /me`: authenticated only.
- `GET /company`: active tenant context; no separate permission check.
- Sites: `sites.view` filtered by allowed site IDs; create uses `sites.manage`.
- Waste types: list `waste_types.view`; create request `waste_types.manage`.
- Stock: list `stock.view`; create request `stock.thresholds.manage` at chosen site.
- Lots: create `lots.create`; read/events `lots.view`; store `lots.move`; split `lots.split`; group `lots.group` on first lot's site.
- Pickups: create/submit `pickups.request`; read `pickups.view` on site OR provider `provider.pickups.view`; cancel `pickups.cancel`; collect `pickups.load`; confirm/refuse `provider.pickups.respond`; receive `provider.pickups.receive`.
- Providers: directory `providers.view`; partnership creation `providers.partnerships.manage`.
- Documents: list/show `documents.view` (show is site-specific); issue and manifest creation `documents.generate` at site; declarations use `declarations.prepare` (create is site-specific; list only calls permission without site argument).
- Audit list request checks `audit_logs.view`; report create request checks `reports.create`; neither name exists in the seeded catalog. Their seeded roles therefore do not receive those names through this catalog. Ad-hoc database permissions/runtime configuration could change outcomes; no such assignment endpoint was found.
- Reports list/show `reports.view`; report create `reports.create` as above.
- Payment creation request checks `payments.declare`.
- Device registration request checks `app.mobile.access`.
- Notification preferences/read use authentication/ownership, with tenant context; no named permission is checked.
- Certificate upload request `authorize()` returns true. `UploadCertificate` then permits only current company equal to pickup owner or pickup provider, and pickup status collected/received. The `provider.certificates.upload` catalog permission is not explicitly checked.

Source: each route/request under [app/Modules](../../app/Modules/), especially [Audit request](../../app/Modules/Audit/Requests/IndexAuditLogsRequest.php), [report request](../../app/Modules/Reporting/Requests/StoreGeneratedReportRequest.php), [certificate action](../../app/Modules/Documents/Actions/UploadCertificate.php).

## Failure Semantics

Unauthenticated protected route: 401 problem response `UNAUTHENTICATED`. Missing/inactive tenant membership/company: 403 `FORBIDDEN` (middleware throws access denied). Insufficient endpoint permission: 403 `FORBIDDEN`. Tenant-scoped non-visible model generally 404 `NOT_FOUND`. Validation and business failures are detailed in [06-errors-and-edge-cases.md](06-errors-and-edge-cases.md).

`UNKNOWN — NOT FOUND IN BACKEND`: an API for discovering current tenant grants, user/company membership listing, role assignment, invitation lifecycle, authentication challenge/refresh, or tenant-company switching.