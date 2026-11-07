# Finance and Audit (payment vouchers)

Two modules, **Finance** and **Audit**, show ONE shared payment-voucher (PV) workflow. Finance prepares a voucher, Audit
acknowledges it (a tick) or queries it (an X), Finance acknowledges back. Behind ONE flag, `GWL_FINANCE_AUDIT_ENABLED`
(`config/gwl.php` `finance_audit_enabled`, default **off**, true in `phpunit.xml`); when off the routes do not register and both
modules disappear from navigation. Module slugs `finance` and `audit`; roles `finance_officer` and `audit_officer`.

Status: **Phases 1 (core) and 2 (suppliers: list, quick add, Excel import; the "left service" flag) are built.** Phase 3
(analytics, charts, exports, reminders, settings) is not. Sections marked *(phase 3)* are the design for it.

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

**The "payee left service" flag** (phase 2) is ONE test, `Services/Finance/PayeeStatusService::evaluate()`, never in Blade. It only looks at
an *employee* payee who is no longer active and whose exit was not a Transfer: `deactivated_at <= pv_date` gives
`left_before_pv` (a payment to someone already gone), anything else (left after the PV date, or an older record with no exit date)
gives the softer `left_since`. A retiree, district or supplier payee, a transfer and a reactivated employee are never flagged.
`warningFor($pv)` is used on the Finance and Audit detail pages (an alert with the sentence) and `warningsFor($page)` on the Audit
queue (ONE staff query for the page; a "Left service" badge with an icon, words and the sentence as a tooltip). It is information:
it blocks nothing and is not put in a notice or an audit row.

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

## 7. Suppliers (phase 2)

`fin_suppliers`: ONE company-wide list, NOT location-scoped (every Finance and Audit user reads all of it). Name is the only required
field; **code, TIN and phone are optional and a blank is stored as NULL, never `''`**. Written ONLY by `SupplierService`
(`Services/Finance/`): the Suppliers screen, the PV form's "Supplier not on the list? Add it" panel and the Excel import all call it.

- **Permissions** (checked in the service, the screens and the routes): add/edit `finance.manage_suppliers`; switch off/on
  `finance.deactivate_suppliers` (not given to `finance_officer`: it affects every location); import `finance.import_suppliers`
  (the import also needs `manage_suppliers`). Audit reads only (`audit.view`); even a person holding both roles cannot change
  anything through the Audit door (`Suppliers` component, `module = audit`).
- **Cleaning** (`SupplierService::clean()`, every problem reported at once): name 1-200 characters, spaces collapsed; TIN upper-cased,
  spaces removed, letters/digits/hyphens, 6-20 characters (an assumption: the real Ghana formats were not supplied, so it is
  deliberately loose; change `TIN_PATTERN` in one place); phone with spaces/dashes/dots/brackets removed, an optional `+` and 9-15
  digits, and a 9-digit number that lost its leading zero in Excel gets it back. A format is checked only when something is given.
- **Duplicates are refused, naming the supplier that is there**: the same name once case and spacing are ignored (`name_key`, unique;
  a switched-off supplier counts and the message says so) or the same TIN (unique). A unique-index race gives a friendly message.
- **Look-alikes are a warning** (`SupplierMatcher`, one place): "ABC Trading Ltd." / "ABC Trading Limited" (noise words such as LTD,
  LIMITED, ENTERPRISE, GHANA dropped), a name the listed one begins with, or ONE word of the same number of words a letter or two off
  (a word under 5 letters must match exactly, so "A Traders" and "B Traders", "Fresh One" and "Fresh Two" are different). The screen
  and the form show them while typing and refuse the save until "This is a different supplier" is ticked. An edit only checks when
  the NAME changes.
- **Nothing is deleted**; switching off hides a supplier from the picker for NEW vouchers, an old voucher keeps it. A voucher freezes
  the supplier id AND the name it had when saved, so renaming a supplier never rewrites a voucher.
- **Audit rows** hold ids and the NAMES of the fields changed, never a supplier's name, TIN or phone. The import writes ONE summary row
  (`finance.suppliers_imported`: counts and whether "valid rows only" was used), not one per supplier.
- **The screen** (`finance.suppliers` / `audit.suppliers`) lists name, code, TIN, phone, status and, beside each, the number of
  vouchers, the total and the date last used. Those figures come from `SupplierDirectory`, which asks `FinanceScope::pvs()`, so a
  location sees only what it paid itself (super_admin: every location; Audit's never include a draft). Drafts and voided vouchers are
  not counted as use. "Vouchers" opens the latest ten in the viewer's reach. Search covers name, TIN, phone and code.
- **The import** (`SupplierImportService`, screen `finance.suppliers.import`, template `finance.suppliers.import.template`): one
  sheet "Suppliers" with `name, code, tin, phone` (a workbook with one sheet is accepted whatever it is called; common heading
  spellings such as "Supplier Name" / "TIN No" / "Phone Number" are understood). Preview runs everything in a transaction that is
  rolled back; confirm READS THE FILE AGAIN. Create-only and every row through `SupplierService`. A supplier already listed (or
  earlier in the same file) is **skipped, not an error**, so importing a file twice adds nothing and a listed supplier is never
  updated; a TIN held by another supplier, a bad TIN/phone format or a missing name is an **error** naming the reason; a look-alike is a
  **warning** and the row IS imported (nobody can tick a box inside a file), so the preview lists them for the person to take out of
  the file first. All-or-nothing unless "valid rows only". Cells are read as text; the template's four columns are formatted as
  text (a whole-column style) so a phone keeps its zero. Row cap `gwl.fin_supplier_import_max_rows` (2000).

## 7b. Phase 3 (to build)

- **Analytics** `PvAnalyticsService` (the ONLY evaluator; cached by `FinanceScope::keyFor()` + `FinanceBooksVersion`) and exports
  `FinanceExportService` (Excel via `CommercialReportExport`/`ReportSheet`, PDF via the Commercial PDF page), reminders for
  vouchers waiting too long (`PvNotificationService::waiting()` is ready), settings.
## 8. Easy to get wrong

- Never filter vouchers by region in Blade or a screen, and never by region alone. Always `FinanceScope`.
- A draft is Finance's only: Audit queries use `AS_AUDIT`. A user holding both roles still gets the Audit view on Audit screens.
- Never write a `Fin*` row outside `PaymentVoucherWorkflowService` / `PvCatalogService` / `PvAttachmentService`.
- Never put a payee, amount, comment, reason, supplier name/TIN/phone or file name in an audit row, a notice or a log
  (`PvNoticesAndAuditRowsTest` scans for them).
- Never write `fin_suppliers` outside `SupplierService`, and never read a supplier's figures other than through `SupplierDirectory`
  (a location must not see what another paid). The import must not become a second writer: it calls the service.
- `PvConflictException` is not a validation error: screens catch it and offer a reload.
- Do not register `NotifyPvParties` in `AppServiceProvider`: event discovery already finds it and a second registration sends every
  notice twice. (Transport's listeners are registered both ways and look doubled in `php artisan event:list`.)
- Tests use `Notification::fake()` (the Transport fixtures do): read notices with `Notification::sent()`, not the table.
- `FinPaymentVoucher` has `$table = 'fin_pvs'` (the inferred name would be `fin_payment_vouchers`).
