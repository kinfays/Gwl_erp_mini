# Finance and Audit (payment vouchers)

Two modules, **Finance** and **Audit**, show ONE shared payment-voucher (PV) workflow. Finance prepares a voucher, Audit
acknowledges it (a tick) or queries it (an X), Finance acknowledges back. Behind ONE flag, `GWL_FINANCE_AUDIT_ENABLED`
(`config/gwl.php` `finance_audit_enabled`, default **off**, true in `phpunit.xml`); when off the routes do not register and both
modules disappear from navigation. Module slugs `finance` and `audit`; roles `finance_officer` and `audit_officer`.

Status: **Phase 1 (core) is built.** Phase 2 (suppliers list/import, the "left service" warning on the screens) and Phase 3
(analytics, charts, exports, reminders, settings) are not. Sections marked *(phase 2)* / *(phase 3)* are the design for them.

## 1. What a voucher is

`fin_pvs`: `pv_date` (not in the future), `pv_no`, `payee`, `category`, `type`, `details`, `amount`, `remarks` (Finance's own
note, NOT where Audit's queries go), optional attachments, `status`, `round`, `lock_version`, the location (`region_id` +
`district_id`, copied from the creator), `created_by`, `submitted_at`. Money is integer **pesewas**, GHS only, > 0, converted only
by `Services/GWater/Money` (there is one cedis <->pesewas conversion in the ERP) and capped by `gwl.fin_amount_max_cedis`.

### Workflow

```
Draft -> With Audit -> (Audit tick) Audit acknowledged -> (Finance tick) Completed
With Audit -> (Audit X, reason mandatory) Queried -> Finance corrects + resubmits -> With Audit (round + 1) ... until Audit ticks
```

- A Draft is visible to Finance only. Finance edits only in Draft or Queried.
- Audit may **withdraw a tick** (reason mandatory) only while the status is *Audit acknowledged*; it returns to With Audit and
  Finance is told. Once Finance has acknowledged (*Completed*) it is final.
- **Void** (reason, never a delete) from Draft, Queried or With Audit. *Audit acknowledged* must be withdrawn first. A voided PV
  number is never reusable.
- **Separation of duties**: whoever submitted a voucher in ANY round (the `submitted` or `resubmitted` event) cannot tick, query
  or withdraw on it, even holding both roles; `super_admin` is not exempt. Finance's own acknowledge is not restricted (it is
  Finance's step).
- Every action writes an immutable `fin_pv_events` row (pv, round, action `submitted|queried|audit_acknowledged|tick_withdrawn|
  resubmitted|finance_acknowledged|voided`, actor, comment, query reason code, `changes` json on resubmit, created_at). A model
  hook refuses update/delete. Names shown anywhere come from the event's actor.
- A query needs a **reason code** (amount does not match, missing supporting document, wrong category/type, duplicate, payee not
  eligible, calculation error, other) AND a free-text comment.
- On resubmit, `changes` holds `[{field, old, new}]` of what differs from the content Audit last saw
  (`fin_pvs.submitted_snapshot`, refreshed on every submit/resubmit). The detail page shows it as "Changed since Audit's last
  query".

### The marks (list and detail)

Audit column and Finance column: **tick** = acknowledged, **X** = queried, **clock** = waiting. Computed in one place,
`FinPaymentVoucher::marks()`. Each mark is an icon AND a visible word (never colour alone), has a tooltip AND opens a small
popover on click/tap/keyboard focus (`resources/views/components/finance/mark.blade.php`): who, when, reason, comment. The popover
is `position: fixed` (placed from the button's rectangle) so a scrolling table never clips it.

## 2. Location scope: `Services/Finance/FinanceScope`

Head Office and every region each run their **own** Finance and **own** Audit. A location is **Head Office OR a region WITHOUT
Head Office** (same rule as G-Water/Transport: `TransportScope::applyUnit()`, never scope by region alone: Head Office shares a
region id with its staff).

- ONE class decides reach: vouchers, events, attachments, the employee pickers, notice recipients, (phase 3) analytics.
  `pvs($user, 'finance'|'audit')`: the **audit** view never includes drafts and never a voided draft (`submitted_at` null).
- Another location's row answers **404**, not 403. `super_admin` reads every location but must pick the district for a write
  (`recordingLocation()`); a login with no employee region has nothing.
- Suppliers and the category/type catalogue are deliberately **not** location-scoped.
- The Livewire trait `Livewire/Finance/Concerns/ScopesFinanceByActor` only wraps `FinanceScope` (a separate scoping trait, like
  the other modules'); `FinanceScopeTest::test_the_livewire_trait_agrees_with_the_scope...` pins the two together.

## 3. Writes: `PaymentVoucherWorkflowService`

The ONLY writer of `fin_pvs`, `fin_pv_events` and `fin_pv_attachments`. Each method: permission -> (new voucher) the location ->
inside one transaction **re-read `lockForUpdate` and authorise on that copy** (as Finance, or as Audit so a draft is a 404) -> the
rule (state, version, separation of duties) -> event row + `AuditLog::record` -> after commit: `FinanceBooksVersion::touch()` and
`PaymentVoucherActioned` (notices). Audit rows hold ids, statuses, round, region/district and counts ONLY: never the payee,
amount, a comment, a reason, a file name, a supplier name/TIN/phone.

`lock_version` rises on every change of the **content** (fields, and any file added or removed); the form passes the version it
opened and a newer one raises `PvConflictException` (the form shows a warning and "Reload the latest"; nothing is overwritten).
Status changes do not move it.

### PV number

Unique across the whole company, voided PVs included: `pv_no` as typed (trimmed, runs of spaces collapsed) plus `pv_no_key` (the
same, upper-cased) with a DB unique index. A clash says only **"This PV number is already used."** (never which PV, nor which
location). A unique-violation race is caught and gives the same message.

### Payee (frozen when saved)

`payee_kind` = `employee` (CURRENT staff of the location), `retiree` (former staff of the location), `district` (any district) or
`supplier` (the company-wide list, active only). The voucher stores the kind, the display name and the id of the person /
district / supplier. A voucher being edited keeps its payee even if the person has since left or the supplier was deactivated.

**Staff exit data (what Staff already records, nothing was changed):** `employees.is_active`, `employees.deactivation_reason`
(`retired`, `resigned`, `contract_ended`, `transfer`, `left`, `dead`, `other`; null on old records) and `employees.deactivated_at`
(stamped by `Employee::saving` when deactivated, back-filled from `updated_at` for older rows, so for legacy records it is
approximate). `Employee::EXIT_REASON_GROUPS` already treats `transfer` as "not an exit". There is a clean marker, so no Staff
change was needed:
- **employee picker** = `is_active = 1`;
- **retiree picker** = `is_active = 0` and reason not `transfer` (retired, resigned, contract ended, left, dead, other, none);
- both are `FinanceScope::employees($actor, $kind, $location)`; `EmployeeDirectory` is untouched.

*(phase 2)* The Audit "payee left service" warning (employee left on or before the PV date, or payee kind employee but the person
has since left) is ONE test in a service (`deactivated_at <= pv_date`, or `is_active = 0` for an employee payee), never in Blade.

### Attachments: `PvAttachmentService`

Optional (PV scan, supporting documents). Checked by **content**: a PDF by its `%PDF-` signature, a picture by decoding its header
(JPG/PNG/WebP; **no SVG**); the name or the browser's type is not trusted. Limits are config (`fin_attachment_max_mb` 5,
`fin_attachment_max_per_pv` 10). Stored on the private `local` disk as `finance/pvs/{pv}/{random}.ext`, never given a URL, served
only by `finance.pvs.attachment` / `audit.pvs.attachment`, which resolve the voucher through `FinanceScope` (404 outside the
location; the Audit route cannot see a draft). `display_name` (sanitised) is shown to authorised viewers and is never put in an
audit row or notice.

## 4. Catalogue: `PvCatalogService`

`fin_pv_categories` / `fin_pv_types` (two levels; a type belongs to ONE category), shared company-wide. Seeded: **Staff Claims**
(Medical, Risk Allowance, Travel and Transportation (T&T) Allowance, Sitting Allowance, Incidental Allowance) and **Operations
Activities** (Operations, Stationery, New Service, Contracts). Changed ONLY through the service (create, rename, move,
activate/deactivate; **never delete**; an audit row per change, ids only), permission `finance.manage_categories` checked in the
service, the screens and (for the form's panel) the component. A deactivated category/type cannot start a new choice but stays on
old vouchers, and the voucher form keeps a voucher's current one selectable. A type **used by a voucher cannot be moved** to
another category (the voucher stores both ids); add a new type and deactivate the old. Finance has the "Add a category or type"
panel on the PV form and a Categories and types screen; Audit sees the same screen read-only.

## 5. Notices: `PvNotificationService`

In-app only, through `GeneralDatabaseNotification`. Recipients are people **based at the voucher's location** holding the
permission (`FinanceScope::usersAt()`; `audit.review` for Audit, `finance.create_pv` for Finance), never the person who did the
action. Audit is told on submit/resubmit; Finance on query, tick and tick withdrawn. A notice carries the **PV number and the
action only**. The listener `Listeners/Finance/NotifyPvParties` is found by Laravel's event discovery. *(phase 3)* A reminder
command for vouchers waiting too long (`PvNotificationService::waiting()` is ready for it).

## 6. Access

Permissions (migration-seeded, `2026_11_10_000002`, repeated for fresh installs/tests by `FinanceAuditRolePermissionSeeder`):
`finance.view`, `.create_pv` (create, edit, send, resubmit), `.void_pv`, `.acknowledge`, `.manage_categories`,
`.manage_suppliers` (add/edit), `.deactivate_suppliers`, `.import_suppliers`, `.view_reports`, `.export`; `audit.view`,
`.review` (tick, query, withdraw), `.view_reports`, `.export`. `finance_officer` holds all `finance.*` except
`finance.deactivate_suppliers` (an edit or deactivation of a supplier affects every location, so it is granted to few);
`audit_officer` all `audit.*`; `super_admin` everything. Both roles are ICT-assignable, not protected, not system roles, and in
**neither** `RoleGrantPolicy::HEAD_OFFICE_ROLES` nor `REGIONAL_ROLES`. Routes: `finance.*` (`module:finance`) and `audit.*`
(`module:audit`) with `permission:` per screen.

## 7. Phase 2 and 3 (to build)

- **Suppliers** (`fin_suppliers` already exists): one company-wide list; name required, TIN/phone/code optional and stored as NULL
  when blank; unique on the normalised name and on TIN when present; near-match warning; `SupplierService` (create, edit,
  deactivate, reactivate, audit with ids only); `SupplierImportService` (create-only, rolled-back preview, confirm re-reads,
  all-or-nothing unless "valid rows only", text cells stay text, template, row cap, `finance.import_suppliers`). A supplier's
  PVs, totals and last-used date are always worked out through `FinanceScope`.
- **Analytics** `PvAnalyticsService` (the ONLY evaluator; cached by `FinanceScope::keyFor()` + `FinanceBooksVersion`) and exports
  `FinanceExportService` (Excel via `CommercialReportExport`/`ReportSheet`, PDF via the Commercial PDF page).

## 8. Easy to get wrong

- Never filter vouchers by region in Blade or a screen, and never by region alone. Always `FinanceScope`.
- A draft is Finance's only: Audit queries use `AS_AUDIT`. A user holding both roles still gets the Audit view on Audit screens.
- Never write a `Fin*` row outside `PaymentVoucherWorkflowService` / `PvCatalogService` / `PvAttachmentService`.
- Never put a payee, amount, comment, reason, supplier name/TIN/phone or file name in an audit row, a notice or a log
  (`PvNoticesAndAuditRowsTest` scans for them).
- `PvConflictException` is not a validation error: screens catch it and offer a reload.
- Do not register `NotifyPvParties` in `AppServiceProvider`: event discovery already finds it and a second registration sends every
  notice twice. (Transport's listeners are registered both ways and look doubled in `php artisan event:list`.)
- Tests use `Notification::fake()` (the Transport fixtures do): read notices with `Notification::sent()`, not the table.
- `FinPaymentVoucher` has `$table = 'fin_pvs'` (the inferred name would be `fin_payment_vouchers`).
