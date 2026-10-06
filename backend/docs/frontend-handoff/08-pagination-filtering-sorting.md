# Pagination, Filtering, And Sorting

There is no single list convention. All page-paginated application lists accept `per_page` default 25 and cap values with `min(requested,100)` unless otherwise stated. Several routes do not validate `per_page` explicitly, so lower bounds/type behavior are Laravel request coercion plus paginator behavior. Laravel resource paginator response has `data`, `links`, and `meta`.

| Endpoint | Pagination | Filters/search | Ordering |
|---|---|---|---|
| `GET /audit-logs` | page; default25/max100; request validates 1..100 | `filter[entity]` auditable type; `filter[user]` actor numeric ID; `filter[between]` exactly two dates, both nonnull to apply | `created_at` descending |
| `GET /documents` | page; default25/max100 | `filter[type]`, `filter[pickup]` numeric FK, `filter[site]` numeric FK, `filter[period]` date contained by period_start/end | `created_at` descending |
| `GET /manifests` | page; default25/max100 | site/pickup numeric FKs; status string | `created_at` descending |
| `GET /declarations` | page; default25/max100 | site, status, year | reporting_year descending |
| `GET /reports` | page; default25/max100 | type and status | created_at descending |
| `GET /sites` | page; default25/max100 | No query filters; result may be restricted by site permission scope | code ascending |
| `GET /stock-thresholds` | page; default25/max100 | No query filters; result may be restricted by site permission scope | numeric ID ascending |
| `GET /waste-types` | page; default25/max100 | `active_only` boolean, default true | code ascending |
| `GET /me/notifications` | cursor pagination, fixed 20 | Current user's notifications only | created_at descending |
| `GET /providers/directory` | None | Active companies with published provider profiles only | legal name ascending |
| `GET /lots/{lot}/events` | None | Exact lot only | occurred_at ascending then ID |

No collection endpoint exists for lots or pickups. Other GET-by-ID routes are not list pagination.

## Filter Syntax And ID Semantics

Laravel nested query input is read as `filter.type`, `filter.site`, etc.; the URL form is typically `?filter[type]=...`. No free-text `search` or explicit sort parameter was found. Filter semantics are route-local. Document/manifest `site` and `pickup` filters query numeric database foreign keys, while create routes and path binding typically use ULIDs. Audit user filter is numeric user ID; audit entity is a model type string. Report filter names map to report_type/status. Date filter audit uses a two-element array; document period expects one date value.

No cursor pagination beyond notifications. No explicit page-size max parameter, include/expand syntax, multi-sort grammar, or consistent filter error contract is documented.

Source: list closures in [Audit](../../app/Modules/Audit/Routes/api.php), [Documents](../../app/Modules/Documents/Routes/api.php), [Reporting](../../app/Modules/Reporting/Routes/api.php), [Sites](../../app/Modules/Sites/Routes/api.php), [Catalog](../../app/Modules/Catalog/Routes/api.php), [Notifications](../../app/Modules/Notifications/Routes/api.php), [Providers](../../app/Modules/Providers/Routes/api.php), [Waste](../../app/Modules/Waste/Routes/api.php).

`UNKNOWN — NOT FOUND IN BACKEND`: API-wide pagination envelope override, total-count stability guarantees, filter validation for most route-closure query params, client-selectable sorting, full-text search, cursor parameters beyond framework-generated links.