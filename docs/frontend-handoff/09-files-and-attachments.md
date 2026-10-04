# Files And Attachments

## Application Upload

The only application upload endpoint found is `POST /api/v1/pickups/{pickup}/certificates` (see [02-api-inventory.md](02-api-inventory.md)). It requires authenticated tenant context and a multipart file. FormRequest limits: detected MIME must be `application/pdf`, `image/jpeg`, or `image/png`; maximum 20480 KiB (20 MiB); `type` is exactly `recycling` or `destruction`; `lots` or alias `lot_numbers` has 1..32 string identifiers, each <=100; optional title <=255. No other application upload route was found.

## Storage And Metadata

`FileStorage` uses configured default disk (defaults to `local`), detects MIME from file contents, computes SHA-256, stores under `tenants/{company-ulid}/{purpose}/{YYYY}/{MM}/{file-ulid}.{extension}`, and creates `stored_files` metadata. Certificate purpose is `certificates`; owner company is the stored-file tenant even when provider uploads. Metadata includes ULID, original name, MIME, extension, byte size, checksum, purpose, uploader user/company, scan status (`pending`), retention date and legal hold. Certificate retention is ten years. File path is not included in certificate document responses.

Certificate document response includes nested file metadata when `storedFile` is loaded: ULID, original name, MIME type, size bytes, checksum, retention-until date. It does not include a URL or raw path. See [FileStorage](../../app/Modules/Common/Support/FileStorage.php), [StoredFile](../../app/Modules/Common/Models/StoredFile.php), [migration](../../database/migrations/2026_10_01_000400_create_stored_files_and_number_sequences_tables.php), [DocumentResource](../../app/Modules/Documents/Resources/DocumentResource.php), [UploadCertificate](../../app/Modules/Documents/Actions/UploadCertificate.php).

## Downloads, Access, Deletion, Scanning

No application endpoint was found that returns a download URL, streams an application `StoredFile`, deletes an attachment, previews content, or transitions scan state. `FileStorage::temporaryUrl()` exists (default five minutes), but no route/resource calls it in the inspected app route inventory. `scan_status` starts `pending`; no scan worker/status transition was found. StoredFile is soft-deletable with deletion actor/reason fields; no deletion endpoint/workflow was found.

The local disk config has `serve: true`, causing Laravel framework routes `GET|HEAD /storage/{path}` and `PUT /storage/{path}` to register. The configured local disk has no public visibility, so `ServeFile` requires a valid relative URL signature for GET; `ReceiveFile` requires both `upload=true` and a valid relative signature for PUT. The handlers do not use application `StoredFile` authorization. Invalid signatures return 403 outside production and 404 in production; missing files and path traversal return 404. `FileStorage::temporaryUrl()` delegates to the disk driver (default five minutes); URL support/shape is disk-specific. S3 configuration exists, but actual disk depends on environment. Sources: [filesystem config](../../config/filesystems.php), [ServeFile](../../vendor/laravel/framework/src/Illuminate/Filesystem/ServeFile.php), [ReceiveFile](../../vendor/laravel/framework/src/Illuminate/Filesystem/ReceiveFile.php).

No preview derivatives, thumbnail formats, virus-scanning behavior, upload resumability, chunking, or general file-type policy is implemented by the application endpoint examined.

`UNKNOWN — NOT FOUND IN BACKEND`: a frontend-facing download/preview URL, attachment listing API, generic file upload API, authorization policy for application downloads, scan service and clean/infected transition route, deletion API, maximum upload limits for other file consumers.