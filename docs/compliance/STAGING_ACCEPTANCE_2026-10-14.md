# Business decisions resolved and staging acceptance — 14 October 2026

Follows [ACCEPTANCE_AUDIT_2026-10-13.md](ACCEPTANCE_AUDIT_2026-10-13.md). Decisions F97–F103 in
[DECISIONS.md](../DECISIONS.md). Everything below ran on throwaway databases (`dw_disposable_p8*` on MySQL 8.0.44,
`dw_disposable_m8*` on a local MariaDB 10.11.10); `divawaste` and `divawaste_demo` were only read.

## What was decided

| Decision | Behaviour | Where |
|---|---|---|
| Minimums judged again at loading (F97, F98) | Judged on what left, stored beside the request answer (never rewritten); the receiver told once, in its units; nothing refused; a blocking one no longer met is an exception explained afterwards by someone entitled to override | `ProviderRequirements::atLoading`, `PickupNotifier::collected`, `POST pickups/{p}/loading-requirements/acknowledge` |
| Requirements of one waste type (F99) | Catalogue item (provider) or company type (partner notes); replaces the family rule of the same kind for its lots unless "adds to the family"; duplicates refused; unit must suit the type | `ProviderRequirements::evaluate`, provider profile and partner forms |
| Closure apart from compliance (F100) | Closure unchanged (the cahier names no transition for signatures); documents state `not_due` / `incomplete` / `pending` / `complete` on page, list and filter, for both parties | `PickupCompliance` |
| Capacity emails (F101) | In-app for everyone who sees the stock; email by default to managers of limits or zones of the site; others opt in | `NotificationCatalog::STOCK_MANAGERS`, `BusinessEventNotification::via` |
| Hazardous codes (F102) | Required at creation; legacy types flagged and completed once; such lots not requested; other slips still issued; missing slips issued after the code, also on a closed pickup | `StoreWasteTypeRequest`, `PUT waste-types/{t}/regulatory-code`, `ManifestWorkflow::generate` |
| MariaDB (F103) | Suite and upgrade run on MariaDB 10.11; one unordered grouped query ordered | `ZoneOrganisation::usage` |

## How each point is verified

**A** automated test · **B** in the browser, production build, by clicks · **R** API run with each person's sign-in on
the upgraded copy · **I** code inspection only · **N** not verified.

| Point | Level | Evidence |
|---|---|---|
| Loading answer stored beside the request answer, request answer unchanged | A, R, B | `AcceptanceDecisionsTest` (1st test) · E1 (ENL-2026-0008: 1,62 t at request, 1,618 t at loading, cardboard left on site) · U2 |
| No double evaluation or notification on a retried loading | A | second `collect` refused, one notification, one audit row |
| Receiver told, French and English, figures and units | A, R, B | E2 "minimum 2 m³ : 1,2 m³ chargé(s), il manque 0,8 m³" / "… 0.8 m³ short" · U4 (inbox FR then EN) |
| Blocking exception: never refuses departure, explained by an entitled person only | A, R, B | 2nd test · E2 (requester 403, provider 403, Sami 200, once) · U3 (explained by clicks) |
| Type rule replaces family rule; "adds to family"; duplicates refused; unit check | A, R, B | 3rd and 4th tests · E3, E4 (corrected run) · U5, U6 (field present, desktop and 390 px; only the family's types offered) |
| Pending signatures shown, closed ≠ complete, filter | A, R, B | 5th test · E1 (four outstanding pickups, closed and open) · U1, U2 |
| Capacity email recipients, opt-in, tenant | A, R, B | 6th test (other company's manager gets nothing) · E6 (log: emails to amira and sami only; Leila sees 14 alerts in-app) · U8 |
| Hazardous code required, legacy completion, partial slips | A, R, B | 7th test · E5 (refused with type and lot named; loaded; 1 slip then 2 after the code; first slip untouched) · U7 |
| Upgrade on MySQL and MariaDB, rollback | R | see below |
| Previously not re-run: register export (D3), notification units (N1, O8), workshop counted lot (M1), stage C expectations, CSR "non pesé" | R, B | C 50/50 · D3 PDF and Excel downloaded by clicks · N1 and O8 pass reading the whole inbox · M1 by taps (120 pièces, no weight) · CSR PDF downloaded: cones and used oils read "non pesé" |
| Partner-requirement dialog after the change to four columns | I | the browser checks ran before that CSS change; the provider form was re-checked after it (ui7a K2/K3) |
| Real mailbox, thermal printer, camera, load at the cahier's volumes, penetration test | N | — |

## Runs and results

- Backend: 287 tests pass on MySQL 8.0.44 and on MariaDB 10.11.10. PHPStan 0 errors, Pint clean.
- Frontend: lint clean, every translation key resolves, 59 unit tests, production build.
- API runs on the upgraded copy: A 21/21, B 21/21, C 50/50, D 3/3. E: 28/31 at first; the 3 failures were script errors
  (the cone rule used another channel than the cone lots; the solvent request was stopped by the provider's blocking
  sludge minimum before the code check). Corrected: E4 3/3, E5 3/3.
- Browser runs (headless Chrome on the build): ui7b 31/33 → N1 (inbox read past its first page) and O8 (the loading
  message is now the "requirement not met" one, F97) re-checked: pass. ui8 29/33 → U5–U7 script errors (a tap reached the
  table row before its button; a capitalised label), re-run: 7/8, the last one an expectation error (the list held
  exactly the company's one textile-fibre type, which is right). m7 12/13 → M1 alone 3/3. ui7a 20/20 after a real
  defect was fixed: with the new waste-type field the provider form cut "Kilogrammes (kg)" and "Mètres cubes (m³)" on
  desktop; both forms now use four columns.
- Upgrade (MySQL): backup 841 KB, "Dump completed", restore identical; 8 migrations in 4.9 s; seeder +1 permission on 2
  roles, 12 assignments identical; 24 historical record sets identical except the known 0 kg placeholder (lot 81, line
  64); 280 foreign keys, no orphan; dry run 0 messages; `migrate:rollback --step=8` gives back every column, index and
  row except that placeholder.
- Upgrade (MariaDB 10.11.10): MySQL dump imported unchanged (93 tables, 2,034 rows, row counts equal); `mariadb-dump`
  backup restored; 8 migrations in 2.3 s; same records, permissions and roles as MySQL after upgrade; rollback as on
  MySQL. Checksums of `user_role_assignments` differ across engines because of its stored generated column; all 12 rows
  are identical column by column.

## Staging acceptance tests (to run on the staging servers)

1. Rehearse the upgrade on a copy of the staging database (OPERATIONS.md), on the staging MariaDB.
2. Run `limits:evaluate --dry-run`: workshop operators appear without email.
3. Load a pickup leaving one lot behind against a provider minimum: provider receives the email in its language; the
   pickup shows "Au chargement" beside the request answer.
4. Close a pickup without signing its slips: list shows "Signatures en attente"; sign all three: "Documents complets".
5. Complete the code of any hazardous type flagged on the waste types page; issue the slips the pickups list as missing.
6. Real SMTP delivery to a test mailbox; thermal label print; camera scan on a phone.

## Open

- A workshop operator has no web access (the workshop application has no notification preferences), so cannot opt in
  to stock emails; an administrator can give the role web access, or a preference screen can be added to the workshop
  application.
- Production: personal data export and erasure (missing), load test, penetration test, the business points of
  [MVP_ACCEPTANCE.md](../MVP_ACCEPTANCE.md) (legal value of the signature, official slip layout, prices, VAT).
