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
| F18 | Pantone: a reference typed by the company on its own colours; the Pantone library is not bundled (see F35) | The library is proprietary; bundling it needs a licence |
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
| F35 | The application can hold a colour catalogue but ships none: `php artisan colors:import` loads JSON files the operator of the platform provides, `colors:remove-catalogue` removes them. No Pantone data is in the repository | The dataset tested (github.com/brettapeters/pantones) has no licence, and Pantone claims rights on its libraries: using it is a decision for whoever holds the rights or a licence, not something the code may assume |
| F36 | The catalogue lives in `color_families` (rows without a company, `source = import`), is read-only, and is not offered on lots: a company takes a colour into its own list, and only that list is offered | One colour table and one foreign key everywhere; operators choose in a short list, not among 3,000 swatches; removing the catalogue never breaks a lot |
| F37 | A reference is unique per owner, colour system and collection, never alone | The same reference exists in several collections with different colours |
| F38 | Lab values are computed from the HEX value (sRGB, D65); imported colours are marked `unverified`; matches use CIEDE2000 and are labelled approximate | Nothing was measured on a physical sample: the application must not present a screen colour as a colour standard |
| F39 | A company colour is deleted only while nothing uses it; otherwise it is withdrawn | Lots and documents keep naming their colour |
| F40 | Received lot, produced lot and waste lot are three records. A received lot carries its own supplier (proposed from the material, never rewritten by it); a produced lot belongs to an OF and has no supplier | The supplier is a fact about a delivery, the client a fact about an order; mixing them breaks traceability |
| F41 | Neither received nor produced lots are a stock: no balance, location or movement. "Consumed" on a received lot is the sum of what OFs declared; more than the lot held is flagged, not blocked | The cahier keeps raw-material inventory out of scope; a flag reveals a data error without stopping production records |
| F42 | Material cost = quantity consumed × cost of the received lot (price paid + delivery cost spread over its quantity). Lot not specified = weighted average of the priced lots of the material, counted apart as "estimated". No priced lot at all = unknown, never zero | Actual lot cost is the only method that needs no assumption; the average is an explicit, labelled fallback; a silent zero would overstate every margin |
| F43 | There is no invoicing module for sales (the Billing module is the platform's subscription). Selling prices sit on order lines; invoices and credit notes issued elsewhere are recorded by number, date and amount | The least disruptive way to tell "ordered" from "invoiced" without building accounting |
| F44 | Invoices belong to an OF only when they name it or when the order has a single OF; otherwise they stay at order level | Any other split would invent a figure |
| F45 | Recorded gain = produced quantity × net agreed price − recorded costs, given only when price, production and every consumption cost are known; the missing data is named | A partial margin presented as profit is worse than no margin |
| F46 | Amounts are excluding tax in the company's currency; an amount in another currency is left out and reported. No exchange rates | No rate source exists; converting silently would falsify totals |
| F47 | Money is behind `production.finance.view`: without it amounts are null in every answer, cannot be written (existing ones are kept), and money imports are refused | Workshop planners follow production without seeing prices or margins |
| F48 | Imports of events (received lots, consumptions, produced lots, expenses, invoices) require their parent to exist and never create or choose one; reference data imports still create clients/suppliers/articles and say so | A wrong parent created silently is worse than a rejected line |
| F49 | The waste register stays the one record of waste; production, received and produced lots only explain it. Navigation follows: operations, compliance, reference, then production (its waste statistics first, money last) | DivaWaste is a waste-traceability tool, not an ERP |
| F50 | A gap is judged per lot, as a percentage of what left, by the most precise rule among: waste type + channel, type, channel, every waste, then the company setting (5 % by default, cahier M4-04). No blocking limit and no regulatory percentage exist by default | The cahier defines only the 5 % warning; any other value is the company's to configure, a legal one only with its text |
| F51 | What a type is compared in follows from its unit (mass, count, volume). Pieces or litres become kilograms only through the conversion the type states; otherwise the weight is recorded as 0 kg with `weight_basis = unknown` and shown as "non mesuré" | `net_weight_kg` is not nullable across stock, statistics and documents; a flagged zero is the least disruptive way never to present a guess as a weight |
| F52 | Above the warning limit the gap is explained (pickups.complete); above the blocking limit it is accepted by pickups.authorize_variance, with a reason. Nothing is rejected or altered automatically; closing is what waits | The cahier asks for an acknowledgement, not a refusal; a blocking level needed a distinct entitlement |
| F53 | The rule and the figures it was applied to are stored on the pickup line at reception | A later change of settings must not rewrite why a decision was taken |
| F54 | A missing or zero reference gives no percentage and asks for a review; 0 against 0 is not a gap | A percentage of nothing is meaningless; hiding the case would be worse |
| F55 | The whole of a pickup is judged apart only when a weighed lot that left was not received at all | Each received lot is already judged by its own rule; with different rules per type a single total percentage has no meaning |
| F56 | Counted lots are not split or grouped, and are priced flat unless weighed | Both operations and per-kg prices share out a weight the lot does not have |
| F15 | Only French ships; the language switch, English and Arabic files and right-to-left were prepared, not delivered | Cahier §1: other languages are V2. All texts are already in translation files |

## Not done

- Tolerances and counted waste: stock limits, waste authorisations (customs quota), statistics and the exported register remain in kilograms — a counted lot without a weight counts for 0 kg there (the register API carries its quantity; its PDF/Excel export does not yet). No per-piece price. No minimum/maximum quantity per lot. Split/group of counted lots. Suggestions of an expected waste quantity from history are not built: nothing in the cahier defines when history is reliable enough.
- Production: attachments (scanned invoice, delivery note) on received lots, expenses and invoices — only their reference is recorded. No labour or machine rates: such costs count only when entered as an expense. No currency conversion. Cost-and-gain figures are not in the queued "Rapports et exports" list (Excel export from their page).
- **Personal data export and erasure** (non-functional requirement). No endpoint. Open.
- Performance and volume measurements at the cahier's targets.
- Production statistics in the queued "Rapports et exports" list (they are produced directly from their page, as PDF and Excel).
- Tests on real devices: thermal printer for the label, phones for the camera scan, use with gloves.
- English and Arabic translations.
- `notification_logs` and `waste_daily_stats` tables exist in the schema and are not used.
