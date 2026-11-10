# Finance and Audit (payment vouchers)

Two modules, **Finance** and **Audit**, show ONE shared payment-voucher (PV) workflow. Finance prepares a voucher, Audit
acknowledges it (a tick) or queries it (an X), Finance acknowledges back. Behind ONE flag, `GWL_FINANCE_AUDIT_ENABLED`
(`config/gwl.php` `finance_audit_enabled`, default **off**, true in `phpunit.xml`); when off the routes do not register and both
modules disappear from navigation. Module slugs `finance` and `audit`; roles `finance_officer`, `finance_manager`, `audit_officer`
and `audit_manager` (section 6).

Status: **all three phases and the change request (steps A-E) are built**: 1 (core), 2 (suppliers: list, quick add, Excel import; the
"left service" flag), 3 (reports and charts, exports, reminders, settings), then A the two manager roles, B the per-location
catalogue (and the rename fix), C the payee comboboxes with region-only districts, D two separate reminder sets, E the demo data
command (section 9). **Change request 2 (steps F-J) is built too: batches, assignment by the Audit manager, printing, the new
analytics and reminders, and demo batches (section 10).** It changes three things earlier sections describe: an Audit officer no longer
reads the whole location's queue (only what is assigned to them, section 10.2), the Audit manager gained an assignment queue (10.2),
and the Audit reminders now go to the manager (unassigned) or to the one officer (assigned), not to every officer (10.6).

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
- Suppliers are deliberately **not** location-scoped. The category/type catalogue IS per location (section 4).
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

`payee_kind` = `employee` (CURRENT staff of the location), `retiree` (former staff of the location), `district` (a district OF THE
LOCATION: region based only) or `supplier` (the company-wide list, active only). The voucher stores the kind, the display name and
the id of the person / district / supplier. A voucher being edited keeps its payee even if the person has since left, the supplier
was deactivated or an old voucher's district would not be offered today.

**The payee pickers (change request step C).** Choose the kind FIRST (changing the kind, or super_admin's location, clears the picked
payee), then one accessible combobox, the shared `<x-form.searchable-select>` (ARIA 1.2 combobox + listbox; the Assets screens use it
too, the new props are `min-chars`, `limit`, `more-text` and a `noMatch` slot):
- **Employees, retirees, suppliers: server-side search.** The component calls the form's `searchPeople()` / `searchSuppliers()` after a
  250 ms debounce, from 2 characters, and gets at most 20 rows (a "keep typing to narrow the list" row appears when 20 came back); the
  whole list is never put in the page. The work is `FinanceScope::searchEmployees($actor, $kind, $term, $location)` (still only that
  location's people, current staff or retirees, never mixed) and `SupplierService::search($term)` (company-wide, ACTIVE only; name or
  code). `%` and `_` in a term are plain text (`FinanceScope::like()` + `ESCAPE '!'`, which behaves the same on MySQL and SQLite).
  A row is the id, the name and a short disambiguator (staff ID + department; supplier code): never a phone, an e-mail address or a TIN
  (a TIN is not searchable here either).
- **Districts: region based only.** `FinanceScope::districts($actor, $location)`: a regional user gets their region's districts (NOT
  Head Office, NOT another region's), Head Office staff the Head Office district, super_admin the districts of the location they
  picked (none until they pick). Few rows, so this picker filters in the browser. The workflow service re-checks it on every save.
- **The browser sends back only an id.** `PaymentVoucherWorkflowService::payee()` re-resolves every id inside the actor's scope on
  every save (a person of another location, a retiree sent as an employee, an inactive supplier, a district outside the location are
  validation errors); the shown name is worked out the same way and never from the browser.
- **No match** on the supplier picker offers "Add '<typed>' as a new supplier" to `finance.manage_suppliers` holders: it opens the
  existing quick-add panel with the name filled in (`startNewSupplier()`); a successful add chooses the new supplier.
- There is no payee filter on the voucher lists today (they search the PV number and payee name as plain text), so there was nothing to
  convert there.
- **Manual keyboard / screen-reader QA** (the browser behaviour cannot be unit tested; the tests cover the server side and the markup):
  1. Tab into "Employee": the input is announced as a combobox, "Employee", collapsed; Tab leaves it without opening anything.
  2. Type one letter: the list stays closed and the status is announced as "Type at least 2 characters to search". Type a second: after a
     moment "N results available. Use the up and down arrow keys, then Enter to choose" is announced (and "Keep typing to narrow the list"
     when 20 came back).
  3. Down/Up move through the options and wrap; the active option is announced (focus stays in the input, `aria-activedescendant`
     changes). Once you have used the arrows, Home/End jump to the first/last option; before that they move the text cursor.
  4. Enter chooses the active option and closes the list, the input shows the chosen name; Escape closes the list and puts the
     chosen name (or nothing) back, never half-typed text; typing over a chosen name un-chooses it.
  5. The clear (x) button is announced as "Clear Employee", empties the picker and returns focus to it.
  6. A search with no result announces "No one matches that" (suppliers: "No supplier matches that" plus the "Add ... as a new supplier"
     button for those who may add one).
  7. Change "Who is being paid": the picked payee is gone and the new picker is empty. Districts: type part of a name, only the
     location's districts appear.
  8. Repeat with 200% zoom and a screen reader (NVDA/VoiceOver) in the light and dark themes; nothing may depend on colour alone.

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

`fin_pv_categories` / `fin_pv_types` (two levels; a type belongs to ONE category) are **per location**, "own row else system
default" (the G-Water price pattern). Seeded: **Staff Claims** (Medical, Risk Allowance, Travel and Transportation (T&T) Allowance,
Sitting Allowance, Incidental Allowance) and **Operations Activities** (Operations, Stationery, New Service, Contracts) are SYSTEM
DEFAULT rows (`unit` NULL); only super_admin edits the defaults themselves. A location is `unit` `ho` or `r<region id>` (the
`FinanceBooksVersion` key).
- **One reader/writer: `PvCatalogService`.** `effective($location)` is the ONE method that works out names and on/off state (each item
  gets `effective_name`, `effective_active`, `is_default`, `is_renamed`, `is_switched`); the voucher form (`choices()`), the lists'
  filters, Reports, exports and Settings all call it, and a voucher's labels come from `labelPvs()` / `labelsOf()` (its OWN
  location's current name; the model's `category_label` / `type_label`; never print `category->name`). The workflow service
  validates a voucher's category/type through `resolve()`.
- **A finance_manager (`finance.manage_categories`), for their own location only:** adds a location-owned category or type
  (`unit` = their location); renames ANY category/type for the location only (a row in `fin_catalog_overrides`: `unit`, `kind`,
  `item_id`, nullable `display_name`, nullable `is_active`; it exists only while it differs from the default and is removed when it
  is back at it); switches ANY off/on for the location only (a category switched off takes its types off there: a type is
  effectively active only while its category is, and a type switched off on its own stays off when the category comes back);
  moves the location's OWN types between the location's categories. A standard type is never moved by a manager.
- **super_admin** must name the district whose location a change is for, or work on the **system defaults** (`$defaults`; a
  default edit bumps every location's books version). Anyone else asking for the defaults gets 403.
- **Names** are unique (case-insensitive) within the location's effective list, types within their category (the global unique
  indexes were dropped in `2026_11_13_000001`). Another location's owned row answers 404. Every change writes an audit row with
  ids and the location key only (never a name).
- A voucher keeps its category/type ids; a switched-off item stays on the vouchers already using it and on their edit form, but
  cannot start a new voucher. A type **used by a voucher cannot be moved** to another category.
- **Cache:** every catalogue change bumps the books version of the location (all locations for a default), so cached Reports and
  exports never show a name from before it. `comparisonLabel()` is the label for a cross-location view: a standard item by its
  standard name, an owned one as "<Location>: <name>" (no screen sets locations side by side yet).
- **Screens:** the voucher form only OFFERS the location's active items (no add panel for anyone). Management lives only in Finance >
  Settings > Categories and types (`finance.settings`, route/screen/service all need `finance.manage_categories`: officers get 403
  and no menu entry). Audit has none.
- **The rename bug (reported "rename seems not to work"):** two causes, both fixed with a regression test
  (`PvCatalogRenameTest`). (1) The Rename button's handler was the literal text `startRenameType(1, @js($type->name))`, because
  Blade does not compile `@js()` inside a component's attributes, so the click was never a valid call (the Livewire tests called
  the method directly, which hid it). The buttons now send only the id and the server reads the current name. (2) The Reports
  figures and exports are cached per location and a catalogue change never invalidated that cache, so they kept the old name for
  up to `fin_analytics_cache_seconds`.

## 5. Notices: `PvNotificationService`

In-app only, through `GeneralDatabaseNotification`. Recipients are people **based at the voucher's location** holding the
permission (`FinanceScope::usersAt()`), never the person who did the action. Since change request 2: a new voucher or batch tells the
location's Audit MANAGERS (`audit.assign`: "waiting to be assigned", linking to the assignment queue); an assignment tells the officer
(one digest for several vouchers or a batch); a resubmit tells the officer the voucher is assigned to (the managers only if it somehow has
none); Finance (`finance.create_pv`) is told on query, tick and tick withdrawn. A notice carries the **PV number and the action only**
(a batch notice the batch number and a count). The listener `Listeners/Finance/NotifyPvParties` is found by Laravel's event discovery. Reminders for vouchers waiting
too long are digest notices (the count, the longest wait and the PV numbers; section 7b).

## 6. Access

Permissions (migration-seeded, `2026_11_10_000002`, repeated for fresh installs/tests by `FinanceAuditRolePermissionSeeder`):
`finance.view`, `.create_pv` (create, edit, send, resubmit), `.void_pv`, `.acknowledge`, `.manage_categories`,
`.manage_suppliers` (add/edit), `.deactivate_suppliers`, `.import_suppliers`, `.view_reports`, `.export`,
`.manage_reminders` (was `.manage_settings`, renamed in place); `audit.view` (the door to the Audit screens; an officer's own list is
what is assigned to them, see 10.2), `.review` (tick, query, withdraw: only on a voucher assigned to you), `.view_reviewed` (only vouchers
Audit has worked on), `.assign` (the manager: assignment queue, assigning), `.view_reports`, `.export`, `.manage_reminders`. Four ICT-assignable roles
(not protected, not system roles, in **neither** `RoleGrantPolicy::HEAD_OFFICE_ROLES` nor `REGIONAL_ROLES`; roles are permissions,
never reach: every one goes through `FinanceScope`, their own location only):
- `finance_officer`: create, edit, send, resubmit, void, acknowledge, add/edit/import suppliers, reports, export. NO catalogue, NO
  reminder settings, NO deactivating suppliers (the migration `2026_11_12_000001` removes `finance.manage_categories` from officers
  on databases that had it).
- `finance_manager`: `finance.view`, `.view_reports`, `.export`, `.manage_categories`, `.manage_reminders`,
  `.deactivate_suppliers`. Reads EVERYTHING of its location (drafts, every status, events, files, suppliers, analytics) but cannot
  add, edit, send, resubmit, void or acknowledge: `PaymentVoucherWorkflowService` refuses (403) for a user without
  `finance.create_pv` / `.void_pv` / `.acknowledge`, and the create/edit routes answer 403. Buttons are hidden as a courtesy only.
- `audit_officer`: `audit.view`, `.review`, `.view_reports`, `.export`. Reads and reviews only what is assigned to them (10.2).
- `audit_manager`: `audit.view_reviewed`, `.view_reports`, `.export`, `.manage_reminders`, `.assign`; NO `audit.review`, so never a tick,
  query or withdrawal. Assigns (10.2) and reads the minimal assignment queue; opens ONLY a voucher with at least one
  `audit_acknowledged`, `queried` or `tick_withdrawn` event, with the full timeline and who did each action. This is ONE condition in
  `FinanceScope::pvs()` (`auditReach()` is `all` for super_admin, `assigned` for `audit.review`, `worked` for `audit.assign` /
  `audit.view_reviewed` / a read-only `audit.view`, `none` otherwise; the `worked` case adds a `whereExists` on
  `FinPvEvent::REVIEWING`). The register, detail screen, file route, timeline, queue search and the waiting export all start from
  `pvs()`, so a With Audit voucher nobody has reviewed answers 404 there. Figures and "how many are waiting" are location-wide totals
  (`FinanceScope::pvsForTotals()` and `batchesForTotals()`, used ONLY by `PvAnalyticsService`; never list or open rows from them) but the
  waiting list names only vouchers the viewer may open.
- `super_admin` everything. Audit has NO catalogue screen, control, route or menu entry (`audit.categories` answers 403).
Routes: `finance.*` (`module:finance`) and `audit.*` (`module:audit`) with `permission:` per screen (`audit.view,audit.view_reviewed`
for the review list and detail).

## 7. Suppliers (phase 2)

`fin_suppliers`: ONE company-wide list, NOT location-scoped (every Finance and Audit user reads all of it). Name is the only required
field; **code, TIN and phone are optional and a blank is stored as NULL, never `''`**. Written ONLY by `SupplierService`
(`Services/Finance/`): the Suppliers screen, the PV form's "Supplier not on the list? Add it" panel and the Excel import all call it.

- **Permissions** (checked in the service, the screens and the routes): add/edit `finance.manage_suppliers`; switch off/on
  `finance.deactivate_suppliers` (not given to `finance_officer`: it affects every location); import `finance.import_suppliers`
  (the import also needs `manage_suppliers`). Audit reads only (`audit.view`); even a person holding both roles cannot change
  anything through the Audit door (`Suppliers` component, `module = audit`).
- **Cleaning** (`SupplierService::clean()`, every problem reported at once): name 1-200 characters, spaces collapsed; TIN upper-cased,
  spaces removed, exactly one letter then ten digits such as `P0012345678` (the format the company gave; `TIN_PATTERN` is the one
  place to change it); phone with spaces/dashes/dots/brackets removed, an optional `+` and 9-15
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

## 7b. Reports, exports, reminders and settings (phase 3)

**Reports** (`finance.reports` / `audit.reports`, `{module}.view_reports`; Livewire `Reports`, Chart.js through `<x-ui.chart>`): ONE
location at a time (the user's own; super_admin picks), a period by PV date (last 12 months by default). Everything on the screen, the
charts and the exports is `PvAnalyticsService::report()`, the ONLY evaluator (never add a second one, in SQL or a screen). It reads
vouchers through `FinanceScope::pvs()` (Finance, or Audit which never sees a draft), at the location, so it can only show what the
viewer may. Amounts are integer pesewas (the chart payload gets a cedis copy); a draft and a voided voucher are never spend; every
ratio is `null` on a zero denominator. The payload (arrays and scalars only) is cached for `gwl.fin_analytics_cache_seconds` under
a key made of what the viewer reads, the location's `FinanceBooksVersion` (bumped after every voucher write and every settings save),
the period and the view, so a figure is never shown after a change.
- **Period figures:** vouchers that went ahead, total / average / completed / still-open amounts, drafts and voided counts, status
  counts, spend by category / type (top 10) / payee kind / month (the 36 months ending with the period, zero-filled) / supplier (top
  10), how many vouchers Audit queried (rate), rounds per voucher, why Audit queried (reason codes) and four turnaround averages
  computed from the immutable `fin_pv_events`: Audit's response (each sending -> tick or query), Finance's answer to a query
  (query -> resubmission), Finance's acknowledgement (tick -> acknowledged) and first sending -> acknowledged.
- **Right now** (whatever the period): what is waiting, how long, how much is overdue, and an aging chart of vouchers With Audit
  (0-2, 3-6, 7-13, 14+ days). `PvBacklog` is the ONE definition of "waiting": With Audit (since the latest submission or withdrawn
  tick), Queried (since the query) and Audit acknowledged (since the tick); the threshold per kind is the location's setting.

**Exports** (`FinanceExportService`, controller `FinanceExportController`, routes `finance.export` / `audit.export`
`/export/{report}/{format}`): reports `register` (every voucher of the period with marks, one row each), `summary` (the analytics as
titled tables) and `waiting` (every waiting voucher), in Excel and PDF. The same titled-tables array feeds both: Excel through the
Commercial `CommercialReportExport` / `ReportSheet` (a text cell that starts `= + - @` stays text) and the PDF through the Commercial
report page. A file needs `{module}.export` AND `{module}.view_reports`, is for ONE location (the user's own; `?district=` only for
super_admin, ignored for anyone else), is seen as Finance or as Audit (no drafts), and is capped (`fin_export_max_rows` 5000,
`fin_export_pdf_max_rows` 1500; a register over the cap is not even built). One audit row per download holds the report, format,
period, row count and location, never a payee, number or amount.

**Reminders: TWO independent sets (change request step D).** Each is changed only by its own manager, per location, as overrides of
`config/gwl.php` stored in `fin_settings` (`unit` `ho` or `r<region id>`, the `FinanceBooksVersion` key) ONLY when they differ from the
default (a switch is stored as 0/1; the audit row holds the location ids and the NAMES of what changed, never a value). The mechanics
are `ModuleReminderSettings`; `FinanceSettings` and `AuditSettings` are bound as singletons so a save is seen by every reader.
- **Finance reminders** (Finance > Settings > Reminders, `finance.reminder-settings`, `finance.manage_reminders`): on/off
  (`finance_remind_enabled`), days Queried before Finance officers are reminded (`queried_wait_days` 5), days Audit acknowledged before
  they are reminded to acknowledge (`acknowledge_wait_days` 3), days a Draft may sit unsent (`draft_wait_days` 7), repeat interval
  (`finance_repeat_days` 3). Command `finance:remind` (`FinanceReminderService`). Recipients: active people BASED AT the voucher's
  location holding `finance.create_pv` (a query, a draft) or `finance.acknowledge` (a tick).
- **Audit reminders** (Audit > Settings > Reminders, `audit.reminder-settings`, `audit.manage_reminders`): on/off
  (`audit_remind_enabled`), days a sent voucher may stay UNASSIGNED before the Audit manager is reminded (`audit_unassigned_days` 1),
  days an officer's voucher may wait for their tick or query (`audit_wait_days` 3, which is also what the Reports call overdue for
  vouchers With Audit), repeat interval (`audit_repeat_days` 3). Command `audit:remind` (`AuditReminderService`): an unassigned voucher
  reminds the location's `audit.assign` holders; an assigned one reminds ONLY the officer it is assigned to, counted from the later of the
  wait's start and the assignment (a reassignment is a fresh wait). Both are `PvBacklog::auditItems()` (kinds `unassigned` / `officer`).
- A finance_manager cannot see or change the Audit set and the audit_manager cannot see or change Finance's: the screen is ONE
  component (`Livewire/Finance/ReminderSettings`) whose `module` is set by the route and locked against the browser, the route needs
  the module's own permission, and the service's `save()` checks it again. Officers have no reminder settings. super_admin may do both
  for the location they pick. The old shared `remind_repeat_days` was copied into both sets by migration `2026_11_14_000001`.
- **Mechanism** (`PvReminderService`, abstract; both commands scheduled daily at 08:00 only while the flag is on; the scheduler must be
  running): what is waiting is `PvBacklog`'s (With Audit, Queried, Audit acknowledged; plus `PvBacklog::drafts()` for the Finance set
  only, since a draft is never "waiting" on the Reports). A voucher at or over its limit is due unless its location switched the set
  off or it was reminded within the repeat interval for the SAME wait: `fin_reminder_states` (pv, kind, `since`, last sent; kind
  audit | queried | acknowledge | draft) is the dedupe, so a NEW wait (a second query, a withdrawn tick) is reminded afresh.
  Vouchers due together at one location are grouped by kind and each recipient gets ONE notice for the group: **the count of vouchers,
  the longest wait and the PV numbers** (the longest waiting first, at most ten, then "and N more"; also in the notice's `pv_numbers`),
  never a payee, amount or comment (`PvNotificationService::waiting()`), linking to the list.
  The commands read every location on purpose (no signed-in user). `--dry-run` records and sends nothing.

## 8. Easy to get wrong

- Never filter vouchers by region in Blade or a screen, and never by region alone. Always `FinanceScope`.
- A draft is Finance's only: Audit queries use `AS_AUDIT`. A user holding both roles still gets the Audit view on Audit screens.
- **The Audit manager's view is ONE condition** in `FinanceScope::pvs()` (`auditReach()`): only vouchers with a tick, query or withdrawn-tick
  event. `pvsForTotals()` is for COUNTING only (analytics figures, how many are waiting): never list, open or export rows from it.
- Roles are permissions, not reach, and the workflow service checks the permission itself (`finance.create_pv` / `.void_pv` /
  `.acknowledge` / `audit.review`): hiding a button is a courtesy, never the rule. The managers hold none of them.
- Never write a `Fin*` row outside `PaymentVoucherWorkflowService` / `PvCatalogService` / `PvAttachmentService` / `SupplierService`.
  (The demo data command goes through those services too; only its removal uses direct deletes.)
- **Never print a category or type name from the model** (`category->name`, `type->name`): a location may have renamed it. Use
  `PvCatalogService::effective()` for a list and `category_label` / `type_label` (or `labelPvs()` for a page of vouchers) for a voucher.
- **Blade does not compile `@js()` (or any directive) inside a component's attributes** (`<x-ui.button wire:click="f(@js($x))">` renders the
  literal text and the click does nothing). Send an id and look the value up on the server, or use a plain element.
- Never put a payee, amount, comment, reason, supplier name/TIN/phone or file name in an audit row or a log, nor in a notice other than
  the PV number the reminder and action notices carry (`PvNoticesAndAuditRowsTest` scans for them).
- Never compute a figure outside `PvAnalyticsService`, define "waiting" outside `PvBacklog`, or write `fin_settings` /
  `fin_reminder_states` outside the settings / reminder services. An export or a chart must show what the Reports screen shows. A change that
  alters what a cached figure shows (a catalogue change, a settings save, any voucher write) must bump the books version.
- The two reminder sets never share a key: `FinanceSettings` and `AuditSettings` (singletons), each saved only with its own permission.
- Payee ids from the browser are re-resolved inside the actor's scope on every save (`payee()`); a district payee is one of the LOCATION's
  districts (`FinanceScope::districts()`).
- Never write `fin_suppliers` outside `SupplierService`, and never read a supplier's figures other than through `SupplierDirectory`
  (a location must not see what another paid). The import must not become a second writer: it calls the service.
- `PvConflictException` is not a validation error: screens catch it and offer a reload.
- Do not register `NotifyPvParties` in `AppServiceProvider`: event discovery already finds it and a second registration sends every
  notice twice. (Transport's listeners are registered both ways and look doubled in `php artisan event:list`.)
- Tests use `Notification::fake()` (the Transport fixtures do): read notices with `Notification::sent()`, not the table.
- `FinPaymentVoucher` has `$table = 'fin_pvs'` (the inferred name would be `fin_payment_vouchers`).
- A full-suite flake unrelated to this module: the Leave letter PDF tests sometimes fail with a Dompdf "Division by zero" and an MDM
  policy test with a unique violation; they pass alone.

## 9. Demo data (change request step E)

`php artisan finance:demo-data [--with-users] [--remove]` and `Database\Seeders\FinanceAuditDemoSeeder` (NOT in `DatabaseSeeder`; run it with
`php artisan db:seed --class=FinanceAuditDemoSeeder`, which is the command without `--with-users`). Both are `FinanceDemoDataService`. They
**refuse to run in production and unless `GWL_FINANCE_AUDIT_ENABLED` is on** (`--remove` too); `--with-users` is for the `local` and
`testing` environments only.
- **What it makes:** for Head Office and up to three regions (a region without Head Office; the first non-Head Office district
  of each): four demo accounts per location (finance officer, finance manager, audit officer, audit manager) as demo STAFF records with staff
  ids `DEMO-<LOC>-FO|FM|AO|AM` (so they are valid accounts, made the way every staff record gets its user); about 25 suppliers company-wide
  (every name ends " (Demo)", some with a TIN and phone, some a name only); and 60 vouchers per location, `DEMO-<LOC>-0001`..., spread over the
  last six months: Draft, With Audit aged 0-2 / 3-7 / 8-14 / 15+ days, Queried once and twice (different reason codes), resubmitted, Audit
  acknowledged and waiting, Completed (some after a query), tick withdrawn then re-reviewed, Voided. All four payee kinds (existing staff and
  retirees of the location first, topped up with demo ones), both categories and most types, and two vouchers per location paid to demo staff
  who resign afterwards (the "payee left service" flag). The first region also gets a location-owned type, a renamed type and a switched-off type.
- **How:** through `PaymentVoucherWorkflowService`, `SupplierService` and `PvCatalogService`, acting as the demo accounts, with
  `Carbon::setTestNow` set back so events, rounds, audit rows, turnaround and ageing are genuine. Notices (and the invitation e-mail a new
  staff record would send) are suppressed for the run, and the clock and the signed-in user are put back. Existing employees and districts are
  only read; Staff data is never altered.
- **Accounts:** without `--with-users` the demo users are INACTIVE (nobody can sign in as them; they are only the actors in the history).
  With it they are active with a random password each, printed once, and `must_change_password` set.
- **Re-running** does nothing while any `DEMO-` voucher exists.
- **`--remove`** deletes ONLY: `DEMO-` vouchers with their events, attachments (files too) and reminder states (direct deletes, because the
  event model refuses them); suppliers ending " (Demo)" that no other voucher uses (those are kept and counted); location-owned catalogue rows
  named "... (Demo)" and the overrides the demo accounts made; the demo accounts, their notices, the audit rows they wrote and their staff records.
  `FinanceDemoDataTest` proves a normal run and a removal leave every non-demo row exactly as it was.
  Since change request 2 it also makes five **batches** per location and a second Audit officer (10.7).

## 10. Batches, assignment by the Audit manager, and printing (change request 2, steps F-J)

### 10.1 A batch is a container, not an approval

A batch (`fin_pv_batches`, model `FinPvBatch`) is a container for sending, assigning, tracking and printing a group of vouchers. Audit
still ticks or queries **each voucher on its own**, with its own event, actor and reason. A voucher is in at most one batch
(`fin_pvs.batch_id`) or in none; it can still be sent alone.
- **Number:** `BT-<HO or the region's letter prefix>-<year>-<NNNN>`, made by `BatchWorkflowService` under `lockForUpdate` from
  `fin_batch_counters` (per location and year), never typed. The cap is `gwl.fin_batch_max_vouchers` (100).
- **State is computed in ONE place**, `FinPvBatch::computeState()` (no SQL twin; screens, counts, exports and analytics all call it):
  Draft; Awaiting assignment (sent, and some voucher that is neither Completed nor Voided has no officer); In progress (n of m done, done =
  Completed or Voided); Completed (every voucher Completed or Voided); Cancelled. There is no stored progress. The Assignments worklist
  filter ("needs an officer now") is a worklist, not the state.
- **Rules** (`BatchWorkflowService`, the same pattern as the voucher service: permission, `lockForUpdate` re-read, authorise on that copy,
  rule, immutable `fin_batch_events` row, `AuditLog` with ids and counts only, books version and events after the commit): create from
  selected Drafts of the creator's location that are in no batch; add/remove only while the batch is a draft (membership is frozen once
  sent; a stale screen is a `PvConflictException`); send is ALL OR NOTHING (`PaymentVoucherWorkflowService::sendProblems()` is checked for
  every voucher first, one `BatchSendException` lists each failing voucher; each voucher then gets its own `submitted` event carrying the
  batch id); cancel only a draft batch, with a reason (vouchers are released, nothing is deleted). A voucher in a draft batch cannot be sent
  alone or voided (take it out first). The shared "can this voucher be batched" sentence is `FinPaymentVoucher::batchBlocker()`.
- **Existing data:** vouchers already sent when migration `2026_11_15_000001` ran have no officer, so they show as Awaiting assignment and
  are invisible to officers until the manager assigns them. Nothing is assigned automatically.

### 10.2 Assignment and what each Audit role reads

- **The manager assigns** (`audit.assign`: audit_manager, or super_admin for a location they chose). `PaymentVoucherWorkflowService::assign()`
  gives a voucher that is With Audit to an officer or moves it (an `assigned` / `reassigned` event with who, from, to and an optional note);
  `BatchWorkflowService::assignBatch()` / `assignVouchers()` do a whole batch or a selection (so one batch can be split across officers).
  Giving it to the officer who already holds it writes nothing. The officer must be an active `audit.review` holder BASED AT the voucher's
  location who did not send it to Audit in any round (`FinanceScope::assignableOfficers()`, also the picker's list).
- **Tick, query and withdraw need `audit.review` AND `assigned_to_user_id` = the actor.** No exceptions, `super_admin` included (nothing is
  ever assigned to them). An unassigned or someone else's voucher is a 404 to an officer (invisible); a voucher the officer worked on but
  that was since reassigned stays readable to them and answers "assigned to another Audit officer". A corrected voucher stays with the same
  officer (round + 1); the manager can reassign it. Separation of duties still holds (a submitter is never assignable or able to act).
- **Reach, decided only in `FinanceScope`:** an officer reads vouchers assigned to them plus any they ticked, queried or withdrew a tick on
  (`REVIEWING` events by them; a voucher they merely SENT is not theirs to read); the manager and read-only roles read vouchers an officer has
  worked on; `assignmentQueue()` / `assignmentBatches()` are the manager's list of EVERY sent voucher/batch of the location with
  `QUEUE_COLUMNS` only (no payee, details, remarks, creator or file), and never a draft. The queue is a list, not a door: opening a voucher
  still goes through `pvs()`, so the manager gets 404 on one nobody has acted on. `batches($user, $as)` is the batch twin of `pvs()` (Finance
  every status; Audit sent only, the manager and super_admin all of them, an officer only batches holding a voucher they may open).
- **Finance:** `finance_officer` sees the batches of the location and creates/sends them (`finance.create_pv`); `finance_manager` reads every
  batch read-only (no create, edit, send, cancel or acknowledge).
- **Bulk:** an officer's "Acknowledge all remaining" (`acknowledgeRemaining()`) ticks, one voucher and one event at a time, every With Audit
  voucher of the batch assigned to them; a voucher they can see but not act on is skipped and listed, ones they cannot see are only counted. A
  query is never bulk. Finance's "Acknowledge all" (`financeAcknowledgeAll()`) acknowledges the Audit-acknowledged vouchers one event each.

### 10.3 Screens

- **Finance:** the voucher list has a tick box on each row for people who can prepare vouchers (only a Draft in no batch can be ticked; a
  row that cannot says why, a tooltip and screen-reader text from `batchBlocker()`), "Select page", and "Create batch from selected" (title
  and remarks optional, count and total shown; the ids are re-read inside the user's reach). **Finance > Batches** lists batches (number, title,
  count, total, state, officers, date sent; filter by the batch's own status) and the batch page shows the vouchers with their Audit and Finance
  marks and popovers, progress "x of y", add/remove/edit/send/cancel while a draft, "Acknowledge all" after Audit, and the batch's history. A voucher
  shows its batch (a link in Finance) and its officer ("Awaiting assignment" until given).
- **Audit:** **Assignments** (the manager; the Audit tab opens here for them) lists sent batches and single vouchers with the minimal columns and
  who holds each and since when; pick vouchers and batches, pick an officer in the combobox (only eligible officers, each with their open
  workload), assign or reassign in one transaction. **Batches** and the **batch page**: the manager sees every voucher but only the minimal
  columns of one nobody has worked on ("Opens after an officer has acted on it"), assigns the whole batch or ticked vouchers; an officer sees
  only their own vouchers of it (count, total and state are theirs), with marks, popovers and "Acknowledge all remaining". **My work** is the
  officer's list (Reviewed vouchers for the manager and read-only roles).

### 10.4 Printing (`PvPrintService`, `FinancePrintController`)

Dompdf directly (A4 portrait). **All wording lives in `PvPrintService`**; the Blade view only lays out its arrays (a test checks no words are
left in the view).
- **Voucher print:** copy label ("Finance copy" / "Audit copy" by the door), location, voucher no., batch, date, payee and kind, category and type
  (effective names), details, amount in figures and words (the words reuse `LeaveLetterService::numberToWords()` per group of thousands), remarks,
  Audit officer, and the timeline by round (who sent, assigned, ticked or queried with reason and comment, who acknowledged, dates and times).
  A draft prints with a DRAFT banner, a voided one VOIDED. No attachment. Footer: printed by, date and time, "printed n times" (this print counts)
  and "Page x of y" (drawn on the canvas, `isPhpEnabled` stays off).
- **Batch print:** a cover sheet (number, title, location, date sent, state, officers, count, total, vouchers by status) and a table of every voucher
  the user may list with the Audit and Finance mark and who, and a total; `?pages=1` adds each voucher the user may open on its own page.
- **Who prints what** is the screens' own reach (`FinanceScope`), nothing wider: Finance its location (drafts included; the Finance manager reads
  the same); an officer only what they may open (a batch print counts only their vouchers and names no other officer); the manager only vouchers an
  officer has worked on (minimal rows, no payee, for the rest). Outside reach is a 404.
- **Record:** after the PDF exists, one audit row (`finance.pv_printed`, `audit.pv_printed`, `{module}.pvs_printed`, `{module}.batch_printed`: ids and
  counts only) and one added to `print_count` on every voucher and batch printed (a voucher listed only in a batch's table is not "printed"; one with
  its own page is). Over `gwl.fin_print_max_vouchers` (200) nothing is printed or counted. "Print selected" uses a one-use token (`PrintSelection`),
  never the URL, and ends in a link to open (no pop-up).

### 10.5 Analytics (`PvAnalyticsService`, still the only evaluator)

New blocks beside the unchanged ones: `assignment` (average days from sending to assignment and from assignment to the officer's first tick or query,
and the vouchers waiting for an officer now with how many are past the limit), `batches` (batches created in the period, vouchers per batch, how many
in each computed state; Audit counts sent ones only) and `officers` (per Audit officer: assigned, done, open, average days to first action, queried and
the query rate). `officers` is the Audit manager's and the Finance manager's view only (`seesOfficers()`; anyone else gets null, and the cache key
separates them); everything is location-wide totals. The Reports screens and the summary export show all three.

### 10.6 Reminders

See section 7b. `AuditSettings::UNASSIGNED_WAIT` is new (`audit_unassigned_days`, config `fin_audit_unassigned_wait_days`), changed only by the Audit
manager; the Finance set is unchanged.

### 10.7 Demo data

`finance:demo-data` now also makes a second Audit officer per location (`DEMO-<LOC>-AO2`) and five batches per location through the real services,
built from vouchers the plan already had (so the status mix and the ageing buckets are unchanged): a draft batch, one sent and awaiting assignment, one
assigned and in progress, one completed (assigned, acknowledged by the officer in bulk, acknowledged by Finance in bulk) and one split across the two
officers; some single vouchers stay unassigned. `--remove` deletes the demo batches and their events and puts the batch-number counters back, so the
next real batch is numbered as it would have been. The demo accounts are active while the history is made (an assignment needs an active officer) and
switched off at the end unless `--with-users`.

### 10.8 Decisions made that the brief did not state

The brief's `fin_payment_vouchers` is `fin_pvs`; the model is `FinPvBatch`. Assignment only while With Audit; assigning the same officer is a no-op;
a batch assignment is all-or-nothing on eligibility and skips vouchers that are not With Audit. An officer's "own events" are their tick, query and
withdraw only. A read-only `audit.view` holder reads like the manager. Only a draft batch can be cancelled. A draft voucher in a draft batch cannot be
voided. The single send now also refuses an incomplete draft (`sendProblems()`). Officers never see a batch's remarks, other officers or its history.
The tick box for printing is a second column so batching keeps its own rules. "Printed n times" is in the footer for one voucher or one batch and on each
voucher's page otherwise.
