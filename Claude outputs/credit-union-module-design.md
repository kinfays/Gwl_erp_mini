# Credit Union Module — Design Spec

Status: draft for review · Prepared: 2026-09-10, revised 2026-09-11 · Source data: `INDIVIDUAL PRIVATE.xlsx` (per-member passbook), `PRIVATE CUA LEDGER 2026.xlsx` (union master workbook), plus policy clarifications supplied by the credit union committee on 2026-09-11 (loan eligibility, interest rate, guarantors, non-staff members, interest distribution, entry fees, membership application routes)

This document analyzes the credit union's current Excel-based record-keeping and proposes a `credit_union` module for the ERP that follows the conventions already established in `CLAUDE.md` and verified directly against the current codebase (`app/Models/Permission.php`, `routes/web.php`, the Transport module's migrations, etc.).

> **2026-09-11 revision** — incorporated confirmed business rules: loans capped at 2× savings without a guarantor; guarantors must not be in arrears; loans carry 15%-per-annum straight-line interest; the association has non-staff ("associate") members who repay/contribute in cash rather than payroll deduction and are identified by a `P`-prefixed member number (e.g. `P0012`); membership can be added by an officer from the employee directory *or* an employee can apply for membership themselves; loan interest income is distributed back to members pro-rata by shares+savings holdings; a 20-cedi membership form fee and a one-time 200-cedi initial share purchase both happen at registration. See the inline changes in §2.1, §2.2, §2.3, §2.4, §2.6, and the rewritten §3/§4/§5 below.

---

## 1. What the two workbooks actually contain

### `INDIVIDUAL PRIVATE.xlsx` — one member's passbook
A single sheet named after the member's account number (`16161`), manually re-typed from the union master sheet. It has three linked sections:
- **Shares** — annual running balance (`B/F`, then one row per year, `=prev+paid_in`).
- **Savings** — monthly ledger: date/month label, paid in, withdrawal, running balance (`=D_prev+paid_in-withdrawal`), with occasional `INT.` (interest) rows folded into the same column.
- **Loans** — date, loaned, repaid, running balance, interest paid.

This file is one of what is presumably 100+ near-identical workbooks (one per member), each hand-maintained in parallel with the union's own master ledger.

### `PRIVATE CUA LEDGER 2026.xlsx` — the union's master workbook
Nine sheets, each a different slice of the same underlying activity:

| Sheet | Rows | What it is |
|---|---|---|
| `REGISTRATION` | ~10 | New members: staff #, name, entrance fee, month |
| `BANK DRAWINGS` | ~20 | Cash the union has withdrawn from its own bank account, with a "PIOUS" (bank charge) note per entry |
| `LEDGER 2026` | 126 | **The core matrix**: one row per member, one column per month (Jul'25–Jul'26), each cell = that member's share/savings deduction that month, `TOTAL` = row sum |
| `LOAN DEDUCTIONS 2020-2026` | 67 | Same matrix shape for loans: opening balance, interest, monthly repayment columns, `TOTAL`, `DIFF. LEFT` (outstanding) |
| `CHEQUE REGISTER` | 794 | Cheques received — both individual member payments *and* the bulk monthly payroll-deduction remittance from GWCL (e.g. `GCB 659863 · GWCL · JULY'25 DEDUCTIONS · 220,686.27`) |
| `CASH SHEET` | 311 | Cash received directly from members (mostly loan top-up payments) |
| `WITHDRAWALS` | 437 | Savings/shares withdrawal payouts: month, staff #, name, savings amount, shares amount |
| `REFUNDS` | 13 | Corrections for wrongly-deducted amounts, credited back to a member |
| `Sheet1` | 8 | An ad-hoc one-off breakdown supporting a `REFUNDS` line — not a real system sheet |

**How money actually flows today:** GWCL deducts each member's monthly shares/savings/loan-repayment contribution from payroll and remits it to the union in one lump sum (a bank drawing / cheque, e.g. the `GCB 659863` line). Someone at the union then manually splits that lump sum back out, member by member, into `LEDGER 2026` and `LOAN DEDUCTIONS`, *and* into that member's personal passbook file — three (or more) manual re-entries of the same figure, with no system tying them together.

### Problems this creates (why "a better way" matters)
1. **No single source of truth.** The same monthly figure is typed into the master ledger, the loan ledger, and a separate per-member file. They already show signs of drifting (blank cells where a deduction was clearly expected but never recorded, e.g. `ANIM DOREEN`, `ADDO-MFODWO BENJAMIN` at 0 for the whole period).
2. **No reconciliation.** Nothing checks that what the union *received* from GWCL in a given month (`BANK DRAWINGS` / the bulk cheque) equals what was actually *posted* to members that month. A shortfall or double-post would only surface by accident.
3. **Fragile formulas.** Running balances are per-row Excel formulas (`=D14+B15-C15`); inserting or deleting a row anywhere breaks the chain silently, and several rows already lack their balance formula.
4. **No accountability.** Any edit to any cell is untraceable — no who, no when, no before/after.
5. **Free-text matching.** `REFUNDS` has rows with no ID at all, just a name typed in ("ACCRA EAST"), because there's no structured link back to a member record.
6. **No workflow.** Loan approval, withdrawal approval, and refunds are just numbers typed into cells, with no approval trail.
7. **Redundant manual labor.** A member's personal passbook file is hand-maintained in lockstep with the master ledger, purely to give that member their own statement — a report the system should generate on demand instead.

---

## 2. Proposed module: `credit_union`

Verified against the actual codebase rather than assumed — module slug conventions, migration patterns, and the module-access/permission seeding approach below are copied directly from `2026_06_13_000001_create_transport_module_tables.php` / `..._000002_seed_transport_module_access.php`, the most recent precedent for a brand-new module.

### 2.1 Permissions & roles

Add to `app/Models/Permission.php`:
```php
public const MODULE_CREDIT_UNION = 'credit_union';
// ...append to MODULES
```

Permission slugs (module = `credit_union`):

| Slug | Purpose |
|---|---|
| `credit_union.view_dashboard` | Union-wide stats dashboard |
| `credit_union.view_own_statement` | Any employee views/downloads **their own** passbook |
| `credit_union.apply_membership` | Any employee applies to join the union themselves (self-service) |
| `credit_union.manage_members` | Register members from the employee directory, manually enter associate members, approve self-applied membership applications, edit members, membership fee/initial share, exit |
| `credit_union.manage_deductions` | Upload/preview/post monthly payroll deduction batches (staff members only) |
| `credit_union.manage_loans` | Apply for / record loans, submit guarantor requests |
| `credit_union.approve_loans` | Approve/reject loan applications and guarantor requests (the loan committee) |
| `credit_union.manage_withdrawals` | Log withdrawal requests |
| `credit_union.approve_withdrawals` | Approve/reject + mark paid |
| `credit_union.manage_refunds` | Record refunds |
| `credit_union.manage_receipts` | Log direct cash/cheque payments (the only contribution/repayment path for associate members) |
| `credit_union.manage_interest_distribution` | Compute an annual interest-distribution run |
| `credit_union.approve_interest_distribution` | Approve and post an interest-distribution run |
| `credit_union.view_reports` | Reconciliation & summary reports |
| `credit_union.export_reports` | Excel/PDF export |
| `credit_union.manage_settings` | Interest rate, loan multiple, fees, etc. |

New roles (mirrors how Transport added `transport_manager` + `driver`): `credit_union_officer` (day-to-day entry: members, deductions, receipts, refunds, membership-application approval) and `credit_union_committee` (approvals: loans, guarantor requests, withdrawals, interest distribution, reports — the loan committee every SACCO/credit-union has). Every employee gets `credit_union.view_own_statement` *and* `credit_union.apply_membership` by default, the same way Transport gave every `employee` role `transport.view_own_vehicle` — this is what lets any employee apply to join without needing an officer to go find them first, and replaces the personal Excel file once they're a member. Associate (non-staff) members are not `users`/`employees` in this ERP at all, so their self-service statement access needs a decision — see the open question in §3.

`super_admin` continues to bypass everything, per the existing layered-authorization pattern.

### 2.2 Data model

One deliberate simplification vs. the spreadsheets: **shares and savings are modeled as one ledger table** with an `account_type` column, not two tables. The two spreadsheets already treat them as structurally identical (date, in, out, running balance) — collapsing them avoids duplicating every rule twice.

```
credit_union_members
  id, member_type (string: staff|associate, indexed — associate = a member of the association who
    is not GWL staff; drives which contribution/repayment sources are even allowed, see below)
  member_number (string, unique, indexed — the account/ID number shown on statements. For
    member_type=staff this defaults to the linked employee's staff_id. For member_type=associate,
    MemberRegistrationService assigns the next sequential P-prefixed code — P0001, P0002, ... —
    since associates have no staff_id to key off of)
  employee_id (FK employees, nullable, nullOnDelete, unique — null for associate members, who have
    no employees row at all)
  staff_id (string, nullable, indexed — denormalized from employees.staff_id for import matching;
    null for associate members)
  full_name, phone, address (string, nullable — associate members need these captured directly
    since there's no employee record to pull them from)
  legacy_account_number (string, nullable, unique — maps old passbook account #s during migration)
  application_source (string: hr_added|self_applied|associate_manual, default hr_added, indexed
    — hr_added: an officer picked the member from the employee directory, goes straight to active;
    self_applied: the employee submitted their own application via /credit-union/apply, starts at
    status=pending until an officer/committee member approves it; associate_manual: an officer
    hand-entered a non-staff associate, goes straight to active since an officer already vetted it)
  applied_by (FK users, nullable, nullOnDelete — who submitted a self_applied application)
  approved_by (FK users, nullable, nullOnDelete), approved_at (timestamp, nullable — set when a
    pending self-applied membership is approved)
  status (string: pending|active|inactive|exited, default active — default is overridden to
    `pending` by MemberRegistrationService specifically for application_source=self_applied)
  registered_at (date), exited_at (date, nullable), exit_reason (string, nullable)
  membership_form_fee_amount (decimal 15,2, default from config — the 20-cedi form fee; a
    non-refundable admin charge, not a member asset, so it is recorded here and NOT posted to the
    ledger), membership_form_fee_paid_at (date, nullable)
  initial_share_amount (decimal 15,2, default from config — the one-time 200-cedi initial share;
    unlike the form fee this IS a member asset, so MemberRegistrationService auto-posts it as a
    `shares` ledger entry at registration), initial_share_paid_at (date, nullable)
  default_monthly_savings_amount, default_monthly_shares_amount (decimal 15,2, nullable
    — the member's standing deduction plan; lets the import step flag "expected but missing" or
    "amount doesn't match plan" instead of silently accepting a blank cell like today)
  created_by (FK users, nullOnDelete)
  timestamps, soft deletes

credit_union_ledger_entries        -- replaces the SHARES + SAVINGS sections/sheets
  id, member_id (FK credit_union_members, cascadeOnDelete)
  account_type (string: shares|savings, indexed)
  entry_type (string: contribution|withdrawal|interest|adjustment|refund, indexed
    — the one-time 200-cedi initial share is posted as a `contribution` on account_type=shares
    with remarks "Initial share purchase at registration"; `interest` covers both loan-interest
    proportional distributions (see credit_union_interest_distributions) and any savings interest)
  amount (decimal 15,2), balance_after (decimal 15,2 — service-maintained, not a spreadsheet formula)
  transaction_date (date, indexed)
  source (string: payroll_deduction|cash|cheque|manual_adjustment)
  deduction_batch_id (FK credit_union_deduction_batches, nullable, nullOnDelete)
  withdrawal_id (FK credit_union_withdrawal_requests, nullable, nullOnDelete)
  refund_id (FK credit_union_refunds, nullable, nullOnDelete)
  reference_no (string, nullable), remarks (text, nullable)
  recorded_by (FK users, nullOnDelete)
  timestamps
  index [member_id, account_type, transaction_date]

credit_union_deduction_batches     -- replaces BANK DRAWINGS + the implicit monthly remittance
  id, period_month (date, first-of-month)
  bank_reference (string, nullable — e.g. "GCB 659863")
  banked_date (date, nullable)
  amount_received (decimal 15,2), amount_posted (decimal 15,2, default 0, service-maintained)
  status (string: draft|imported|posted|reconciled|variance, default draft, indexed)
  import_file_path (string, nullable)
  imported_by / imported_at, posted_by / posted_at
  notes (text, nullable)
  timestamps

credit_union_deduction_batch_lines -- replaces one member's cell-set across LEDGER 2026 + LOAN DEDUCTIONS
  id, deduction_batch_id (FK, cascadeOnDelete)
  member_id (FK credit_union_members, nullable, nullOnDelete — null = unmatched, needs manual resolution)
  staff_id_raw (string), name_raw (string, nullable)   -- exactly what was in the uploaded file
  shares_amount, savings_amount, loan_repayment_amount (decimal 15,2, default 0)
  match_status (string: matched|unmatched|skipped|invalid_associate_member, default matched, indexed
    — `invalid_associate_member` is set when a payroll file row matches a member whose
    member_type=associate, since associates are never on GWL payroll; DeductionPostingService
    refuses to post these and surfaces them for manual correction instead)
  resolution_notes (text, nullable)
  timestamps

credit_union_loans                 -- replaces LOAN DEDUCTIONS' opening-balance columns
  id, member_id (FK credit_union_members, restrictOnDelete)
  loan_number (string, unique)
  principal_amount, interest_amount, total_repayable (decimal 15,2)
  interest_rate (decimal 5,2 — from config, default 15.00% per annum; stored per loan so a future
    policy change never rewrites the interest on an already-disbursed loan)
  term_months (unsigned smallint)
  monthly_installment_amount (decimal 15,2 — total_repayable / term_months, straight-line/equal
    installments per the confirmed "straight line method")
  savings_balance_at_application (decimal 15,2 — snapshot of the member's savings ledger balance
    when they applied, kept for audit since the balance moves over time)
  requires_guarantor (boolean, default false — true when principal_amount exceeds
    savings_balance_at_application × config('gwl.credit_union_loan_multiple_without_guarantor', 2))
  status (string: pending|awaiting_guarantor|approved|rejected|disbursed|active|completed|defaulted,
    default pending, indexed)
  purpose (text, nullable)
  applied_at, applied_by; approved_by/approved_at, rejection_reason
  disbursed_at, disbursement_reference
  outstanding_balance (decimal 15,2 — service-maintained, replaces the "DIFF. LEFT" column)
  timestamps
  index [member_id, status]

credit_union_loan_guarantors        -- new: the guarantor path required beyond 2x savings
  id, loan_id (FK credit_union_loans, cascadeOnDelete)
  guarantor_member_id (FK credit_union_members, restrictOnDelete — must be an existing member)
  guaranteed_amount (decimal 15,2, nullable — the portion of the loan being guaranteed, if the
    committee allows partial guarantees; see open question in §3)
  status (string: pending|accepted|declined, default pending, indexed)
  eligibility_checked_at (timestamp, nullable), was_in_good_standing (boolean, nullable — snapshot
    of MemberEligibilityService's arrears check at the time they were added as guarantor, so a
    guarantor who later falls into arrears doesn't retroactively invalidate an already-approved loan)
  responded_at (timestamp, nullable)
  timestamps
  unique [loan_id, guarantor_member_id]

credit_union_loan_repayments       -- replaces LOAN DEDUCTIONS' monthly columns + CASH SHEET "LOAN" rows
  id, loan_id (FK credit_union_loans, cascadeOnDelete)
  amount (decimal 15,2), repayment_date (date, indexed)
  source (string: payroll_deduction|cash|cheque — payroll_deduction only valid when the loan's
    member is member_type=staff)
  deduction_batch_id (FK, nullable, nullOnDelete)
  reference_no (string, nullable), balance_after (decimal 15,2)
  recorded_by (FK users, nullOnDelete)
  timestamps

credit_union_interest_distributions        -- new: annual pro-rata distribution of loan interest income
  id, period_label (string, e.g. "2025/2026" — matches the fiscal-year style already used in the
    REFUNDS sheet header)
  total_interest_pool (decimal 15,2 — total loan interest income being distributed for the period)
  credit_account_type (string: shares|savings, default savings — which ledger account the
    distribution is credited into; see open question in §3)
  status (string: draft|computed|approved|posted, default draft, indexed)
  computed_by/computed_at, approved_by/approved_at, posted_by/posted_at
  notes (text, nullable)
  timestamps

credit_union_interest_distribution_lines
  id, interest_distribution_id (FK credit_union_interest_distributions, cascadeOnDelete)
  member_id (FK credit_union_members, cascadeOnDelete)
  asset_balance_at_computation (decimal 15,2 — the member's savings+shares balance snapshot used
    to compute their proportional share, per the confirmed "distributed proportionally according
    to a member's asset holdings (both savings and shares)" rule)
  share_of_pool_percent (decimal 7,4)
  amount (decimal 15,2)
  ledger_entry_id (FK credit_union_ledger_entries, nullable, nullOnDelete — set once posted)
  timestamps

credit_union_withdrawal_requests   -- replaces the WITHDRAWALS sheet, as an actual workflow
  id, member_id (FK credit_union_members, restrictOnDelete)
  savings_amount, shares_amount (decimal 15,2, default 0)
  reason (text, nullable)
  status (string: pending|approved|rejected|paid, default pending, indexed)
  requested_by/requested_at; decided_by/decided_at, rejection_reason
  payment_method (string: cash|cheque|bank_transfer, nullable), payment_reference, paid_at
  timestamps

credit_union_refunds               -- replaces REFUNDS (+ absorbs the ad-hoc Sheet1 pattern)
  id, member_id (FK credit_union_members, restrictOnDelete)
  amount (decimal 15,2), reason (string), refunded_at (date)
  recorded_by (FK users, nullOnDelete)
  timestamps

credit_union_manual_receipts       -- replaces CASH SHEET + the per-member rows of CHEQUE REGISTER
  id, member_id (FK credit_union_members, nullable, nullOnDelete)
  method (string: cash|cheque), purpose (string: savings|shares|loan_repayment|membership_form_fee)
  cheque_no (string, nullable), payer_name (string, nullable — free-text fallback)
  amount (decimal 15,2), received_date (date), banked_date (date, nullable), banked (boolean, default false)
  recorded_by (FK users, nullOnDelete), remarks (text, nullable)
  timestamps
```

**Note on the bulk GWCL remittance cheque**: `CHEQUE REGISTER` mixes individual member cheques (e.g. `DONKOR EVANS · JULY'25 SAVINGS · 500`) with the one bulk monthly payroll-deduction cheque (`GWCL · JULY'25 DEDUCTIONS · 220,686.27`). Only the former belongs in `credit_union_manual_receipts`; the bulk cheque *is* a `credit_union_deduction_batches` row's `bank_reference`/`banked_date`/`amount_received`. Keeping these separate is what makes the reconciliation in 2.4 possible — today they're indistinguishable rows in one sheet.

**Note on associate (non-staff) members**: confirmed — members of the association who are not GWL staff pay everything (contributions and loan repayments) in cash, deposited directly into the association's bank account; they are never in a payroll deduction file, and are identified by a `P`-prefixed `member_number` (e.g. `P0012`) rather than a staff ID. Every ledger posting for an associate member therefore goes through `credit_union_manual_receipts`/`credit_union_loan_repayments` with `source=cash`, never through `credit_union_deduction_batches`. The member-registration and repayment screens should hide the "payroll deduction" option entirely when `member_type=associate`, and `DeductionPostingService` treats any payroll-file row matching an associate member as an error (`invalid_associate_member`), not a normal post.

**Note on how someone becomes a member**: two paths for staff, one for associates. (1) An officer with `credit_union.manage_members` browses the employee directory (reusing `Services/Staff/EmployeeDirectory`, the same role-scoped visibility service UAC's employee search already uses) and registers a chosen employee directly — `application_source=hr_added`, immediately `active`. (2) Any employee with the baseline `credit_union.apply_membership` permission can submit their own application from `/credit-union/apply` — `application_source=self_applied`, starts at `status=pending` and only becomes `active` once an officer/committee member approves it from the applications queue. (3) An officer manually keys in a non-staff associate's details — `application_source=associate_manual`, immediately `active` (an officer already vetted it by entering it).

All financial child tables use `cascadeOnDelete` on `member_id`/`loan_id` (per `CLAUDE.md`'s "child/detail rows" rule); `credit_union_members` itself uses soft deletes, so financial history can never silently vanish — a deliberate, documented exception to "only `Vehicle` uses `SoftDeletes`," justified because this is money, not equipment. `employee_id` is `nullOnDelete` rather than `restrictOnDelete` (a change from the earlier draft) because it's now nullable for associate members; a staff member's underlying `employees` row is restricted from deletion elsewhere in the app anyway (employees are deactivated, not deleted), so this doesn't weaken protection in practice.

Every mutating action (member registration, application approval, batch posting, loan approval, withdrawal payout, refund) calls `Audit::log(...)` / `AuditLog::record(...)` with `module: 'credit_union'`, exactly like every other module.

### 2.3 Services (`app/Services/CreditUnion/`)

Following the "controllers/Livewire stay thin" rule:

- `MemberRegistrationService` — create a member via any of the three paths in the note above (officer-picked from the employee directory, employee self-application, or manually-entered associate). Assigns `member_number`: the employee's `staff_id` for staff members, or the next sequential `P####` code (e.g. `P0012`) for associates — a simple `max + 1` lookup scoped to `member_type=associate` is sufficient given expected volumes. Captures the 20-cedi membership form fee (recorded, not ledgered) and auto-posts the 200-cedi initial share as a `shares` ledger entry once the member is `active` (i.e. immediately for hr_added/associate_manual, or on approval for self_applied).
- `MembershipApplicationService` — the approve/reject step for `status=pending` (`application_source=self_applied`) members: on approval, flips `status` to `active`, stamps `approved_by`/`approved_at`, and triggers `MemberRegistrationService`'s fee/initial-share posting; on rejection, leaves a note and the member row inactive.
- `LedgerService` — post a ledger entry and maintain `balance_after` transactionally (replaces per-row Excel `SUM` formulas).
- `MemberEligibilityService` — the arrears/"good standing" check: is a member active and free of any loan with an overdue installment. Used both to gate who can be a guarantor and, more generally, anywhere "good standing" matters. The exact overdue threshold (e.g. one missed monthly installment vs. a grace period) is an open question — see §3.
- `DeductionImportService` — parse an uploaded payroll-deduction file, preview/validate against `config('gwl.max_import_failure_percent')` (reusing the existing threshold pattern from `Services/Import/DataImportService`), match rows to members by `staff_id`.
- `DeductionPostingService` — transactionally fan a validated batch out into `credit_union_ledger_entries` + `credit_union_loan_repayments`, update `amount_posted`, flag `reconciled` vs `variance` against `amount_received`, and reject any row matched to an `associate` member as `invalid_associate_member`.
- `LoanService` — apply, approve/reject, disburse, record repayments, maintain `outstanding_balance`. Confirmed rules baked in:
  - **Eligibility cap**: a loan up to `savings_balance_at_application × config('gwl.credit_union_loan_multiple_without_guarantor', 2)` needs no guarantor; above that, at least one `accepted` `credit_union_loan_guarantors` row from a guarantor who passes `MemberEligibilityService` (not in arrears, active/good standing) is required before the loan can move past `awaiting_guarantor`.
  - **Interest**: straight-line at `config('gwl.credit_union_loan_annual_interest_rate_percent', 15.0)` per annum — `interest_amount = principal_amount × (annual_rate / 100) × (term_months / 12)`, `total_repayable = principal_amount + interest_amount`, `monthly_installment_amount = total_repayable / term_months`. The rate is captured onto the loan row at application time so a later policy change never rewrites an existing loan's terms.
  - **Repayment source**: `payroll_deduction` only for `member_type=staff` loans; `associate` members repay via `credit_union_manual_receipts`/cash only.
- `WithdrawalService` — request, approve/reject, validate against the member's actual ledger balance (something Excel cannot enforce), mark paid.
- `RefundService` — record a refund, post the offsetting ledger entry.
- `InterestDistributionService` — for a period: sum loan interest income into `total_interest_pool`, snapshot every active member's shares+savings balance, compute each member's proportional share (`asset_balance_at_computation / sum(all members' asset_balance_at_computation)`), write `credit_union_interest_distribution_lines`, and on approval post each line as an `interest` ledger entry against `credit_account_type`.
- `StatementService` — build a member's shares + savings + loans statement (the digital, always-current replacement for `INDIVIDUAL PRIVATE.xlsx`) and render it to PDF via Dompdf, matching the Visitors/Transport export pattern.
- `ReconciliationReportService` — batch totals vs. postings vs. bank drawing, surfaced as a report instead of manual eyeballing.

### 2.4 Screens (`app/Livewire/CreditUnion/`) and routes

Route group, following the exact shape used by every other module in `routes/web.php`:

```php
Route::middleware(['auth', 'active', 'module:credit_union'])
    ->prefix('credit-union')
    ->name('credit-union.')
    ->group(function () {
        Route::get('/', [CreditUnionModuleController::class, 'home'])
            ->middleware('permission:credit_union.view_dashboard,credit_union.view_own_statement')
            ->name('home');

        Route::get('/statement', [CreditUnionModuleController::class, 'ownStatement'])
            ->middleware('permission:credit_union.view_own_statement')
            ->name('statement');
        Route::get('/statement/pdf', [CreditUnionModuleController::class, 'ownStatementPdf'])
            ->middleware('permission:credit_union.view_own_statement')
            ->name('statement.pdf');

        Route::get('/apply', [CreditUnionModuleController::class, 'apply'])
            ->middleware('permission:credit_union.apply_membership')->name('apply');
        Route::post('/apply', [CreditUnionModuleController::class, 'submitApplication'])
            ->middleware('permission:credit_union.apply_membership')->name('apply.store');

        Route::middleware('permission:credit_union.manage_members')->group(function () {
            Route::get('/members', [CreditUnionModuleController::class, 'members'])->name('members');
            Route::get('/members/applications', [CreditUnionModuleController::class, 'applications'])->name('members.applications');
            Route::get('/members/{member}', [CreditUnionModuleController::class, 'memberShow'])->name('members.show');
            Route::get('/members/{member}/statement/pdf', [CreditUnionModuleController::class, 'memberStatementPdf'])->name('members.statement.pdf');
        });

        Route::get('/deductions', [CreditUnionModuleController::class, 'deductions'])
            ->middleware('permission:credit_union.manage_deductions')->name('deductions');
        Route::get('/deductions/{batch}', [CreditUnionModuleController::class, 'deductionBatchShow'])
            ->middleware('permission:credit_union.manage_deductions')->name('deductions.show');

        Route::get('/loans', [CreditUnionModuleController::class, 'loans'])
            ->middleware('permission:credit_union.manage_loans,credit_union.approve_loans')->name('loans');
        Route::get('/loans/{loan}', [CreditUnionModuleController::class, 'loanShow'])
            ->middleware('permission:credit_union.manage_loans,credit_union.approve_loans')->name('loans.show');

        Route::get('/interest-distributions', [CreditUnionModuleController::class, 'interestDistributions'])
            ->middleware('permission:credit_union.manage_interest_distribution,credit_union.approve_interest_distribution')
            ->name('interest-distributions');

        Route::get('/withdrawals', [CreditUnionModuleController::class, 'withdrawals'])
            ->middleware('permission:credit_union.manage_withdrawals,credit_union.approve_withdrawals')->name('withdrawals');

        Route::get('/refunds', [CreditUnionModuleController::class, 'refunds'])
            ->middleware('permission:credit_union.manage_refunds')->name('refunds');

        Route::get('/receipts', [CreditUnionModuleController::class, 'receipts'])
            ->middleware('permission:credit_union.manage_receipts')->name('receipts');

        Route::get('/reports', [CreditUnionModuleController::class, 'reports'])
            ->middleware('permission:credit_union.view_reports')->name('reports');
        Route::get('/reports/export/excel', [CreditUnionModuleController::class, 'reportExcel'])
            ->middleware('permission:credit_union.export_reports')->name('reports.export.excel');
        Route::get('/reports/export/pdf', [CreditUnionModuleController::class, 'reportPdf'])
            ->middleware('permission:credit_union.export_reports')->name('reports.export.pdf');
    });
```

Each `GET` route wraps a Livewire component, matching how `TransportModuleController`/`AssetModuleController` delegate to `resources/views/livewire/credit-union/*`. `app/Support/ErpNavigation.php` needs the new module + its sidebar entries added, following its existing per-module pattern (a 25KB file — read it directly when implementing rather than guessing its exact structure).

### 2.5 Migrations

Two migrations, mirroring `2026_06_13_000001_create_transport_module_tables.php` and `..._000002_seed_transport_module_access.php` exactly:
- `2026_09_10_000001_create_credit_union_module_tables.php` — all thirteen tables above (members, ledger entries, deduction batches + lines, loans, loan guarantors, loan repayments, withdrawal requests, refunds, manual receipts, interest distributions + lines), `Schema::hasTable()`-guarded, explicit FK delete behavior as specified.
- `2026_09_10_000002_seed_credit_union_module_access.php` — insert `credit_union_officer`/`credit_union_committee` roles, all `credit_union.*` permissions, `module_access` rows per existing role (mostly `false`, `true` for the two new roles + `super_admin`; `employee` gets module access `true` so self-service statement viewing and applying work), and `role_permissions` mapping — copy the `updateOrInsert`/`insertOrIgnore` structure from the Transport seeding migration verbatim. `employee` gets `credit_union.view_own_statement` and `credit_union.apply_membership` specifically (mirroring how Transport gave `employee` only `transport.view_own_vehicle`, not the manager permissions).

### 2.6 Config

Add to `config/gwl.php` (not `gwcl.php`, per the "prefer `gwl.php` for new settings" rule) — values below are the confirmed policy figures, not the earlier draft's guesses:
```php
'credit_union_loan_annual_interest_rate_percent' => (float) env('GWL_CREDIT_UNION_LOAN_INTEREST_RATE', 15.0),
'credit_union_loan_multiple_without_guarantor' => (float) env('GWL_CREDIT_UNION_LOAN_MULTIPLE_WITHOUT_GUARANTOR', 2),
'credit_union_membership_form_fee' => (float) env('GWL_CREDIT_UNION_MEMBERSHIP_FORM_FEE', 20),
'credit_union_initial_share_amount' => (float) env('GWL_CREDIT_UNION_INITIAL_SHARE_AMOUNT', 200),
```

### 2.7 Testing

`tests/Feature/CreditUnion/*`, `Livewire::test()` + `RefreshDatabase` per convention. Unlike the older modules, I'd recommend **factories** for these models (`CreditUnionMemberFactory`, `CreditUnionLoanFactory`, etc.) rather than inline builders — the ledger/interest/balance math benefits from generated data the way Transport's tests already do, and Transport is the closest precedent for "new module gets factories."

---

## 3. Open questions to confirm before/while building

The 2026-09-11 policy clarifications resolved most of the previous round of questions (guarantors, interest method, dividends, loan eligibility cap, associate member IDs, membership application routes — all now specified above). What's still genuinely open:

1. **"Not in arrears" / "good standing" threshold** — confirmed that a guarantor must not be in arrears, but not *how much* arrears disqualifies them: one missed monthly installment, a grace period (e.g. 30/60 days overdue), or something else. This drives `MemberEligibilityService` and should be nailed down before that service is built.
2. **Partial guarantees** — can multiple guarantors split a single loan's excess-over-2x-savings amount (hence `guaranteed_amount` being nullable on `credit_union_loan_guarantors`), or must one guarantor cover the entire amount above the cap?
3. **Interest distribution cadence and destination account** — confirmed the *proportion* (by combined shares+savings holdings) but not how often a distribution runs (annually at fiscal year-end, seems likely given the "2025/2026" period labels already in the sheets) or which account it's credited into (`credit_union_interest_distributions.credit_account_type` defaults to `savings` above — confirm, or whether it should split across both accounts in the same proportion as the member's existing holdings).
4. **Associate member self-service** — `credit_union.view_own_statement` currently rides on an existing `employees`/`users` login. Associate members have neither, so they can't self-apply via `/credit-union/apply` either — for them, `application_source=associate_manual` (an officer keys them in) is the only path in this design. Either associates get a lightweight portal login of their own for statement access, or (simpler for a first cut) union staff print/email their statement on request and associate self-service is deferred.
5. **Membership application approval scope** — is `credit_union.manage_members` (any officer) sufficient to approve a self-applied membership, or should that specific step require the committee (`credit_union.approve_loans`-style dual control), given it's the entry point for who's allowed to hold union assets at all?
6. **Interest income source of truth** — `InterestDistributionService.total_interest_pool` needs a defined source: presumably `sum(credit_union_loans.interest_amount)` for interest actually *collected* (via repayments) in the period, not merely accrued/billed. Worth confirming which the committee means.
7. **Existing records migration** — migrating `LEDGER 2026`, `LOAN DEDUCTIONS 2020-2026`, and the per-member passbook files into the new tables (including backfilling `member_type`, `member_number` for existing staff accounts, historical loan interest rates that predate the 15% policy, and the legacy ~3%-rate loans visible in the sample data) is a one-time data-import job, not covered in this design; worth scoping separately once the schema is agreed.

---

## 4. Suggested phased delivery

1. **Foundation** — migrations, permission/role/module-access seeding, `credit_union_members` CRUD covering all three registration paths (officer-added from the employee directory, employee self-application with a `pending` approval queue, manually-entered associates with `P`-prefixed member numbers), membership form fee capture, auto-posted initial share, manual ledger entry (deposit/withdrawal) screens, on-demand PDF statement (already a major upgrade: one source of truth + a statement nobody has to hand-maintain).
2. **Deduction import & reconciliation** — the batch upload/match/post pipeline (`DeductionImportService`/`DeductionPostingService`, including the associate-member rejection rule), reconciliation report. This is the highest-value phase — it's what eliminates the triple-retyping.
3. **Loans** — application, the 2×-savings/guarantor eligibility check, guarantor request/accept workflow, committee approval, disbursement, 15%-straight-line repayment tracking (both payroll-deduction for staff and cash for associates).
4. **Withdrawals, refunds, manual receipts** — the remaining workflows.
5. **Interest distribution** — the annual pro-rata run (`InterestDistributionService`), once §3's cadence/destination-account questions are settled.
6. **Reporting & exports** — dashboard, Excel/PDF exports, reconciliation dashboard.

---

## 5. Phase 1 kickoff prompt (ready to paste into Claude Code)

```
Implement Phase 1 of the Credit Union module for this ERP, per the design in
credit-union-module-design.md (sections 2.1, 2.2 tables: credit_union_members and
credit_union_ledger_entries only for this phase, 2.5, 2.6). Follow CLAUDE.md
conventions throughout — in particular:

- Model the two new migrations exactly on
  database/migrations/2026_06_13_000001_create_transport_module_tables.php and
  2026_06_13_000002_seed_transport_module_access.php (Schema::hasTable guards,
  explicit FK delete behavior, updateOrInsert-based role/permission/module_access
  seeding inside the migration).
- Add Permission::MODULE_CREDIT_UNION to app/Models/Permission.php's MODULES array.
- Flat models under app/Models/ (CreditUnionMember.php, CreditUnionLedgerEntry.php)
  — do not nest them in a CreditUnion/ subfolder.
- Controller in app/Http/Controllers/CreditUnion/, Livewire components in
  app/Livewire/CreditUnion/, service classes app/Services/CreditUnion/LedgerService.php
  and app/Services/CreditUnion/MemberRegistrationService.php.
- Add the route group to routes/web.php following the existing module block shape
  (see the transport. and staff. groups for the pattern), including /apply,
  /apply (POST), /members, and /members/applications for this phase.
- Register the module in app/Support/ErpNavigation.php following its existing
  per-module pattern.
- Use both EnforcesModuleAccess traits (controller-level AND Livewire-level) on the
  new controller/components, per the belt-and-suspenders pattern CLAUDE.md describes.
- Call Audit::log(...) on member creation, application approval, and every ledger
  entry post.
- Add config/gwl.php entries for credit_union_membership_form_fee (default 20) and
  credit_union_initial_share_amount (default 200), env-driven, per existing gwl.php
  style. (Leave credit_union_loan_annual_interest_rate_percent and
  credit_union_loan_multiple_without_guarantor for the Loans phase — not needed yet.)
- Write tests/Feature/CreditUnion/MemberRegistrationTest.php,
  MembershipApplicationTest.php, and LedgerEntryPostingTest.php using
  Livewire::test() + RefreshDatabase; add database/factories/CreditUnionMemberFactory.php
  (and a ledger-entry factory) since this is a new module — follow the Transport
  module's factory style rather than the older modules' inline-builder style. Cover:
  officer-added staff members (linked to an employee, member_number = staff_id,
  status=active immediately); employee self-applications (status=pending until an
  officer approves, at which point the initial share posts); manually-entered
  associate members (no employee link, member_number auto-assigned as the next
  P#### code, status=active immediately); and assert the initial share auto-posts
  as a `shares` ledger entry while the membership form fee does NOT create a
  ledger entry.

Scope for this phase only: member registration/edit via all three paths described
above (officer picks from the employee directory / employee self-applies and awaits
approval / officer manually enters an associate with a P-prefixed member number);
registration captures the membership form fee (recorded on the member, not
ledgered) and auto-posts the one-time initial share as a shares ledger entry once
a member is active. Also build manual ledger entry (deposit/withdrawal, shares or
savings — cash/cheque source only for associate members), member list + detail
view, a pending-applications queue for officers, and a PDF statement (shares +
savings only — loans come in a later phase) via Dompdf, matching the export
pattern already used for Transport reports. Do not build the deduction-batch
import, loans/guarantors, withdrawals, refunds, manual receipts, or interest
distribution yet — those are later phases.
```

---

*Prepared from a direct read of `app/Models/Permission.php`, `app/Models/ModuleAccess.php`, `app/Models/AuditLog.php`, `app/Support/Audit.php`, both `EnforcesModuleAccess` traits, `config/gwl.php`/`gwcl.php`, `routes/web.php`, `app/Models/Employee.php`, and the Transport module's two migrations, via the linked device bridge into `C:\laragon\www\erp_project`.*
