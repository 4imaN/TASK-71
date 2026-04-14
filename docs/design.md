# Design Notes

## System overview

- Internal offline-first research support reservation and catalog application.
- Stack: Laravel 11, Livewire 3, PostgreSQL 16, Redis, Docker Compose, local TLS termination.
- Delivery shape: monolithic Laravel application with an API gateway layer (`app/Services/Api/`) consumed by both Livewire components and REST controllers. Domain services sit behind the gateways; presentation layers do not call domain services directly.

## Architecture choice

- Livewire provides responsive form-driven screens without page reloads.
- REST-style endpoints exist as a decoupled integration surface and parallel application surface, but business logic is not duplicated there.
- Domain services own reservation rules, policy execution, eligibility enforcement, audit writes, import/export processing, and security-sensitive behavior.
- Controllers and Livewire components remain thin orchestration layers.

## Runtime contract

- Primary runtime command: `docker compose up --build`
- Database initialization path: `./init_db.sh`
- Broad test command: `./run_tests.sh`

## Docker/bootstrap model

- Runtime uses a Docker-managed bootstrap service that runs inside the Compose graph.
- `docker compose up --build` starts a bootstrap container first.
- Bootstrap generates local-development secrets and TLS artifacts into a Docker volume, not into committed repo files.
- Other services depend on bootstrap completion and read generated runtime values from the Docker volume.
- No checked-in `.env`, `.env.example`, or similar startup dependency exists.
- `./init_db.sh` is complementary: it brings the stack up if needed, waits for readiness, then runs migrations and seeds.
- `./run_tests.sh` uses the same Dockerized bootstrap/value-generation model as runtime, but with isolated test volumes and regenerated test secrets each run.

## Major modules

### 1. Auth and identity
- Offline username/password authentication only.
- Password complexity, last-5 history enforcement, 90-day rotation, failed-attempt tracking, lockout, local CAPTCHA, session idle timeout, and single-logout across devices.

### 2. Session and anomaly management
- Custom session tracking with revocation support.
- Device-fingerprint and unusual-time anomaly detection.
- Cross-device logout by invalidating all active sessions for a user.

### 3. RBAC and step-up verification
- Roles: Learner, Content Editor, Administrator.
- Users may hold multiple roles simultaneously.
- Critical actions require current-password re-entry.

### 4. Service catalog
- Services, categories, tags, target audiences, pricing, eligibility notes, and status lifecycle.
- Target audience is both a filter dimension and an enforced booking constraint.

### 5. Scheduling
- Time slots per service with capacity tracking and conflict prevention.
- Content Editors manage schedule windows and slots.

### 6. Reservation engine
- State machine for pending, confirmed, cancelled, checked-in, partial attendance, checked-out, no-show, expired, and rescheduled flows.
- Guards enforce ownership, policy timing, booking freeze, eligibility, and slot availability.

### 7. Policy engine
- Pending-expiry processing.
- Late-cancellation fee or points deduction.
- No-show breach counting in a rolling 60-day window.
- Automatic 7-day booking freeze after threshold breaches.

### 8. Dashboard and learner activity
- Favorites, recent views, prior reservations, upcoming reservations, and related persisted learner activity.

### 9. Admin configuration
- Bounded metadata-driven configurability for dictionaries, dynamic form rules, supported relationship assignments, and system configuration.
- No arbitrary schema mutation or SQL-entry admin behavior.

### 10. Sensitive-data and audit system
- Sensitive-field classification registry.
- Masking by default in UI/API output.
- Encryption at rest for classified fields.
- Immutable audit log with redaction and critical-event coverage.

### 11. Import/export and exchange workflows
- Operator-managed CSV/JSON import/export with field mapping, reusable mapping templates, incremental sync, duplicate detection, and conflict resolution.
- Exchange surfaces include SSO user exports, HR/finance extracts, research administration records, service catalog exchange, and outbound reservation-history export.

### 12. Backup and restore
- Daily snapshot scheduling with 30-day retention.
- Manual and scheduled backup logging.
- Monthly restore-test recording through the application.

## Role responsibilities

### Learner
- Browse/filter/search catalog
- View next availability and service details
- Book and manage own reservations
- Favorite services and view recent/history data

### Content Editor
- Maintain service descriptions
- Maintain eligibility notes
- Manage scheduling windows and service time slots

### Administrator
- Govern policies, security, access, user/role management, audits, import/export, and backup visibility

## Reservation lifecycle model

### Core states
- `pending`
- `confirmed`
- `cancelled`
- `checked_in`
- `partial_attendance`
- `checked_out`
- `no_show`
- `expired`
- `rescheduled`

### Key rules
- Reservation request starts as pending.
- Pending expires after 30 minutes if unconfirmed.
- Free cancellation until 24 hours before slot start.
- Inside 24 hours, policy engine applies either `$25.00` or `50 points` based on policy.
- Check-in opens 15 minutes before start and closes 10 minutes after start.
- Late arrival within the grace window becomes partial attendance.
- Late arrivals cannot extend into the next slot.
- No-show breaches are counted across a rolling 60-day window.
- Two no-show breaches trigger an automatic 7-day booking freeze.

## Shared cross-cutting contracts

### Validation
- Every write uses Laravel request/component validation plus dynamic rules from admin-managed metadata.
- Dynamic rules may tighten base safety rules but may not loosen hardcoded required protections.

### Error handling
- Domain exceptions normalize user-visible and API-visible failure behavior.
- Livewire surfaces immediate inline feedback without page reload.

### Logging and audit
- Audit writes are append-only.
- Permission changes, login anomalies, reservation policy execution, step-up events, import/export operations, and critical lifecycle actions are mandatory audit events.
- Sensitive fields are redacted before audit persistence.

### Security boundaries
- Passwords never leave server-side validation paths.
- Critical actions require active-session password re-entry.
- Sensitive fields are masked by default and only exposed through authorized flows.
- TLS is required for local-network transit.

## Data model overview

### Identity and access
- `users`
- `password_history`
- custom `sessions`
- RBAC tables for roles and permissions

### Catalog and scheduling
- `service_categories`
- `services`
- `tags`
- `service_tags`
- `target_audiences`
- `service_audiences`
- `time_slots`

### Reservations and policy tracking
- `reservations`
- `reservation_status_history`
- `no_show_breaches`
- `booking_freezes`
- `points_ledger`

### User activity
- `user_favorites`
- `user_recent_views`

### Config and governance
- `data_dictionary_types`
- `data_dictionary_values`
- `form_rules`
- `system_config`
- `sensitive_data_classifications`
- `audit_logs`
- `notifications`

### Exchange and operations
- `departments`
- `user_profiles`
- `research_projects`
- `service_research_project_links`
- `import_jobs`
- `import_conflicts`
- `import_field_mapping_templates`
- `backup_logs`
- `restore_test_logs`

## Frontend/backend crosswalk

### Learner surfaces
- Login and password-change flows
- Catalog listing and filtering
- Service detail with slot browsing
- Booking form and confirmation path
- Reservation list/detail, cancel, reschedule, check-in, check-out
- Dashboard with favorites, recent views, and history

### Editor surfaces
- Service CRUD
- Eligibility and scheduling maintenance
- Slot management

### Admin surfaces
- User and role management
- Policy/system configuration
- Dictionaries and form rules
- Audit log review
- Import/export operations
- Backup and restore-test tracking
- Session management and forced revocation

### Controller/component contract
- Livewire components and REST controllers consume the same API gateway layer (`ReservationApiGateway`, `CatalogApiGateway`, `EditorApiGateway`, `AdminConfigApiGateway`, `BackupApiGateway`). Gateways delegate to domain services, returning typed result objects (`GatewayResult`, `ApiResult`).
- Shared validation and policy enforcement via the gateway layer prevents behavior drift between UI and API surfaces.

## Planning-critical risks

1. Reservation state transitions and slot-conflict correctness.
2. Maintaining one source of truth across Livewire and REST surfaces.
3. Offline auth/session security, anomaly detection, and cross-device logout.
4. Immutable auditing with correct redaction boundaries.
5. Bounded admin configurability without opening unsafe arbitrary mutation.
6. Import/export conflict UX and duplicate detection quality.
7. Backup/restore operator workflow and monthly drill traceability.

## Implementation sequencing guidance

1. Docker/runtime bootstrap, Laravel scaffold, and PostgreSQL/Redis wiring.
2. Core schema, RBAC seeds, and system configuration foundations.
3. Auth/session/CAPTCHA/step-up/anomaly/audit infrastructure.
4. Catalog, scheduling, and reservation domain services plus lifecycle policies.
5. Learner UI surfaces and REST counterparts.
6. Content Editor and Administrator management surfaces.
7. Import/export and backup/restore operational workflows.
8. Broad verification, hardening, and documentation completion.

## Accepted implementation progress

### Accepted scaffold baseline
- Docker-first runtime/test foundations are accepted.
- Username auth baseline, local CAPTCHA baseline, RBAC seeds, core schema, and health/test infrastructure are accepted.

### Accepted development slice: learner catalog and activity surfaces
- Real learner catalog browse UI is implemented with search, category, tag, audience, price, and sort controls.
- Real service detail UI is implemented with upcoming slot browsing.
- Favorites and recent views are persisted server-side per authenticated user.
- Dashboard now exposes favorites, recent views, and upcoming reservation history data more concretely.
- REST user/catalog read endpoints and Livewire catalog surfaces share the same catalog service layer.
- Booking lifecycle mutation flows remain outside this accepted slice and stay for later development work.

### Accepted development slice: learner reservation request and self-management surfaces
- Learners can create reservation requests from service-detail slot lists.
- Reservation list and reservation-detail Livewire surfaces are implemented with ownership enforcement.
- Pending/manual-confirm versus auto-confirm behavior is real.
- Learners can cancel and reschedule reservations through real UI and API surfaces.
- Late-cancel fee/points consequences are enforced without incorrectly counting as no-show breaches.
- Pending-expiry state and the scheduled expiration command are implemented.

### Accepted development slice: attendance and no-show breach enforcement
- Check-in and check-out flows are implemented for learner-owned reservations.
- Late arrivals are marked partial attendance when check-in occurs after start but before the close window.
- No-show detection is implemented after the check-in window closes.
- Only no-shows create breach records and contribute to rolling-window freeze logic.
- Automatic 7-day booking freeze enforcement is implemented when the no-show threshold is reached.
- Policy execution for no-show breach recording and freeze application now writes audit events.
- Admin remediation/reporting surfaces for these lifecycle events still remain for later work.

### Accepted development slice: content editor service and slot management
- Content Editors can create, edit, publish, and archive services through real Livewire and REST surfaces.
- Service descriptions, eligibility notes, category/tags/audiences, pricing, and manual-confirm settings are editable.
- Slot management is implemented with create/update/cancel flows and booking-count/capacity guards.
- Editor and administrator authorization is enforced on these management routes.
- Audit logging exists for service and slot mutation operations.
- Broader administrator dictionary/policy/configuration surfaces remain for later work.

### Accepted development slice: administrator configuration and step-up security
- Administrators can manage grouped policy/system configuration values through real UI and API surfaces.
- Administrators can manage supported data dictionary values and form-rule definitions through bounded admin surfaces.
- Critical configuration writes in this slice require current-password step-up verification with a short-lived session grant.
- Configuration, dictionary, form-rule, and step-up verification events are audited.
- Arbitrary schema mutation remains disallowed; the configurability model stays bounded to supported entity/key types.
- Broader import/export, backup/restore, audit-viewer, and user-governance admin domains remain for later work.

### Accepted development slice: import/export exchange workflows
- Administrators can create and review import jobs through real UI and API surfaces.
- Field mapping, reusable mapping templates, incremental sync filtering, duplicate detection, and conflict resolution are implemented.
- Supported exchange surfaces now cover service data, department data, research-project data, user-profile data, and user-account provisioning data.
- Export generation exists for the supported entity set and stays behind administrator access with step-up where required.
- Imported user-account handling remains faithful to offline username/password identity and does not introduce SSO login semantics.
- Backup/restore operational workflows still remain for later work.

### Accepted development slice: backup and restore-test operations
- Administrators can trigger backups and record restore-test drill results through real UI and API surfaces.
- Backup execution is implemented for the delivered local/runtime environments and is documented honestly where test-only placeholders are used.
- 30-day retention is enforced automatically.
- Scheduled daily backup command wiring is implemented.
- Backup and restore-test actions are audited.
- Audit-viewer presentation and broader remaining admin domains still remain for later work.

### Accepted development slice: administrator audit viewer and user governance
- Administrators can review immutable audit logs through real UI and API surfaces.
- Audit viewing keeps sensitive fields masked and does not expose raw device-fingerprint hashes.
- Administrators can manage account status, password-reset forcing, session revocation, role assignment/revocation, and account deletion through real UI and API surfaces.
- Step-up verification gates the sensitive user-governance write actions.
- Last-active-administrator protection is enforced for role revocation.
- Last-active-administrator protection is also enforced for account deletion.

### Accepted development slice: password-change and rotation enforcement surface
- Authenticated users can change passwords through real UI and API surfaces.
- Current-password verification, complexity rules, password-history checks, and password-change audit logging are enforced.
- Forced password-change flow is implemented for `must_change_password` and rotation-expiry conditions.
- Browser and API paths are both constrained when password change is required.
