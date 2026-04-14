# API Specification Notes

## API shape

- REST-style Laravel endpoints consumed by Livewire-adjacent application surfaces and offline operator/integration workflows.
- API is not the only product surface; Livewire remains primary for user interaction.
- Controllers stay thin and delegate to shared domain services.

## Versioning

- Base path: `/api/v1`

## Authentication and security

- Offline session-based authentication with Laravel session/cookie model.
- Critical admin/security actions require successful step-up verification before write operations proceed.
- Sensitive responses mask classified fields by default.

## Core endpoint groups

### Authentication
- `POST /api/v1/auth/sessions`
- `PUT /api/v1/auth/password`
- `POST /api/v1/auth/step-up`

### Catalog
- `GET /api/v1/catalog/services`
- `GET /api/v1/catalog/services/{slug}`
- `GET /api/v1/catalog/services/{slug}/slots`

Query/filter expectations:
- search text
- category ids
- tag ids
- audience ids
- price type (`free|paid|all`)
- sort (`earliest|lowest_fee`)
- pagination

### Learner reservations and dashboard
- `POST /api/v1/reservations`
- `GET /api/v1/reservations`
- `GET /api/v1/reservations/{uuid}`
- `POST /api/v1/reservations/{uuid}/cancel`
- `POST /api/v1/reservations/{uuid}/reschedule`
- `POST /api/v1/reservations/{uuid}/check-in`
- `POST /api/v1/reservations/{uuid}/check-out`
- `GET /api/v1/user/dashboard`
- `GET /api/v1/user/favorites`
- `POST /api/v1/user/favorites/{service_id}`
- `DELETE /api/v1/user/favorites/{service_id}`
- `GET /api/v1/user/recent-views`

### Content Editor
- `GET /api/v1/editor/services`
- `POST /api/v1/editor/services`
- `PUT /api/v1/editor/services/{id}`
- `GET /api/v1/editor/services/{id}/slots`
- `POST /api/v1/editor/services/{id}/slots`
- `DELETE /api/v1/editor/services/{id}/slots/{slot_id}`

### Administrator
- user management endpoints
- role/permission mutation endpoints
- `GET/PUT /api/v1/admin/system-config`
- dictionary and form-rule CRUD endpoints
- audit-log read endpoints
- import job endpoints
- export generation endpoints
- backup and restore-test endpoints
- session review/revocation endpoints

## Response and error contract

- Validation errors use Laravel-style `{ message, errors }` structure.
- Domain failures map to normalized HTTP responses:
  - `401` unauthenticated
  - `403` forbidden / booking freeze / eligibility / step-up required
  - `404` missing resource
  - `409` slot conflict or duplicate-submission conflict
  - `422` invalid transition, expired reservation, failed validation window
  - `423` locked account

## Reservation endpoint behavior notes

- `POST /reservations` creates a pending reservation and sets expiry.
- Confirmation behavior may auto-confirm based on service configuration or require staff action.
- Cancellation path invokes policy engine when inside the late-cancel window.
- Check-in/check-out endpoints enforce window and state constraints.
- Reschedule creates a new reservation path while preserving historical linkage.

## Admin/configuration contract

- Dictionaries and form rules are bounded to supported entity/field types.
- Admin-configurable relationships cover supported business links only.
- System configuration contains policy values such as expiry minutes, penalty amounts, thresholds, and timing windows.

## Import/export contract

### Import
- create import job with file upload and source/entity selection
- upload field mapping or select mapping template
- run validation and duplicate detection
- resolve conflicts using prefer-newest or admin-override rules
- reprocess resolved conflicts to apply them via entity strategies (`POST /api/v1/admin/import/{id}/reprocess`)
- complete import with audit trail
- sensitive classified fields are redacted in import conflict records

### Admin user governance
- set initial password for imported/provisioned users (`POST /api/v1/admin/users/{id}/set-password`, requires step-up)
- imported users get `must_change_password=true` — credential is set by admin before first login

### Export
- create export with entity/date/field filters
- require step-up for sensitive export surfaces
- mask or omit sensitive fields by policy and permission

### Exchange surfaces
- SSO user/account provisioning data
- HR/finance staff profile and department data
- research administration project/grant/patent records
- service catalog exchange
- outbound reservation-history/reporting export

## Audit expectations

- Every policy execution and critical security/governance action must emit audit entries.
- Import/export operations must include actor, scope, and summary metadata.
- Audit log writes are immutable and redacted.

## Accepted implementation status

- Implemented now: public catalog list/detail APIs plus authenticated user dashboard/favorites/recent-views APIs.
- Implemented now: learner reservation list/detail/create/cancel/reschedule APIs with ownership enforcement and policy snapshots.
- Implemented now: learner reservation check-in/check-out APIs plus scheduled no-show marking behavior behind the reservation service layer.
- Implemented now: content-editor service and slot management APIs.
- Implemented now: administrator step-up, grouped system-config, dictionary-value, and form-rule APIs.
- Implemented now: administrator import/export job, template, conflict-resolution, and export-generation APIs.
- Implemented now: administrator backup listing/triggering and restore-test recording APIs.
- Implemented now: administrator audit-log viewer and user-governance APIs.
- Implemented now: authenticated password-change API with forced-change enforcement.
- Still scaffold-level: no major prompt-critical API surfaces remain; remaining work is verification and hardening.
