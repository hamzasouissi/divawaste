# Data Contracts

## Wire Conventions

- JSON resource objects are wrapped as `{ "data": ... }`; resource collections use `data`. Laravel paginated resource collections add `links` and `meta`; cursor pagination metadata differs from page pagination. Direct arrays/JSON responses are endpoint-specific (see [02-api-inventory.md](02-api-inventory.md)).
- Route-bound models using `HasPublicUlid` resolve route values by ULID. Most public references are ULIDs or reference codes, but there are important raw database-ID exceptions below.
- Database decimal columns are exact fixed-precision values; model casts for lot and pickup values serialize with fixed scale (e.g. `184.500`). There is no global numeric serializer. Where resource returns an uncased attribute directly, inspect runtime response; no universal wire-number guarantee is specified.
- Date-only resource fields use `YYYY-MM-DD`; datetimes commonly call `toISOString()` or `toIso8601ZuluString()` (UTC ISO 8601). Database dates are `DATE`; migrations generally use MariaDB `DATETIME`. The Laravel notifications table uses `$table->timestamps()` as an exception.
- Most nullable database values remain nullable in resources; `whenLoaded()` properties can be omitted when relation not loaded. Resources may use fallback numeric IDs if a relation is not loaded.
- Request validation is Laravel validation. FormRequest fields marked `required` are mandatory; `sometimes` and `nullable` differ. Inputs are transformed into typed DTOs before Actions in many write routes; several pickup/lot transitions validate inline.

## Authentication And Common Shapes

`POST /api/v1/auth/login` accepts `{ email, password, company? }`; `company` is an optional company ULID. The response is a `data` union: company choice (`requires_company_selection: true`, `companies: [{ulid, legal_name}]`) or successful login (`requires_company_selection: false`, `access_token`, `token_type: "Bearer"`, nullable `company`, and `user`). The token is a Sanctum PAT with its `company_id` set to the selected company. A multi-company response does not issue a token; repeat the login request with the selected company ULID. Source: [Identity route](../../app/Modules/Identity/Routes/api.php).

`GET /api/v1/me` data schema:

| Field | Type / nullability | Source |
|---|---|---|
| `ulid` | string, required | User ULID |
| `first_name`, `last_name`, `email`, `locale` | string | user attributes |
| `two_factor_enabled` | boolean | derived from non-null two-factor confirmed time |
| `platform_roles` | array of role-name strings | platform role names only |

`GET /api/v1/company` returns `ulid`, `company_type`, `status`, `legal_name`, nullable `trade_name`, nullable `tax_id`, `currency_code`, `timezone`. [MeResource](../../app/Modules/Identity/Resources/MeResource.php), [CompanyResource](../../app/Modules/Tenancy/Resources/CompanyResource.php).

## Waste Lot Request And Response

Create request (`POST /lots`) requires `site`, `zone`, `waste_type` string ULIDs and `net_weight_kg` decimal >=0, scale <=3. Optional: `ulid` ULID unique, `tag` <=64 unique QR tag, gross/tare >=0 scale <=3, packaging/color-family codes active, quantity >0 (required when unit supplied), unit code, color label <=100, grammage >0 scale <=2, composition rows with active material code and decimal percentage, extras array, notes <=1000, and generated_at date <=now. `ValidComposition` controls aggregate validity. `StoreWasteLotRequest` also derives source and device association from the current/access token.

`WasteLotResource`: `ulid`, `lot_number`, `status`, nullable `closed_reason`, `origin_type`, `site` ULID, nullable `zone` ULID, `waste_type` ULID, `is_hazardous`, net/gross/tare kg, `composition_label`, nullable `qr`, `version`, nullable ISO generated_at. It excludes DB foreign keys and most source fields. Decimal casts are three-place strings. Source: [request](../../app/Modules/Waste/Requests/StoreWasteLotRequest.php), [DTO](../../app/Modules/Waste/DTOs/CreateWasteLotData.php), [resource](../../app/Modules/Waste/Resources/WasteLotResource.php), [model](../../app/Modules/Waste/Models/WasteLot.php).

## Pickup Contracts

Create body: `site` ULID, `provider` provider-company ULID, optional `transporter` provider-company ULID, `requested_date` today-or-later date, `lots` array of lot ULIDs (min 1). Workflow stores numeric foreign keys and snapshot lines.

`PickupResource` fields: `ulid`, `pickup_number`, `status`, requested/confirmed date-only, departure/received kg, variance percent/flag, `lots` array ordered by line ID containing lot number/type snapshots, hazard, line status, declared/departure/received weights. No site/provider/company IDs are returned in this resource. [PickupResource](../../app/Modules/Pickups/Resources/PickupResource.php).

Transitions: collect body `lots: [{lot, departure_weight_kg?}]`; receive body `lots: [{lot_number, received_weight_kg}]`; cancel/refuse each require `reason`; confirm requires `confirmed_date`. [Pickup routes](../../app/Modules/Pickups/Routes/api.php).

## Sites, Types, Thresholds

`SiteResource`: ULID/code/name/site_kind/city/region/timezone/capacity_kg/is_active. Create body uses API field `country` (ISO2 code), while DB stores `country_id`; required code/name/country; kind defaults in migration/action; capacity optional. `StockThresholdResource`: ULID, related site/zone/waste_type ULIDs, max kg, warning percent, alert level/time, active. Create takes `site`, optional `zone`, optional `waste_type` ULIDs rather than numeric FK values; warning defaults 90 in DTO. Sources: [site/threshold resources](../../app/Modules/Sites/Resources/), [requests](../../app/Modules/Sites/Requests/).

`WasteTypeResource`: ULID/code/name/nullable description/hazard flag/nullable default grammage/default composition/density/unit weight/extra attrs/is_active. Create uses API reference `catalog_item`, `waste_family`, `regulatory_code`, unit/packaging/color/treatment **codes**, and composition materials by code; action resolves them to DB IDs. [Waste type request](../../app/Modules/Catalog/Requests/StoreWasteTypeRequest.php).

## Compliance Contracts And ID Differences

`DocumentResource`: ULID/type/title/reference/status/regulatory flag; **`site_id` is the raw numeric DB site ID**; `pickup_id` is relation ULID or null; `shared_with_company_id` and `uploaded_by_company_id` are numeric IDs; optional loaded `stored_file` contains ULID/name/MIME/size/checksum/retention date; optional loaded `signatures` items contain `id` (actually signature ULID), party/status/sequence/signed time; issue/retention/source snapshot/created/updated values. List loads storedFile+site, show additionally loads signatures; fields governed by `whenLoaded` can be absent when not loaded.

`HazardousWasteManifestResource`: ULID/number/status; pickup/site/document/waste-type IDs use related ULID if loaded, else numeric DB ID; regulatory waste code, transporter company, receiver company IDs are numeric; snapshots/package/declared weight/timestamps. `StoreHazardousWasteManifestRequest` requires numeric `regulatory_waste_code_id`; optional numeric `waste_type_id`, `transporter_company_id`, `receiver_company_id`; legacy `waste_type` string also supported and action resolves numeric-or-ULID. Status accepts arbitrary string <=40 at request validation, although model enum may constrain assignment at runtime.

`AnnualDeclarationResource`: ULID, `site_id` related ULID or numeric fallback, format/year/status/data/computed/validated/submitted/reference, document ULID or numeric fallback, timestamps. Create accepts format/year/data/status. `DocumentWasteLot`, `DocumentSignature`, `HazardousWasteManifest`, and declarations store company-scoped numeric foreign keys internally.

Certificate upload is `multipart/form-data`; only PDF/JPEG/PNG max 20 MiB and `type` `recycling|destruction`. Accepts `lots` or `lot_numbers`; action normalizes to uppercase, deduplicates identifiers, matches pickup line lot number or lot ULID, and returns an issued `DocumentResource`. See [UploadCertificateRequest](../../app/Modules/Documents/Requests/UploadCertificateRequest.php) and [UploadCertificate](../../app/Modules/Documents/Actions/UploadCertificate.php).

## Other Response Schemas

- `PaymentResource`: ULID, method/status, amount, currency, nullable bank_reference, declared_paid_on date. The bank-transfer request amount is required >0, reference required, date not future.
- `NotificationResource`: `id` is UUID primary key (not ULID), type, numeric company_id, JSON data, nullable read_at, created_at. Preference resource includes numeric `company_id` and `user_id`, type/channel/is_enabled.
- `AuditLogResource`: numeric id/company/actor/auditable IDs plus `auditable_ulid`, event/type/source/network details and old/new JSON. It intentionally exposes internal numeric identifiers in the current resource.
- `GeneratedReportResource`: ULID/report_type/format/status/parameters, **numeric stored_file_id**, error, start/completion/expiry/created timestamps.
- Provider directory direct rows: provider ULID, legal name, four booleans, service area. Partnership create direct `data` object has ULID/provider ULID/status.
- Device registration direct data has device ULID, one-time plain text token, `expires_in_days: 30`.
- Lot event projection direct data rows have event type/status transition/weight/payload/source/occurred_at; event row database ID is not selected.

## Database Versus API

Database integer FKs generally become related ULIDs in lot/site/threshold/pickup resources, but not uniformly. Documents expose numeric site/company IDs; manifests and declarations have relation-loaded ULID-or-numeric fallback; reports expose numeric file ID; notification/audit resources expose numeric IDs. Manifest request fields use database IDs, while many other requests use ULIDs or codes. Do not globally treat every `_id` field as a ULID or every reference input as a public identifier.

Common persistence fields (`id`, company FK, timestamps, soft delete) are often not serialized. Write DTOs may transform ULID/code strings to numeric FKs, normalize ULIDs uppercase, apply defaults, and attach actor/device/source values. Migrations are authoritative for storage types/defaults; requests and resources are authoritative for API shape.

`UNKNOWN — NOT FOUND IN BACKEND`: an OpenAPI/JSON Schema file; a global decimal-as-string guarantee for every resource; a universal response contract for framework/package routes; exhaustive resource output for un-routed model classes.