# Test accounts (local demo environment only)

Created by `DemoSeeder` when you run `scripts\demo.ps1 reset`. They exist in the demo database only
(`divawaste_demo`) and must never be created in production (the seeder refuses to run there).

**Password of every account: `DivaWaste!2026`** — Sign in at **http://localhost:4300**

All emails are already confirmed and no account has two-factor authentication.

## The platform administrator

| User | Email | Password | Role | Company | Site | Main purpose |
|---|---|---|---|---|---|---|
| **Admin Plateforme** | **`admin@divawaste.test`** | `DivaWaste!2026` | **Super-administrateur plateforme** | Diva Software (platform) | — | Operate the SaaS: approve / reject / suspend companies, accreditations, plans and prices, invoices, bank transfers, reference catalogue, role matrix, platform activity. Lands on `/platform/overview` |

## Textile du Cap Bon — the main client

| User | Email | Password | Role | Company | Site | Main purpose |
|---|---|---|---|---|---|---|
| Amira Trabelsi | `amira@capbon.test` | `DivaWaste!2026` | Administrateur client | Textile du Cap Bon | all | Everything on the client side: company, users, sites, subscription, audit |
| Sami Gharbi | `sami@capbon.test` | `DivaWaste!2026` | Responsable environnement | Textile du Cap Bon | all | Daily operations: lots, stock, pickups, register, slips, declarations, reports |
| Leila Mansour | `leila@capbon.test` | `DivaWaste!2026` | Opérateur atelier | Textile du Cap Bon | Usine de Nabeul only | Workshop app `/m`: create lots, scan, store, load a pickup, offline |
| Hedi Jaziri | `direction@capbon.test` | `DivaWaste!2026` | Direction (lecture) | Textile du Cap Bon | all | Read-only dashboard and reports, no action button |
| Nadia Khelifi | `audit@capbon.test` | `DivaWaste!2026` | Auditeur | Textile du Cap Bon | all | Read-only trail: lots, pickups, register, documents. Lands on `/lots` |

## Other companies

| User | Email | Password | Role | Company | Site | Main purpose |
|---|---|---|---|---|---|---|
| Karim Ben Salah | `karim@sahel-recyclage.test` | `DivaWaste!2026` | Administrateur prestataire | Recyclage du Sahel (provider) | — | Confirm / refuse requests, reception, treatment, sign slips, certificates, profile, accreditations |
| Nour Belhadj | `nour@monastir-jeans.test` | `DivaWaste!2026` | Administrateur client | Monastir Jeans Confection (industrial, paying) | all | **Tenant isolation** (its own 3 lots); a paying client with an invoice and a declared bank transfer |
| Mehdi Chaabane | `mehdi@tissages-kh.test` | `DivaWaste!2026` | Administrateur client | Tissages de Ksar Hellal (industrial) | — | Registration **awaiting approval**: sees only the status page |
| Sonia Hammami | `sonia@sfax-eco.test` | `DivaWaste!2026` | Administrateur prestataire | Sfax Éco-Collecte (provider) | — | Provider awaiting approval, with one accreditation to review |
| Walid Dridi | `walid@ecotri-tunis.test` | `DivaWaste!2026` | Administrateur prestataire | Éco-Tri Tunis (provider) | — | Provider whose accreditation **has expired**: listed, cannot be chosen |
| Hatem Ben Amor | `hatem@sousse-mode.test` | `DivaWaste!2026` | Administrateur client | Sousse Mode Confection (industrial) | — | A **suspended** company: sees the status page with the reason |
| Rim Gharsallah | `rim@atelier-rades.test` | `DivaWaste!2026` | Administrateur client | Atelier Textile de Radès (industrial) | — | A **rejected** registration: sees the status page with the reason |

## What the demo data contains (after a reset)

**The platform's situation** (what the administrator finds on the overview):

| Item | Value |
|---|---|
| Registrations to approve | Tissages de Ksar Hellal (industrial), Sfax Éco-Collecte (provider) |
| Accreditation to review | Sfax Éco-Collecte — ANGED-REC-2026-207 |
| Bank transfer to validate | Monastir Jeans Confection — 179,500 DT, reference VIR-BIAT-2026-10-0412 |
| Plan to activate | Textile du Cap Bon asked for "Abonnement par site", 2 sites |
| Paying client | Monastir Jeans Confection — per site, 1 site, 150 DT / month, invoice issued |
| On trial | Textile du Cap Bon |
| Suspended | Sousse Mode Confection (unpaid invoices) |
| Rejected | Atelier Textile de Radès (tax identifier not found) |
| Reference prices | Per site: 150 DT / month, 1 500 DT / year. Per volume: 400 DT / month, 4 000 DT / year. **Example values**, not a decided price grid |

**Textile du Cap Bon** — two sites, each with four zones (Atelier de coupe, Confection, Teinture, Aire de stockage
déchets): Usine de Nabeul and Atelier de Korba. Six waste types: Chutes de coupe, Déchets de fil, Rebuts de
confection, Cartons, Films et sacs plastique, Résidus de teinture (hazardous).

About 70 lots over three months and six pickups with Recyclage du Sahel:

| Pickup | State | Use |
|---|---|---|
| ENL-2026-0001, 0002 | Clôturé | History, register, signed slips, dashboard figures |
| ENL-2026-0003, 0004 | Réceptionné, priced, treated | Can be closed; a certificate can be uploaded |
| ENL-2026-0005 | Demandé | Waiting for the provider: confirm or refuse as Karim |
| ENL-2026-0006 | Confirmé for today, 3 lots (L-2026-000069, 070, 071) | Ready to **load from the workshop app**; its slip BSD-2026-0005 awaits the producer's signature |

Numbers are assigned in order at seeding; dates are relative to the day you reset, so "current month" lots may be
few at the very beginning of a month.

## Rules worth knowing while testing

- **Five sign-in attempts per minute.** If you switch accounts quickly you will see "Trop de tentatives": wait one
  minute.
- **Five wrong passwords** lock the account for 15 minutes.
- To use two accounts at the same time, use one normal and one private window — the session is a cookie, so two
  tabs of the same window share one account.
- Reset everything with `scripts\demo.ps1 reset` (35 seconds). Accounts you created by invitation or registration
  disappear; the thirteen above come back.
