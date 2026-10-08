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
| F16 | Zone capacity is counted in lots per packaging ("40 bales, or 100 bags"); shares add up; an optional weight limit is a second ceiling. A lot whose packaging the zone does not mention is reported, not converted | Storage room depends on volume, not weight, and people know how many bales fit, not how many m³. No guessed conversion |
| F17 | The waste types and colours a zone is reserved for are a warning, never a refusal | An operator on the floor must never be blocked from recording a lot |
| F18 | Pantone: a reference typed by the company on its own colours; the Pantone library is not bundled | The library is proprietary; bundling it needs a licence |
| F19 | A transporter or receiver without an account is recorded by the industrial as its own partner (not a company of the platform). The industrial records the receiver's stages; a receiver with an account keeps its own | The same partner recorded by two industrials must not become one shared, unverified company; tenant isolation stays simple |
| F20 | The platform does not review the authorisation of a partner without an account; for hazardous waste a recorded reference, valid on the pickup date and marked as covering hazardous waste, is required | The industrial answers for the partners it brings. **Whether this is enough under Tunisian law is to validate** |
| F21 | Every waste has a tracking slip, issued automatically at loading; one per regulatory code, or per waste type when a non-hazardous waste has no code. Closing a pickup does not require its slips to be signed | Requested by the client. Blocking the closure on paper that may lag would stop the trail of the lots; **to confirm** |
| F22 | A party signs electronically or by hand. A slip with a handwritten signature is complete only when the scan of the signed copy is attached; that scan is the archived document | A typed-in name is not a signature: the paper is the proof |
| F23 | An authorisation (customs declaration) is a quota per waste type over a period; its usage is counted from the pickup lines, never stored. A pickup holds its share from the request, and is refused when nothing covers it on the pickup date | Requested by the client: blocked until a new authorisation is entered. Counting from the lines means a cancelled pickup releases its share by itself |
| F24 | The allowed quantity is a weight, a number of lots and/or lots per packaging — the same engine as zone capacity. At loading, a weight above the remaining room is shown as exceeded, not refused | The document states quantities in different forms. A truck already loaded cannot be un-loaded by software |
| F25 | A partner without an account is tied to an account by hand, on a suggestion based on the same tax ID among published providers. History keeps the partner as recorded | Automatic merging on a tax ID typed by someone else is not safe enough; published providers are already visible to every industrial |
| F26 | Production is a reference to attribute waste (clients, suppliers, materials and received batches, articles with consumption per piece, orders, OFs with stages per site and real consumption), not an ERP: no planning, purchasing, stock or prices | The factories already run an ERP for those; duplicating it would be large and always behind. The import feeds this reference from it |
| F27 | The import maps the columns of any file to the information needed, checks every line before writing, skips lines in error, creates on the way what a line points to, and is idempotent | No fixed template to conform to: each ERP exports differently. Re-importing a corrected file must be safe |
| F28 | New permissions `production.view`, `production.manage`, `production.stats.view`. An existing role receives a permission that is new in a version only if its default matrix includes it; roles edited by hand keep their edits | Clients and volumes are commercially sensitive. `php artisan db:seed --class=AuthorizationSeeder --force` must be run on upgrade |
| F29 | A lot's stage is deduced from the type of its zone, its material from the OF when the OF has a single one; the operator only chooses the OF | One tap on the floor. A lot created without an OF still knows its stage, which is what makes the estimate possible |
| F30 | Statistics keep measured (lot names its OF) and estimated (lot only says its stage; shared between the OFs at that stage, site and day, by pieces done) apart; what cannot be attributed is reported, not guessed | Sewing-line waste often cannot be tied to one OF. An estimate shown as a measurement would be a false precision |
| F31 | The waste rate is waste ÷ consumed material in kg; metres are converted with the fabric's width and weight, otherwise no rate is shown | Waste is weighed, fabric is measured in metres: without width and weight the two cannot be compared |
| F32 | Pieces produced per site and month follow the finished OFs (month of the end, site of the last stage); a manual entry always wins | The dashboard's kg per piece stays right without retyping; the person who typed a figure knows better than a rule |
| F33 | The word is "limite", not "seuil"; a line of stock that nothing watches says so ("Aucune limite", "Capacité non définie") and offers to define one | Client feedback: an absent limit must not look like "everything is fine" |
| F34 | Most pages open with a short explanation that can be hidden, remembered per page on the device | Client feedback: the screens must explain themselves to an occasional user |
| F15 | Only French ships; the language switch, English and Arabic files and right-to-left were prepared, not delivered | Cahier §1: other languages are V2. All texts are already in translation files |

## Not done

- **Personal data export and erasure** (non-functional requirement). No endpoint. Open.
- Performance and volume measurements at the cahier's targets.
- Production statistics in the queued "Rapports et exports" list (they are produced directly from their page, as PDF and Excel).
- Tests on real devices: thermal printer for the label, phones for the camera scan, use with gloves.
- English and Arabic translations.
- `notification_logs` and `waste_daily_stats` tables exist in the schema and are not used.
