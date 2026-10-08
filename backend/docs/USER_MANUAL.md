# DivaWaste Textile — user manual

Every screen of the application, by workspace, with what it shows, what you can do on it and a screenshot taken
from the running application (demo data, re-captured on 5 October 2026 on the current interface). Screen labels are quoted in French, as they appear.

- To run the application and sign in: [DEVELOPER_RUNBOOK.md](DEVELOPER_RUNBOOK.md) and [TEST_ACCOUNTS.md](TEST_ACCOUNTS.md).
- To operate the SaaS itself: [PLATFORM_ADMIN_GUIDE.md](PLATFORM_ADMIN_GUIDE.md).
- Who may do what: [ROLES_AND_PERMISSIONS.md](ROLES_AND_PERMISSIONS.md).

## Contents

1. [How the product is organised](#1-how-the-product-is-organised)
2. [Getting an account](#2-getting-an-account)
3. [Common elements of every screen](#3-common-elements-of-every-screen)
4. [Client administrator](#4-client-administrator)
5. [Environment manager — daily operations](#5-environment-manager--daily-operations)
6. [Compliance](#6-compliance)
7. [Reports](#7-reports)
8. [Workshop operator — the `/m` application](#8-workshop-operator--the-m-application)
9. [Direction — read-only](#9-direction--read-only)
10. [Auditor / donor — read-only trail](#10-auditor--donor--read-only-trail)
11. [Provider workspace](#11-provider-workspace)
12. [Platform administration](#12-platform-administration)
13. [Status reference](#13-status-reference)

---

## 1. How the product is organised

| Workspace            | Who                                                                       | Starts at                                 | Navigation colour                                |
| -------------------- | ------------------------------------------------------------------------- | ----------------------------------------- | ------------------------------------------------ |
| Client workspace     | Textile companies: administrator, environment manager, direction, auditor | `/dashboard` (or `/lots` for the auditor) | Dark green                                       |
| Workshop application | Operators, on a phone or tablet                                           | `/m`                                      | Full-screen, large buttons                       |
| Provider workspace   | Collectors, transporters, recyclers                                       | `/provider/pickups`                       | Dark green, own menu                             |
| Back office          | Diva Software                                                             | `/platform/overview`                      | Dark blue, tag "Administration de la plateforme" |

The life of a waste lot, which the whole product follows:

```
Créé → Stocké → En attente d'enlèvement → Enlevé → Traité → Clôturé
(lot created)   (in a pickup request)     (loaded) (treated by the provider) (pickup closed)
```

## 2. Getting an account

### Registration — `/register` (public)

A company registers itself, as **Industriel textile** or **Prestataire**.

![Registration form](screenshots/onboarding/02-register-filled.png)

Required: company name, country, tax identifier (checked against the country's format), activities, the contact
(first name, last name, email, phone, password of 12 characters minimum), the intended plan, acceptance of the
legal documents. A provider also gives its activity and at least one accreditation PDF.

Then: confirm the email (link valid 24 hours) → wait for Diva Software's approval → sign in.

| Screen | When |
|---|---|
| ![Mail sent](screenshots/onboarding/03-register-done.png) | Right after registration |
| ![Email confirmed](screenshots/onboarding/04-email-verified.png) | After clicking the link |
| ![Pending](screenshots/onboarding/05-pending-status.png) | Signing in before approval |
| ![Rejected](screenshots/onboarding/07-rejected-status.png) | If the registration is refused (with the reason) |
| ![Suspended](screenshots/onboarding/08-suspended-status.png) | If the account is suspended later (with the reason) |

### Invitation — `/invitation` (link received by email)

Colleagues do not register: the administrator invites them (see [Utilisateurs](#utilisateurs-et-rôles--adminusers)).
The link is valid 7 days; the person chooses a password and accepts the terms.

### Sign-in — `/login`

Email and password; a 6-digit code if two-factor authentication is on; the company if you belong to several.
Five wrong passwords lock the account for 15 minutes. "Mot de passe oublié ?" sends a reset link.

### First steps — `/onboarding`

A new client lands on an empty dashboard with the banner "Terminez la mise en route". The wizard lists four
steps and opens the real screen for each; it can be left and resumed.

![First dashboard](screenshots/onboarding/06-first-dashboard.png)
![Set-up wizard](screenshots/onboarding/09-onboarding-wizard.png)

The first three steps, done for real by a new client: a site, its zones, a waste type from the catalogue.

![First site](screenshots/onboarding/10-site-form.png)
![Site with its zones](screenshots/onboarding/11-site-with-zones.png)
![First waste type activated](screenshots/onboarding/12-waste-type-activated.png)

## 3. Common elements of every screen

- **Navigation** on the left, grouped (Pilotage, Opérations, Conformité, Référentiel, Administration, Atelier). You
  only see what your role allows. The double arrow at the bottom folds it to icons.
- **Top bar**: where you are, the notification bell, your name (profile, security, sign out).
- **Page header**: title, one-line explanation, and the actions of the page on the right. The main action is the
  filled green button.
- **Filters** sit on one line above each list. A filter that is set is tinted green; "Réinitialiser" clears all.
- **Tables**: click a column title with arrows to sort; click a row to open it; the footer gives the count and
  the pages.
- **Messages** appear at the bottom right. A red field shows what is wrong under it.
- **Statuses** are coloured tags; their meaning is in [section 13](#13-status-reference).

## 4. Client administrator

Account: `amira@capbon.test`. Sees everything of the company *Textile du Cap Bon*.

### Tableau de bord — `/dashboard`

*Roles: client administrator, environment manager, direction.*

![Dashboard](screenshots/client/01-dashboard.png)

Filters (top right): period (this month, 3 months, year, 12 months, 3 years, custom dates) and site.

| Figure                     | Meaning                                                              |
| -------------------------- | -------------------------------------------------------------------- |
| Déchets générés            | Weight and number of lots created in the period                      |
| Stock sur site             | Weight present on site right now (not affected by the period)        |
| Taux de valorisation       | Share of treated waste that was reused, recycled or energy-recovered |
| Part enfouie               | Share sent to landfill (amber above 20 %)                            |
| Déchets par pièce produite | Waste divided by the pieces produced (entered in Rapports)           |
| Déchets dangereux          | Weight of hazardous lots                                             |
| Déchets évacués            | Weight that left the site in the period                              |
| Bilan économique net       | Revenue from sold waste minus treatment costs                        |

Then: **À traiter** (alerts: requests waiting for the provider, slips to sign, thresholds exceeded,
accreditations expiring — each links to the screen), **Tonnage par site**, **Enlèvements en cours**, the monthly
evolution, the split by treatment channel and the tonnage by waste type (hazardous in amber).

### Société — `/admin/company`

*Permission: company.update.* Legal details, address, logo (printed on reports), contact. Tax identifier and
country are fixed after registration.

![Company](screenshots/client/02-company.png)

### Sites et zones — `/sites`

*View: sites.view. Change: sites.manage, zones.manage.*

![Sites](screenshots/client/03-sites.png)

Search; "Afficher les sites fermés". **Nouveau site**: code (short, unique), name, nature, address, capacity.
Click a site to manage its zones:

![Zones of a site](screenshots/client/04-site-zones.png)
![New zone](screenshots/client/05-zone-dialog.png)

A zone has a code and a name. Everything else is optional:

- **Type** (cutting, sewing, dyeing, waste storage…). A zone of type "Aire de stockage" is where lots wait for a pickup.
- **Capacité**: how many lots the zone holds when it receives a single packaging — "40 balles", "100 sacs" — one line per
  packaging. Mixed lots add up: 20 bales out of 40 and 50 bags out of 100 fill the zone. A maximum weight in kg can be
  given as well; the higher of the two percentages is the one shown.
- **Réservée à**: the waste types and colours the zone is meant for. A lot that does not match is **reported, never
  refused**: a warning under the zone when the lot is created or moved, and "lot(s) hors affectation" on the stock page.
  In the workshop application, the types planned for the chosen zone come first.

Sites and zones are **deactivated**, never deleted: history keeps pointing to them.

### Utilisateurs et rôles — `/admin/users`

*View: users.view. Invite: users.invite. Manage: users.manage. Roles: roles.manage.*

![Members](screenshots/client/06-users.png)

Tab **Membres**: name, roles per site, two-factor, last sign-in, status. The "…" menu: *Modifier les rôles*,
*Suspendre* / *Réactiver*, *Retirer de la société*.

**Inviter un utilisateur**: email, name, role, and the sites the role applies to (empty = all sites).

![Invite](screenshots/client/07-user-invite.png)

The invited person receives a link valid 7 days, chooses a password, accepts the terms, then signs in.

![Invitation filled](screenshots/onboarding/13-invite-filled.png)
![Invitation pending](screenshots/onboarding/14-invitation-sent.png)
![The invited person accepts](screenshots/onboarding/15-invitation-accept.png)
![An invited operator lands on the workshop app](screenshots/onboarding/16-invited-operator-home.png)

Tab **Invitations**: pending invitations, resend or revoke. Tab **Rôles et permissions**: the standard roles
(read-only) and your own roles, with their permissions.

![Roles](screenshots/client/08-roles.png)

### Types de déchets — `/waste-types`

*View: waste_types.view. Change: waste_types.manage.*

![My types](screenshots/client/09-waste-types.png)

Tab **Mes types**: the types usable in lots. Tab **Catalogue de référence**: the platform's list; **Activer**
adds one to your types.

![Reference catalogue](screenshots/client/10-waste-catalog.png)

**Type personnalisé** creates your own: code, family, name, regulatory code, hazardous flag, tracking unit,
usual channel, packaging, colour, grammage, density, unit weight and default fibre composition.

### Prestataires — `/providers`

*View: providers.view. Partners: providers.partnerships.manage.*

![Providers directory](screenshots/client/17-providers.png)

**Partenaires sans compte** (top of the page): transporters and receivers that do not use the platform.
**Ajouter un partenaire** records the legal name, the activities (transporter, recycler, eliminator — any
combination), the tax ID, the contact and, optionally, the authorisation number with its expiry date and whether it
covers hazardous waste. Such a partner is visible to your company only; the platform does not review its
authorisation. For its pickups you record the stages yourself and it signs the tracking slip by hand. A partner
you stop using is switched off ("Utilisé" unticked), never deleted.

**Prestataires de la plateforme**: directory of approved providers: activities, description, area, channels,
contact, accreditations with their validity. Filters: search, channel, "Mes partenaires uniquement", "Agréés déchets dangereux".
**Ajouter à mes partenaires** makes a provider selectable in your pickup requests. A provider whose accreditation
has expired shows a red notice and cannot be chosen.

### Abonnement — `/admin/subscription`

*View: subscription.view. Manage: subscription.manage.*

![Subscription](screenshots/client/11-subscription.png)

Current plan and its status, trial end or current period, sites used / included. **Changer de formule**: choose
a plan and a number of sites; this sends a request to Diva Software, which activates it at the agreed price.
**Résilier** stops the subscription at the end of the paid period. **Factures**: number, dates, total, paid,
status, PDF, and **Déclarer un virement** (amount, bank reference, date) once you have paid by bank transfer.

![Declare a transfer](screenshots/client/12-declare-transfer.png)

### Journal d'audit — `/admin/audit`

*Permission: audit.view.* Every creation, change and deletion in the company, with author and source. Filters:
action, object, dates. Click a line to see the values before and after.

![Audit journal](screenshots/client/13-audit.png)
![Audit entry detail](screenshots/client/13b-audit-detail.png)

### Mon compte — `/account/profile`, `/account/security`, `/account/notifications`

Everyone. Profile (name, phone, language); security (change password, enable two-factor authentication with an
authenticator app and recovery codes); notifications (history, and per type: in the application and/or by email).

![Profile](screenshots/client/14-profile.png)
![Security](screenshots/client/15-security.png)

Two-factor authentication: **Activer** shows a QR code and a key for your authenticator application; type the
6-digit code and **Confirmer**. Eight recovery codes are then shown once — keep them. From then on, sign-in asks
for the code after the password.

![Two-factor set-up](screenshots/client/15b-two-factor-setup.png)
![Two-factor enabled, recovery codes](screenshots/client/15c-two-factor-enabled.png)
![Sign-in asks for the code](screenshots/client/15d-login-two-factor.png)
![Notifications](screenshots/client/16-notifications.png)

## 5. Environment manager — daily operations

Account: `sami@capbon.test`. Same screens as the administrator except users' management, subscription and
invoices.

![Manager dashboard](screenshots/manager/01-dashboard.png)

### Lots de déchets — `/lots`

![Lots](screenshots/manager/02-lots.png)

Filters: lot number, status (several), site, waste type, "Dangereux uniquement". Columns: number (⚠ = hazardous),
type, site code · zone, weight, creation date, status. Sort by number, weight or date.

Tick two or more lots of the same site, type and hazard class (status Créé or Stocké) → **Regrouper** merges
them into one lot.

**Nouveau lot**:

![New lot](screenshots/manager/03-lot-create.png)

Required: site, zone, waste type, net weight. Optional: packaging, colour, grammage, notes. The lot gets its
number (`L-2026-000072`) and a QR label.

### A lot — `/lots/:id`

![Lot detail](screenshots/manager/04-lot-detail.png)

![Split a lot](screenshots/manager/04b-lot-split.png)
![Select lots to group](screenshots/manager/04c-lots-selected.png)
![Group lots](screenshots/manager/04d-lot-group.png)

Identification, QR label, full history (who, when, from the web or the workshop app). Actions depend on status:

| Action | When | Effect |
|---|---|---|
| Mettre en stock / Déplacer | Créé, Stocké | Moves the lot to a zone; status Stocké |
| Repeser | Créé, Stocké | Corrects the weight (the old value stays in the history) |
| Fractionner | Créé, Stocké | Splits into several lots; a difference above 2 % needs a comment |
| Imprimer l'étiquette | always | 100 × 50 mm QR label |

### Stock — `/stock`

![Stock](screenshots/manager/05-stock.png)

Computed live from the lots on site. Figures: total, part already in a pickup request, thresholds exceeded or
close. Then, per site and zone, the weight by waste type, the number of lots, what is waiting for pickup and the
date of the oldest lot. For a zone with a capacity: a bar, the percentage and what still fits ("Reste : 3 × Balle ou
7 × Sac · 880 kg"). "lot(s) non compté(s)" are lots without packaging, or with a packaging the capacity does not
mention: they are not in the percentage. "lot(s) hors affectation" are lots of a type or colour the zone is not meant for.

**Seuils d'alerte**: maximum quantity for a site / zone / waste type; a notification is sent at 80 % and when
exceeded. "Ajouter un seuil", or "…" on a line to edit or delete.

**Demander un enlèvement** goes to the pickup request.

### Enlèvements — `/pickups`

![Pickups](screenshots/manager/06-pickups.png)

Filters: number, status, site, "Avec écart de poids". Columns: number, provider, site, date, lots, weight,
status.

**Nouvelle demande** — `/pickups/new`:

![New pickup request](screenshots/manager/07-pickup-new.png)

1. Choose the site, tick the lots to send (only lots in stock are listed).
2. Choose the **receiver**: a provider of the platform among your partners, or a partner without an account (tag
   "Sans compte"). One that cannot take the selection (hazardous waste without the right authorisation, expired
   authorisation) cannot be chosen, and says why.
3. Choose the **transporter** when it is not the receiver ("Le destinataire assure le transport" by default). A
   receiver that only recycles or eliminates needs one. Give the wished date.
4. **Enregistrer le brouillon** or **Envoyer la demande**.

When the receiver has no account, the pickup page offers you its stages: **Confirmer une date** or **Refuser**,
then, after the loading, **Enregistrer la réception** (weights received) and **Déclarer le traitement** (channel).
A receiver of the platform keeps doing these itself.

![Request sent](screenshots/manager/08-pickup-requested.png)

The lots become "En attente d'enlèvement". The pickup then moves through:

| Status          | Who acts              | What happens                                                                              |
| --------------- | --------------------- | ----------------------------------------------------------------------------------------- |
| Brouillon       | Client                | Not sent yet                                                                              |
| Demandé         | Provider              | Confirms a date, or refuses with a reason                                                 |
| Confirmé        | Client's operator     | Loads the lots (scan) on the day → weight at departure                                    |
| Enlevé          | Provider              | Records the weights received                                                              |
| Réceptionné     | Provider, then client | Provider declares the treatment channel and uploads the certificate; client enters prices |
| Clôturé         | Client                | Closes: lots become Clôturé                                                               |
| Refusé / Annulé | —                     | Lots return to stock                                                                      |

**Weight discrepancy.** If the received weight differs by more than 5 % from the departure weight — on the
total or on any line — a red banner appears and the client must click **Prendre en compte l'écart** before
closing.

![Pickup with discrepancy](screenshots/manager/10-pickup-variance.png)
![Acknowledge the discrepancy](screenshots/manager/11-variance-acknowledge.png)
![Enter prices](screenshots/manager/12-pricing-dialog.png)
![Closed pickup](screenshots/manager/13-pickup-closed.png)
![Received pickup](screenshots/manager/09-pickup-received.png)

Other actions on a pickup: **Saisir les prix** (per line: sale or cost, per kg / per tonne / flat),
**Déposer un certificat**, **Clôturer**, **Annuler l'enlèvement**.

## 6. Compliance

### Registre des déchets — `/compliance/register`

*Permission: documents.view.* The chronological register required by regulation, built automatically from the
lots: nothing is typed.

![Register](screenshots/compliance/01-register.png)

Filters: period, site, "Dangereux uniquement". Columns: date, lot, site and zone, type, regulatory code, weight,
status, exit (pickup and date), provider, channel. **Exporter en PDF / Excel** prepares the file; it appears in
Rapports when ready.

### Bordereaux de suivi — `/compliance/manifests`

Every waste leaves with a tracking slip, **hazardous or not**: one slip per waste code of a pickup, issued
automatically when the lots are loaded (or earlier with **Établir les bordereaux de suivi** on a confirmed pickup),
pre-filled, and signed in order by the producer, the transporter and the receiver.

Each party signs one of two ways:

- **Électronique**: the user's password. Who, when, from where and the fingerprint of the content are kept.
- **À la main**: print the slip (**Ouvrir le PDF** — it has a "Signature et cachet" box for every party that has not
  signed electronically), have it signed, then **Enregistrer la signature**: name of the signatory, date, and your
  password. A partner without an account can only sign this way; you record its signature.

A slip signed electronically by everyone is archived at the last signature. As soon as one signature is handwritten,
the slip waits for **Joindre l'exemplaire signé**: the scan (PDF, JPG or PNG) closes it and becomes the archived
document.

![Slips](screenshots/compliance/02-manifests.png)

Columns: slip number and pickup, waste, packages, weight, the three signatures (producer, transporter, receiver)
with who signed and when, status. **Signer** asks for your password (this is the electronic signature);
**Ouvrir le PDF** shows the document.

![Sign with password](screenshots/compliance/03-manifest-sign.png)
![Signed](screenshots/compliance/04-manifest-signed.png)

### Déclarations annuelles — `/compliance/declarations`

*Prepare: declarations.prepare. Validate: declarations.validate.*

![Annual declaration](screenshots/compliance/05-declaration.png)
![Validated declaration](screenshots/compliance/05b-declaration-validated.png)

Choose a site and a year, **Préparer une déclaration**: produced, hazardous, evacuated and year-end stock are
computed from lots and pickups, with the detail by waste code and by channel. **Recalculer** after corrections,
**Valider la déclaration** to freeze it, **Exporter** in PDF or Excel. The layout is an approximation of the
ANGed form, not the official form.

### Documents — `/compliance/documents`

Archive of slips, certificates, declarations and reports, filter by type and year, download.

![Documents](screenshots/compliance/06-documents.png)

## 7. Reports

### Rapports et exports — `/reports`

*View: reports.view. Export: reports.export. Production volumes: production_volumes.manage.*

![Reports](screenshots/reports/01-reports.png)

**Générer un rapport**: choose the report (CSR waste report, register, …), the format (PDF or Excel), the period
and the site, then **Générer**. The line shows "En préparation" then "Prêt" with **Télécharger**; files are kept
30 days.

**Pièces produites**: number of pieces produced per site and month, needed for "Déchets par pièce produite".

## 8. Workshop operator — the `/m` application

Account: `leila@capbon.test` (site *Usine de Nabeul* only). Operators are sent to `/m` at sign-in and never see
the desktop screens. Made for a phone or tablet, with gloves: one decision per screen, large buttons.
Installable on the device and usable without network in the production build.

The black band on top always shows the connection and what is waiting to be sent.

| | |
|---|---|
| ![Home](screenshots/operator/01-home.png) | **Accueil.** *Nouveau lot*, *Scanner*, *Charger un enlèvement*, then "Mes derniers lots" with their state (Synchronisé / À synchroniser). |

### Create a lot — three taps and a weight

| 1. Zone | 2. Waste type | 3. Weight | 4. Done |
|---|---|---|---|
| ![Zone](screenshots/operator/02-new-lot-zone.png) | ![Type](screenshots/operator/03-new-lot-type.png) | ![Weight](screenshots/operator/04-new-lot-weight.png) | ![Created](screenshots/operator/05-lot-created.png) |

The lot number is shown; **Imprimer l'étiquette** prints the QR label, **Créer un autre lot** starts again with
the same zone.

### Scan a lot

| Camera | Typed code | Lot found |
|---|---|---|
| ![Scan](screenshots/operator/06-scan.png) | ![Manual](screenshots/operator/07-scan-manual.png) | ![Found](screenshots/operator/08-lot-found.png) |

Point the camera at the QR code, or tap **Saisir le code à la main** and type the lot number printed on the
label. Then **Mettre en stock** (the lot becomes "Stocké") or **Imprimer l'étiquette**.

![Lot in stock](screenshots/operator/08b-lot-stored.png)

### Load a pickup

| Pickups of the day | Scanning | 
|---|---|
| ![List](screenshots/operator/09-loading-list.png) | ![Progress](screenshots/operator/10-loading-progress.png) |

Open the confirmed pickup, scan (or type) each lot as it goes on the truck: it turns green. A lot that is not
scanned stays in stock. **Valider le chargement** records the departure; the pickup becomes "Enlevé".

### Without network

| Saved on the device | Home, offline | Queue | After reconnection |
|---|---|---|---|
| ![Saved](screenshots/operator/12-offline-lot-saved.png) | ![Offline](screenshots/operator/13-offline-home.png) | ![Pending](screenshots/operator/14-sync-pending.png) | ![Synced](screenshots/operator/15-sync-done.png) |

The band turns amber ("Hors ligne"). Lots are saved on the device and sent automatically when the network is
back; the lot then gets its number and its label can be printed. Tap the band to open the queue;
**Synchroniser** forces a new attempt. An operation the server refuses stays in the queue, marked in red, with
the reason.

### Signing out

![Mon compte](screenshots/operator/16-account.png)

The round button at the top right of every workshop screen, and the tile with your name at the bottom of the home
screen, open **Mon compte**: who is signed in, for which company, and **Se déconnecter**. If lots are still waiting
on the device, a message says how many: they stay on the device under your name and are sent as soon as the network
is back, even after you signed out. The next person signs in with their own account on the same tablet.
## 9. Direction — read-only

Account: `direction@capbon.test`. Sees the dashboard, lots, stock, pickups, providers, documents, reports,
subscription and audit — with **no action button**.

![Lots, read-only](screenshots/direction/03-lots-read-only.png)

![Direction dashboard](screenshots/direction/01-dashboard.png)
![Direction reports](screenshots/direction/02-reports.png)

Can generate and download reports (reports.export) but cannot enter production volumes.

## 10. Auditor / donor — read-only trail

Account: `audit@capbon.test`. Lands on the lots. Sees lots, pickups, the register, slips, documents and reports;
no dashboard, no stock, no administration. Any other address sends back to the lots.

![Auditor lots](screenshots/auditor/01-lots.png)
![Auditor lot detail](screenshots/auditor/02-lot-detail.png)
![Auditor register](screenshots/auditor/03-register.png)
![Auditor documents](screenshots/auditor/04-documents.png)

## 11. Provider workspace

Account: `karim@sahel-recyclage.test` (*Recyclage du Sahel*). Access is free for providers.

### Demandes d'enlèvement — `/provider/pickups`

![Provider requests](screenshots/provider/01-pickups.png)

Requests addressed to you: number, client, site and city, date, lots, weight, status. Open one:

![A request](screenshots/provider/02-pickup-request.png)

| Action | When | Effect |
|---|---|---|
| Confirmer une date | Demandé | Proposes the pickup date; the client is notified |
| Refuser | Demandé | With a reason; the lots return to the client's stock |
| Enregistrer la réception | Enlevé | Weight received per lot |
| Déclarer le traitement | Réceptionné | Treatment channel per lot |
| Déposer un certificat | from Enlevé | Recycling or destruction certificate (PDF) |

![Confirm a date](screenshots/provider/03-confirm-date.png)
![Confirmed](screenshots/provider/04-pickup-confirmed.png)
![Reception](screenshots/provider/09-reception-dialog.png)
![Received, with discrepancy](screenshots/provider/10-pickup-received.png)
![Treatment](screenshots/provider/11-treatment-dialog.png)
![Upload a certificate](screenshots/provider/12-certificate-upload.png)
![Certificate listed on the pickup](screenshots/provider/13-certificate-listed.png)

### Bordereaux de suivi — `/provider/manifests`, Documents — `/provider/documents`

Slips to sign as transporter and as receiver (same password signature as the client), and the archive of your
certificates.

![Provider slips](screenshots/provider/05-manifests.png)
![Provider documents](screenshots/provider/06-documents.png)

### Profil et déchets acceptés — `/provider/profile`

What clients read in the directory: activities, description, area, contact, and the waste families you accept
with channel and minimum quantity.

![Provider profile](screenshots/provider/07-profile.png)

### Agréments — `/provider/accreditations`

Your accreditations with their validity. **Ajouter un agrément** (type, reference, authority, dates, PDF): it is
checked by Diva Software before it counts. Without a valid, approved accreditation you cannot be chosen.

![Provider accreditations](screenshots/provider/08-accreditations.png)


### Utilisateurs — `/admin/users`

Same screen as for a client: invite colleagues as provider administrator or provider operator.

## 12. Platform administration

The back office of Diva Software is described screen by screen in
[PLATFORM_ADMIN_GUIDE.md](PLATFORM_ADMIN_GUIDE.md).

![Back office](screenshots/platform/01-overview.png)

## 13. Status reference

| Object | Status | Meaning |
|---|---|---|
| Lot | Créé | Just created, not stored yet |
| | Stocké | In a zone, available for a pickup |
| | En attente d'enlèvement | Included in a pickup request |
| | Enlevé | Loaded on the provider's truck |
| | Traité | Treatment declared by the provider |
| | Clôturé | Pickup closed; end of the trail |
| Pickup | Brouillon, Demandé, Confirmé, Enlevé, Réceptionné, Clôturé, Refusé, Annulé | See [Enlèvements](#enlèvements--pickups) |
| Pickup line | Prévu, Chargé, Non chargé, Reçu, Rejeté, Traité | State of one lot within the pickup |
| Tracking slip | Signature producteur / transporteur / destinataire attendue, Exemplaire signé à joindre, Signé, Annulé | Whose signature is missing, or that the scan of the hand-signed slip is still to attach |
| Declaration | Projet, Validée | Draft (recomputable) or frozen |
| Accreditation | À valider, Approuvé, Rejeté; Valide, Expire bientôt, Expiré | Review by the platform; validity in time |
| Company | Email à confirmer, En attente de validation, Active, Rejetée, Suspendue | See the platform guide |
| Subscription | Essai gratuit, Actif, Paiement en retard, Résilié, Terminé | |
| Invoice | À régler, Partiellement réglée, Réglée, En retard, Annulée | |
| Member | Actif, Suspendu | |
| Invitation | En attente, Acceptée, Expirée, Révoquée | |
| Threshold | Normal, Seuil proche, Seuil dépassé | Below 80 %, 80–100 %, above |
| Workshop operation | À synchroniser, Synchronisé, Échec | Offline queue |

## 14. Data isolation between companies

A company never sees another company's data. Signed in as the second client (`nour@monastir-jeans.test`), the
address of a lot of Textile du Cap Bon answers:

![Another company's lot is not accessible](screenshots/client/18-isolation-not-found.png)
