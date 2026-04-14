# Research Services

Internal reservation and service-catalog platform for research institutions. Staff, faculty, and graduate learners discover library and research support services, browse time-slot availability, and make reservations. Administrators manage the service catalog, enforce booking policies, review audit logs, and import data from HR, SSO, and research-administration systems.

**Stack:** Laravel 11.51 · Livewire 3.7 · Alpine.js · PostgreSQL 16 · Redis 7 · Docker Compose v2

---

## Quick start

The only host prerequisite is **Docker with the Compose plugin**. No PHP, no Node, no database client, no secret tooling is required on the host.

```bash
# 1. Build images and start all services (generates runtime secrets automatically)
docker compose up --build

# 2. In a second terminal — run migrations and seed reference data
./init_db.sh

# Application is now available at https://localhost:8443
# Default admin credentials are printed to the init_db.sh output — change them immediately
```

> **HTTPS only.** The bootstrap service generates a self-signed TLS certificate. Your browser will warn on first access; accept the certificate for local development.

> **Port strategy.** nginx binds only the HTTPS port on the host — default **8443** — so the stack starts cleanly on shared machines where 80/443 may already be in use. To use standard ports: `HTTPS_PORT=443 docker compose up --build`.

---

## Runtime and secret model

There are **no `.env` files** in this repo and none should ever be created. All runtime secrets are generated inside Docker at container start time.

How it works:

1. `docker compose up --build` starts a `bootstrap` service (alpine + openssl) before any other container.
2. The bootstrap service generates fresh secrets into a named Docker volume (`runtime_config`):
   - `APP_KEY`, `APP_ENCRYPTION_KEY` — via openssl rand
   - `DB_PASSWORD`, `REDIS_PASSWORD` — via /dev/urandom
   - TLS certificate + key — via openssl req
   - `app.env` — sourced by the application entrypoint
   - `db_password` — read by PostgreSQL via `POSTGRES_PASSWORD_FILE`
   - `redis.conf` — read by Redis at startup
3. All application containers (`app`, `queue-worker`, `scheduler`) mount `runtime_config:/runtime:ro` and source `/runtime/app.env` via `docker/scripts/docker-entrypoint.sh` before starting PHP-FPM.
4. The bootstrap service is idempotent: it skips regeneration if `/runtime/app.env` already exists (unless `REGENERATE=true`).

**No secret values are committed, interpolated from host environment variables, or written to disk outside the named volume.**

---

## Database initialization

```bash
# Assumes docker compose up --build is already running
./init_db.sh

# Start services and initialize in one step
./init_db.sh --start

# Destroy volumes, regenerate secrets, re-migrate, re-seed (full reset)
./init_db.sh --reset
```

`init_db.sh` runs `php artisan migrate --force` and `php artisan db:seed --force` inside the running `app` container. It waits for PostgreSQL readiness using `pg_isready` via `docker compose exec` — no host psql needed.

---

## Running tests

```bash
# Full test suite (Unit + Feature + Integration) — fully Dockerized
./run_tests.sh

# Suite filters
./run_tests.sh --unit
./run_tests.sh --feature
./run_tests.sh --integration

# With coverage report
./run_tests.sh --coverage
```

The test runner uses `docker-compose.test.yml`, which:
- Spins up its own isolated `test_runtime_config` volume
- Runs the bootstrap service with `REGENERATE=true` — fresh secrets on every run
- Uses PostgreSQL with `tmpfs` storage (destroyed on teardown)
- Runs the app with `APP_ENV=testing`, `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync`
- Tears down all containers and volumes on exit via `trap`

The broad test suite (`./run_tests.sh`) requires PostgreSQL and Redis via Docker. Targeted local tests (catalog, auth) can be run locally with SQLite in-memory:

```bash
APP_KEY=base64:$(openssl rand -base64 32) \
  DB_CONNECTION=sqlite DB_DATABASE=":memory:" \
  php artisan test --testsuite=Feature --filter="CatalogBrowseTest|CatalogFavoriteTest|CatalogRecentViewTest"
```

PostgreSQL-specific constructs (`CREATE RULE`, `ILIKE`) are guarded behind driver checks so migrations and queries degrade cleanly to SQLite in the local test path.

---

## Browser E2E tests (Playwright)

The Playwright suite covers the core learner and admin/operator journeys against the real application UI — no API shortcuts. Screenshots are captured for every test run.

```bash
# Full E2E suite — fully Dockerized, no host Node required
./run_e2e.sh

# Spec filter — run only auth tests
./run_e2e.sh --spec 01-auth

# Keep containers alive after run for debugging
./run_e2e.sh --no-teardown
```

**How it works**

`run_e2e.sh` uses `docker-compose.e2e.yml`, which:
- Spins up an isolated `e2e_runtime_config` volume (same bootstrap pattern as the main stack)
- Runs the app with `SESSION_DRIVER=database` + `SESSION_SECURE_COOKIE=false` (HTTP in E2E — no TLS)
- Adds an `nginx` service using `docker/nginx/e2e.conf` (HTTP-only, port 80)
- Runs migrations + `E2eSeeder` to create deterministic test fixtures before Playwright starts
- Runs the `mcr.microsoft.com/playwright:v1.49.0-jammy` container in the same Docker network
- Tears down all containers and volumes on exit (`--no-teardown` to suppress)

**E2E test fixtures (`E2eSeeder`)**

| Fixture | Credentials |
|---|---|
| Admin user (`e2e_admin`) | `AdminE2e1!` |
| Learner user (`e2e_learner`) | `LearnerE2e1!` |
| Service: "Data Consultation (E2E)" | slug `e2e-data-consultation`, 2 upcoming slots |

**Specs**

| File | What it covers |
|---|---|
| `e2e/tests/01-auth.spec.ts` | Login page, valid/invalid credentials, unauthenticated redirect, logout + session invalidation |
| `e2e/tests/02-catalog.spec.ts` | Catalog loads, category sidebar, debounced search filter, empty state, service detail + upcoming slots |
| `e2e/tests/03-reservation.spec.ts` | Book a slot, reservation appears in list, detail page, cancel modal + confirm |
| `e2e/tests/04-admin.spec.ts` | User management page, step-up modal on write, correct/incorrect password, audit log page, backup page, learner access control |

**Artifacts**

Screenshots land in `e2e/test-results/` (one per test, always captured). The HTML report is at `e2e/test-results/html-report/`. Both directories are gitignored.

**First-run on host (headed / debug mode)**

```bash
cd e2e
npm ci
BASE_URL=https://localhost:8443 npx playwright test --headed
```

This requires a running instance (either `docker compose up --build` + `./init_db.sh` + manual E2eSeeder, or `--no-teardown` from a previous `./run_e2e.sh` run).

---

## Repository structure

```
.
├── app/
│   ├── Exceptions/          # Domain exception hierarchy (DomainException base + 8 specific)
│   ├── Http/
│   │   ├── Controllers/Api/V1/   # HealthController, CatalogController (search/filter/sort, REST),
│   │   │                         # UserController (dashboard/favorites/recent-views),
│   │   │                         # ReservationController (list/show/store/cancel/reschedule/check-in/check-out)
│   │   │                         # Auth/PasswordChangeController (POST /api/v1/auth/password/change)
│   │   │                         # Editor/ServiceController (CRUD + publish/archive),
│   │   │                         # Editor/SlotController (create/update/cancel, scoped to service)
│   │   │                         # Editor/ReservationController (pending queue, confirm, reject)
│   │   │                         # Admin/RelationshipController (definition CRUD + instance link/unlink)
│   │   ├── Livewire/             # Auth/LoginComponent, Dashboard/LearnerDashboardComponent,
│   │   │                         # Catalog/BrowseComponent, Catalog/ServiceDetailComponent,
│   │   │                         # Reservation/ReservationListComponent, Reservation/ReservationDetailComponent
│   │   │                         # Editor/ServiceListComponent, Editor/ServiceFormComponent,
│   │   │                         # Editor/SlotListComponent
│   │   │                         # Admin/PolicyConfigComponent, Admin/DataDictionaryComponent,
│   │   │                         # Admin/FormRulesComponent (all with step-up modal)
│   │   │                         # Admin/RelationshipManagerComponent
│   │   │                         # Auth/PasswordChangeComponent
│   │   └── Middleware/           # ValidateAppSession, ForceHttps, EnforcePasswordChange
│   ├── Models/              # 30 Eloquent models covering all domain tables
│   ├── Providers/           # AppServiceProvider (service bindings, lazy-load guard)
│   ├── Console/Commands/    # ExpirePendingReservations, MarkNoShowReservations (both scheduled every minute)
│   └── Services/
│       ├── Admin/           # SystemConfigService, DynamicValidationResolver,
│       │                    # StepUpService, AdminConfigService,
│       │                    # AdminDictionaryService, AdminFormRuleService,
│       │                    # RelationshipManagerService
│       ├── Audit/           # AuditLogger, SensitiveDataRedactor
│       ├── Auth/            # PasswordValidator, PasswordChangeService, SessionManager
│       ├── Catalog/         # CatalogService (browse, filter, sort, favorites, recent views)
│       ├── Editor/          # ServiceEditorService (create/update/publish/archive/slug), SlotEditorService
│       ├── Import/          # ImportParserService, ImportProcessorService, ConflictResolutionService,
│       │                    # ExportGeneratorService, EntityStrategyInterface,
│       │                    # EntityStrategies/{UserAccount,Department,UserProfile,ResearchProject,Service}Strategy
│       ├── Api/             # API gateways — shared contracts consumed by both REST + Livewire:
│       │                    #   ReservationApiGateway (reservation list + lifecycle mutations),
│       │                    #   CatalogApiGateway (browse, favorites, recent views, reference data),
│       │                    #   EditorApiGateway (service CRUD, publish, archive, reference data),
│       │                    #   AdminConfigApiGateway (system config grouped read/write),
│       │                    #   BackupApiGateway (backup list, trigger, restore-test recording),
│       │                    #   GatewayResult (reservation-specific typed value object),
│       │                    #   ApiResult (generic typed value object for non-reservation gateways)
│       └── Reservation/     # ReservationService, SlotAvailabilityService, PolicyService
├── database/
│   ├── factories/           # UserFactory + ServiceFactory, ServiceCategoryFactory,
│   │                        #   TagFactory, TargetAudienceFactory, TimeSlotFactory
│   ├── migrations/          # 22 domain migrations (see below)
│   └── seeders/             # 7 seeders: roles/permissions, data dictionary, system config,
│                            #   target audiences, sensitive data classifications, admin user,
│                            #   E2eSeeder (deterministic Playwright fixtures)
├── e2e/                     # Playwright browser E2E suite
│   ├── tests/               # 01-auth, 02-catalog, 03-reservation, 04-admin specs
│   ├── helpers.ts           # loginAs(), logout(), waitForLivewire(), credential constants
│   ├── playwright.config.ts # Chromium project, screenshot:on, BASE_URL from env
│   ├── tsconfig.json
│   └── package.json
├── docker/
│   ├── nginx/nginx.conf.template
│   ├── nginx/e2e.conf       # HTTP-only nginx config for E2E environment
│   └── scripts/
│       ├── bootstrap-init.sh    # Secret generation (runs inside bootstrap container)
│       └── docker-entrypoint.sh # Sources /runtime/app.env before php-fpm
├── resources/views/
│   ├── layouts/             # auth.blade.php, app.blade.php (Livewire slot + Blade yield)
│   ├── livewire/            # login.blade.php (functional), learner dashboard (functional)
│   └── [admin|catalog|reservations|editor|auth]  # scaffold stub views
├── routes/
│   ├── web.php              # All Livewire + Blade routes with auth/role guards
│   └── api.php              # REST API routes (v1 prefix)
├── tests/
│   ├── Unit/Services/       # PasswordValidatorTest, SystemConfigServiceTest,
│   │                        #   SensitiveDataRedactorTest
│   ├── Feature/             # HealthEndpointTest, LoginTest,
│   │                        #   Reservation/EditorReservationConfirmTest (confirm/reject/queue/role guard)
│   └── Integration/         # DatabaseSchemaTest (schema + audit immutability)
├── docker-compose.yml
├── docker-compose.test.yml
├── docker-compose.e2e.yml   # Isolated E2E environment (HTTP nginx + SESSION_DRIVER=database)
├── init_db.sh
├── run_tests.sh
├── run_e2e.sh               # Playwright E2E orchestrator
└── phpunit.xml
```

### Migrations (in order)

| Migration | Tables / changes |
|---|---|
| `000001` | `users`, `password_reset_tokens` |
| `000002` | `password_history` |
| `000003` | `sessions` (app sessions with device fingerprint) |
| `000010` | `system_config` |
| `000011` | `data_dictionary_types`, `data_dictionary_values` |
| `000012` | `form_rules` |
| `000013` | `audit_logs` + PostgreSQL immutability rules |
| `000014` | `notifications` |
| `000020` | `service_categories`, `tags`, `services`, `target_audiences`, pivots |
| `000021` | `time_slots` |
| `000022` | `reservations`, `reservation_status_history`, `no_show_breaches`, `booking_freezes`, `points_ledger` |
| `000023` | `user_favorites`, `user_recent_views` |
| `000030` | `departments`, `user_profiles`, `research_projects`, `service_research_project_links` |
| `000031` | `sensitive_data_classifications` |
| `000040` | `import_jobs`, `import_conflicts`, `import_field_mapping_templates` |
| `000041` | `backup_logs`, `restore_test_logs` |
| `000050` | `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions` (Spatie RBAC) |
| `2026_04_14_000001` | `user_profiles.employee_id` widened to `text` (removes 60-char limit) |
| `2026_04_14_000002` | `relationship_definitions` (admin-declared entity relationship types) |
| `2026_04_14_000003` | `entity_relationship_instances` (runtime links between entity pairs; soft-delete) |
| `2026_04_14_000004` | `user_profiles.employee_id_hash` — HMAC-SHA256 blind index for encrypted employee_id lookups |

### RBAC

Three roles seeded by `RolesPermissionsSeeder`:

| Role | Key permissions |
|---|---|
| `learner` | browse catalog, make/cancel own reservations, view own profile |
| `content_editor` | all learner permissions + manage services, categories, time slots |
| `administrator` | all permissions including user management, policy config, audit logs, import/export, backup |

---

## What is implemented vs. scaffold-level

### Fully implemented

- Docker infrastructure (multi-stage Dockerfile, bootstrap secret model, nginx TLS, compose files)
- All 22 domain database migrations with correct constraints, foreign keys, indexes, and PostgreSQL-specific features (immutable audit log rules, timestampTz, JSONB columns)
- All 32 Eloquent models with relationships, casts, and domain helpers
- Full domain service layer: `AuditLogger`, `SensitiveDataRedactor`, `PasswordValidator`, `SessionManager`, `SystemConfigService`, `DynamicValidationResolver`, `ReservationService`, `SlotAvailabilityService`, `PolicyService`, `RelationshipManagerService`
- **API Gateway layer** — shared contracts consumed by both the REST API surface and Livewire components; all Livewire components delegate through gateways rather than calling domain services or models directly:
  - `ReservationApiGateway` — reservation listing and lifecycle mutations (`list`, `book`, `cancel`, `reschedule`, `checkIn`, `checkOut`); consumed by `ReservationController`, `ReservationListComponent`, `ServiceDetailComponent`, `ReservationDetailComponent`; returns typed `GatewayResult`
  - `CatalogApiGateway` — catalog browsing, favorites, recent views, reference data; consumed by `CatalogController`, `UserController`, `BrowseComponent`, `ServiceDetailComponent`; returns paginator / collection
  - `EditorApiGateway` — service CRUD, publish, archive, editor reference data; consumed by `Editor\ServiceController`, `ServiceFormComponent`; returns typed `ApiResult`
  - `AdminConfigApiGateway` — grouped system-config read/write; consumed by `Admin\ConfigController`, `PolicyConfigComponent`; returns typed `ApiResult`
  - `BackupApiGateway` — backup list, trigger, restore-test recording; consumed by `Admin\BackupController`, `BackupComponent`; returns typed `ApiResult`
  - `GatewayResult` — reservation-specific typed value object (success/error/reservation/httpStatus)
  - `ApiResult` — generic typed value object for non-reservation gateways (success/error/data/httpStatus)
- Domain exception hierarchy (9 exception classes)
- RBAC seeding (23 permissions, 3 roles via Spatie Laravel Permission)
- Data dictionary seeding (service types, cancellation reasons, audience types, etc.)
- System config seeding (20 policy constants with meaningful defaults)
- `AdminUserSeeder` — generates a secure random password and prints it once
- `GET /api/health` — live checks for DB and Redis
- `GET /api/v1/catalog/services` — service listing with full filter parity: text search (title + description), category, tag (multi), audience, price type, sort by name / earliest availability / lowest fee
- `GET /api/v1/catalog/services/{slug}` — service detail with upcoming time slots
- **Catalog browse page** (`/catalog`) — Livewire 3 component delegating to `CatalogApiGateway` (same contract as the REST surface) with sidebar filters (category, price, audience, tags), debounced search, URL-bound filter state, active filter chips, real pagination, inline favorite toggle
- **Service detail page** (`/catalog/{slug}`) — service description, eligibility notes, tags/audiences, upcoming slot list with remaining capacity, favorite/unfavorite button, recent-view tracking on mount
- **Favorites** — toggle from catalog cards or service detail; persisted per user; surfaced on dashboard with service-detail links
- **Recent views** — server-side upsert on every detail-page visit; shown on dashboard with timestamps and direct links
- **User REST API** (`/api/v1/user/`) — dashboard summary (`GET /dashboard`), paginated favorites list (`GET /favorites`), add favorite (`POST /favorites/{id}`, 201 on create / 200 if already exists), remove favorite (`DELETE /favorites/{id}`, 204 idempotent), paginated recent views (`GET /recent-views`); all endpoints delegate to `CatalogService` — same service layer as Livewire
- Login Livewire component — brute-force protection, account lock enforcement, local math-challenge CAPTCHA (server-side, no external service, disabled via `CAPTCHA_ENABLED=false` in test environments), audit logging, session recording, must-change-password redirect
- Learner dashboard Livewire component — upcoming reservations with links, saved services, recently viewed with `diffForHumans` timestamps
- **Reservation list page** (`/reservations`) — Livewire 3 component delegating to `ReservationApiGateway::list()` (same contract as `GET /api/v1/reservations`) with status-filter tabs (all / pending / confirmed / cancelled / rescheduled / expired), paginated cards, links to service detail and reservation detail
- **Reservation detail page** (`/reservations/{uuid}`) — slot details, status badge, pending-expiry countdown, late-cancel warning callout with consequence amount, reschedule slot-picker panel, cancel confirmation modal; ownership-enforced (other users get 404)
- **Slot booking from service detail** — "Book" / "Request" button on each available slot; creates reservation and redirects to reservation detail; surfaced error on slot-full or freeze
- **Pending → confirmed / expired flow** — manual-confirmation service stays `pending` (slot held); auto-confirm service transitions to `confirmed` immediately; `expires_at` timestamp recorded
- **Cancellation with late-cancel policy** — `PolicyService` checks slot window against `late_cancel_free_hours_before` config; applies fee or points consequence via `cancellation_consequence` + `cancellation_consequence_amount` columns; points debit written to `PointsLedger`; pending-reservation slot decrement fixed (was bug: only confirmed was decremented). Late cancellations do **not** create a `NoShowBreach` record — only actual no-shows do.
- **Reschedule flow** — modelled as cancel + new booking: old reservation → `rescheduled` (slot freed); if the original slot is inside the late-cancel window the same fee/points consequence is applied to the original reservation; new `Reservation` created with `rescheduled_from_id`; auto-confirm or pending based on service config. Reschedule inside the window does NOT create a `NoShowBreach` record.
- **`reservations:expire-pending` artisan command** — finds `pending` reservations with `expires_at <= now()`, calls `ReservationService::expire()` on each; registered in the scheduler to run every minute
- **Check-in behavior** — window is `[starts_at − 15 min, starts_at + 10 min]` (configurable via `checkin_opens_minutes_before` / `checkin_closes_minutes_after`); on-time check-in → `checked_in`; check-in after start but within window → `partial_attendance` (late arrival)
- **Check-out** — allowed from `checked_in` or `partial_attendance`; transitions to `checked_out`
- **No-show detection** — `reservations:mark-noshows` artisan command (runs every minute) marks `confirmed` reservations whose check-in window has closed as `no_show`, creates a `NoShowBreach` record (`breach_type='no_show'`), and enforces the breach/freeze policy
- **Breach/freeze policy** — rolling 60-day window (`noshow_breach_window_days`); only `no_show` breach type counts (late cancellations do not); at 2 breaches (`noshow_breach_threshold`) within the window a 7-day booking freeze is applied (`noshow_freeze_duration_days`); freeze is extended if a later no-show would push the end date further; a `BookingFreeze` record is created and `user.booking_freeze_until` is updated
- **Reservation REST API** (`/api/v1/reservations/`) — list with optional `?status=` filter (`GET`), single detail with policy snapshot (`GET /{uuid}`), create (`POST`), cancel (`POST /{uuid}/cancel`), reschedule (`POST /{uuid}/reschedule`), check-in (`POST /{uuid}/check-in`), check-out (`POST /{uuid}/check-out`); all endpoints ownership-scoped to the authenticated user
- `ValidateAppSession` middleware — session validity and touch on each request
- `init_db.sh` and `run_tests.sh` scripts
- Unit tests: password complexity, system config typed accessors, sensitive data masking
- Feature tests: health endpoint, login flow, **catalog browse filters** (text, category, price, tag, audience, reset), **favorites toggle** (create/remove/state/bulk), **recent view tracking** (upsert, component mount, 404 gate, slot visibility), **user API** (dashboard structure, reservation status filter, favorites CRUD + idempotency + auth guard, recent-views pagination + ordering + isolation), **reservation create** (auto-confirm, pending, booking-freeze guard, slot-full guard, Livewire bookSlot), **reservation cancel** (status transition, slot decrement for confirmed + pending, late-cancel fee, late-cancel points, free-window no-consequence, guard), **reservation reschedule** (marks old, creates new, slot bookkeeping, late-cancel consequence inside window, no consequence outside window, no breach created, same-slot guard, full-slot guard), **reservation expire** (service method, slot decrement, artisan command picks up stale / leaves fresh), **reservation API** (list + filter, show + ownership, store, 409 full, cancel, reschedule, check-in, check-out, auth guard), **check-in/check-out** (on-time→checked_in, late→partial_attendance, window guards, checkout from checked_in/partial, wrong-status guards, API endpoints), **no-show policy** (markNoShow transitions + breach record, window-still-open guard, single no-show no freeze, two no-shows trigger freeze, only no_show type counts, rolling-window expiry, freeze extension, command marks/ignores correct reservations)
- **Content editor service management** (`/editor/services`) — Livewire 3 list with search and status-filter tabs (All/Draft/Active/Inactive/Archived); create/edit form delegating to `EditorApiGateway` (same contract as the REST editor surface) with all fields (title, description, eligibility notes, category, tags, audiences, price/free toggle, manual-confirmation toggle, status); publish (draft/inactive → active) and archive actions; audit-logged; slug auto-generated from title with soft-delete-aware uniqueness
- **Content editor slot management** (`/editor/services/{id}/slots`) — Livewire 3 slot list with inline add/edit forms; cancel slot (blocked if active bookings); capacity guard (cannot reduce below booked count); audit-logged
- **Editor REST API** (`/api/v1/editor/`) — `GET/POST /services`, `GET/PUT /services/{id}`, `POST /services/{id}/publish`, `POST /services/{id}/archive`; `GET/POST /services/{id}/slots`, `PUT /services/{id}/slots/{slot}`, `POST /services/{id}/slots/{slot}/cancel`; all behind `role:content_editor|administrator`
- **Manual reservation confirmation/rejection** (`/api/v1/editor/reservations`) — operator workflow for services that require manual approval
  - `GET /api/v1/editor/reservations` — paginated pending queue scoped to manual-confirmation services; filterable by `?status=` and `?service_id=`; ordered oldest-first (FIFO)
  - `POST /api/v1/editor/reservations/{id}/confirm` — transitions a `pending` reservation to `confirmed`; returns 422 if not in a confirmable state
  - `POST /api/v1/editor/reservations/{id}/reject` — cancels a `pending` or `confirmed` reservation on behalf of the operator; returns 422 for terminal states (`checked_in`, `checked_out`, `cancelled`)
  - All endpoints require `role:content_editor|administrator`
- Integration test: database schema completeness, audit log immutability verification
- Feature tests: **editor service management** (create, slug generation/collision, tag/audience sync, update title regenerates slug, publish draft→active, publish throws for archived, archive, idempotency, API CRUD + role guard), **editor slot management** (create, update, capacity guard, update throws for cancelled slot, cancel, cancel blocked by active bookings, idempotent cancel, API create/cancel/422), **editor reservation management** (pending queue filtered to manual-confirm services, status/service_id filters, confirm pending→confirmed, 422 for non-pending, reject pending/confirmed, 422 for terminal states, learner role guard, auth guard)

- **Admin relationship management** (`/admin/relationships`) — admin-configurable entity relationship definitions and runtime instance management
  - `RelationshipDefinition` model — bounded entity types (`service`, `department`, `user`, `research_project`, `tag`, `target_audience`, `service_category`); supported cardinalities (`many_to_many`, `one_to_many`); soft-deactivate preserves historical instances
  - `EntityRelationshipInstance` model — soft-delete; links any two entity records under a definition; idempotent create (restores soft-deleted pair rather than duplicating)
  - `RelationshipManagerService` — `createDefinition()`, `deactivateDefinition()`, `listInstances()`, `createInstance()`, `deleteInstance()`; all mutations are audit-logged; `createInstance()` validates entity existence by type-to-model lookup and enforces one-to-many cardinality (source may only have one target per definition)
  - `RelationshipManagerComponent` (Livewire) — definition list, create definition form, instance link/unlink UI
  - REST API: `GET /api/v1/admin/relationship-definitions`, `POST /api/v1/admin/relationship-definitions`, `DELETE /api/v1/admin/relationship-definitions/{id}`, `GET /api/v1/admin/relationship-definitions/{id}/instances`, `POST /api/v1/admin/relationship-definitions/{id}/instances`, `DELETE /api/v1/admin/relationship-definitions/{id}/instances/{iid}`; all behind `role:administrator`

- **Admin configuration slice** (`/admin/policies`, `/admin/data-dictionary`, `/admin/form-rules`) — real Livewire 3 components; `PolicyConfigComponent` delegates to `AdminConfigApiGateway` (same contract as the REST config surface); all three require step-up re-authentication before writes are accepted
  - `StepUpService` — session-scoped re-authentication grant valid for 15 minutes; `verify()` checks current password + writes ISO-8601 timestamp to session; `isGranted()` validates TTL; `revoke()` clears grant; all verify events are audit-logged
  - `AdminConfigService` — grouped read/write for all 20 system-config keys; `allGrouped()` returns four groups (reservation, auth, import, login_anomaly); `update()` + `updateBulk()` run per-key validation, update DB, forget cache, and write audit log entries
  - `AdminDictionaryService` — `allTypes()` with eager-loaded values; `createValue()`, `updateValue()`, `deactivateValue()` — all audit-logged; system types are displayed read-only; values are fully editable
  - `AdminFormRuleService` — `all()`, `upsert()` (create-or-update by entity_type + field_name), `deactivate()` — all audit-logged; cache invalidation for `form_rule:{entity}:{field}` on every write
  - REST API surface: `POST /api/v1/admin/step-up`, `GET/PUT /api/v1/admin/system-config/{key}`, `GET /api/v1/admin/data-dictionary`, `POST /api/v1/admin/data-dictionary/{typeCode}/values`, `PUT /api/v1/admin/data-dictionary/values/{id}`, `GET/POST/PUT/DELETE /api/v1/admin/form-rules/{id}`
  - All write API endpoints return 403 without a valid step-up grant

- **Admin import/export slice** (`/admin/import-export`, `/admin/import-export/{id}`) — full import pipeline and export generation
  - `ImportParserService` — parses CSV (header-mapped, `str_getcsv`) and JSON (bare array or `{"data":[...]}` wrapper); applies `field_mapping` to rename source columns; incremental sync via `last_sync_timestamp` (skips rows with `last_updated_at <= threshold`)
  - Entity strategy pattern — each entity type implements `EntityStrategyInterface` with `findExisting()`, `computeFieldDiffs()`, and `apply()`:
    - `UserAccountStrategy` — identifies by `username` (the offline identity key for this system); upserts `display_name`, `status`, `audience_type`; on create provisions the account with `must_change_password=true` and a cryptographically random temporary password that is not disclosed — authentication is strictly offline username/password and an administrator must set the user's initial credential via the admin management surface before the account becomes usable; password data is never read from import rows
    - `DepartmentStrategy` — exact `code` match; upserts by code
    - `UserProfileStrategy` — exact `employee_id` match; resolves `user_id` in order: (1) explicit `user_id` in row, (2) existing profile's `user_id` on update, (3) `User` lookup by `username` column in the row (the offline identity key; the User account must already exist before the profile row can be linked); fails the row with an actionable error only if none of the three paths yields a user
    - `ResearchProjectStrategy` — priority: (1) exact project_number, (2) exact patent_number, (3) normalized title similarity; stores `normalized_title` on save
    - `ServiceStrategy` — normalized title similarity; creates draft services via `ServiceEditorService`; updates via `ServiceEditorService::update()`
  - `ConflictResolutionService` — `resolve()` supports `prefer_newest` (compare `last_updated_at`, default to apply when no timestamps), `admin_override`, and `pending` (both create an `ImportConflict` record); `adminResolve()` stamps resolution/resolved_record/resolved_by/resolved_at
  - `ImportProcessorService` — `process(job, rawContent, admin)` orchestrates parse → validate required fields → find existing → compute diffs → resolve conflicts → set status (`needs_review` if pending conflicts remain, `completed` otherwise); audit logs `import.job_started` and `import.job_completed`/`import.job_needs_review`; `reprocessResolvedConflicts()` applies resolved records and marks job completed if no pending conflicts remain
  - `ExportGeneratorService` — generates CSV or pretty-printed JSON for departments, user_profiles, research_projects, services, users; supports `status`, `date_from`, `date_to` filters; audit logs `export.generated`; export requires step-up via controller guard
  - Livewire UI: `ImportJobListComponent` — filterable paginated job list, create-job modal (file upload or paste content, field mapping editor, template loader/saver); `ImportJobDetailComponent` — job summary cards, conflict filter tabs (pending/resolved/all), per-conflict field diff table, inline resolve (use incoming / admin override), reprocess button
  - REST API surface: `GET/POST /api/v1/admin/import`, `GET /api/v1/admin/import/{id}`, `POST /api/v1/admin/import/{id}/resolve`, `GET/POST /api/v1/admin/import/templates`, `POST /api/v1/admin/export` (step-up required; returns `Content-Disposition: attachment` file download)
  - Similarity threshold configurable via `import_similarity_threshold` system config key (default `0.85`)
  - 53 targeted tests covering parser (CSV, JSON, incremental sync), processor (new rows, prefer_newest, admin_override, needs_review, missing fields, users entity type incl. offline password invariants, user_profiles username resolution, audit log), conflict resolution (all three strategies, adminResolve prefer_newest and admin_override), and export (CSV/JSON generation, audit log, API step-up gate)

- **Admin backup/restore slice** (`/admin/backups`) — real operator workflow for snapshot management and restore-drill tracking
  - `BackupService` — `run(admin, type)` executes the database dump synchronously, records a `BackupLog`, applies 30-day retention, and audit-logs the outcome; `applyRetention()` deletes `BackupLog` records and their files older than 30 days; `recordRestoreTest()` creates a `RestoreTestLog` entry with result + notes and audit-logs it
  - Execution reality by driver: **PostgreSQL** (production/Docker) — runs `pg_dump --format=custom | gzip`; **SQLite file** (local dev) — copies the `.sqlite` file; **SQLite `:memory:`** (test suite) — writes a zero-byte placeholder so the full workflow (log creation, retention, audit) is exercisable without a real dump command
  - `backups:run --type=daily|manual --actor=<user_id>` artisan command; resolves the acting user from `--actor` or falls back to the first administrator; scheduled daily at 02:00 via the Laravel scheduler
  - Livewire UI (`BackupComponent`) — delegates to `BackupApiGateway` (same contract as the REST backup surface); snapshot table with type, size, status, restore-test count, and age; "Run Backup" button behind step-up password confirmation (15-minute grant window); "Record Test" button opens a modal to log restore-drill outcome (success/partial/failed) with optional notes
  - REST API surface: `GET /api/v1/admin/backups`, `POST /api/v1/admin/backups` (step-up required), `GET /api/v1/admin/backups/{id}` (detail with restore tests), `POST /api/v1/admin/backups/{id}/restore-tests`
  - 17 targeted tests: BackupService run (success, audit, daily type, placeholder file), retention (prune old, keep recent, delete file on disk), recordRestoreTest (creates record, audit log, all result values), API (list, role guard, step-up gate, trigger with grant, show with restore tests, record restore test, validation)

- **API Gateway tests** — 5 dedicated test files verifying that the gateway layer is the shared contract between REST and Livewire:
  - `CatalogApiGatewayTest` — gateway browse filters/pagination, toggle favorite, favorite IDs, categories/tags, Livewire BrowseComponent delegation, REST parity
  - `ReservationListGatewayTest` — gateway list with status filter, user scoping, eager loading, Livewire ReservationListComponent delegation
  - `EditorApiGatewayTest` — gateway create/update/publish/archive, 404 handling, audit log, reference data, REST parity
  - `AdminConfigApiGatewayTest` — gateway grouped read, bulk update, unknown key handling, audit log, REST parity
  - `BackupApiGatewayTest` — gateway list, run, restore-test recording, 404 handling, retention constant, REST parity

- **Admin audit log viewer** (`/admin/audit-logs`) — read-only Livewire component backed by `AuditLogService`; no step-up required
  - Filters: action (partial match), entity_type (exact, dropdown), actor username (partial match via join), date range, correlation_id (exact UUID)
  - Expandable detail row: before/after states as pretty-printed JSON (already redacted at write time by `SensitiveDataRedactor`), IP address, device fingerprint surfaced as boolean "present / absent" only (raw hash never returned)
  - "Chain" button on each row filters by correlation_id for tracing multi-step operations
  - `AuditLog.actor()` relationship added (BelongsTo User) for actor username display
  - REST API: `GET /api/v1/admin/audit-logs` (paginated + filtered), `GET /api/v1/admin/audit-logs/{id}` (detail, exposes `has_fingerprint` bool not raw hash), `GET /api/v1/admin/audit-logs/correlation/{id}` (event chain)
  - 13 targeted tests: list/filter/ordering, find with actor, correlation chain, distinct entity types, device_fingerprint exposure guard, API list/filter/auth, API correlation chain

- **Admin user governance** (`/admin/users`) — Livewire component + REST API backed by `UserGovernanceService`; all write operations require step-up
  - Account status operations: `lockAccount` (defaults 24 h), `unlockAccount`, `suspendAccount` (indefinite), `reactivateAccount` — all audit-logged, before/after state captured
  - Credential operations: `forcePasswordReset` (sets `must_change_password=true`), `revokeSessions` (via `SessionManager`; emits `user.sessions_revoked_by_admin` audit entry)
  - Role management: `assignRole`, `revokeRole` with last-active-administrator guard (blocks removal of final admin role)
  - Account deletion: `deleteAccount` — soft-deletes the account, revokes all active sessions, audit-logs `user.account_deleted`; blocks deletion of the last active administrator (consistent with the role-revocation guard)
  - Livewire UI: searchable/filterable user table (status, role), contextual action buttons per status, step-up modal (15-minute grant window), delete confirmation modal (shows username + irreversibility warning; step-up fires on confirm), slide-in detail panel showing account fields, active sessions, and HR profile summary; "Delete" button absent for already-deleted accounts
  - REST API: `GET/SHOW /api/v1/admin/users`, `POST /{id}/lock|unlock|suspend|reactivate|force-password-reset|revoke-sessions` (all step-up required), `POST /{id}/roles` (assign), `DELETE /{id}/roles/{role}` (revoke), `DELETE /{id}` (delete account — step-up required)
  - 38 targeted tests: each operation + audit log verification, last-admin guard, API step-up gates, API list/show, role guard, deletion (soft-delete, audit, last-admin block, second-admin allowed, API step-up gate, API last-admin block, role guard)

- **Password change and rotation** (`/password/change`) — full voluntary, forced, and rotation-expired password change flow
  - `PasswordChangeService` — `change(user, current, new)`: verifies current password, runs complexity + history validation against live system-config thresholds (min_length, history_count), persists hash, clears `must_change_password`, stamps `password_changed_at`, records in `password_history`, audit-logs `auth.password_changed`; `isRotationExpired(user)`: returns `false` when `rotation_days=0` (disabled), `true` when `password_changed_at` is null, `true` when past the rotation window; `mustChange(user)`: `must_change_password` flag OR rotation expired
  - `EnforcePasswordChange` middleware — applied to the main authenticated web route group; redirects to `/password/change` when `mustChange()` is true; returns HTTP 403 JSON for JSON/API requests; the `/password/change` route is placed outside the group to prevent infinite redirect
  - `PasswordChangeComponent` (Livewire) — shows a contextual "password change required" banner for forced/rotation-expired cases; current + new + confirm fields; service-layer validation errors surfaced inline; successful change redirects to dashboard
  - REST API: `POST /api/v1/auth/password/change` — `{current_password, new_password, new_password_confirmation}`; returns 200 on success, 422 with `errors[]` on validation failure
  - 30 targeted tests: service change() (wrong password, weak, history, success, clears flag, stamps timestamp, records history, audit log), rotation expiry (disabled/null/past/within window), mustChange(), middleware (redirect, allow, route-not-blocked, JSON 403), API (auth guard, success, wrong password, mismatch, weak), Livewire (renders, success, error on wrong password, mismatch validation, forced banner, no banner)

### Scaffold-level (routes and views exist, business logic not yet implemented)

*(none — all planned surfaces are now fully implemented)*

### Not yet started

- Queue jobs for reservation expiry / no-show marking (currently artisan commands; queue-based sweep not wired)
- Manual freeze lifting by administrators
- Email/in-app notification delivery
- Points ledger display in learner dashboard

---

## Backup and restore

### Operator workflow

Manual snapshots are triggered from `/admin/backups` (requires step-up password confirmation) or via `php artisan backups:run --actor=<user_id>`. Scheduled daily backups run automatically at 02:00 via the Laravel scheduler.

| Environment | Dump mechanism | Output format |
|---|---|---|
| PostgreSQL (production / Docker) | `pg_dump --format=custom \| gzip` | `.dump.gz` |
| SQLite file (local dev) | `copy()` | `.sqlite` |
| SQLite `:memory:` (test suite) | Zero-byte placeholder | `.sqlite-placeholder` |

Snapshots are retained for **30 days**. Retention is enforced automatically after every successful run and removes both the `backup_logs` record and the file on disk.

### Restore-drill tracking

Operators record the outcome of monthly restore drills via the "Record Test" button on any successful snapshot row, or via `POST /api/v1/admin/backups/{id}/restore-tests`. Results (`success` / `partial` / `failed`) and free-text notes are stored in `restore_test_logs` and appear in the snapshot detail view at `GET /api/v1/admin/backups/{id}`.

Operational backups of the `postgres_data` and `app_storage` volumes are the responsibility of the deployment environment.

---

## Environment and configuration notes

- No `.env` file exists or should be created. All configuration flows through the Docker bootstrap model.
- `docker/compose.env.required` documents every generated key and its purpose — for operator reference and static review only.
- `config/database.php` defaults to `pgsql`. The `DB_HOST`, `DB_DATABASE`, and `DB_USERNAME` values are set as static (non-secret) environment variables in `docker-compose.yml`. The password comes from `/runtime/app.env` via the entrypoint.
- `config/session.php` uses `DATABASE` driver backed by the `sessions` table in production. Tests use `array`.
- Spatie Laravel Permission package provides RBAC. Role/permission checks use `role:` and `permission:` middleware aliases registered in `bootstrap/app.php`.
- `composer.json` pins `"platform": {"php": "8.3"}` so that host-side `composer update` resolves dependencies for the Docker PHP version, not the host PHP version.
- Redis healthcheck in both compose files uses `grep requirepass /runtime/redis.conf` to extract the generated password at check time — no hardcoded secret in the compose file.
- CAPTCHA is disabled in tests via `CAPTCHA_ENABLED=false` in `docker-compose.test.yml` and `phpunit.xml`. In production it defaults to enabled (`CAPTCHA_ENABLED=true`).
- CAPTCHA triggers at 3 failed attempts (`captcha_show_after_attempts=3`), lockout at 5 (`brute_force_max_attempts=5`). This ensures CAPTCHA is always shown before lockout under shipped defaults.
- `user_profiles.employee_id` is encrypted at rest (Laravel `encrypted` cast). A deterministic HMAC-SHA256 blind index (`employee_id_hash`) enables exact-match lookups for import duplicate detection without exposing plaintext.
- Import conflict records (`import_conflicts`) redact classified sensitive fields (e.g. `employee_id`) via `SensitiveDataRedactor` before storage in the `incoming_record` and `local_record` JSON columns.
- Imported user accounts are provisioned with a random password and `must_change_password=true`. Administrators set the initial credential via `POST /api/v1/admin/users/{id}/set-password` (requires step-up verification). The user must change the password on first login.
- Import conflict resolution has two phases: resolve (`POST /import/{id}/resolve`) records the admin decision, then reprocess (`POST /import/{id}/reprocess`) applies resolved records via entity strategies. This matches the Livewire UI workflow.

### Internal TLS (encryption in transit)

All internal network links use TLS:
- **Host ↔ nginx:** HTTPS with bootstrap-generated self-signed certificate.
- **App ↔ PostgreSQL:** `sslmode=verify-ca` with CA-signed service certificate. The bootstrap service generates an internal CA and issues a PostgreSQL server certificate.
- **App ↔ Redis:** TLS via `tls-port 6379` (plaintext port disabled). phpredis connects with `scheme=tls` and verifies the CA.
- The internal CA, all service certificates, and keys are generated by `docker/scripts/bootstrap-init.sh` into the `runtime_config` volume. No TLS materials are committed to the repository.
- Service import duplicate detection for services uses the admin-managed `import_similarity_threshold` from `system_config` (not a static Laravel config value).
