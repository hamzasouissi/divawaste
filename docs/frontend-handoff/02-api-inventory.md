# API Inventory

Runtime verification: `php artisan route:list --path=api/v1` reports 42 application API routes. All use the `/api/v1` prefix. All routes listed below also have `api` middleware. Unless stated otherwise, every route except health uses `auth:sanctum` and `tenant`; resources return JSON under `data`. `GET|HEAD` routes accept HEAD through Laravel. There are no controllers for these module handlers; most are route closures.

Authorization below describes the route/request/action checks found in code. `tenant` separately requires a bound active membership and, by default, an active company. Error shapes are in [06-errors-and-edge-cases.md](06-errors-and-edge-cases.md). Paginated shape is `data`, `links`, `meta`; direct collection/array responses are called out.

## Common

| Method and path | Purpose / access | Request | Response, side effects, errors |
|---|---|---|---|
| `GET /health` | DB connectivity probe; unauthenticated | None | `{"status":"ok"}` after `select 1`; database exception renders API problem response. Source: [Common routes](../../app/Modules/Common/Routes/api.php), [ApiFoundationTest](../../tests/Feature/Foundation/ApiFoundationTest.php). |

## Identity And Tenancy

| Method and path | Purpose / access | Request | Response / conditions |
|---|---|---|---|
| `POST /auth/login` | Public credential login; no Sanctum/tenant middleware; throttle 5 requests per minute per client IP | `email` required valid email; `password` required string; optional `company` ULID | 200 on credential success. If a company is selected, `data` contains `access_token`, `token_type: Bearer`, `requires_company_selection: false`, company `{ulid,legal_name}`, and `user` with `MeResource` fields. Without `company`, the endpoint uses an active `last_company_id` if available or auto-selects the only active membership. With multiple active memberships and no applicable last company, returns `{data:{requires_company_selection:true,companies:[{ulid,legal_name},...]}}` and no token. With no active memberships, issues an unbound token and returns `company:null`. Successful token issuance updates last-login time/IP and last company. 401 `UNAUTHENTICATED` for wrong credentials, inactive users, or locked users; 403 for a requested company without active membership/active company; 422 for invalid input; 429 after throttle limit. Source: [Identity routes](../../app/Modules/Identity/Routes/api.php), [User](../../app/Modules/Identity/Models/User.php). |
| `GET /me` | Current authenticated user; Sanctum only, no tenant middleware | None | `data`: `ulid`, `first_name`, `last_name`, `email`, `locale`, `two_factor_enabled`, `platform_roles`. Tenant grants are not returned. 401 if unauthenticated. [Identity route/resource](../../app/Modules/Identity/Routes/api.php). |
| `GET /company` | Current tenant; auth + tenant | None | `data`: company ULID/type/status/legal_name/trade_name/tax_id/currency_code/timezone. 403 if tenant context/membership/company status fails. [Tenancy route/resource](../../app/Modules/Tenancy/Routes/api.php). |

## Audit And Billing

| Method and path | Purpose / authorization | Request | Response / side effects |
|---|---|---|---|
| `GET /audit-logs` | Audit query; `IndexAuditLogsRequest` checks `audit_logs.view` | `filter[entity]` string <=50; `filter[user]` integer; `filter[between]` two nullable dates; `per_page` 1..100/default 25 | Paginated `AuditLogResource`, newest first. Query filters tenant, `auditable_type`, actor user, optional date pair. Catalog has `audit.view`, not `audit_logs.view`; see security mismatch. [Audit route/request/resource](../../app/Modules/Audit/Routes/api.php). |
| `POST /invoices/{invoice}/payments` | Declare bank transfer; request checks `payments.declare` | `amount` required decimal 0..3, >0; `bank_reference` required <=100; `declared_paid_on` required date <= today | 201 `PaymentResource`: ULID/method/status/amount/currency/bank ref/date. Creates pending payment only when invoice status accepts payment. Path invoice is model-bound. 403/404/422/409 `INVOICE_NOT_PAYABLE`. [Billing route/action](../../app/Modules/Billing/Routes/api.php). |

## Catalog

| Method and path | Purpose / authorization | Request | Response / side effects |
|---|---|---|---|
| `GET /waste-types` | List tenant types; `waste_types.view` | `active_only` boolean, default true; `per_page` default 25, capped at 100 | Paginated resource ordered by code; fields in [WasteTypeResource](../../app/Modules/Catalog/Resources/WasteTypeResource.php). |
| `POST /waste-types` | Create tenant waste type; request checks `waste_types.manage` | `catalog_item` optional code; `code` required <=40 alpha_dash unique per company; `name` and `waste_family` required without catalog item; description <=1000; optional regulatory_code/unit/packaging/color/treatment references; hazard boolean; grammage >0 (2 decimals), default composition array of distinct materials with percentages >0..100 summing exactly 100; density/unit weight >0; extras object/array | 201 `WasteTypeResource`; action creates custom/catalog-derived type. `ValidationException` on bad references/percentages; permission mismatch not found. [Request/action/resource](../../app/Modules/Catalog/Requests/StoreWasteTypeRequest.php). |

## Sites And Stock Thresholds

| Method and path | Purpose / authorization | Request | Response / side effects |
|---|---|---|---|
| `GET /sites` | List sites visible under `sites.view` site grants | `per_page` default 25, capped 100 | Paginated `SiteResource`, code ascending; permission scope filters site IDs unless all-sites grant. |
| `POST /sites` | Create site; `sites.manage` | `code` required <=20 alpha_dash unique per company; `name` required <=150; `site_kind` optional enum; `country` required active ISO2 exactly 2 chars; nullable address/city/postal/region/timezone/regulatory identifier/capacity >=0 | 201 `SiteResource`; creator/company/country stored by action. |
| `GET /stock-thresholds` | List thresholds under `stock.view` site grants | `per_page` default 25, capped 100 | Paginated `StockThresholdResource`, ID ascending, related site/zone/type ULIDs included. |
| `POST /stock-thresholds` | Create threshold; request checks `stock.thresholds.manage` for site | `site` required tenant site ULID; optional `zone` must belong to selected site; optional tenant `waste_type`; `max_quantity_kg` required >0, max 3 decimals; `warning_pct` optional integer 1..100, defaults 90 | 201 resource; site/zone/type references resolved to DB IDs. Unknown site proceeds to 422 validation. Sources for both: [Sites routes](../../app/Modules/Sites/Routes/api.php), [requests](../../app/Modules/Sites/Requests/), [actions](../../app/Modules/Sites/Actions/). |

## Waste Lots

| Method and path | Purpose / authorization | Request | Response / side effects |
|---|---|---|---|
| `POST /lots` | Create lot; `lots.create` at selected site | `site`,`zone`,`waste_type` required ULIDs; `net_weight_kg` required decimal <=3 and >=0; optional ULID/tag, gross/tare >=0, packaging/unit/color reference codes, quantity >0 required with unit, grammage >0, composition material/pct array, extras, notes <=1000, generated_at <=now | 201 `WasteLotResource`; lot number/QR/composition/events may be derived. `ValidComposition` and action rules apply. |
| `GET /lots/{lot}` | Read one; `lots.view` at lot site | Path model ULID | `WasteLotResource`; 404 outside tenant, 403 without site grant. |
| `GET /lots/{lot}/events` | Event timeline; `lots.view` at lot site | Path model ULID | Direct `data` array of event_type/from_status/to_status/weight/payload/source/occurred_at ordered occurred_at then ID; unpaginated. |
| `POST /lots/{lot}/store` | Move/store; `lots.move` at lot site | Optional nullable `zone` ULID belonging to lot site | `WasteLotResource`; transition to stored, status event and version update. Invalid transition 409. |
| `POST /lots/{lot}/split` | Split; `lots.split` at lot site | `outputs` required array min 2; each `net_weight_kg` >0 max 3 decimals; optional site-zone ULID; optional notes <=1000 | 201 resource collection of child lots; parent closes with `split`, lineage/events written. Weight gap >2% requires notes; 409 otherwise. |
| `POST /lots/group` | Group; `lots.group` at first lot's site | `lots` required array min 2 tenant lot ULIDs; `zone` required tenant zone ULID; notes optional <=1000 | 201 one new stored grouped lot. All input lots must share site/type/hazard; parents close. 409 on mixed/invalid/non-transformable input. |

There is no `GET /lots` collection route. Source: [Waste routes](../../app/Modules/Waste/Routes/api.php), [StoreWasteLotRequest](../../app/Modules/Waste/Requests/StoreWasteLotRequest.php), [lot actions](../../app/Modules/Waste/Actions/).

## Pickups

| Method and path | Purpose / authorization | Request | Response / side effects |
|---|---|---|---|
| `POST /pickups` | Industrial creates draft; `pickups.request` at site | `site` tenant ULID; `provider` active provider company ULID; optional `transporter` provider company ULID; `requested_date` today or later; `lots` array min 1 tenant lot ULIDs | 201 `PickupResource`. Lots must belong to site and be `created` or `stored`; a lot cannot be in another active pickup. |
| `GET /pickups/{pickup}` | Read party-visible pickup; `pickups.view` on site OR `provider.pickups.view` | Path ULID | `PickupResource`; scoped tenant binding permits only owner/provider/transporter party rows. |
| `POST /pickups/{pickup}/submit` | Submit draft; `pickups.request` on site | Empty body | 200 resource; eligibility checked; lots transition to awaiting pickup. |
| `POST /pickups/{pickup}/cancel` | Cancel draft/requested/confirmed; `pickups.cancel` on site | `reason` required string <=1000 | 200; releases pending lots. |
| `POST /pickups/{pickup}/collect` | Industrial load confirmed pickup; `pickups.load` on site | `lots` array min 1; each `lot` required string and optional `departure_weight_kg` decimal <=3 >=0 | 200; listed lots load (missing weight defaults to lot net weight), omitted lines become not_loaded and lots return to stored; pickup departure total is summed. |
| `POST /pickups/{pickup}/confirm` | Provider confirms requested pickup; `provider.pickups.respond` | `confirmed_date` required date today or later | 200; eligibility checked against confirmation date. |
| `POST /pickups/{pickup}/refuse` | Provider refuses requested pickup; `provider.pickups.respond` | `reason` required string <=1000 | 200; releases awaiting lots. |
| `POST /pickups/{pickup}/receive` | Provider records receipt; `provider.pickups.receive` | `lots` min 1; each `lot_number` required string and `received_weight_kg` required decimal <=3 >=0 | 200; per-line and total variances computed/flagged; status becomes received. |

No pickup list endpoint is registered. The public response includes pickup ULID/number/status/dates/total weights/variance and line snapshots. See [Pickup routes](../../app/Modules/Pickups/Routes/api.php), [PickupWorkflow](../../app/Modules/Pickups/Actions/PickupWorkflow.php), [PickupResource](../../app/Modules/Pickups/Resources/PickupResource.php).

## Providers

| Method and path | Purpose / authorization | Request | Response / side effects |
|---|---|---|---|
| `GET /providers/directory` | Published provider directory; `providers.view` | None | Direct `data` array ordered by legal name; active published companies only; fields ULID/legal_name/collector/transporter/recycler/eliminator/service_area. No pagination. |
| `POST /provider-partnerships` | Create/return partnership; `providers.partnerships.manage` | `provider` required active provider company ULID | 201 direct `{data:{ulid,provider,status}}`; `firstOrCreate` makes the company-provider relation idempotent. |

Source: [Providers routes](../../app/Modules/Providers/Routes/api.php).

## Documents, Manifests, Declarations, Certificates

| Method and path | Purpose / authorization | Request/query | Response / side effects |
|---|---|---|---|
| `GET /documents` | `documents.view` | `filter[type]`, `filter[pickup]`, `filter[site]`, `filter[period]`; `per_page` default25/max100 | Paginated documents newest first with stored file/site loaded; period matches rows containing supplied date. |
| `GET /documents/{document}` | `documents.view` at document site | Path ULID | Document resource including loaded file/signatures; 403 or tenant-scoped 404. |
| `POST /documents/{document}/issue` | `documents.generate` at document site | Empty body | 200 `DocumentResource`; issuing an already-issued document is idempotent. Cancelled/superseded documents or documents without a stored file return 409 (`DOCUMENT_CANCELLED`, `DOCUMENT_SUPERSEDED`, `DOCUMENT_FILE_REQUIRED`). |
| `GET /manifests` | `documents.view` | `filter[site]`, `filter[pickup]`, `filter[status]`; `per_page` default25/max100 | Paginated manifest resource newest first. |
| `POST /pickups/{pickup}/manifests` | `documents.generate` at pickup site | Required integer `regulatory_waste_code_id` existing; optional `waste_type` string <=100 or `waste_type_id` integer; optional package_count >=1, declared_weight >=0, producer/transporter/receiver snapshot arrays, integer transporter/receiver company IDs, status string <=40 | 201 manifest resource; creates linked draft document and manifest. Input uses database IDs in places. |
| `GET /declarations` | `declarations.prepare` (unscoped check) | `filter[site]`, `filter[status]`, `filter[year]`; `per_page` default25/max100 | Paginated declaration resource, reporting year descending. |
| `POST /sites/{site}/declarations` | `declarations.prepare` at site | `format_code` required <=40; `reporting_year` 2024..2100 required; nullable data array/status string <=40 (defaults draft) | 201 declaration resource; unique site/format/year can conflict; computes reference. |
| `POST /pickups/{pickup}/certificates` | Auth+tenant and action-level company-party/status checks; FormRequest `authorize()` returns true | Multipart `file` PDF/JPEG/PNG <=20480 KiB; `type` is `recycling|destruction`; one of `lots` or `lot_numbers`, array 1..32 strings <=100; optional title <=255 | 201 `DocumentResource`, created document is issued immediately. Pickup must be collected/received; submitted lots must belong to pickup. Action restricts owner/provider company. |

Source: [Documents routes](../../app/Modules/Documents/Routes/api.php), [requests](../../app/Modules/Documents/Requests/), [resources](../../app/Modules/Documents/Resources/), [certificate action](../../app/Modules/Documents/Actions/UploadCertificate.php).

## Notifications

| Method and path | Purpose / authorization | Request/query | Response / side effects |
|---|---|---|---|
| `GET /me/notifications` | Auth+tenant; user ownership query | Laravel cursor pagination, fixed page size 20 | Notification resource cursor collection, newest first. |
| `POST /me/notifications/{notification}/read` | Auth+tenant; explicit notifiable user must equal caller | Empty body | Resource after marking read; 403 for another user. |
| `PUT /me/notification-preferences` | Auth+tenant; authenticated request | `preferences` required array; each requires `notification_type` <=100, channel `mail|database|whatsapp`, boolean `is_enabled` | Resource collection of upserted user+company preferences. |

Source: [Notifications routes](../../app/Modules/Notifications/Routes/api.php), [preference request](../../app/Modules/Notifications/Requests/SetNotificationPreferencesRequest.php).

## Reporting And Device Registration

| Method and path | Purpose / authorization | Request | Response / side effects |
|---|---|---|---|
| `GET /reports` | `reports.view` | `filter[type]`, `filter[status]`; `per_page` default25/max100 | Paginated report resource newest first. |
| `POST /reports` | Request checks `reports.create` | `report_type` required <=50; optional format `pdf|xlsx|csv` (DTO default `pdf`); optional parameters array (default empty); optional integer existing `stored_file_id`; optional status `queued|processing|completed|failed|expired` (default `queued`) | 202 report resource. Action stores a report with started_at now and expires_at +30 days; no generation worker is established by this route. `reports.create` absent from permission catalog. |
| `GET /reports/{report}` | `reports.view` | Path ULID | Report resource. |
| `POST /mobile/devices` | Auth+tenant; request checks `app.mobile.access` | `device` required ULID, `name` required <=100, optional platform `android|ios|other`, app_version <=20 | 201 direct `{data:{device,token,expires_in_days:30}}`; token is plain text, bound to company/device, valid 30 days. Cross-company reused ULID and revoked device return 409. |

Source: [Reporting routes/request/action/resource](../../app/Modules/Reporting/Routes/api.php), [device route/request/action](../../app/Modules/Sync/Routes/api.php).

## Other Runtime Routes (Not `/api/v1`)

`GET /up` is Laravel's health route. `GET /sanctum/csrf-cookie` is Sanctum package session/CSRF setup. With `filesystems.disks.local.serve=true`, Laravel registers `GET|HEAD /storage/{path}` and `PUT /storage/{path}`; these use framework `ServeFile`/`ReceiveFile`, not `StoredFile` application authorization. For the configured private local disk, GET requires a valid relative signature; PUT also requires `upload=true`. Invalid signatures return 403 outside production and 404 in production; missing/path-traversing files return 404. These routes are not counted in the 42 application APIs. No rate-limit declaration was found on the 42 module routes. Sources: [filesystem config](../../config/filesystems.php), [ServeFile](../../vendor/laravel/framework/src/Illuminate/Filesystem/ServeFile.php), [ReceiveFile](../../vendor/laravel/framework/src/Illuminate/Filesystem/ReceiveFile.php).

`UNKNOWN — NOT FOUND IN BACKEND`: generated OpenAPI specification; standardized API request media-type policy other than the certificate multipart body; documented idempotency support except device registration keys and first-or-create partnership behavior.