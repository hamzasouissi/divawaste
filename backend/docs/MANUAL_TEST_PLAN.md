# Manual test plan

Ten tests that cover every MVP workflow, to run by hand in the demo environment. Each step is
**Action → Expected result**.

Every test below was executed click by click on 5 October 2026, on the current interface, against a freshly
reset demo database. The shortest end-to-end run is the golden path of [MVP_ACCEPTANCE.md](MVP_ACCEPTANCE.md).

## Before you start

1. Start the demo ([DEVELOPER_RUNBOOK.md](DEVELOPER_RUNBOOK.md)) and **reset the data** so it matches this plan:
   `powershell -ExecutionPolicy Bypass -File .\scripts\demo.ps1 reset`
2. Open http://localhost:4300. Password of every account: `DivaWaste!2026` ([TEST_ACCOUNTS.md](TEST_ACCOUNTS.md)).
3. Use **one private window per account** you need at the same time.
4. Emails are not sent: `powershell -ExecutionPolicy Bypass -File .\scripts\demo.ps1 mail` prints the latest links.
5. Sign-in is limited to 5 attempts per minute: if you see "Trop de tentatives", wait a minute.

Run the tests in order: some reuse what a previous one created.

---

## Test 01 — Platform administrator: a company registers and is approved

| # | Action | Expected result |
|---|---|---|
| 1 | Private window → `/register`. Keep "Industriel textile". Fill: Raison sociale `Maille Bizerte SARL`, Matricule fiscal `4455667H`, Ville `Bizerte`, Téléphone, one activity; contact `Rania Mejri`, `rania@maille-bizerte.test`, phone, password `DivaWaste!2026`; Formule "Abonnement par site"; tick the conditions; **Créer mon compte** | "Vérifiez votre boîte mail" |
| 2 | Run `demo.ps1 mail`, open the `verify-email` link in the same window | "Adresse email confirmée" |
| 3 | **Se connecter** as Rania | "État de votre compte — En attente de validation" |
| 4 | Normal window → sign in as `admin@divawaste.test` | "Vue d'ensemble"; "inscription(s) à valider" shows 3 |
| 5 | Click that tile | Sociétés filtered on "En attente de validation": Maille Bizerte, Tissages de Ksar Hellal, Sfax Éco-Collecte |
| 6 | Open **Maille Bizerte SARL** | Tax ID, contact with "Email confirmé : Oui", "Formule demandée : Abonnement par site" |
| 7 | **Valider l'inscription** → **Confirmer** | Status "Active"; panel Abonnement "Essai gratuit", end date in 30 days |
| 8 | Rania's window → **Actualiser** | The dashboard opens, with the banner "Terminez la mise en route" |
| 9 | Admin → **Activité de la plateforme** | First line "Inscription validée — Maille Bizerte SARL — Admin Plateforme" |
| 10 | Admin → **Catalogue de déchets** → **Nouveau type de référence**: code `denim_offcuts`, family, name `Chutes de denim délavé`, waste code 04 02 22 → **Enregistrer** | "Catalogue mis à jour"; search `denim` finds it, "Activé par 0" |

## Test 02 — Platform administrator: reject, suspend, plan, invoice, payment

| # | Action | Expected result |
|---|---|---|
| 1 | Admin → Sociétés → **Tissages de Ksar Hellal** → **Rejeter**, type a reason → **Confirmer** | Status "Rejetée", the reason is shown |
| 2 | Private window → sign in as `mehdi@tissages-kh.test` | "Rejetée — Votre inscription n'a pas été retenue" with your reason |
| 3 | Admin → Vue d'ensemble → "Formules demandées" → **Textile du Cap Bon** | Abonnement "Essai gratuit"; form pre-filled: Abonnement par site, 2 sites, 150, hint "Tarif de référence : 150,000 DT" |
| 4 | **Activer une formule** | "Formule activée et première facture émise"; Abonnement "Actif", 150 DT / mois, 2 sites |
| 5 | Admin → **Factures** | Two invoices: Textile du Cap Bon 358,000 DT "À régler", Monastir Jeans Confection 179,500 DT "À régler — Virement déclaré" |
| 6 | Admin → **Virements déclarés** → **Valider** on Monastir → **Confirmer** | List empty; in Factures the Monastir invoice is "Réglée" |
| 7 | Private window → sign in as `amira@capbon.test` → Abonnement | Plan "Abonnement par site — Actif"; invoice 358 DT with **Déclarer un virement** |
| 8 | **Déclarer un virement**: reference `VIR-TEST-001`, today's date → confirm | "Virement en cours de validation"; admin's overview shows 1 "virement à rapprocher" |
| 9 | Admin → Sociétés → **Monastir Jeans Confection** → **Suspendre**, reason → **Confirmer** | Status "Suspendue" |
| 10 | Private window → sign in as `nour@monastir-jeans.test` | "Suspendue — L'accès de cette société est suspendu" with the reason |
| 11 | Admin → **Réactiver** → **Confirmer**; Nour clicks **Actualiser** | Nour reaches her dashboard |
| 12 | Admin → **Formules et tarifs** → **Modifier** on "Abonnement par site": monthly 180 → **Enregistrer** | Line shows 180,000 DT; Textile du Cap Bon's subscription still says 150 DT |
| 13 | Admin → Sociétés, filter "Suspendue" then "Rejetée" | Sousse Mode Confection (suspended); Atelier Textile de Radès and Tissages de Ksar Hellal (rejected) |

## Test 03 — Client administrator: set up the company

Account `rania@maille-bizerte.test` (created in Test 01), or `amira@capbon.test` for steps 4–8.

| # | Action | Expected result |
|---|---|---|
| 1 | Dashboard banner → **Reprendre** | Wizard with 4 steps, none done |
| 2 | Step "Société" → fill the address → **Enregistrer** | "Modifications enregistrées" |
| 3 | **Sites et zones** → **Nouveau site**: code `BIZ`, name `Usine de Bizerte`, city → create; open it → **Ajouter une zone**: code `COUPE`, name `Atelier de coupe`, type Coupe | Site listed "Actif"; zone listed |
| 4 | **Types de déchets** → tab **Catalogue de référence** → **Activer** on "Chutes de coupe" | Button becomes "Activé"; the type is in "Mes types" |
| 5 | **Utilisateurs et rôles** → **Inviter un utilisateur**: an email, role "Opérateur atelier", site → send | Invitation listed "En attente" in tab Invitations |
| 6 | `demo.ps1 mail` → open the `invitation` link in a private window, choose a password, accept | "Invitation acceptée. Vous pouvez vous connecter." Sign in with the new password: lands on `/m` |
| 7 | Tab **Membres** → "…" on a member → **Modifier les rôles** | Dialog with role and sites |
| 8 | **Journal d'audit** | Lines for the site, the zone, the type created; click one → before / after values |

## Test 04 — Operator: the workshop application

Account `leila@capbon.test`. On a PC: F12 → device toolbar → a phone size.

| # | Action | Expected result |
|---|---|---|
| 1 | Sign in | `/m`: Nouveau lot, Scanner, Charger un enlèvement. Band "En ligne — Tout est synchronisé" |
| 2 | **Nouveau lot** → "Atelier de coupe" → "Chutes de coupe" → type `37,5` → **Créer le lot** | "Lot créé" with a number `L-2026-0000NN` |
| 3 | **Accueil** → **Scanner** → **Saisir le code à la main** → `L-2026-000062` → **Confirmer** | Lot page: Résidus de teinture, Dangereux, 33 kg, status Créé |
| 4 | **Mettre en stock** | "Lot mis en stock", status "Stocké" |
| 5 | F12 → Network → **Offline** | Band turns amber "Hors ligne" |
| 6 | **Nouveau lot** → Confection → Déchets de fil → `12` → **Créer le lot** | "Lot enregistré sur l'appareil" (no number yet) |
| 7 | Tap the band | Queue: the lot "À synchroniser" |
| 8 | Network → **No throttling** | Within a few seconds the lot is "Synchronisé" with its number |
| 9 | Address bar → `/dashboard` | Sent back to `/m` |

Do not reload the page while offline with `ng serve`: only the production build starts without network.

## Test 05 — Environment manager: lots and stock

Account `sami@capbon.test`.

| # | Action | Expected result |
|---|---|---|
| 1 | **Lots de déchets** | About 70 lots; the lots created by Leila are first |
| 2 | Filter Statut "Créé" + "Stocké", then type "Chutes de coupe" | Filters turn green; "Réinitialiser 2"; list reduced |
| 3 | **Réinitialiser** → **Nouveau lot**: site, zone, type, weight `42,5` → **Créer** | Lot page opens, status Créé, history "Lot créé" |
| 4 | **Repeser** → `41` → save | Weight 41 kg; history shows the old and new weight |
| 5 | **Scinder** → `20` and `21` → confirm | Two new lots; the original is closed |
| 6 | Lots: tick two lots of the same site, type and class (Créé or Stocké) → **Regrouper** → zone → confirm | One new lot whose weight is the sum |
| 7 | **Stock** | Total, by site and zone; the new lots are counted |
| 8 | **Ajouter un seuil**: site, type "Chutes de coupe", maximum `100` → save | Line "Seuil dépassé" in red; a notification in the bell |

## Test 06 — Complete pickup with a weight discrepancy

Accounts: `sami@capbon.test`, `karim@sahel-recyclage.test`, `leila@capbon.test`.

| #   | Who   | Action                                                                                                                                                       | Expected result                                                                                             |
| --- | ----- | ------------------------------------------------------------------------------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------- |
| 1   | Sami  | **Enlèvements** → **Nouvelle demande** → site "Atelier de Korba", tick 2 lots, choose "Recyclage du Sahel", date → **Envoyer au prestataire**                | Pickup `ENL-2026-0007` "Demandé"; lots "En attente d'enlèvement"                                            |
| 2   | Karim | Bell shows a new request. Open `ENL-2026-0007` → **Confirmer une date** → **Confirmer**                                                                      | Status "Confirmé"                                                                                           |
| 3   | Leila | `/m` → **Charger un enlèvement** → `ENL-2026-0006` → type `L-2026-000069`, `L-2026-000070`, `L-2026-000071` (**Confirmer** each) → **Valider le chargement** | "Chargement enregistré"                                                                                     |
| 4   | Sami  | Open `ENL-2026-0006`                                                                                                                                         | Status "Enlevé", weight at departure 211,5 kg                                                               |
| 5   | Karim | Open `ENL-2026-0006` → **Enregistrer la réception** → set the 2nd line to `108` → **Enregistrer**                                                            | "Réceptionné"; banner "Poids reçu 199,5 kg pour 211,5 kg au départ, soit -5,7 % d'écart"; line −10 % in red |
| 6   | Karim | **Déclarer le traitement** → choose a channel per line → save                                                                                                | Lines "Traité", channel shown                                                                               |
| 7   | Karim | **Déposer un certificat** → type, PDF file → send                                                                                                            | Certificate listed under Documents                                                                          |
| 8   | Sami  | Open the pickup                                                                                                                                              | Red banner with the discrepancy; **Prendre en compte l'écart** is the main button                           |
| 9   | Sami  | **Prendre en compte l'écart** → confirm; **Saisir les prix** → a price per line → save                                                                       | Banner turns green; amounts shown                                                                           |
| 10  | Sami  | **Clôturer** → confirm                                                                                                                                       | Status "Clôturé"; the 3 lots are "Clôturé"                                                                  |

## Test 07 — Compliance

Account `sami@capbon.test` (and `karim@sahel-recyclage.test` for step 3).

| # | Action | Expected result |
|---|---|---|
| 1 | **Registre des déchets**, period "Année en cours" | One line per lot with its exit, provider and channel |
| 2 | **Bordereaux de suivi** → `BSD-2026-0005` → **Signer** → password → confirm | "Producteur — signé par Sami Gharbi"; status "Signature transporteur attendue" |
| 3 | Karim → **Bordereaux de suivi** → sign twice (transporter, receiver) | Status "Complet" |
| 4 | Sami → **Ouvrir le PDF** | The slip with the three signatures |
| 5 | **Déclarations annuelles** → site "Usine de Nabeul", year 2026 → **Préparer une déclaration** | Totals and tables by waste code; status "Projet" |
| 6 | **Valider la déclaration** → **Exporter** → PDF | Status "Validée"; a PDF opens |
| 7 | Registre → **Exporter en PDF**, then **Rapports et exports** | The export is listed "Prêt" with **Télécharger** |
| 8 | **Documents** | Slips, certificates and the declaration are archived |

## Test 08 — Providers and accreditations

| # | Who | Action | Expected result |
|---|---|---|---|
| 1 | Admin | **Agréments à valider** | One line: Sfax Éco-Collecte, ANGED-REC-2026-207 |
| 2 | Admin | **Voir le document**, then **Approuver** → **Confirmer** | Queue empty |
| 3 | Admin | Sociétés → **Sfax Éco-Collecte** → **Valider l'inscription** | "Active"; the accreditation shows "Approuvé" |
| 4 | Sonia (`sonia@sfax-eco.test`) | Sign in → **Profil et déchets acceptés** → publish the profile, save | "Profil publié dans l'annuaire" |
| 5 | Amira | **Prestataires** | Sfax Éco-Collecte is listed; **Ajouter à mes partenaires** works |
| 6 | Amira | Same page: "Éco-Tri Tunis" | Red notice "Ne peut pas être choisi pour un enlèvement — Agrément expiré ou absent" |
| 7 | Karim | **Agréments** → **Ajouter un agrément** with a PDF | Listed "À valider"; appears in the admin's queue |
| 8 | Admin | `demo.ps1 scheduler` (terminal) | Karim and Amira receive "agrément expire dans 20 jours" |

## Test 09 — Dashboard and reports

Account `direction@capbon.test`.

| # | Action | Expected result |
|---|---|---|
| 1 | Sign in | Dashboard; about 4,6 t generated, recovery rate about 90 % |
| 2 | Period "Ce mois-ci", then site "Atelier de Korba" | Every figure and chart changes |
| 3 | Click an alert in "À traiter" | Opens the screen concerned |
| 4 | **Lots de déchets** | List visible, **no** "Nouveau lot" button |
| 5 | **Rapports et exports** → "Rapport RSE", PDF, "Année en cours" → **Générer un rapport** | Line "Prêt" within seconds → **Télécharger** opens the PDF |
| 6 | Same with format Excel | An `.xlsx` file downloads |
| 7 | Sign in as `sami@capbon.test` → Rapports → "Pièces produites": type a number in a month | Saved on leaving the field; the dashboard's "Déchets par pièce produite" changes |

## Test 10 — Tenant isolation and role limits

| # | Action | Expected result |
|---|---|---|
| 1 | Sign in as `amira@capbon.test`, open a lot, copy the address | — |
| 2 | Private window → `nour@monastir-jeans.test` → **Lots de déchets** | Only 3 lots (DENIM), none of Cap Bon |
| 3 | Paste Amira's lot address | "Le chargement a échoué" / not found: no data shown |
| 4 | Nour → `/platform/companies` | Sent back to her dashboard |
| 5 | `audit@capbon.test` → `/admin/users` | Sent back to `/lots` |
| 6 | `sami@capbon.test` → **Utilisateurs et rôles** | List visible, no "Inviter un utilisateur" |
| 7 | `karim@sahel-recyclage.test` → `/lots` | Sent back to the provider's requests |
| 8 | Admin → there is no menu to a client's lots | The back office never shows operational data |

## After testing

`scripts\demo.ps1 reset` puts everything back as it was (35 seconds).
