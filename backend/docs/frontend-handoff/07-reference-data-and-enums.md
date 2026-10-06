# Reference Data And Enums

Do not translate or rename persisted backend codes. Reference rows may carry localized JSON names; see [ReferenceDataSeeder](../../database/seeders/ReferenceDataSeeder.php). Some tables are intentionally empty until validated regulatory sources exist.

## Application Enums

- Company type: `industrial`, `provider`, `brand`; company status: `pending_verification`, `pending_approval`, `active`, `rejected`, `suspended`, `closed`; membership status: `active`, `suspended`, `removed`.
- User status: `active`, `disabled`, `anonymized`.
- User invitation status: `pending`, `accepted`, `revoked`, `expired`; legal document type: `terms`, `privacy`, `dpa`, `provider_terms`; legal acceptance context: `registration`, `invitation`, `reacceptance`, `provider_registration`.
- Role/permission audience: `platform`, `industrial`, `provider`, `brand`, `any`; permission scope: `platform`, `company`, `site`.
- Site kind: `production`, `warehouse`, `mixed`; stock alert level: `warning`, `exceeded`.
- Lot status: `created`, `stored`, `awaiting_pickup`, `collected`, `treated`, `closed`; origin: `created`, `split`, `grouping`; closed reason: `completed`, `split`, `grouped`, `cancelled`.
- Lot tag type: `qr`, `rfid_epc`; tag status: `unassigned`, `active`, `revoked`; weight source: `manual`, `scale`, `computed`.
- Pickup status: `draft`, `requested`, `confirmed`, `refused`, `collected`, `received`, `completed`, `cancelled`; requested time slot: `morning`, `afternoon`, `any`; departure weight source: `lots_sum`, `weighbridge`. Pickup line: `planned`, `loaded`, `not_loaded`, `received`, `rejected`, `treated`; pricing mode: `per_kg`, `per_tonne`, `flat`; price basis: `received`, `departure`, `flat`.
- Document type: `recycling_certificate`, `destruction_certificate`, `hazardous_manifest`, `annual_declaration`; document status: `draft`, `pending_signatures`, `issued`, `superseded`, `cancelled`.
- Manifest status: `draft`, `pending_producer_signature`, `pending_transporter_signature`, `pending_receiver_signature`, `completed`, `cancelled`.
- Annual declaration status: `draft`, `in_review`, `validated`, `submitted`; signature status `pending`, `signed`, `declined`; signer party `producer`, `transporter`, `receiver`.
- Subscription status: `trialing`, `active`, `past_due`, `suspended`, `cancelled`, `expired`; invoice status `draft`, `issued`, `partially_paid`, `paid`, `overdue`, `cancelled`; payment status `pending`, `validated`, `rejected`, `refunded`.
- Subscription billing model: `trial`, `per_site`, `per_volume`, `hybrid`; price component: `base_fee`, `per_site`, `per_tonne`; tax type: `vat`, `stamp_duty`, `withholding`.
- Provider accreditation review status: `pending`, `approved`, `rejected`, `revoked`; provider partnership status: `active`, `suspended`, `ended`.
- Device platform input: `android`, `ios`, `other`; sync operation stored status `applied`, `rejected`, `conflict`; sync resolution status `open`, `resolved`, `dismissed`.
- Report format: `pdf`, `xlsx`, `csv`; report status: `queued`, `processing`, `completed`, `failed`, `expired`.
- Notification preference/log channels: `mail`, `database`, `whatsapp`; notification-log status: `queued`, `sent`, `failed`, `bounced`.
- Audit actor type: `user`, `system`, `api_client`, `scheduler`; audit source: `web`, `mobile`, `provider_portal`, `api`, `sync`, `system`, `console`.
- Bank/payment method fields: `bank_transfer`, `card`; subscription interval `monthly`, `yearly`; invoice type `invoice`, `credit_note`; invoice item types `subscription`, `site`, `volume`, `adjustment`, `discount`; usage metric `active_sites`, `tonnes_managed`.
- Notification log status: `queued`, `sent`, `failed`, `bounced`; audit actor type: `user`, `system`, `api_client`, `scheduler`; audit source: `web`, `mobile`, `provider_portal`, `api`, `sync`, `system`, `console`.
- Split/group operation type and lineage relation: `split`, `grouping`; tag type `qr`, `rfid_epc`; tag status `unassigned`, `active`, `revoked`; weight source `manual`, `scale`, `computed`.

Sources: enum files under [app/Modules/*/Enums](../../app/Modules/), [migrations](../../database/migrations/), and [request rules](../../app/Modules/).

## Seeded Global Reference Values

`ReferenceDataSeeder` seeds:

- Currencies: `TND` (minor unit 3), `MAD` (2), `EUR` (2).
- Countries: `TN`/Tunisia (code system `TN`, signup enabled, authority ANGed, TND); `MA`/Morocco (`MA`, MAD); `FR`/France and `BE`/Belgium (`EU_LOW`, EUR). Seeded locale is French and country-specific timezone/tax labels are in source.
- Units: `kg` (mass base), `t` (mass factor 1000), `m3` (volume base), `l` (volume factor 0.001), `piece` (count base).
- Textile activities: `spinning`, `weaving`, `knitting`, `dyeing`, `finishing`, `garment_making`, `washing`, `printing`, `other`.
- Zone types: `cutting`, `sewing`, `dyeing`, `finishing`, `warehouse`, `waste_storage`, `spinning`, `weaving`, `knitting`, `other`.
- Packaging: `bag`, `bale`, `big_bag`, `drum`, `box`, `pallet`, `container`, `bulk`.
- Waste families: `textile_fibre`, `textile_product`, `packaging`, `chemical_sludge`, `oil`, `metal`, `other`.
- Materials: `CO`, `LI`, `WO`, `CV`, `PES`, `PA`, `PAN`, `EL`, `OTHER`.
- Color families: `white`, `ecru`, `undyed`, `black`, `grey`, `navy`, `blue`, `red`, `pink`, `yellow`, `orange`, `green`, `brown`, `beige`, `multicolor`, `mixed`.
- Treatment channels: `reuse`, `recycling`, `energy_recovery`, `incineration`, `other_treatment`, `landfill`.
- Waste catalog items (14): `cutting_scraps`, `selvedges`, `yarn_waste`, `garment_rejects`, `roll_ends`, `downgraded_pieces`, `cardboard`, `cones`, `plastic_films_bags`, `etp_sludge`, `dye_residues`, `oils`, `needles`, `metals`. Only `oils` is provisionally marked hazardous by this seeder.
- Subscription plan: `trial`, 30-day trial, public.

## Authorization Values

Seeded role and permission names are enumerated in [03-auth-and-permissions.md](03-auth-and-permissions.md). Roles: `super_admin`, `platform_support`, `client_admin`, `environment_manager`, `workshop_operator`, `management_viewer`, `auditor`, `provider_admin`, `provider_operator`.

## Intentionally Unseeded Reference Data

Seeder comments explicitly defer: `tax_rates`, `regulatory_waste_codes`, `accreditation_types`, `legal_documents`, and `subscription_plan_prices` until validated sources exist (despite migration tables for them). Consumers must not assume these have usable rows in every environment. Also no API lookup endpoints for the global reference vocabularies were found.

`UNKNOWN — NOT FOUND IN BACKEND`: exhaustive meanings for every code beyond names and evident metadata; dynamic/custom reference values per installation; definitive regulatory code and accreditation content.