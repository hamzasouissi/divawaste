# Test accounts (local demo environment only)

Created by `DemoSeeder` when you run `scripts\demo.ps1 reset`. They exist in the demo database only (`divawaste_demo`)
and must never be created in production (the seeder refuses to run there).

**Password of every account: `DivaWaste!2026`** — Sign in at **http://localhost:4300**

All emails are already confirmed and no account has two-factor authentication.

| # | Email | Person | Company | Role | Site | Lands on | Use it to test |
|---|---|---|---|---|---|---|---|
| 1 | `admin@divawaste.test` | Admin Plateforme | Diva Software (platform) | Administrateur plateforme | — | `/platform/companies` | Approve / reject / suspend companies, review accreditations, validate bank transfers, reference catalogue, role matrix |
| 2 | `amira@capbon.test` | Amira Trabelsi | Textile du Cap Bon (industrial) | Administrateur client | all sites | `/dashboard` | Everything on the client side: company, users, sites, pickups, compliance, subscription, audit |
| 3 | `sami@capbon.test` | Sami Gharbi | Textile du Cap Bon | Responsable environnement | all sites | `/dashboard` | Day-to-day operations: lots, stock, pickups, register, slips, declarations, reports |
| 4 | `leila@capbon.test` | Leila Mansour | Textile du Cap Bon | Opérateur atelier | Usine de Nabeul only | `/m` (workshop app) | Create lots, scan, store, load a pickup, offline mode |
| 5 | `direction@capbon.test` | Hedi Jaziri | Textile du Cap Bon | Direction (lecture) | all sites | `/dashboard` | Read-only: dashboard and reports, no action buttons |
| 6 | `audit@capbon.test` | Nadia Khelifi | Textile du Cap Bon | Auditeur | all sites | `/lots` | Read-only trail: lots, pickups, register, documents. No dashboard, no administration |
| 7 | `karim@sahel-recyclage.test` | Karim Ben Salah | Recyclage du Sahel (provider) | Administrateur prestataire | — | `/provider/pickups` | Confirm / refuse requests, reception, treatment, sign slips, upload certificates, profile and accreditations |
| 8 | `nour@monastir-jeans.test` | Nour Belhadj | Monastir Jeans Confection (industrial) | Administrateur client | all sites | `/dashboard` | **Tenant isolation**: a second, unrelated client with its own 3 lots |
| 9 | `mehdi@tissages-kh.test` | Mehdi Chaabane | Tissages de Ksar Hellal (industrial) | Administrateur client | — | `/account-status` | A registration **awaiting approval**: sees only "En attente de validation" until account 1 approves it |
| 10 | `sonia@sfax-eco.test` | Sonia Hammami | Sfax Éco-Collecte (provider) | Administrateur prestataire | — | `/account-status` | A provider awaiting approval, with one accreditation to review |
| 11 | `walid@ecotri-tunis.test` | Walid Dridi | Éco-Tri Tunis (provider) | Administrateur prestataire | — | `/provider/pickups` | A provider whose accreditation **has expired**: listed in the directory, cannot be chosen |

## What the demo data contains (after a reset)

**Textile du Cap Bon** — two sites, each with four zones (Atelier de coupe, Confection, Teinture, Aire de stockage déchets):
Usine de Nabeul and Atelier de Korba. Six waste types: Chutes de coupe, Déchets de fil, Rebuts de confection, Cartons,
Films et sacs plastique, Résidus de teinture (dangerous).

About 70 lots over three months and six pickups with Recyclage du Sahel:

| Pickup | State | Use |
|---|---|---|
| ENL-2026-0001, 0002 | Clôturé | History, register, signed slips, dashboard figures |
| ENL-2026-0003, 0004 | Réceptionné, priced, treated | Can be closed (`Clôturer`); a certificate can be uploaded |
| ENL-2026-0005 | Demandé | Waiting for the provider: confirm or refuse as Karim |
| ENL-2026-0006 | Confirmé for today, 3 lots | Ready to **load from the workshop app**; its slip BSD-2026-0005 awaits the producer's signature |

Numbers are assigned in order at seeding; the dates are relative to the day you reset, so "current month" lots may be
few at the very beginning of a month.

Other companies: Monastir Jeans Confection (1 site, 3 lots), Tissages de Ksar Hellal and Sfax Éco-Collecte (awaiting
approval), Éco-Tri Tunis (expired accreditation).

## Rules worth knowing while testing

- **Five sign-in attempts per minute** per browser/IP. If you switch accounts quickly you will see "Trop de tentatives":
  wait one minute.
- **Five wrong passwords** lock the account for 15 minutes (an email is "sent": see the mail log).
- To use two accounts at the same time, use two browser profiles or one normal and one private window — the session is a
  cookie, so two tabs of the same window share one account.
- Reset everything with `scripts\demo.ps1 reset` (35 seconds). Accounts you created by invitation or registration
  disappear; the eleven above come back.
