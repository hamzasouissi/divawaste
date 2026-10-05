# MVP acceptance — status, gaps and golden path

State on 5 October 2026. Source of truth for the requirements: the *Cahier des charges — DivaWaste Textile*
(28 Sep 2026); each requirement's detailed status is in
[compliance/REQUIREMENTS_MATRIX.md](compliance/REQUIREMENTS_MATRIX.md).

## 1. What was executed for this acceptance pass

Everything below was done **by clicks in a browser** against a freshly reset demo database, on the current
interface (after the move to PrimeNG), on 5 October 2026.

| Workflow | Result |
|---|---|
| Registration → email confirmation → pending → approval → trial → plan activation → invoice → client sign-in → set-up wizard | ✅ New company "Maille Bizerte SARL"; invoice DW-2026-000002, 179,500 DT |
| Rejection with a reason; suspension and reactivation | ✅ The applicant / the client see the status page with the reason |
| Client set-up: site, zone, waste type from the catalogue | ✅ |
| Invitation: sent, link opened, password chosen, terms accepted, invited operator signs in and lands on `/m` | ✅ |
| Two-factor authentication: enabled with a real authenticator code, recovery codes shown, sign-in asks for the code and accepts it | ✅ |
| Workshop app: create a lot, find it by typed number, put it in stock, load a pickup | ✅ |
| Offline: lot created without network, queued, synchronised and numbered when the network returns | ✅ |
| Lot split (42,5 kg → 20 + 22,5) and lot grouping (back to 42,5 kg) | ✅ |
| Pickup: request, provider confirms, loading, reception with a −10 % line, discrepancy acknowledged, treatment, prices, closing | ✅ Net balance 26,000 DT |
| Certificate upload by the provider | ✅ Listed on the pickup |
| Tracking slip signed by producer, transporter and receiver | ✅ |
| Annual declaration: prepared, validated, exported in PDF and Excel | ✅ Both files returned by the server |
| Register export PDF and Excel; CSR report PDF and Excel, downloaded | ✅ |
| Plan activation at the reference price; bank transfer validated | ✅ |
| Tenant isolation: second company sees its 3 lots only; another company's lot address answers "not found" | ✅ |

Fixed during this pass:

- Technical codes replaced by words everywhere they were shown: textile activities, country, packaging, colour,
  material, origin of an action, audited objects and events, role audiences, plan codes, permission and module
  names in the role matrices.
- Internal identifiers removed from the audit list, the platform activity and the lot page.
- The platform's company page now shows sites, users, invoices and the account's history.
- "formule(s) à activer" on the overview opens the companies concerned.
- Closing a pickup asks for confirmation.
- Bank-transfer decisions appear in the platform activity.

Automated checks: 163 backend tests pass; frontend lint, translation keys, 20 unit tests and production build pass.

## 2. Remaining gaps, classified

### MUST FIX BEFORE MVP ACCEPTANCE

| Gap | Why | Size |
|---|---|---|
| **Personal-data export and erasure** | Required by the cahier (non-functional requirements); nothing exists. Until then Diva Software must answer such requests by hand | A few days |
| **API documentation generator** (Scribe or Swagger) | Required by the cahier's stack (§5); only a hand-written route list exists | 1–2 days |
| **Static analysis (Larastan)** | Required by the cahier's stack (§5); not installed | 1–2 days, plus fixing what it reports |

None of these three prevents showing the product to a customer. They prevent signing off the MVP against the
cahier.

### BUSINESS DECISION REQUIRED

| Gap | What Diva Software must decide or supply |
|---|---|
| **Subscription price grid** | The prices. The demo values (150 DT per site per month; 400 DT per month for the volume plan) are examples |
| **Volume metering** | How the "par volume" plan is billed. Today it is a flat amount per period; tonnage is not metered |
| **Legal texts** | The real terms of use, privacy policy and provider terms. The application records acceptance of placeholder texts |
| **VAT and stamp duty** | Confirmation of 19 % and 1 DT, and Diva Software's own tax identifiers on invoices |
| **Official regulatory layouts** | The official tracking-slip form and ANGed declaration form. Figures and signatures are in place; the layouts are ours |
| **Legal value of the in-app signature** | It records user, password re-entry, time and IP. It is not a qualified electronic signature |
| **Reference data** | Validation of the 15 reference waste types, the waste codes and the accreditation types |
| **Stack deviations** | Written agreement on: QR codes generated with bacon-qr-code instead of simple-qrcode (incompatible with Fortify); Horizon not installed; Angular 20 rather than the latest major; PrimeNG as the component library |
| **Penetration test** | Commissioning an external test before real customer data is hosted. None has been done |
| **Admin creation of companies** | The cahier makes companies register themselves; the back office has no "create company". Confirm this is what you want |

### REAL-WORLD HARDWARE TEST REQUIRED

| Item | State |
|---|---|
| **Phone and camera** | The workshop app was driven on a phone-sized screen on a PC. Never installed on a real phone; the camera scan was never exercised (typed lot number was). Camera needs HTTPS |
| **Thermal label printer** | The 100 × 50 mm label PDF is generated; never printed on a thermal printer |
| Use with gloves, in a workshop | Never observed |
| iPhone home-screen icon | Needs PNG icons; only an SVG exists |

### MUST DO BEFORE PRODUCTION (not before a customer demo)

| Item | State |
|---|---|
| **Load test** | Not done. The dashboard is computed live; nothing was measured at the cahier's target volumes (200 companies, 5 million lots) |
| Production set-up | HTTPS, queue worker, scheduler, backups, real mail server: documented in [OPERATIONS.md](OPERATIONS.md), not deployed |

### V2 (out of the MVP by the cahier)

SSO · RFID · connected scales · production link (M3) · marketplace · CO₂ avoided · brand portal · public API with
keys · card payment · SMS / WhatsApp · native mobile applications · English and Arabic.

### NON-BLOCKING POLISH

| Item |
|---|
| A waste type activated from the catalogue gets an English-looking code (`CUTTING_SCRAPS`) in the "Code" column |
| In audit details, field names of the before / after table are only partly translated |
| No notification bell in the back office (the overview counters replace it) |
| No screen to edit countries, taxes or legal documents (reference data loaded at install) |
| The scheduler-driven events (renewal, trial end, expiry alerts) need `demo.ps1 scheduler` in the demo |
| No session with real users or with a screen reader yet |

## 3. Golden path — one run through the whole product

Reset first: `powershell -ExecutionPolicy Bypass -File .\scripts\demo.ps1 reset`, then open
http://localhost:4300. Password everywhere: `DivaWaste!2026`. Use a private window when the account changes
(the session is a cookie). Emails: `scripts\demo.ps1 mail` prints the latest links.

### A. Platform administrator — `admin@divawaste.test`

| # | Action | Expected |
|---|---|---|
| A1 | Private window → `/register`. Industriel textile; Raison sociale `Maille Bizerte SARL`; Matricule fiscal `4455667H`; Ville `Bizerte`; phone; one activity; contact `Rania Mejri`, `rania@maille-bizerte.test`, phone, password `DivaWaste!2026`; Formule "Abonnement par site"; accept → **Créer mon compte** | "Vérifiez votre boîte mail" |
| A2 | `demo.ps1 mail` → open the `verify-email` link | "Adresse email confirmée" |
| A3 | Normal window → sign in as **admin@divawaste.test** | "Vue d'ensemble": 3 inscriptions à valider |
| A4 | Tile "inscription(s) à valider" → **Maille Bizerte SARL** | Contact "Email confirmé : Oui", activity "Filature", formule demandée |
| A5 | **Valider l'inscription** → **Confirmer** | "Active"; Abonnement "Essai gratuit"; history "Inscription validée" |
| A6 | Same page, **Activer une formule** (pre-filled: par site, 1 site, 150) | "Formule activée et première facture émise"; Factures: 179,500 DT "À régler" |

### B. Client administrator — `rania@maille-bizerte.test`

| # | Action | Expected |
|---|---|---|
| B1 | Private window → sign in | Dashboard with the banner "Terminez la mise en route" |
| B2 | **Sites et zones** → **Nouveau site**: code `BIZ`, name `Usine de Bizerte`, city → **Enregistrer** | Site page; a storage zone already exists |
| B3 | **Ajouter une zone**: code `COUPE`, type Coupe, name `Atelier de coupe` | Two zones |
| B4 | **Types de déchets** → **Catalogue de référence** → **Activer** "Chutes de coupe" | Listed in "Mes types" |
| B5 | **Utilisateurs et rôles** → **Inviter un utilisateur**: `omar@maille-bizerte.test`, role "Opérateur atelier" | Tab Invitations: "En attente" |
| B6 | `demo.ps1 mail` → open the `invitation` link in another private window; password; accept | "Invitation acceptée. Vous pouvez vous connecter." |

### C. Operator — `leila@capbon.test` (Textile du Cap Bon; phone-size window)

| # | Action | Expected |
|---|---|---|
| C1 | Sign in | `/m` |
| C2 | **Nouveau lot** → Atelier de coupe → Chutes de coupe → `37,5` → **Créer le lot** | "Lot créé" + number; **Imprimer l'étiquette** opens the QR label |
| C3 | **Scanner** → **Saisir le code à la main** → `L-2026-000062` → **Confirmer** → **Mettre en stock** | "Lot mis en stock", status Stocké |
| C4 | **Charger un enlèvement** → `ENL-2026-0006` → type `L-2026-000069`, `…070`, `…071` → **Valider le chargement** | "Chargement enregistré" |

### D. Environment manager — `sami@capbon.test`

| # | Action | Expected |
|---|---|---|
| D1 | **Enlèvements** → **Nouvelle demande**: site Atelier de Korba, tick two lots, **Recyclage du Sahel**, **Envoyer au prestataire** | `ENL-2026-0007` "Demandé" |

### E. Provider — `karim@sahel-recyclage.test`

| # | Action | Expected |
|---|---|---|
| E1 | Open `ENL-2026-0007` → **Confirmer une date** → **Confirmer** | "Confirmé" |
| E2 | Open `ENL-2026-0006` → **Enregistrer la réception** → 2nd line `108` → **Enregistrer** | "Réceptionné"; banner "soit -5,7 % d'écart"; line −10 % in red |
| E3 | **Déclarer le traitement** → a channel per line → **Enregistrer** | Lines "Traité" |
| E4 | **Déposer un certificat** → choose a PDF → **Enregistrer** | "Certificat déposé"; listed under Documents |
| E5 | **Bordereaux de suivi** → after Sami signed (F2): **Signer** twice with the password | Three signatures |

### F. Compliance — `sami@capbon.test`

| # | Action | Expected |
|---|---|---|
| F1 | Open `ENL-2026-0006` → **Prendre en compte l'écart** (explanation) → **Saisir les prix** → **Clôturer** → confirm | "Clôturé" |
| F2 | **Bordereaux de suivi** → `BSD-2026-0005` → **Signer** → password | Producer signed |
| F3 | **Registre des déchets** | One line per lot, with exit and channel |
| F4 | **Documents** | The slip and the certificate |
| F5 | **Déclarations annuelles** → Usine de Nabeul, 2026 → **Préparer** → **Valider la déclaration** → **Exporter** PDF | "Validée"; a PDF opens |

### G. Reporting — `direction@capbon.test`

| # | Action | Expected |
|---|---|---|
| G1 | Dashboard → period "Ce mois-ci", then a site | Figures and charts change |
| G2 | **Rapports et exports** → Rapport RSE, PDF → **Générer** → **Télécharger** | A PDF |
| G3 | Same with Excel | An `.xlsx` file |

### H. Security — `nour@monastir-jeans.test`

| # | Action | Expected |
|---|---|---|
| H1 | Sign in → **Lots de déchets** | 3 lots only |
| H2 | Paste the address of a lot copied from Sami's window | "Cet élément n'existe pas ou n'est plus accessible" |
| H3 | Type `/platform/companies` | Sent back to the dashboard |
