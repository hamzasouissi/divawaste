# User test guide — DivaWaste Textile

For someone who wants to try the product by hand, without reading code. The screens are in French; the labels to click
are quoted exactly as they appear.

Every step below was performed in a browser against the demo data on 4 Oct 2026. Where something was *not* tried, it is
said in "Known limits" at the end.

## Before you start

1. Start the two servers (see [DEVELOPER_RUNBOOK.md](DEVELOPER_RUNBOOK.md)) and, for a clean start, reset the data:

   ```powershell
   cd C:\Projects\diva-waste
   powershell -ExecutionPolicy Bypass -File .\scripts\demo.ps1 reset
   powershell -ExecutionPolicy Bypass -File .\scripts\demo.ps1 api
   ```
   ```powershell
   cd C:\Projects\DIVA-WASTE-FRONTEND-LAB\frontend
   npm run demo
   ```

2. Open **http://localhost:4300** in Chrome.
3. Password of every account: **`DivaWaste!2026`**. The accounts are in [TEST_ACCOUNTS.md](TEST_ACCOUNTS.md).

Tips: use a **private window** (Ctrl+Shift+N) for a second account at the same time. Do not sign in more than five times
per minute ("Trop de tentatives" → wait one minute).

## How sign-in works

| Step | Expected |
|---|---|
| Open http://localhost:4300 | The "Connexion" screen |
| Type `amira@capbon.test` and `DivaWaste!2026`, click **Se connecter** | You arrive on **Tableau de bord** (`/dashboard`). Top right: "Amira Trabelsi"; bottom left: "Textile du Cap Bon" |
| Click your name (top right) → **Se déconnecter** | Back to "Connexion" |
| Sign in as `leila@capbon.test` | You arrive on the workshop application (`/m`): three large buttons |
| Sign in as `karim@sahel-recyclage.test` | You arrive on **Demandes d'enlèvement** (`/provider/pickups`) |
| Sign in as `admin@divawaste.test` | You arrive on **Sociétés** (`/platform/companies`) |
| Sign in as `mehdi@tissages-kh.test` | "État de votre compte — En attente de validation": nothing else is reachable |

**Switching user** = sign out, sign in with another account. There is no "switch role" button: a role belongs to an
account.

**Access denied.** You never see a broken page: a screen your role does not include is not in the menu, and typing its
address sends you back to your own home page. Try it: as Leila (operator) open `http://localhost:4300/dashboard` → you
are back on `/m`. As Amira open `http://localhost:4300/platform/companies` → back on `/dashboard`. If the server refuses
an action, a red message says "Vous n'avez pas les droits nécessaires pour cette action."

**Email verification.** The demo accounts are already verified. For a new registration the email is not really sent:
it is written to a log. Print the latest links with:

```powershell
cd C:\Projects\diva-waste
powershell -ExecutionPolicy Bypass -File .\scripts\demo.ps1 mail
```

and paste the `…/verify-email?…` link in the browser → "Adresse email confirmée".

**Forgotten password.** On "Connexion" click **Mot de passe oublié ?**, type the email, **Envoyer le lien** → run
`demo.ps1 mail`, open the `…/reset-password?…` link, type a new password (12 characters minimum, with a letter and a
digit), **Enregistrer le mot de passe** → "Mot de passe modifié. Vous pouvez vous connecter."

**Two-factor (optional).** Your name → **Sécurité du compte** → "Double authentification (TOTP)" → **Activer** → scan
the QR code with an authenticator app (Google Authenticator, Authy…) → type the 6-digit code → **Confirmer** → write
down the recovery codes. At the next sign-in a 6-digit code is asked after the password. **Désactiver** asks for your
password.

**Invitations.** See Test 1, step 6.

---

## Test 1 — The industrial company (as `amira@capbon.test`)

| # | Action | Expected result |
|---|---|---|
| 1 | Menu **Société** | Raison sociale "Textile du Cap Bon", matricule fiscal `1234567A` (not editable), editable address, "Seuil d'écart de poids à l'enlèvement (%)", logo upload on the right |
| 2 | Menu **Sites et zones** | Two sites: `KOR` Atelier de Korba and `NAB` Usine de Nabeul, 4 zones each |
| 3 | Click **Usine de Nabeul** | Zones Confection, Atelier de coupe, Aire de stockage déchets (2 t), Teinture |
| 4 | **Ajouter une zone** → Code `EXP`, Type de zone **Magasin**, Nom `Quai d'expédition` → **Enregistrer** | "Zone enregistrée." The zone appears in the list and is offered to operators |
| 5 | Menu **Types de déchets** | Tab "Mes types": 6 types; Résidus de teinture carries the badge **Dangereux** and the code `04 02 16`. Tab "Catalogue de référence": the 15 reference types with an **Activer** button for those not yet activated |
| 6 | Menu **Utilisateurs et rôles** → **Inviter un utilisateur** → Email `yasmine@capbon.test`, Prénom, Nom, Rôle **Opérateur atelier** → **Inviter un utilisateur** | "Invitation envoyée." The tab **Invitations** shows it "En attente", valid 7 days |
| 7 | In PowerShell: `demo.ps1 mail`, open the `…/invitation?token=…` link in a private window, type a password, tick the acceptance box, **Accepter l'invitation** | "Invitation acceptée. Vous pouvez vous connecter." Signing in as Yasmine opens the workshop application |
| 8 | Tab **Membres** → the ⋮ menu of a member → **Modifier les rôles** | A role can be given for "Tous les sites" or for one site. Tab **Rôles et permissions** shows what each role allows |
| 9 | Menu **Prestataires** | Recyclage du Sahel: badge **Partenaire**, two accreditations (one "Expire bientôt"). Éco-Tri Tunis: red line "Ne peut pas être choisi pour un enlèvement — Agrément expiré ou absent" |

## Test 2 — The operator on the workshop application (as `leila@capbon.test`)

Use a narrow window, or press F12 then Ctrl+Shift+M to show the page as a phone.

| # | Action | Expected result |
|---|---|---|
| 1 | Sign in | `/m`: **Nouveau lot**, **Scanner**, **Charger un enlèvement**. Header: "En ligne — Tout est synchronisé" |
| 2 | **Nouveau lot** | "Dans quelle zone ?" (Leila has one site, so the site is not asked) |
| 3 | Tap **Atelier de coupe**, then **Chutes de coupe** | "Poids net en kg" with a keypad |
| 4 | Tap `5` `8`, then **Créer le lot** | "Lot créé" with its number (for example `L-2026-000072`). Note it |
| 5 | **Imprimer l'étiquette** | A PDF opens in a new tab: 100 × 50 mm label with the QR code and the lot number |
| 6 | **Accueil** → **Scanner** | The camera starts if the PC has one. Otherwise click **Saisir le code à la main**, type the lot number, **Confirmer** |
| 7 | — | The lot card: number, status **Créé**, type, 58 kg, "Usine de Nabeul · Atelier de coupe" |
| 8 | **Mettre en stock** | "Lot mis en stock." Status becomes **Stocké**. This is the only status change an operator makes by hand; the others follow the pickup |
| 9 | Sign in as `amira@capbon.test` → **Lots de déchets** → click the lot | Its history: "Lot créé … par Leila Mansour", then the storing. **Stock** now counts it. **Journal d'audit** has the entries |

## Test 3 — Offline mode (as `leila@capbon.test`)

| # | Action | Expected result |
|---|---|---|
| 1 | On `/m`, online, create one lot (Test 2, steps 2–4) | "Lot créé" with a number |
| 2 | Press **F12** → tab **Network** → the list that says **No throttling** → choose **Offline** | The header turns brown: "Hors ligne" |
| 3 | **Accueil** → **Nouveau lot** → a zone → a type → `1` `8` → **Créer le lot** | "Lot enregistré sur l'appareil — Il sera envoyé automatiquement au retour du réseau." No number yet |
| 4 | **Accueil** | Header: "Hors ligne — 1 à synchroniser". The lot is listed under "Mes derniers lots" with **À synchroniser** |
| 5 | Tap the header | "File de synchronisation": the pending operation |
| 6 | In DevTools set the list back to **No throttling** | Within seconds, without touching anything: "En ligne — Tout est synchronisé"; the lot now has its number and **Synchronisé** |
| 7 | Sign in as Amira (private window) → **Lots de déchets** | **One** lot of 18 kg with that number — not two |
| 8 | Optional proof in the database: `php artisan tinker --env=demo --execute="echo DB::table('sync_operations')->count();"` | The count went up by one per synchronised operation |

Do **not** press F5 while offline: the development server has no offline cache, the page would not reload. (The installed
production build does reload offline.) Loading a pickup and opening a lot by scan need the network.

## Test 4 — A pickup from request to closure

Three accounts: Amira (industrial), Karim (provider), Leila (operator). Use private windows.

| # | Who | Action | Expected result |
|---|---|---|---|
| 1 | Amira | **Enlèvements** → **Nouvelle demande** | "1. Site et lots à enlever" |
| 2 | Amira | Site **Usine de Nabeul**; tick **Résidus de teinture (33 kg)** and **Films et sacs plastique (19 kg)** (and the lot you stored in Test 2 if you wish) | Right panel: "2 lot(s) — 52 kg" and a warning that the selection contains dangerous waste |
| 3 | Amira | Click **Recyclage du Sahel**, keep today's date, **Envoyer au prestataire** | "Demande envoyée au prestataire." The pickup (for example `ENL-2026-0007`) is **Demandé** |
| 4 | Karim | **Demandes d'enlèvement** → click the pickup → **Confirmer une date** → **Confirmer** | "Enlèvement confirmé." Status **Confirmé**. (Or **Refuser** with a reason) |
| 5 | Amira | Open the pickup → **Établir le bordereau de suivi** | "Bordereau établi." (a slip is required because of the dangerous lot) |
| 6 | Leila | `/m` → **Charger un enlèvement** → tap the pickup → for each lot: **Saisir le code à la main**, type its number, **Confirmer** | "2 / 2 lots scannés", each line turns green |
| 7 | Leila | **Valider le chargement** | "Chargement enregistré." For Amira the pickup is now **Enlevé**. (Amira can do the same from the desktop with **Enregistrer le chargement**) |
| 8 | Karim | Open the pickup → **Enregistrer la réception** → for Résidus de teinture replace `33` by `29`, leave `19` → **Enregistrer** | "Réception enregistrée." A banner: "Poids reçu 48 kg pour 52 kg au départ, soit -7,7 % d'écart." The line shows **-12,1 %** |
| 9 | Karim | **Déclarer le traitement** → choose a filière for each lot → **Enregistrer** | "Traitement déclaré." Lines become **Traité** |
| 10 | Amira | Open the pickup | The same banner; a notification (bell) "Écart de poids — enlèvement …"; the pickup list marks it with a scale icon. There is **no "Clôturer" button yet** |
| 11 | Amira | **Prendre en compte l'écart** → type an explanation → **Confirmer** | "Écart pris en compte." |
| 12 | Amira | **Saisir les prix** → for each lot choose Vente or Coût, the mode, the price (e.g. `0.25` and `1.8`) → **Enregistrer** | "Prix enregistrés." Amounts are computed on the received weight; "Bilan net" appears |
| 13 | Amira | **Clôturer** | "Enlèvement clôturé." Status **Clôturé** |
| 14 | Amira | **Lots de déchets** → open one of the lots | History: Créé → En attente d'enlèvement → Enlevé → Traité → Clôturé, each with its date and author |
| 15 | Amira | **Journal d'audit**, filter Objet `pickups` | Entries by Amira, Leila **and Karim** (the provider's actions are recorded too) |

Shortcut: the demo data already contains `ENL-2026-0006` (confirmed, three lots `L-2026-000069`, `…070`, `…071`) —
start at step 6 with it; and `ENL-2026-0005` (Demandé) — start at step 4.

The 5 % threshold is the company's ("Société" → "Seuil d'écart de poids"). A pickup is flagged when its total **or any
single lot** is beyond it.

## Test 5 — Compliance (as `amira@capbon.test`, then Karim)

| # | Action | Expected result |
|---|---|---|
| 1 | **Registre des déchets** | One line per lot in date order, with its exit (pickup, provider, filière) once evacuated. Filters: period, site, "Dangereux uniquement" |
| 2 | **Exporter en Excel**, then **Exporter en PDF** | "Export demandé…". Go to **Rapports et exports**: both are **Prêt** → **Télécharger** |
| 3 | **Bordereaux de suivi** | BSD-2026-0001 to 0004 **Signé** (three signatures listed); BSD-2026-0005 "Signature producteur attendue" with a **Signer** button |
| 4 | **Signer** → type a wrong password → **Signer** | "Le mot de passe est incorrect." Nothing is signed |
| 5 | **Signer** → `DivaWaste!2026` → **Signer** | "Bordereau signé." Status "Signature transporteur attendue" |
| 6 | As Karim: **Bordereaux de suivi** → **Signer** twice (transporter, then receiver) | Status **Signé**. **Ouvrir le PDF** shows the slip with the three signatures |
| 7 | As Amira: **Documents** | The signed slip is archived: "Conservé jusqu'au" = ten years later. **Ouvrir** shows the PDF |
| 8 | **Déclarations annuelles** → Site **Usine de Nabeul**, Année **2026** → **Préparer une déclaration** | "Déclaration calculée." Totals (produced, of which dangerous, evacuated, stock at 31/12) and two tables by waste code |
| 9 | **Exporter** → PDF or Excel | The declaration document |
| 10 | **Valider la déclaration** → confirm | "Déclaration validée et archivée." It can no longer be recomputed; it appears in **Documents** |
| 11 | As Karim: open `ENL-2026-0003` → **Déposer un certificat** → choose the type, pick any PDF file → **Enregistrer** | "Certificat déposé." As Amira, **Documents** lists "Certificat de recyclage — ENL-2026-0003", kept ten years |

There is no button to delete a slip, a declaration or a certificate — by design.

## Test 6 — Dashboard and RSE reports (as `sami@capbon.test` or `direction@capbon.test`)

| # | Action | Expected result |
|---|---|---|
| 1 | **Tableau de bord** | "À traiter" (pending request, accreditation expiring). Figures: Déchets générés (~4,6 t, ~71 lots), Stock sur site, **Taux de valorisation** (~90 %), **Part enfouie** (~4 %), Déchets par pièce produite, Déchets dangereux, Déchets évacués, Bilan économique net |
| 2 | Below | Charts: Évolution mensuelle, Répartition par filière, Tonnage par type de déchet, Tonnage par site |
| 3 | Change **Période** ("Ce mois-ci", "Année en cours"…) or **Site** | All figures and charts are recomputed for that period and site |
| 4 | **Rapports et exports** → Rapport "Rapport RSE — gestion des déchets", Format **PDF** → **Générer un rapport** | "Rapport demandé…"; the line becomes **Prêt** → **Télécharger**: the report with the company logo if one was uploaded |
| 5 | Same with Format **Excel**, and with "Bilan économique" | A spreadsheet |
| 6 | "Pièces produites" at the bottom: type a number in a month, leave the field | "Production enregistrée." It feeds "Déchets par pièce produite" |
| 7 | As `direction@capbon.test` | Same figures, but no creation or edition buttons anywhere |

## Test 7 — Platform administration (as `admin@divawaste.test`)

| # | Action | Expected result |
|---|---|---|
| 1 | **Sociétés** | Two lines "En attente de validation" at the top: Sfax Éco-Collecte and Tissages de Ksar Hellal |
| 2 | Click **Tissages de Ksar Hellal** | Tax ID, contact (Mehdi Chaabane, "Email confirmé : Oui"), requested plan |
| 3 | **Valider l'inscription** → **Confirmer** | "Société validée. Le demandeur est prévenu par email." Mehdi can now sign in and reaches the dashboard with the "mise en route" banner |
| 4 | Instead, **Rejeter** → type a reason | The company is "Rejetée"; Mehdi sees the reason on his status page |
| 5 | Open **Textile du Cap Bon** → **Suspendre** → reason | Status "Suspendue": its users are blocked on the status page. **Réactiver** restores access |
| 6 | **Agréments à valider** | Sfax Éco-Collecte's accreditation: **Voir le document**, **Approuver** or **Rejeter** (with a reason) |
| 7 | **Catalogue de déchets** → click a line, or **Nouveau type de référence** | Edit the label, family, waste code, hazard flag; "Activé par" shows how many clients use it |
| 8 | Open **Textile du Cap Bon** → right panel "Activer une formule": Formule, Périodicité, Nombre de sites, Prix unitaire HT `150` → **Activer une formule** | "Formule activée et première facture émise." As Amira, **Abonnement** shows the invoice (300 + 57 VAT + 1 stamp duty = 358 DT for two sites) |
| 9 | As Amira: **Abonnement** → **Déclarer un virement** → bank reference → confirm. As admin: **Virements déclarés** → **Valider** | The invoice becomes "Réglée" |
| 10 | **Rôles et permissions** | Choose a standard role, tick or untick permissions, **Enregistrer**: applies to every company at once |

There is **no screen to create plans or edit platform settings**: the three plans are reference data, and the price is
entered when a plan is activated (step 8).

## Test 8 — Tenant isolation

Company A = Textile du Cap Bon (Amira). Company B = Monastir Jeans Confection (Nour).

| # | Action | Expected result |
|---|---|---|
| 1 | As Amira, open any pickup and copy the address (`http://localhost:4300/pickups/01…`). Also note a lot number | — |
| 2 | Sign out, sign in as `nour@monastir-jeans.test` | Dashboard of "Monastir Jeans Confection": 3 lots, 635 kg — none of Cap Bon's 70 lots |
| 3 | **Lots de déchets** | Only `L-2026-000001` to `000003` of the site "MON". **Enlèvements**: "Aucun enlèvement". **Sites et zones**: only "Unité de Monastir" |
| 4 | Paste Amira's pickup address | "Le chargement a échoué — Cet élément n'existe pas ou n'est plus accessible." (the server answers 404, not "forbidden": it does not even reveal that the pickup exists) |
| 5 | In the same window open `http://localhost:4300/api/v1/lots?per_page=100` | Raw data: exactly 3 lots, all of Monastir |
| 6 | Open `http://localhost:4300/api/v1/platform/companies` | `"status":403` — a client cannot reach the platform |
| 7 | As Leila (operator), **Scanner** → type one of Nour's numbers… | Lot numbers restart per company, so type `L-2026-000001`: Leila gets **Cap Bon's** lot 1, never Monastir's |
| 8 | As Karim (provider) | Sees the pickups addressed to him by Cap Bon, but `http://localhost:4300/api/v1/lots` answers 403: a provider never reads a client's lots |

The same rules are enforced by 158 automated tests (`php artisan test`).

---

## Known limits (not done or not tried)

- **Camera scanning** was not tried on a real phone. On the PC it needs a webcam; the typed code is the fallback.
- **Label printing** on a thermal printer was not tried (the PDF is 100 × 50 mm, one page).
- **Installation as an app** and offline reload need the production build, not the demo servers.
- **Emails are not sent** in the demo: links come from `demo.ps1 mail`.
- **Scheduled tasks** (accreditation alerts, monthly invoicing) do not run by themselves in the demo:
  `demo.ps1 scheduler` runs them once.
- Only French is available. Plans have no price list. Legal texts are placeholders.
- The official ANGed form and the official slip layout are not reproduced; the documents are structured equivalents.
- No screen for personal-data export or erasure.
- A few reference values still show their technical code (packaging "drum", requested plan "per_site").
