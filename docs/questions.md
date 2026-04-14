## Business Logic Questions Log

## Clarification Decisions

## Item 1: Booking eligibility for staff users
- **Problem:** The prompt says staff and learners book services, but the named roles are Learner, Content Editor, and Administrator.
- **My Understanding:** Booking authority should be tied to the Learner capability rather than granted to every authenticated account.
- **Solution:** Only accounts assigned booking capability through the Learner role can create reservations; staff may also book when they hold that role alongside editor or administrator responsibilities.

---

## Item 2: Audience filtering versus eligibility enforcement
- **Problem:** The prompt mentions target-audience filtering with examples, but does not explicitly say whether audience labels are enforced at booking time.
- **My Understanding:** Audience is both a browse filter and an eligibility rule.
- **Solution:** Services may target multiple audience groups, and booking is allowed only when the user matches at least one eligible audience.

---

## Item 3: Fee representation and sorting
- **Problem:** The prompt requires price display and sorting by lowest fee, but does not say how free versus paid services should be stored or shown.
- **My Understanding:** Fees should be stored numerically and rendered with a clear Free label for zero-cost services.
- **Solution:** Store numeric fee amounts, label zero-dollar services as Free, and sort by lowest fee using numeric values.

---

## Item 4: Availability presentation in catalog views
- **Problem:** The prompt requires available time-slot browsing, but does not state whether that browsing begins in catalog cards, detail pages, or a separate scheduler.
- **My Understanding:** Users should see a lightweight availability summary in the catalog and full slot browsing on the service detail view.
- **Solution:** Catalog cards show the next available slot summary, and detail views expose browsable upcoming slots for booking.

---

## Item 5: Pending confirmation state
- **Problem:** The prompt says awaiting confirmation expires after 30 minutes, but does not define when the reservation record is created.
- **My Understanding:** Reservation submission should create a pending record immediately, then confirmation finalizes it.
- **Solution:** User submission creates a pending reservation that expires automatically after 30 minutes if unconfirmed.

---

## Item 6: Late cancellation fee versus points handling
- **Problem:** The prompt says a $25.00 fee or 50 points is deducted, but does not specify who selects the penalty path.
- **My Understanding:** The active account or policy configuration determines whether the late-cancellation consequence is money or points.
- **Solution:** On late cancellation, support either a $25 fee deduction or a 50-point deduction according to the policy in effect, and audit the policy execution.

---

## Item 7: Breach counting scope
- **Problem:** The prompt explicitly defines no-show breaches, but does not say whether other lifecycle events should count.
- **My Understanding:** Only no-show breaches should count toward the freeze rule.
- **Solution:** Count no-show breaches within a rolling 60-day window, and after the second breach enforce a 7-day booking freeze automatically.

---

## Item 8: Late arrival behavior
- **Problem:** The prompt defines the check-in window and says late arrivals are partial attendance, but does not spell out operational handling.
- **My Understanding:** Users may still check in during the grace window, but with reduced attendance status and no extension.
- **Solution:** Check-in remains open until 10 minutes after start; arrivals after the start but before close are marked partial attendance and cannot extend into the next slot.

---

## Item 9: Local storage scope for dashboard data
- **Problem:** The prompt says favorites, recent views, and prior reservations are stored locally on the workstation/server, but does not say whether browser storage is acceptable.
- **My Understanding:** These records should be persisted in the application database on local infrastructure.
- **Solution:** Store favorites, recent views, and reservation history in local PostgreSQL-backed application tables.

---

## Item 10: Admin configurability boundaries
- **Problem:** The prompt requires configurable dictionaries, form rules, and relationships, but does not define whether configuration is code-based or UI-based.
- **My Understanding:** Configuration should happen through bounded admin UI tooling backed by metadata tables.
- **Solution:** Administrators manage supported dictionaries, form rules, and relationship definitions through admin CRUD interfaces with constrained rule types and server enforcement.

---

## Item 11: Relationship configurability depth
- **Problem:** The prompt says admins can define basic relationships, but does not say whether they can reshape the whole schema.
- **My Understanding:** Relationship management should be limited to a bounded set of supported business links.
- **Solution:** Allow admin management of supported relationships such as service-tags, service-time-slots, and reservation-user links without enabling arbitrary schema mutation.

---

## Item 12: Duplicate detection threshold
- **Problem:** The prompt specifies normalized title similarity and exact project/patent matching, but not whether likely duplicates are auto-merged.
- **My Understanding:** The system should detect and surface likely duplicates locally rather than silently merging them.
- **Solution:** Combine normalized title similarity with exact project/patent-number matching and surface likely duplicates for conflict review.

---

## Item 13: Incremental sync behavior
- **Problem:** The prompt requires field mapping, incremental sync, and conflict resolution, but not the baseline import behavior.
- **My Understanding:** Imports should support both field mapping and timestamp-based incremental updates with explicit conflict choices.
- **Solution:** Support CSV/JSON field mapping, last-updated-based incremental sync, and conflict resolution using prefer-newest or admin-override.

---

## Item 14: Offline login anomaly signals
- **Problem:** The prompt names new device fingerprint and unusual time window as examples, but does not say whether those are mandatory or optional.
- **My Understanding:** Both examples should be treated as mandatory anomaly detections.
- **Solution:** Treat new device fingerprints and unusual login time windows compared with local historical patterns as login anomalies, and write them to immutable audit logs.

---

## Item 15: Cross-device single logout behavior
- **Problem:** The prompt requires single-logout across devices, but does not define whether it applies only to forced revocation or to ordinary logout as well.
- **My Understanding:** Logout should invalidate every active session for that user across local devices, not only the current browser.
- **Solution:** Any user logout or security-driven session revocation invalidates all active sessions for that user across local devices, including ordinary logout, password change, admin-forced logout, and account disable.

---

## Item 16: Sensitive data handling depth
- **Problem:** The prompt requires masking by default and encryption at rest, but does not define whether both UI and API outputs are covered.
- **My Understanding:** Masking should apply to default data presentation in both UI and API responses, while storage encryption protects classified fields at rest.
- **Solution:** Sensitive classified fields are masked by default in UI/API responses based on permission and encrypted at rest with application-managed keys, with TLS protecting transport on the local network.

---

## Item 17: Step-up verification method
- **Problem:** The prompt requires re-entering the password for critical actions, but does not define whether another factor can replace it.
- **My Understanding:** Password re-entry itself is the required step-up mechanism.
- **Solution:** Ask for the current password immediately before role changes, data export, policy edits, and account deletion within the active session.

---

## Item 18: Offline CAPTCHA implementation
- **Problem:** The prompt requires CAPTCHA generated and verified locally, but does not define acceptable implementation boundaries.
- **My Understanding:** CAPTCHA must be fully generated and validated inside the local Laravel application.
- **Solution:** Use a local application-generated CAPTCHA after brute-force thresholds require it.

---

## Item 19: Backup and restore evidence
- **Problem:** The prompt requires documented restore procedures tested monthly, but does not say whether the system should also track those tests.
- **My Understanding:** The project should provide a practical mechanism to document and record monthly restore-test completion.
- **Solution:** Provide scheduled daily local snapshots retained for 30 days, a documented restore procedure, and a project-level mechanism to record monthly restore test completion.

---

## Item 20: Mandatory audit coverage floor
- **Problem:** The prompt lists some mandatory audit events, but not the minimum broader audit set implied by the rest of the requirements.
- **My Understanding:** The audit floor should include all explicitly named security and policy events plus closely related critical operations.
- **Solution:** At minimum audit permission changes, login anomalies, critical-action step-up events, reservation policy executions, cancellations and penalties, breaches and freezes, and import/export operations.