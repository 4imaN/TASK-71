1. Scope

- This is a scoped fix-check only for the four cycle-1 items requested.
- No broader repository review was performed.
- Static evidence only; no runtime execution.

2. Item-by-item Status

## 1. CAPTCHA default threshold mismatch with the prompt

- Status: Resolved.
- Evidence:
  - The seeded default for `captcha_show_after_attempts` is now `5`, matching the prompt threshold: `database/seeders/SystemConfigSeeder.php:29-31`.
  - The login flow still enforces the threshold through config-driven logic, so the new default will take effect: `app/Http/Livewire/Auth/LoginComponent.php:129-135`.
  - The auth test was updated to use and describe the prompt-spec threshold of 5: `tests/Feature/Auth/LoginTest.php:149-170`.

## 2. Relationship-manager write path entity/cardinality validation

- Status: Resolved.
- Evidence:
  - The service now validates that both referenced records actually exist before creating a relationship instance: `app/Services/Admin/RelationshipManagerService.php:127-129`, `app/Services/Admin/RelationshipManagerService.php:245-259`.
  - The service now maps each allowed entity type to a concrete model class for existence checks: `app/Services/Admin/RelationshipManagerService.php:224-243`.
  - The service now enforces `one_to_many` cardinality by blocking multiple active targets for the same source under one definition: `app/Services/Admin/RelationshipManagerService.php:131-146`.
  - The controller still returns validation failures as 422 responses for these service-level checks: `app/Http/Controllers/Api/V1/Admin/RelationshipController.php:116-125`.

## 3. Manual reservation confirmation/rejection documentation and automated verification evidence

- Status: Resolved.
- Evidence:
  - The README now documents the operator confirmation/rejection workflow and endpoints: `README.md:324-330`.
  - There is now dedicated automated verification for the editor/operator queue, confirm, reject, and access-control behavior: `tests/Feature/Reservation/EditorReservationConfirmTest.php:15-29`.
  - The new test file covers queue filtering, confirm success/failure, reject success/failure, role guard, and unauthenticated rejection: `tests/Feature/Reservation/EditorReservationConfirmTest.php:67-336`.
  - The repository structure section now explicitly lists this feature test: `README.md:232-234`.

## 4. README drift against current repo structure/migrations

- Status: Resolved.
- Evidence:
  - The repository structure section now includes the newer API controllers and Livewire/admin relationship surfaces: `README.md:167-183`.
  - The migration section now reflects the added RBAC, employee-id widening, relationship-definition, and relationship-instance migrations: `README.md:244-267`.
  - The structure summary now states `22 domain migrations`, which matches the current migration set described there: `README.md:206`, `README.md:244-267`.
  - The implemented-features section now includes the relationship-management and manual reservation confirmation slices: `README.md:324-337`.

3. Final Scoped Result

- All four scoped cycle-1 items are resolved based on the current repository state.
- No scoped items remain open in this fix-check.
