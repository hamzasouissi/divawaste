# Workflows And Business Rules

Only behaviors implemented in route/request/action/model/test code are stated as live. Status vocabularies do not imply that every transition has a route.

## Lot Lifecycle And Transformations

Lot statuses: `created → stored → awaiting_pickup → collected → treated → closed`, with `awaiting_pickup → stored` for release and certain early statuses able to close. The enum is authoritative: created may go to stored/awaiting_pickup/closed; stored to awaiting_pickup/closed; awaiting_pickup to stored/collected; collected to treated; treated to closed; closed has no outgoing transition. Each transition increments version through model update, writes lifecycle timestamp and an append-only status event. Force-deleting a lot is rejected; event/lineage records are append-only.

Split/group only accept non-deleted `created` or `stored` lots. Split requires at least two positive output weights; if absolute weight difference exceeds 2% of input, non-null notes are required. It derives new lots/tags and parent-child lineage, then closes input with `split`. Group requires at least two unique existing lots with same site, waste type and hazard flag; inputs close with `grouped`, and one output is created stored in a zone on that site. Weighted composition is derived. A grouped/closed lot cannot be transformed again.

Sources: [LotStatus](../../app/Modules/Waste/Enums/LotStatus.php), [TransitionLot](../../app/Modules/Waste/Actions/TransitionLot.php), [TransformLots](../../app/Modules/Waste/Actions/TransformLots.php), [WasteLotTest](../../tests/Feature/Waste/WasteLotTest.php).

## Pickup Workflow

1. Create produces `draft`. Every selected lot must exist on the requested site, be `created|stored`, and not belong to another active pickup.
2. Submit permits draft only. It checks provider and optional transporter eligibility and moves included lots to `awaiting_pickup`; pickup becomes `requested`.
3. Confirm/refuse permit requested only. Confirm records date and rechecks eligibility for that date. Refuse releases lots to stored and deactivates lines.
4. Cancel permits draft/requested/confirmed; releases awaiting lots and stores cancellation reason. Collected/received pickups cannot be cancelled by this workflow.
5. Collect permits confirmed only. Listed lots become collected; omitted lots become `not_loaded` and stored. Missing departure weight defaults to lot net weight. Pickup total sums loaded weights.
6. Receive permits collected only. Provided loaded lines get received weights and variance. Pickup becomes received; no `complete` route/transition implementation is present in this workflow despite `completed` enum/schema value.

Eligibility requires active owner-provider partnership; provider profile published and company active; accreditation approved, not deleted, valid on requested/confirmed date; accreditation must cover hazardous waste if pickup has any hazardous lot. The optional transporter is checked the same way. Weight variance defaults to 5% from company settings, checks both positive and negative difference, and flags only when absolute percent is strictly greater than threshold. A zero departure denominator produces null percent and no flag.

Provider/transporter users can see party-addressed pickup records, but not owner lots through ordinary tenant-scoped lot lookup. Actions apply lot-side effects under the pickup owner's tenant context. Sources: [PickupWorkflow](../../app/Modules/Pickups/Actions/PickupWorkflow.php), [TenantScope](../../app/Modules/Tenancy/Scopes/TenantScope.php), [PickupTest](../../tests/Feature/Pickups/PickupTest.php).

## Certificates And Compliance

Certificate upload is only allowed once pickup is `collected` or `received`; acting company must be pickup owner or provider (not merely an arbitrary authenticated tenant). Each supplied lot identifier must be part of that pickup; otherwise 409. Upload creates file metadata and snapshot, associates all matched lines, creates recycling/destruction certificate document, and immediately calls `IssueDocument`. Title defaults by type and pickup ULID. Retention is ten years. The action deletes the stored object if its transaction fails.

Manifest create generates `HM-{year}-` sequence and a linked draft hazardous-manifest document; uniqueness is per pickup + regulatory code. Annual declaration create defaults status to draft, `data` to empty array, sets computed time and reference `{FORMAT}-{SITE_CODE}-{YEAR}`; unique company/site/format/year prevents duplicate period. The status enums include review/validation/submission states but no API routes for validation, signature, or submission were found.

Issuing a document is idempotent when already issued. A cancelled or superseded document cannot be issued; a document without a stored file cannot be issued. These failures are `DOCUMENT_CANCELLED`, `DOCUMENT_SUPERSEDED`, and `DOCUMENT_FILE_REQUIRED` respectively (409). General issue route checks permission at the document site.

Sources: [UploadCertificate](../../app/Modules/Documents/Actions/UploadCertificate.php), [IssueDocument](../../app/Modules/Documents/Actions/IssueDocument.php), [CreateHazardousWasteManifest](../../app/Modules/Documents/Actions/CreateHazardousWasteManifest.php), [CreateAnnualDeclaration](../../app/Modules/Documents/Actions/CreateAnnualDeclaration.php), [CertificateUploadTest](../../tests/Feature/Documents/CertificateUploadTest.php).

## Billing And Reports

Bank-transfer declaration only creates a pending payment; invoice must have status issued, partially paid, or overdue. Amount/reference/date are request-required, but no equality check against outstanding invoice balance is in the action. Payment validation/rejection exists as separate actions but no route is registered for them.

Report creation stores a GeneratedReport record and returns 202. It accepts status from the request or DTO default, sets `started_at` now and `expires_at` 30 days ahead; the inspected action does not dispatch a generation job or populate a file/completion status. Do not assume a polling interval, download URL, or eventual completion contract.

Sources: [DeclareBankTransfer](../../app/Modules/Billing/Actions/DeclareBankTransfer.php), [InvoiceStatus](../../app/Modules/Billing/Enums/InvoiceStatus.php), [ReviewPayment](../../app/Modules/Billing/Actions/ReviewPayment.php), [CreateGeneratedReport](../../app/Modules/Reporting/Actions/CreateGeneratedReport.php).

## Catalog, Sites, Device, Notifications

- Site code unique per company. Site kind defaults production; active defaults true. Country must be an active seeded country ISO2.
- Waste type code unique per company. If composition supplied, rows must be distinct materials, positive percentages, total exactly 100; catalog-item-derived fields and references are resolved in the create action.
- Stock threshold target uniqueness is company/site/zone-scope/waste-type-scope; omitted zone/type mean broad scopes. warning_pct defaults 90.
- Device ULID is globally unique. Existing ID owned by another company is rejected without revealing owner; revoked device cannot be re-registered. Registration token lasts 30 days.
- Notification preference is upserted by company/user/type/channel. Mark-read checks current user is the notifiable subject.

Sources: [CreateWasteType](../../app/Modules/Catalog/Actions/CreateWasteType.php), [CreateSite](../../app/Modules/Sites/Actions/CreateSite.php), [CreateStockThreshold](../../app/Modules/Sites/Actions/CreateStockThreshold.php), [RegisterDevice](../../app/Modules/Sync/Actions/RegisterDevice.php), [notification routes](../../app/Modules/Notifications/Routes/api.php).

## Immutability, Uniqueness, And Conflict Conditions

Database constraints and action rules include unique company/site code, company/waste type code, site/month production volume, company/site/format/year declaration, pickup/lot pair, active pickup per lot, QR tag value, one current subscription per company, provider partnership pair, and one manifest per pickup/regulatory code. Several use composite company FKs; delete is restrictive unless explicitly null/cascade in migration. Lot status/event history is append-only, lots are soft-deleted and never physically deleted. See cited migrations in [01-domain-model.md](01-domain-model.md).

`UNKNOWN — NOT FOUND IN BACKEND`: exact behavior for status transitions not exposed by routes (e.g. lot collected→treated→closed); who executes report generation; manifest signature progression; declaration validation/submission workflow; stock alert evaluation/scheduling; provider accreditation upload/review routes; payment review endpoints; any general edit/delete behavior for most API resources.