# Backend-to-Frontend Handoff

This package records the API, domain, data, security, workflow, file, pagination, and error behavior that is evidenced by the checked-in Laravel backend as inspected on 2026-10-02. It is a backend discovery artifact, not a frontend design proposal.

## Read First

1. [00-overview.md](00-overview.md) for the API boundary and global conventions.
2. [02-api-inventory.md](02-api-inventory.md) for the runtime API routes and per-endpoint contracts.
3. [03-auth-and-permissions.md](03-auth-and-permissions.md) and [04-data-contracts.md](04-data-contracts.md) for access and wire shapes.
4. [05-workflows-and-business-rules.md](05-workflows-and-business-rules.md), [06-errors-and-edge-cases.md](06-errors-and-edge-cases.md), and [08-pagination-filtering-sorting.md](08-pagination-filtering-sorting.md) before implementing mutations or lists.
5. Consult [01-domain-model.md](01-domain-model.md), [07-reference-data-and-enums.md](07-reference-data-and-enums.md), [09-files-and-attachments.md](09-files-and-attachments.md), and [10-frontend-requirements.md](10-frontend-requirements.md) as needed.

`manifest.yaml` provides machine-readable counts, enumerated permissions/roles/workflows, source locations, and confidence notes.

## Provenance And Verification

Claims are linked to route declarations, requests, DTOs, actions, resources, models, middleware, migrations, seeders, configuration, or tests. Verify an endpoint by comparing its entry in [02-api-inventory.md](02-api-inventory.md) with its route source and following the linked request/action/resource. Verify persisted fields and constraints in the cited migration; migrations, not the blueprint, are the schema source of truth for this package. Runtime route verification used `php artisan route:list` (46 total routes, including framework/package routes) and `php artisan route:list --path=api/v1` (42 application API routes).

Feature tests are evidence for exercised behavior, not proof that untested behavior is complete. The project convention says `php artisan test` uses MariaDB `divawaste_test`, not SQLite; `vendor/bin/pint` is the PHP formatting gate. This documentation change does not modify PHP code.

## Known Unknowns And Conflicts

`UNKNOWN — NOT FOUND IN BACKEND` means the inspected source did not establish the behavior. The principal gaps are logout/token revocation and refresh routes, tenant-company switching, download/preview endpoints, a file malware-scan transition, implemented push/pull sync routes, several role/user management APIs, and an API contract document separate from the architecture blueprint.

The repository's table counts conflict: [CLAUDE.md](../../CLAUDE.md) describes a reviewed 68-table MVP; [README.md](../../README.md) and [the blueprint](../DivaWasteTextile-Technical-Architecture-Blueprint.md) describe 89 MVP / 108 total. The current migration scan found 70 `Schema::create` declarations in 27 migration files. These are different source claims/counting scopes and are not silently reconciled here. The manifest's 42 entity count means module Eloquent model classes counted under `app/Modules/*/Models` excluding `Concerns`, not physical tables or a formally approved conceptual entity count.

When backend behavior differs from a documented convention, this package records the implementation and calls out the discrepancy. The future consumer should re-check linked source before relying on undocumented behavior or blueprint-only plans.