# Decisions and assumptions

What was decided during the takeover where the cahier des charges was silent, impossible to follow literally, or
depended on information Diva Software has not provided yet. **Items marked "to validate" need an answer from Diva.**

## Deviations from the imposed stack

| # | Cahier | Done | Why | Status |
|---|---|---|---|---|
| D1 | simple-qrcode | `bacon/bacon-qr-code` used directly | simple-qrcode requires bacon-qr-code v2; Fortify (imposed for 2FA) requires v3. They cannot be installed together. simple-qrcode is a thin wrapper over the same library | **To validate** (the cahier requires written agreement for any deviation) |
| D2 | Horizon | Standard queue worker; jobs and schedule are in place | Horizon does not run on Windows (development machine). Installing it on the Linux server changes no application code | To do at deployment |
| D3 | Angular latest | Angular 20.3 kept | The existing project was on 20; a major upgrade during a rebuild adds risk without functional gain | **To validate** |
| D4 | Larastan | Installed (3.12, PHPStan 2.2): level 5 on the whole project, no baseline, no ignored error | Level 5 is Larastan's default; higher levels mostly ask for type declarations on the route closures | Done (5 Oct 2026) |
| D5 | Scribe / Swagger | Scribe 5.11: `php artisan api:docs` generates OpenAPI 3, a Postman collection and an HTML page from the routes, and fails if a route is missing | The routes are closures, which Scribe cannot read alone: `app/Modules/Common/Docs` reads module, permissions, validation rules and business errors from the route code | Done (5 Oct 2026) |
| D6 | PHP extension | `ext-gd` is required | QR image on the label and logo in PDF reports | Informative |

## Provisional data — to validate

Seeded by `ProvisionalReferenceSeeder`, clearly separated from the stable reference data so it can be replaced.

| # | Data | Provisional value |
|---|---|---|
| P1 | Tunisian VAT on the subscription | 19 % |
| P2 | Stamp duty on invoices | 1 TND |
| P3 | Accreditation types | recycling/recovery, hazardous collection and transport, disposal… |
| P4 | Regulatory waste codes | European-catalogue-style codes (04 02 22, 15 01 01…) |
| P5 | Default waste types of the catalogue | 15 items |
| P6 | Plans | `trial`, `per_site`, `per_volume` — **without price** |
| P7 | Legal documents (terms, privacy, provider terms) | Placeholders, version `0.1-provisoire` |

## Functional decisions

| # | Decision | Reason |
|---|---|---|
| F1 | The price is entered by the platform when it activates a plan; the client's plan change is a request | The price grid is not decided (P6). No price is ever shown that Diva has not set |
| F2 | Every plan starts with the 30-day trial | One registration path; the paid plan starts when the platform activates it |
| F3 | A lapsed subscription makes the industrial account read-only (HTTP 402 on writes) instead of locking it out | The client must still reach its regulatory documents |
| F4 | Providers use the platform for free and are not subject to a subscription | Cahier: providers are invited parties |
| F5 | Stock is computed from lots, never stored | A stored counter can drift from the lots; the lots are the legal record |
| F6 | The dashboard is computed live with a 60-second cache | Simpler and exact. To revisit with measurements at volume (see OPERATIONS) |
| F7 | A pickup is flagged when its total **or any of its lots** is beyond the variance threshold | A 9 % gap on a small hazardous lot was hidden by a large lot received in full |
| F8 | Whoever may load a pickup (`pickups.load`) sees the confirmed pickups of the site, and nothing else about pickups | The operator must see what to load, not prices or history |
| F9 | Electronic signature of the slip = authenticated user + password re-entered + time + IP, in a fixed order | Meets "signed by each party" for traceability. **It is not a qualified signature — legal value to validate** |
| F10 | The annual declaration is a structured document computed from the data, validated then frozen | **The official ANGed form was not available — layout to align** |
| F11 | Offline covers lot creation and storing; loading a pickup needs the network | Loading changes what the provider sees and expects |
| F12 | Lockout: 5 failed sign-ins → 15 minutes, with an email to the account owner | The cahier gives the count, not the duration |
| F13 | Generated reports are private to the user who requested them and expire after 30 days | They are built with that user's site rights |
| F14 | Company legal name, tax ID and country are changed by the platform only | They identify the contracting party on invoices and regulatory documents |
| F15 | Only French ships; the language switch, English and Arabic files and right-to-left were prepared, not delivered | Cahier §1: other languages are V2. All texts are already in translation files |

## Not done

- **Personal data export and erasure** (non-functional requirement). No endpoint. Open.
- Performance and volume measurements at the cahier's targets.
- Tests on real devices: thermal printer for the label, phones for the camera scan, use with gloves.
- English and Arabic translations.
- `notification_logs` and `waste_daily_stats` tables exist in the schema and are not used.
