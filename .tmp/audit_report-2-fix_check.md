# Delivery Acceptance Audit Recheck

## Verdict
- Result against the issues listed in `.tmp/delivery_acceptance_architecture_audit.md`: All previously reported material issues are fixed by current static evidence.
- Current status of that prior issue list: `Resolved`

## Scope
- Reviewed only the previously reported issues from `.tmp/delivery_acceptance_architecture_audit.md`.
- Static analysis only: code, routes, config, README, and test files.
- Not executed: app startup, Docker, tests, browser flows, external services.
- Manual verification still required for runtime-only claims such as actual TLS handshakes, browser rendering, scheduler cadence, and monthly restore-drill operations.

## Issue Recheck

### 1. CAPTCHA enforcement under shipped defaults
- Previous status: Open
- Current status: Fixed
- Rationale: The seeded defaults now set `captcha_show_after_attempts=3` and `brute_force_max_attempts=5`, so the CAPTCHA threshold is reached before lockout. A dedicated test now asserts this default-path invariant.
- Evidence: `database/seeders/SystemConfigSeeder.php:29-31`, `app/Http/Livewire/Auth/LoginComponent.php:113-135`, `tests/Feature/Auth/LoginTest.php:170-196`

### 2. Encrypted `employee_id` incompatible with import matching
- Previous status: Open
- Current status: Fixed
- Rationale: `user_profiles` now maintain a deterministic blind-index hash alongside the encrypted `employee_id`, and import matching uses that hash instead of plaintext equality against ciphertext.
- Evidence: `app/Models/UserProfile.php:8-50`, `database/migrations/2026_04_14_000004_add_employee_id_hash_to_user_profiles.php:1-42`, `tests/Feature/Import/ImportProcessorTest.php:404-468`

### 3. Sensitive imported identifiers persisted in plaintext conflict records
- Previous status: Open
- Current status: Fixed
- Rationale: Import conflict creation now redacts classified fields from both `incoming_record` and `local_record` before storage, and this behavior is directly tested for `employee_id`.
- Evidence: `app/Services/Import/ConflictResolutionService.php:102-149`, `tests/Feature/Import/ConflictResolutionTest.php:219-255`

### 4. Imported users lacked an admin path to set an initial password
- Previous status: Open
- Current status: Fixed
- Rationale: The admin API now exposes `POST /api/v1/admin/users/{id}/set-password`, backed by `UserGovernanceService::setInitialPassword()`, with step-up protection, password validation, audit logging, and tests.
- Evidence: `routes/api.php:108-110`, `app/Http/Controllers/Api/V1/Admin/AdminUserController.php:128-153`, `app/Services/Admin/UserGovernanceService.php:205-238`, `tests/Feature/Admin/UserGovernanceTest.php:389-455`

### 5. REST import conflict resolution recorded decisions but did not apply them
- Previous status: Open
- Current status: Fixed
- Rationale: The REST surface now includes a dedicated reprocess endpoint, and the processor applies resolved records and completes the job when no pending conflicts remain.
- Evidence: `routes/api.php:123-124`, `app/Http/Controllers/Api/V1/Admin/ImportController.php:128-140`, `app/Services/Import/ImportProcessorService.php:171-212`, `tests/Feature/Import/ConflictResolutionTest.php:257-290`

### 6. Internal app-to-PostgreSQL / app-to-Redis TLS not configured
- Previous status: Open
- Current status: Fixed statically
- Rationale: Docker/bootstrap config now generates an internal CA and service certificates, PostgreSQL is started with SSL enabled, Redis runs TLS-only, and Laravel is configured to require CA-backed TLS for both Postgres and Redis.
- Evidence: `docker/scripts/bootstrap-init.sh:44-143`, `docker-compose.yml:33-102`, `config/database.php:99-176`
- Boundary: Static review can confirm configuration, not successful runtime certificate negotiation.

### 7. Service import similarity threshold ignored admin-managed system config
- Previous status: Open
- Current status: Fixed
- Rationale: `ServiceStrategy` now reads the threshold from `SystemConfigService::importSimilarityThreshold()` and a regression test verifies config-driven behavior.
- Evidence: `app/Services/Import/EntityStrategies/ServiceStrategy.php:14-18`, `app/Services/Import/EntityStrategies/ServiceStrategy.php:38-39`, `tests/Feature/Import/ImportProcessorTest.php:471-490`

### 8. Livewire only partially consumed the advertised decoupled REST/backend contract
- Previous status: Open
- Current status: Fixed
- Rationale: The previously flagged Livewire components now delegate through API gateway classes that mirror the REST surface, and new gateway-focused tests plus README updates document and verify that pattern.
- Evidence:
  - Catalog: `app/Http/Livewire/Catalog/BrowseComponent.php:63-85`, `app/Services/Api/CatalogApiGateway.php:10-88`, `tests/Feature/Gateway/CatalogApiGatewayTest.php:17-161`
  - Reservation list: `app/Http/Livewire/Reservation/ReservationListComponent.php:16-39`, `tests/Feature/Gateway/ReservationListGatewayTest.php:14-147`
  - Editor service form: `app/Http/Livewire/Editor/ServiceFormComponent.php:182-234`, `app/Services/Api/EditorApiGateway.php:15-154`, `tests/Feature/Gateway/EditorApiGatewayTest.php:13-170`
  - Admin config: `app/Http/Livewire/Admin/PolicyConfigComponent.php:57-93`, `app/Services/Api/AdminConfigApiGateway.php:10-81`, `tests/Feature/Gateway/AdminConfigApiGatewayTest.php:14-144`
  - Backups: `app/Http/Livewire/Admin/BackupComponent.php:93-145`, `app/Services/Api/BackupApiGateway.php:11-95`, `tests/Feature/Gateway/BackupApiGatewayTest.php:12-152`
  - Documentation: `README.md:202-205`, `README.md:296-301`, `README.md:312`, `README.md:335`, `README.md:353`, `README.md:381`, `README.md:386-390`

## Conclusion
- The previous audit file is now stale with respect to the issues it reported.
- Based on the current repository state, every previously listed issue in that file is resolved by static evidence.
- Remaining non-static boundaries still require manual verification:
  - actual TLS handshakes and trust chain behavior
  - browser rendering and interaction quality
  - scheduled execution timing
  - monthly restore-drill operational practice
