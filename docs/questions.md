# Clarification Decisions

## Item 1: Booking eligibility for staff users

### What was unclear
The prompt says staff and learners book services, but the named roles are Learner, Content Editor, and Administrator.

### Interpretation
Booking authority should be tied to the Learner capability rather than granted to every authenticated account.

### Decision
Only accounts assigned booking capability through the Learner role can create reservations; staff may also book when they hold that role alongside editor or administrator responsibilities.

### Why this is reasonable
This preserves the prompt’s statement that staff may book resources without weakening role controls or letting every user reserve services.

## Item 2: Audience filtering versus eligibility enforcement

### What was unclear
The prompt mentions target-audience filtering with examples, but does not explicitly say whether audience labels are enforced at booking time.

### Interpretation
Audience is both a browse filter and an eligibility rule.

### Decision
Services may target multiple audience groups, and booking is allowed only when the user matches at least one eligible audience.

### Why this is reasonable
This honors the prompt’s emphasis on eligibility notes and avoids turning audience into a cosmetic label.

## Item 3: Fee representation and sorting

### What was unclear
The prompt requires price display and sorting by lowest fee, but does not say how free versus paid services should be stored or shown.

### Interpretation
Fees should be stored numerically and rendered with a clear Free label for zero-cost services.

### Decision
Store numeric fee amounts, label zero-dollar services as Free, and sort by lowest fee using numeric values.

### Why this is reasonable
This is the simplest way to support accurate fee display and lowest-fee sorting.

## Item 4: Availability presentation in catalog views

### What was unclear
The prompt requires available time-slot browsing, but does not state whether that browsing begins in catalog cards, detail pages, or a separate scheduler.

### Interpretation
Users should see a lightweight availability summary in the catalog and full slot browsing on the service detail view.

### Decision
Catalog cards show the next available slot summary, and detail views expose browsable upcoming slots for booking.

### Why this is reasonable
This fits the responsive Livewire requirement and keeps availability visible without forcing a full page transition or separate module.

## Item 5: Pending confirmation state

### What was unclear
The prompt says awaiting confirmation expires after 30 minutes, but does not define when the reservation record is created.

### Interpretation
Reservation submission should create a pending record immediately, then confirmation finalizes it.

### Decision
User submission creates a pending reservation that expires automatically after 30 minutes if unconfirmed.

### Why this is reasonable
This directly implements the stated expiration rule and preserves a real request lifecycle.

## Item 6: Late cancellation fee versus points handling

### What was unclear
The prompt says a $25.00 fee or 50 points is deducted, but does not specify who selects the penalty path.

### Interpretation
The active account or policy configuration determines whether the late-cancellation consequence is money or points.

### Decision
On late cancellation, support either a $25 fee deduction or a 50-point deduction according to the policy in effect, and audit the policy execution.

### Why this is reasonable
This preserves the prompt exactly without inventing an arbitrary end-user choice model.

## Item 7: Breach counting scope

### What was unclear
The prompt explicitly defines no-show breaches, but does not say whether other lifecycle events should count.

### Interpretation
Only no-show breaches should count toward the freeze rule.

### Decision
Count no-show breaches within a rolling 60-day window, and after the second breach enforce a 7-day booking freeze automatically.

### Why this is reasonable
This matches the prompt literally and avoids over-penalizing cancellations or reschedules.

## Item 8: Late arrival behavior

### What was unclear
The prompt defines the check-in window and says late arrivals are partial attendance, but does not spell out operational handling.

### Interpretation
Users may still check in during the grace window, but with reduced attendance status and no extension.

### Decision
Check-in remains open until 10 minutes after start; arrivals after the start but before close are marked partial attendance and cannot extend into the next slot.

### Why this is reasonable
This follows the prompt directly and prevents schedule spillover.

## Item 9: Local storage scope for dashboard data

### What was unclear
The prompt says favorites, recent views, and prior reservations are stored locally on the workstation/server, but does not say whether browser storage is acceptable.

### Interpretation
These records should be persisted in the application database on local infrastructure.

### Decision
Store favorites, recent views, and reservation history in local PostgreSQL-backed application tables.

### Why this is reasonable
This aligns with durable local persistence and supports cross-session, cross-device use inside the local environment.

## Item 10: Admin configurability boundaries

### What was unclear
The prompt requires configurable dictionaries, form rules, and relationships, but does not define whether configuration is code-based or UI-based.

### Interpretation
Configuration should happen through bounded admin UI tooling backed by metadata tables.

### Decision
Administrators manage supported dictionaries, form rules, and relationship definitions through admin CRUD interfaces with constrained rule types and server enforcement.

### Why this is reasonable
This reduces hardcoding as requested without opening unrestricted code or SQL editing.

## Item 11: Relationship configurability depth

### What was unclear
The prompt says admins can define basic relationships, but does not say whether they can reshape the whole schema.

### Interpretation
Relationship management should be limited to a bounded set of supported business links.

### Decision
Allow admin management of supported relationships such as service-tags, service-time-slots, and reservation-user links without enabling arbitrary schema mutation.

### Why this is reasonable
This satisfies the prompt while keeping the system safe and reviewable.

## Item 12: Duplicate detection threshold

### What was unclear
The prompt specifies normalized title similarity and exact project/patent matching, but not whether likely duplicates are auto-merged.

### Interpretation
The system should detect and surface likely duplicates locally rather than silently merging them.

### Decision
Combine normalized title similarity with exact project/patent-number matching and surface likely duplicates for conflict review.

### Why this is reasonable
This keeps the import process offline-friendly and safer than automatic merges.

## Item 13: Incremental sync behavior

### What was unclear
The prompt requires field mapping, incremental sync, and conflict resolution, but not the baseline import behavior.

### Interpretation
Imports should support both field mapping and timestamp-based incremental updates with explicit conflict choices.

### Decision
Support CSV/JSON field mapping, last-updated-based incremental sync, and conflict resolution using prefer-newest or admin-override.

### Why this is reasonable
This directly matches the prompt and keeps the exchange workflow practical for on-prem systems.

## Item 14: Offline login anomaly signals

### What was unclear
The prompt names new device fingerprint and unusual time window as examples, but does not say whether those are mandatory or optional.

### Interpretation
Both examples should be treated as mandatory anomaly detections.

### Decision
Treat new device fingerprints and unusual login time windows compared with local historical patterns as login anomalies, and write them to immutable audit logs.

### Why this is reasonable
These examples are explicitly named by the prompt and fit an offline environment.

## Item 15: Cross-device single logout behavior

### What was unclear
The prompt requires single-logout across devices, but does not define whether it applies only to forced revocation or to ordinary logout as well.

### Interpretation
Logout should invalidate every active session for that user across local devices, not only the current browser.

### Decision
Any user logout or security-driven session revocation invalidates all active sessions for that user across local devices, including ordinary logout, password change, admin-forced logout, and account disable.

### Why this is reasonable
This is the strongest prompt-faithful reading of single-logout across devices and preserves the stated security expectation.

## Item 16: Sensitive data handling depth

### What was unclear
The prompt requires masking by default and encryption at rest, but does not define whether both UI and API outputs are covered.

### Interpretation
Masking should apply to default data presentation in both UI and API responses, while storage encryption protects classified fields at rest.

### Decision
Sensitive classified fields are masked by default in UI/API responses based on permission and encrypted at rest with application-managed keys, with TLS protecting transport on the local network.

### Why this is reasonable
This gives full effect to the security requirements without over-expanding into unnecessary blanket encryption of all data.

## Item 17: Step-up verification method

### What was unclear
The prompt requires re-entering the password for critical actions, but does not define whether another factor can replace it.

### Interpretation
Password re-entry itself is the required step-up mechanism.

### Decision
Ask for the current password immediately before role changes, data export, policy edits, and account deletion within the active session.

### Why this is reasonable
This matches the prompt exactly and stays compatible with the offline-only identity model.

## Item 18: Offline CAPTCHA implementation

### What was unclear
The prompt requires CAPTCHA generated and verified locally, but does not define acceptable implementation boundaries.

### Interpretation
CAPTCHA must be fully generated and validated inside the local Laravel application.

### Decision
Use a local application-generated CAPTCHA after brute-force thresholds require it.

### Why this is reasonable
This directly satisfies the offline requirement and avoids online dependencies.

## Item 19: Backup and restore evidence

### What was unclear
The prompt requires documented restore procedures tested monthly, but does not say whether the system should also track those tests.

### Interpretation
The project should provide a practical mechanism to document and record monthly restore-test completion.

### Decision
Provide scheduled daily local snapshots retained for 30 days, a documented restore procedure, and a project-level mechanism to record monthly restore test completion.

### Why this is reasonable
This preserves the prompt and makes the monthly testing expectation operational rather than aspirational.

## Item 20: Mandatory audit coverage floor

### What was unclear
The prompt lists some mandatory audit events, but not the minimum broader audit set implied by the rest of the requirements.

### Interpretation
The audit floor should include all explicitly named security and policy events plus closely related critical operations.

### Decision
At minimum audit permission changes, login anomalies, critical-action step-up events, reservation policy executions, cancellations and penalties, breaches and freezes, and import/export operations.

### Why this is reasonable
This fully covers the prompt’s explicit audit requirements and supports traceability of sensitive business-rule execution.
