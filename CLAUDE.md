# DivaWaste — project conventions

Source of truth: `docs/DivaWasteTextile-Technical-Architecture-Blueprint.md` (+ the simplified 68-table MVP agreed after review). Don't redesign the schema without a concrete contradiction.

## Stack
Laravel 13, PHP 8.3+, MariaDB 10.11+ (keep SQL MySQL-compatible), Redis, Sanctum, spatie/laravel-permission. API only (`/api/v1`).

## Structure
`app/Modules/<Module>/` with only the layers a module needs:
`Actions/ DTOs/ Requests/ Models/ Resources/ Services/ Policies/ Rules/ Enums/ Routes/api.php`.
- Request → DTO → Action (transaction, business rules) → Model → Resource.
- No repositories unless a real query problem needs one. No empty folders.
- `Modules/*/Routes/api.php` is auto-loaded under `/api/v1` (`App\Providers\ModuleServiceProvider`).
- Migrations live in `database/migrations` (single global dependency order), named `2026_10_01_<NNNNNN>_...`.

## Database conventions
- PK `id` BIGINT; public id `$table->publicUlid()` (CHAR(26) ascii_bin unique) + model trait `HasPublicUlid` (route key = ulid). Never expose `id` in API.
- `$table->datetimes()` / `dateTime()`, never `timestamps()`/`timestamp()` (2038). Everything UTC.
- Money DECIMAL(15,3) + currency_code; weights DECIMAL(12,3) kg; percentages DECIMAL(5,2).
- Tenant tables: `company_id` NOT NULL, `UNIQUE(company_id, id)` when referenced, composite FKs `(company_id, x_id) → x(company_id, id)`.
- Explicit FK/unique/index names (`fk_`, `uq_`, `ix_`), `restrictOnDelete()` by default.
- No `SELECT *` in application queries; select needed columns.

## Errors / HTTP
All API errors are RFC 9457 problem+json (`App\Modules\Common\Http\ProblemDetails`). `X-Request-Id` on every response.

## Tests
`php artisan test` runs on MariaDB database `divawaste_test` (never SQLite). `vendor/bin/pint` before committing.

## Tenancy
- Tenant models use `App\Modules\Tenancy\Models\Concerns\BelongsToCompany` (fail-closed `TenantScope`, company_id stamped, never changes).
- Tenant routes: `->middleware(['auth:sanctum', 'tenant'])`. Company comes from the token binding (`personal_access_tokens.company_id`) or the session, never a header.
- `TenantContext::runAs($company, fn)` for scoped work; `runAsSystem($reason, fn)` only in allowlisted platform code (logged).
- Every new table must be added to `App\Modules\Tenancy\TableClassification` (SchemaClassificationTest fails otherwise).
