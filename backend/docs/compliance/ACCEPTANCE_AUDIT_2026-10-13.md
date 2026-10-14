# Whole-application acceptance audit — 12–13 October 2026

Requirements: *Cahier des charges — DivaWaste Textile* (28 Sep 2026) and the decisions taken since
([DECISIONS.md](../DECISIONS.md), F16–F96). This audit replaces nothing in [REQUIREMENTS_MATRIX.md](REQUIREMENTS_MATRIX.md);
it re-checks it against the code after phases 1–5 and records what was actually executed.

## How it was verified

- **Copy**: a throwaway copy of `divawaste_demo` (93 tables, 2,034 rows), 7 migrations behind the code, migrated and
  seeded as a deployment would. `divawaste` and `divawaste_demo` were only read.
- **A — API run**: four scripted end-to-end workflows through the real HTTP API, each person with their own sign-in
  (administrator, environment manager, workshop operator, provider, a custom requester role): 21 + 21 + 48 checks.
- **U — browser**: headless Chrome on the production build of the frontend, by clicks: stock and provider forms
  (20 checks), pickups, lots, documents, override and English/phone (33 checks), workshop application on a phone
  viewport (13 checks).
- **P — probes**: every API route called by a member with no permission, by the administrator of another industrial
  company and by an unrelated provider (234 routes), then 25 follow-up calls with valid input.
- **T — automated tests**: the backend suite (named test files).

Status: **V** implemented and verified by the workflow · **I** implemented, verified only partly (named) ·
**M** missing or partial · **B** depends on a business decision · **V2** outside the MVP by the cahier.

## Requirements to implementation

| Area | Requirement (cahier / decision) | Status | Implementation | Evidence |
|---|---|---|---|---|
| Lots | M2-01 lot created from the workshop app (site, zone, type, weight) | V | `/m/lots/new`, `POST lots` | U (online, offline, English) · T `LotOperationsTest`, `OfflineSyncTest` |
| Lots | M2-04 manual weight; a weight nobody measured is empty, never 0 (F57) | V | `weight_basis`, `lots/{lot}/weigh` | A (unweighed lot weighed later: zone and site recalculated) · U · T `UnknownWeightTest` |
| Lots | Estimates identifiable (F58) | V | `weight_estimated`, "≈", "(estimé)" | A + U at every stage: lot, request, loading, reception ("indicatif", "Référence manquante") |
| Units | M1-05 kg by default; pieces, litres, m³ by type (F51, F81) | V | `Measure`, `UnitConversion` | A (300 pièces, 150 l unknown weight; 1,2 m³ with density → 1 320 kg computed) · U |
| Packages | Several packages in one lot, counted per packaging (F61–F65) | V | `package_count`, correction after departure | A + U (12 bags; 5→6 bales corrected after departure with a reason; slip and stored decision unchanged; authorisation drawn by the corrected count) |
| Origin | A lot without OF, material or received lot | V | origin optional (F29) | A + U (loose cardboard, no origin, nothing invented) |
| Stock | M2-07 real-time stock per site, zone, type; limits with alert | V | `/stock`, `stock-thresholds` | A + U: limits in t (1,66/1,5 t), m³ (1,2/1 m³), l (150/100 l), packages (16/10 bags); m³ refused for a type with no density |
| Stock | Movements between zones, with author | V | `lots/{lot}/store` | A (three moves in the history, each zone recalculated) · U |
| Capacity | Zone by packages or by volume, zone load, site weight (F76–F79) | V | `ZoneOrganisation` | A + U: 31 % room in packages; 1,8/2 m³ by volume; boxes with no stated volume → "Non vérifiable", lot named; site weight "Non vérifiable" with unweighed lots |
| Limits | Each limit answered on its own, four outcomes (F67–F69) | V | `Constraint` | A + U on stock, zones, authorisation, provider minimums |
| Authorisations | Customs quota by weight and per packaging (F23, F72) | V | `waste-authorizations` | A (request drew 263→463 kg and 6→11 bales, each its own limit) · T `IndependentLimitsTest`, `WasteAuthorizationTest` |
| Reception | M4-04 gap per lot in its unit, 5 % by default, rule per type (F50–F55) | V | `Tolerance`, `ToleranceRules` | A + U: −2,5 % within; −9,2 % to justify; 298→280 pieces (6,04 % over 3 %); 150→148 l; closing refused until explained |
| Split / group | M2-06 with 2 % weight tolerance (F56, F65) | I | lot dialogs | T `LotOperationsTest`, `PackageCountTest` · browser in phase 3 (not re-clicked in this audit) |
| Providers | M4-01 directory, accepted waste, channels, accreditations | V | `/providers` | A + U |
| Providers | M4-02 alert 30 days before expiry; expired not selectable | I | `accreditations:alert` | T `PickupScenarioTest` · dashboard shows "expire dans 17 jours" (U). The scheduler itself was not run |
| Minimums | Provider minimums in a unit, packaging asked for, warning or blocking (F83, F84) | V | `ProviderRequirements` | A + U: 2 t / 0,5 t warnings; 2 m³ blocking; 200 l warning; drums required → container refused |
| Minimums | Override: dedicated permission, written reason, audit, provider sees it (F87) | V | `pickups.override_provider_requirements` | A + U: requester refused with or without reason; manager refused without reason, sends with one; audit event; provider reads who and why and may still refuse |
| Minimums | Partners without an account, internal requirements, review after linking (F88, F89) | V | `partner_requirements` | A + U (source "Saisi par votre société…", contract reference) · review after linking: T `ProviderRequirementsTest` + browser in phase 5 |
| Pickups | M4-03 request, confirm or refuse; receiver with or without an account | V | `PickupWorkflow` | A: provider confirms itself (industrial refused); partner without account confirmed and received on its behalf; a recycler that does not transport needs a transporter |
| Loading | Lots scanned at loading; lots left behind go back to stock | V | `/m/loading`, `collect` | A (cardboard "Non chargé", back in stock) · U in English on the phone (typed lot numbers, 2/2, server shows "collected") |
| Money | M4-05 price per kg, per tonne or flat; net balance | V | `pricing` | A (net 511,200 DT) · U |
| Documents | M5-02 slip per regulatory code, signed in order (F21, F22) | I | `ManifestWorkflow` | A + U: issued at loading, PDF opened by clicks, designation, counts and pieces correct. Signing not re-clicked in this audit (phase 2 and MVP pass) |
| Documents | M2-02 QR label 100 × 50 mm | V | `lots/{lot}/label` | U (fetched by "Imprimer l'étiquette"; reads "300 pièces"). Not printed on a thermal printer |
| Documents | M5-01 register PDF and Excel | I | `/compliance/register` → queued report | U: export requested by clicks; the files were downloaded in phase 5, not in this run (the servers stopped first) |
| Documents | M5-03 annual declaration | I / B | `/compliance/declarations` | T `ComplianceApiTest`. Layout is ours, not the official ANGed form (B) |
| Documents | M5-04 certificates kept 10 years, linked to lots | I | certificate upload | T `CertificateUploadTest` · MVP pass. Not exercised here |
| Reporting | M6-01 dashboard; M6-02 kg per piece; M6-04 CSR report PDF/Excel | V | `/dashboard`, `/reports` | U (dashboard figures; CSR PDF generated and downloaded by clicks) · T `ReportingTest` |
| Workshop | M2-08 offline creation and synchronisation without loss or duplicate | V | Dexie outbox, `sync/push` | U: lot kept on the phone while offline, sent once when the network returned, history names the operator |
| Notifications | M7-01 email and in-app, deduplicated, both languages | V | `NotifyUsers`, `capacity_alerts` | A + U: one message per limit and state, French and English figures; pickup messages in the units of the lots |
| Audit | M7-02 audit log | V | `audit_logs` | A (override event with author) · T `AuditLoggerTest` |
| Permissions | Role × permission matrix, server-side | V | `PermissionCatalog`, `AccessResolver` | P (no-permission member: 0 business route answered; valid input still 403) · T `SiteScopedAuthorizationTest` |
| Tenancy | No query reads another company's data | V | `TenantScope` | P (another company: every Cap Bon record "not found", no list contains one; same lot number scanned gives its own lot) · T `TenantScopeTest` |
| Configuration | Rules set by administrators in business language | V | waste types, zones, limits, provider form | A + U (types in l and m³, zones by packages or volume, drum volume, limits in t/m³/l/packages; provider form FR/EN, desktop and phone) |
| i18n | French and English, responsive | V | Transloco, `lang/` | U: stock, notifications, provider form, pickup page, workshop app in English; provider form and pickup page at 390 px |
| Data | Personal data export and erasure | M | — | Not implemented (unchanged since the MVP pass) |
| Performance | 200 companies, 5 M lots | M | — | Not measured |
| Production link | M3-01 … M3-04 | V2 | production module exists (F26–F49) | Not audited here |

## Questions the brief asked

**Re-evaluate provider minimums at confirmation or loading?** Not as a rule that blocks. The cahier has no provider
minimum (it came from phase 5). At confirmation the provider itself accepts or refuses, having seen the request-time
answer, so its confirmation is the answer. At loading the truck is there: blocking would contradict F24 ("a truck
already loaded cannot be un-loaded by software"). What is missing is information: lots can be left behind at loading
(pickup 1 left one), so the quantity that leaves can fall below a minimum that was met at request. **Proposal, not
implemented**: at loading, judge the same rules again on what left, store it beside the request-time decision (never
replacing it), show "à la demande / au chargement" on the pickup, and tell the provider when a minimum it set is no
longer met. No blocking.

**Is family + treatment channel precise enough?** For the cahier, yes: it speaks of accepted waste types and channels
and has no minimum. In practice not always: "Produits textiles" holds cutting scraps, selvedges, garment rejects, roll
ends and downgraded pieces. A recycler that wants "500 kg of cutting scraps" cannot say so, and a minimum in pieces set
on the family is unverifiable for every lot of that family counted in kilograms. The evaluation and the provider API
already accept a catalogue item (`catalog_item`); only the forms do not offer it, and `partner_requirements` has no
column for it. **Proposal**: an optional "type de déchet" beside the family in both forms (and that one column). Waiting
for a decision.

## Defects found and fixed in this audit

1. Sign-in limited to 5 a minute **per address for everybody** — a workshop or the whole platform behind one address
   would lock itself out; and trusted proxies could not be configured (F90, F91).
2. A slip covering several waste types under one code named only the first (F92).
3. "Poids au départ 0 kg" for a pickup nobody weighed; unweighed lots no longer mentioned after reception; the list
   showed the same zero (F93).
4. Pickup notifications said "0.000 kg" for counted waste and compared pieces with kilograms (F94); litres were written
   "L" in messages and "l" on screens.
5. The CSR report showed "0 kg" for waste types nobody weighed (F95).
6. Provider and partner-requirement forms cut waste families and units off on a phone.
7. A counted lot's figure was labelled "Poids" on its page.

Added: `limits:evaluate --dry-run` (F96).

## Remaining

*Update 14 Oct 2026: the two proposals, the closure/signature question, the capacity-email recipients, the hazardous
codes and MariaDB are resolved in [STAGING_ACCEPTANCE_2026-10-14.md](STAGING_ACCEPTANCE_2026-10-14.md) (F97–F103).*

- **Business decisions**: the two proposals above; the open points of [MVP_ACCEPTANCE.md](../MVP_ACCEPTANCE.md)
  (prices, legal texts, official slip and ANGed layouts, legal value of the signature, VAT, reference data, stack
  deviations); whether closing a pickup should wait for signed slips (F21; slips of closed pickups are still unsigned
  in the demo data); whether workshop operators, who see the stock, should receive capacity emails by default (the
  rehearsal would have emailed Leila).
- **Data**: a hazardous waste type can be created without a regulatory code; its lots then leave without a slip, and
  the other lots of the same pickup too, because slips are issued all or nothing. Make the code required for hazardous
  types, or issue the slips that can be.
- **Not verified**: MariaDB (the rehearsal ran on MySQL 8.0.44); real mailbox delivery; thermal printer and camera on a
  real phone; load at the cahier's volumes; personal data export and erasure (missing).
