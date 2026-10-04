# Roles and permissions

Who can do what in DivaWaste, role by role. The matrix below is the one installed by default
(`AuthorizationSeeder`) and was read from the running demo database; the platform administrator can adjust it
in **Back-office → Rôles et permissions**, and a client administrator can add its own roles.

## How access works

1. **A user belongs to a company** (or to the platform). The company's type decides the workspace: industrial →
   client workspace, provider → provider workspace, Diva Software → back office.
2. **A user has one or more roles in that company.** A role is a list of permissions (`lots.create`,
   `pickups.request`…).
3. **A role is given for all sites or for chosen sites.** This is the *site-specific role*: the same person can be
   environment manager on one site and nothing on another. When inviting a user, leave "Sites" empty for all
   sites, or pick the sites.
   - Site permissions (lots, stock, pickups, zones) apply only on those sites: lists, forms and the workshop app
     show only those sites.
   - Company permissions (users, subscription, company details, waste types) need a role given for **all sites**.
4. **The server decides.** Menus and buttons are hidden when you lack a permission, but hiding is only comfort:
   every request is checked by the API, and a company never sees another company's data.

## The seven roles of the specification

| # | Role (screen name) | Technical name | Workspace | Lands on |
|---|---|---|---|---|
| 1 | Super-administrateur plateforme | `super_admin` | Back office | `/platform/overview` |
| 2 | Administrateur client | `client_admin` | Client | `/dashboard` |
| 3 | Responsable environnement | `environment_manager` | Client | `/dashboard` |
| 4 | Opérateur atelier | `workshop_operator` | Workshop app | `/m` |
| 5 | Direction (lecture) | `management_viewer` | Client, read-only | `/dashboard` |
| 6 | Prestataire | `provider_admin`, `provider_operator` | Provider | `/provider/pickups` |
| 7 | Auditeur / bailleur | `auditor` | Client, read-only | `/lots` |

Two more technical roles exist: `platform_support` (reads the platform activity only) and `provider_operator`
(a provider's employee who receives pickups but does not manage the company).

---

## 1. Super-administrateur plateforme

**Purpose.** Operates the SaaS for Diva Software. Demo account: `admin@divawaste.test`.

**Navigation.** Vue d'ensemble · Sociétés · Agréments à valider · Formules et tarifs · Factures · Virements
déclarés · Catalogue de déchets · Rôles et permissions · Activité de la plateforme.

| Can | Cannot |
|---|---|
| Approve, reject, suspend, reactivate companies | See or edit a client's lots, pickups, stock, documents |
| Review providers' accreditations | Sign in as a client |
| Manage plans and reference prices; activate a plan; validate bank transfers | Create a company, a site or a user for a client |
| Manage the reference waste catalogue and the role matrix | Delete a company |
| Read the platform activity | |

Permissions: `platform.companies.review`, `platform.companies.suspend`, `platform.providers.review`,
`platform.plans.manage`, `platform.invoices.manage`, `platform.payments.validate`, `platform.catalog.manage`,
`platform.roles.manage`, `platform.support.view`, `platform.countries.manage`, `platform.legal.manage` (the last
two have no screen yet).

Full guide: [PLATFORM_ADMIN_GUIDE.md](PLATFORM_ADMIN_GUIDE.md).

## 2. Administrateur client

**Purpose.** Owns the company's account: team, sites, subscription — and can do everything the environment
manager does. Demo account: `amira@capbon.test`.

**Dashboard.** Full dashboard. **Navigation.** Every group: Pilotage, Opérations, Conformité, Référentiel,
Administration (Utilisateurs et rôles, Abonnement, Journal d'audit, Société), Atelier.

| Can | Cannot |
|---|---|
| Everything of the environment manager | Reach the back office |
| Invite, suspend, remove users; give roles per site; create custom roles | See another company |
| View and manage the subscription, invoices; declare a bank transfer | Approve its own registration or accreditation |
| Edit the company's details | Delete regulated records (lots are closed, sites deactivated) |

Only this role has: `users.invite`, `users.manage`, `roles.manage`, `subscription.view`, `subscription.manage`,
`invoices.view`, `payments.declare`.

## 3. Responsable environnement

**Purpose.** Runs waste management day to day. Demo account: `sami@capbon.test`.

**Dashboard.** Full dashboard. **Navigation.** Pilotage, Opérations, Conformité, Référentiel; in Administration:
Utilisateurs (read), Journal d'audit, Société; Atelier.

| Can | Cannot |
|---|---|
| Create, store, move, reweigh, split, group lots; print labels | Invite or manage users (sees the list only) |
| Manage sites, zones, waste types, stock thresholds | See the subscription and invoices |
| Request, load, price, close, cancel pickups | |
| Manage partner providers | |
| Sign slips, upload documents, prepare and validate the annual declaration | |
| Generate reports, enter production volumes | |
| Use the workshop app | |

**Site restriction.** Often given for one site: the person then sees and handles only that site's lots, stock
and pickups, but loses the company-wide screens (waste types, company) unless the role is given for all sites.

## 4. Opérateur atelier

**Purpose.** Records waste where it is produced. Demo account: `leila@capbon.test` (Usine de Nabeul only).

**Dashboard.** None. **Navigation.** Only the workshop application `/m`; any desktop address sends back to `/m`.

```text
Operator:
Can create lots (zone → type → weight)
Can scan a QR code, or type the lot number
Can store or move a lot, reweigh, split, group
Can print the label
Can load a confirmed pickup of its site
Can work without network; operations are sent when it comes back
Cannot open the desktop application (dashboard, lists, administration)
Cannot request, price or close a pickup
Cannot see another site than the ones given with the role
Cannot access platform administration
```

Permissions: `app.mobile.access`, `lots.view`, `lots.create`, `lots.move`, `lots.weigh`, `lots.split`,
`lots.group`, `lots.print_label`, `pickups.load`, `stock.view`.

**Special behaviour.** With a single site, the app never asks "which site?". With several, it asks once and
remembers.

## 5. Direction (lecture)

**Purpose.** Follows performance without touching anything. Demo account: `direction@capbon.test`.

**Dashboard.** Full dashboard. **Navigation.** Tableau de bord, Lots, Stock, Enlèvements, Prestataires, Registre,
Bordereaux, Documents, Rapports, Sites, Types de déchets, Utilisateurs, Abonnement, Journal d'audit.

| Can | Cannot |
|---|---|
| Read everything listed above | Create or change anything: no action button is shown |
| Generate and download reports | Enter production volumes |
| See invoices | Declare a payment, change the plan |

## 6. Prestataire

**Purpose.** Receives and treats the waste. Demo account: `karim@sahel-recyclage.test` (administrator).

**Navigation.** Demandes d'enlèvement, Bordereaux de suivi, Documents; Profil et déchets acceptés, Agréments,
Utilisateurs.

| Provider administrator can | Provider operator can | Neither can |
|---|---|---|
| Confirm or refuse a request | See the requests | See a client's lots outside a request addressed to them |
| Record reception, declare treatment | Record reception | See another provider's requests |
| Sign slips as transporter and receiver | Upload certificates | Be chosen without a valid, approved accreditation |
| Upload certificates | | |
| Manage the public profile and the accreditations | | |
| Invite colleagues | | |

A provider only sees the pickups where it is the chosen provider, and of each lot only what the pickup needs
(number, type, code, weights).

## 7. Auditeur / bailleur

**Purpose.** Checks traceability from outside: an auditor, a brand, a donor. Demo account: `audit@capbon.test`.

**Dashboard.** None; lands on the lots. **Navigation.** Lots, Enlèvements, Registre, Bordereaux, Documents,
Rapports.

| Can | Cannot |
|---|---|
| Read lots and their full history | See the dashboard, the stock, the economic figures |
| Read pickups, the register, slips, certificates | See users, subscription, audit journal |
| Read reports | Create, change, sign or export anything |

Permissions: `app.web.access`, `lots.view`, `pickups.view`, `documents.view`, `reports.view`.

---

## Permission matrix (client workspace)

✔ = granted by default.

| Permission | Admin client | Resp. environnement | Opérateur | Direction | Auditeur |
|---|:-:|:-:|:-:|:-:|:-:|
| Desktop application (`app.web.access`) | ✔ | ✔ | | ✔ | ✔ |
| Workshop application (`app.mobile.access`) | ✔ | ✔ | ✔ | | |
| Dashboard (`dashboard.view`) | ✔ | ✔ | | ✔ | |
| Lots: view | ✔ | ✔ | ✔ | ✔ | ✔ |
| Lots: create, move, weigh, split, group, print label | ✔ | ✔ | ✔ | | |
| Lots: update, delete (draft errors) | ✔ | ✔ | | | |
| Stock: view | ✔ | ✔ | ✔ | ✔ | |
| Stock thresholds: manage | ✔ | ✔ | | | |
| Pickups: view | ✔ | ✔ | | ✔ | ✔ |
| Pickups: load | ✔ | ✔ | ✔ | | |
| Pickups: request, price, complete, cancel | ✔ | ✔ | | | |
| Providers: view | ✔ | ✔ | | ✔ | |
| Providers: manage partners | ✔ | ✔ | | | |
| Documents: view | ✔ | ✔ | | ✔ | ✔ |
| Documents: generate, upload, sign, delete | ✔ | ✔ | | | |
| Declarations: prepare, validate | ✔ | ✔ | | | |
| Reports: view | ✔ | ✔ | | ✔ | ✔ |
| Reports: export | ✔ | ✔ | | ✔ | |
| Production volumes | ✔ | ✔ | | | |
| Sites, zones: view | ✔ | ✔ | | ✔ | |
| Sites, zones: manage | ✔ | ✔ | | | |
| Waste types: view | ✔ | ✔ | | ✔ | |
| Waste types: manage | ✔ | ✔ | | | |
| Company: view | ✔ | ✔ | | ✔ | |
| Company: update | ✔ | ✔ | | | |
| Users: view | ✔ | ✔ | | ✔ | |
| Users: invite, manage; roles | ✔ | | | | |
| Audit journal | ✔ | ✔ | | ✔ | |
| Subscription: view | ✔ | | | ✔ | |
| Subscription: manage; declare payment | ✔ | | | | |
| Invoices: view | ✔ | | | ✔ | |

## Practical checks

| Check | Account | Expected |
|---|---|---|
| Operator cannot open the desktop | `leila@capbon.test`, go to `/dashboard` | Sent back to `/m` |
| Operator sees one site | `leila@capbon.test`, "Nouveau lot" | Only zones of Usine de Nabeul |
| Direction has no action | `direction@capbon.test`, `/lots` | No "Nouveau lot" button |
| Auditor has no administration | `audit@capbon.test`, go to `/admin/users` | Sent back to `/lots` |
| Manager cannot invite | `sami@capbon.test`, `/admin/users` | List visible, no "Inviter un utilisateur" |
| Client cannot reach the back office | `amira@capbon.test`, go to `/platform/companies` | Sent back to `/dashboard` |
| Another company's data | `nour@monastir-jeans.test`, `/lots` | Only its own 3 lots |
