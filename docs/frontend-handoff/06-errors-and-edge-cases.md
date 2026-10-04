# Errors And Edge Cases

## Standard API Error Format

`ProblemDetails::render()` returns `Content-Type: application/problem+json`. Common members: `type` (`about:blank`), HTTP `title`, `status`, stable `code`, optional `detail`, optional `errors`, optional `request_id`. Null keys are removed. `X-Request-Id` is added to every response by middleware; accepted incoming IDs must match `[A-Za-z0-9._-]{8,64}`, otherwise a ULID is generated.

| HTTP | Code / shape | Trigger evidenced in source |
|---|---|---|
| 401 | `UNAUTHENTICATED` | Sanctum authentication failure. |
| 403 | `FORBIDDEN` | Authorization exception, explicit `abort_unless`, user not allowed for company/tenant, or notification ownership failure. Detail often omitted in ProblemDetails. |
| 404 | `NOT_FOUND` | Model binding/not found; deliberate tenant non-disclosure means records in another tenant are not revealed. |
| 409 | Business-specific code and detail | `BusinessRuleViolation`; catalog below. |
| 422 | `VALIDATION_FAILED`, detail `The given data was invalid.`, `errors` field-to-list-of-messages map | FormRequest or inline validation. |
| Other HTTP status | uppercase snake-cased status-text code | `HttpExceptionInterface` (e.g. rate limit if framework emits 429); login route is throttled to 5 requests/minute, with no API-wide rate-limit declaration found. |
| 500 | `SERVER_ERROR`; exception detail only when `app.debug` true | Unexpected throwable. |

Sources: [ProblemDetails](../../app/Modules/Common/Http/ProblemDetails.php), [AssignRequestId](../../app/Modules/Common/Http/Middleware/AssignRequestId.php), [ApiFoundationTest](../../tests/Feature/Foundation/ApiFoundationTest.php).

## Business Conflicts

Stable conflict codes directly found in route actions/workflows:

- `INVALID_TRANSITION`: lot status transition disallowed.
- `LOT_NOT_DELETABLE`: attempt to physically delete a lot.
- `LOT_NOT_TRANSFORMABLE`: lot cannot be split/grouped (status/deleted).
- `SPLIT_WEIGHT_GAP`: split output weight differs by >2% without notes.
- `GROUP_INVALID_INPUTS`: fewer than two/duplicate/nonexistent group lots.
- `GROUP_MIXED_LOTS`: group members differ by site, waste type or hazard.
- `PICKUP_LOTS_INVALID`: selected lot missing or not on pickup site.
- `LOT_NOT_AVAILABLE`: lot status is not created/stored at pickup creation.
- `LOT_IN_PICKUP`: lot already in active pickup.
- `INVALID_PICKUP_TRANSITION`: action not allowed from current pickup status.
- `PROVIDER_NOT_ELIGIBLE`: partnership/profile/company/accreditation eligibility fails.
- `INVOICE_NOT_PAYABLE`: invoice state does not accept payments.
- `DOCUMENT_CANCELLED`: issue attempted for cancelled document.
- `DOCUMENT_SUPERSEDED`: issue attempted for superseded document.
- `DOCUMENT_FILE_REQUIRED`: issue attempted with no stored file.
- `CERTIFICATE_PICKUP_NOT_READY`: certificate attempted before collected/received.
- `CERTIFICATE_LOTS_NOT_IN_PICKUP`: one or more certificate lot IDs are not attached to pickup.
- `DEVICE_ID_TAKEN`: device ULID belongs to another company.
- `DEVICE_REVOKED`: attempt to re-register revoked device.

Sources: [BusinessRuleViolation](../../app/Modules/Common/Exceptions/BusinessRuleViolation.php), [PickupWorkflow](../../app/Modules/Pickups/Actions/PickupWorkflow.php), [TransformLots](../../app/Modules/Waste/Actions/TransformLots.php), [UploadCertificate](../../app/Modules/Documents/Actions/UploadCertificate.php), [RegisterDevice](../../app/Modules/Sync/Actions/RegisterDevice.php), [DeclareBankTransfer](../../app/Modules/Billing/Actions/DeclareBankTransfer.php).

## Important Edge Cases

- Route model binding plus tenant scope can return 404 for a foreign-company model; a visible record with missing site permission returns 403. Tests cover both for lots and provider party visibility for pickups.
- Site permission checks differ between single-resource routes (site ID supplied) and list routes. Documents/manifests/declarations list routes do not apply a site-ID filter after the permission check; implementation behavior is exact in [02-api-inventory.md](02-api-inventory.md).
- Audit request expects `audit_logs.view`, while seeded catalog has `audit.view`; report creation expects `reports.create`, absent from catalog. These may yield 403 for seeded roles unless extra permission records are configured.
- Audit date filter only applies when `filter.between` is an array with exactly two non-null dates. `filter.site`/`filter.pickup` document filters currently compare DB foreign keys, not ULIDs.
- Certificate request `authorize()` returns true; the action checks company-party and pickup status. A valid PDF/JPEG/PNG MIME check can still fail if PHP cannot determine MIME/file data.
- Manifest accepts database numeric IDs in request; resources may emit numeric fallback IDs if relation is not loaded. Document and report resources also expose some numeric IDs.
- Group route authorizes based on first submitted lot's site; action validates mixed-site input only as a business conflict.
- `GET /lots/{lot}/events` is unpaginated; pickup and lot collections are absent. A frontend cannot retrieve all records by assuming a conventional index route.
- Notifications use UUID `id`, unlike the common public ULID convention.
- `/reports` returns 202 even though the inspected action creates the row directly; no worker lifecycle is evidenced by that action.
- Unexpected detail content in 500 responses depends on `app.debug`; do not rely on it.

Tests: [API foundation](../../tests/Feature/Foundation/ApiFoundationTest.php), [lot](../../tests/Feature/Waste/WasteLotTest.php), [pickup](../../tests/Feature/Pickups/PickupTest.php), [certificate](../../tests/Feature/Documents/CertificateUploadTest.php), [stored file](../../tests/Feature/Common/StoredFileTest.php).

`UNKNOWN — NOT FOUND IN BACKEND`: rate-limit policy/headers, retry guidance, a universal idempotency header, conflict-resolution API payloads, exact production 500 detail (configuration dependent), error contract for package routes.