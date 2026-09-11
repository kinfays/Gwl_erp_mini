# Credit Union Module — Design Spec

Status: draft for review · Prepared: 2026-09-10, revised 2026-09-11 (two rounds) · Source data: `INDIVIDUAL PRIVATE.xlsx` (per-member passbook), `PRIVATE CUA LEDGER 2026.xlsx` (union master workbook), plus policy clarifications supplied by the credit union committee on 2026-09-11

This document analyzes the credit union's current Excel-based record-keeping and proposes a `credit_union` module for the ERP that follows the conventions already established in `CLAUDE.md` and verified directly against the current codebase (`app/Models/Permission.php`, `routes/web.php`, the Transport module's migrations, etc.).

> **2026-09-11, round 1** — loans capped at 2× savings without a guarantor; guarantors must not be in arrears; loans carry 15%-per-annum straight-line interest; the association has non-staff ("associate") members who repay/contribute in cash and are identified by a `P`-prefixed member number (e.g. `P0012`); membership can be added by an officer from the employee directory *or* an employee can apply themselves; loan interest income is distributed back to members pro-rata by shares+savings holdings; a 20-cedi membership form fee and a one-time 200-cedi initial share purchase both happen at registration.

> **2026-09-11, round 2** — answers to the six open questions from round 1, now folded into §2.1–§2.3 below and removed from §3: (1) a guarantor's "good standing" is now concretely defined — disqualified if they're currently servicing a loan of their own, or if their own shares+savings balance is less than the sum they'd be guaranteeing; (2) multiple guarantors can split a loan's shortfall between them; (3) the interest-distribution snapshot is each member's combined shares+savings balance **at fiscal year-end**, i.e. an annual run; (4) confirmed — no self-service portal for associate members; an officer prints/emails their statement on request; (5) approvals run through a **three-person committee where any one of the three approving is sufficient** (no quorum/dual sign-off); (6) the interest pool being distributed is interest **accrued** on loans (the fixed straight-line `interest_amount` set at loan issuance), not merely interest actually collected via repayments so far.

> **2026-09-11, round 3** — confirmed both remaining §3 items: the self-approval guard (a committee member can't approve their own loan/withdrawal/application) is wanted and is now a hard rule in §2.1/§2.3, not just a recommendation; and the interest distribution is credited to each member's **savings** account (the existing default). §2.5 also now phases the migrations one-per-delivery-phase instead of one big upfront migration, to keep each phase's Claude Code prompt buildable on its own — see the note at the top of §2.5. Phase 2 and Phase 3 kickoff prompts added as §6 and §7.

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
| `credit_union.manage_members` | Register members from the employee directory, manually enter associate members, edit members, exit — day-to-day member admin |
| `credit_union.approve_membership` | Approve/reject a self-applied membership application — committee-level, see below |
| `credit_union.manage_deductions` | Upload/preview/post monthly payroll deduction batches (staff members only) |
| `credit_union.manage_loans` | Apply for / record loans, submit guarantor requests |
| `credit_union.approve_loans` | Approve/reject loan applications and guarantor eligibility — committee-level |
| `credit_union.manage_withdrawals` | Log withdrawal requests |
| `credit_union.approve_withdrawals` | Approve/reject + mark paid — committee-level |
| `credit_union.manage_refunds` | Record refunds |
| `credit_union.manage_receipts` | Log direct cash/cheque payments (the only contribution/repayment path for associate members) |
| `credit_union.manage_interest_distribution` | Compute an annual interest-distribution run |
| `credit_union.approve_interest_distribution` | Approve and post an interest-distribution run — committee-level |
| `credit_union.view_reports` | Reconciliation & summary reports |
| `credit_union.export_reports` | Excel/PDF export |
| `credit_union.manage_settings` | Interest rate, loan multiple, fees, etc. |

Roles: `credit_union_officer` (day-to-day entry: members, deductions, receipts, refunds, applying/recording loans and withdrawals, computing interest distributions) and `credit_union_committee`, which holds every `approve_*` permission (`approve_membership`, `approve_loans`, `approve_withdrawals`, `approve_interest_distribution`). **Confirmed: this is a three-person committee, and any one of the three approving is sufficient** — there's no quorum or dual sign-off to build. That falls out of the existing permission model for free: route/Livewire authorization already checks "does this user hold the permission," not "have N holders of the permission agreed," so assigning `credit_union_committee` to three specific users via the normal UAC role-assignment screen (not something to hard-code in the seeding migration, which only seeds the role/permission *definitions*) is all that's needed.

**Confirmed**: a committee member cannot approve their own loan, withdrawal, guarantee, or membership application (`approved_by !== applicant/requester's user_id`, enforced in `LoanService`/`WithdrawalService`/`MembershipApplicationService`) — a hard rule now, not just a recommendation, given a single-approver committee would otherwise allow self-approval.

Every employee gets `credit_union.view_own_statement` *and* `credit_union.apply_membership` by default, the same way Transport gave every `employee` role `transport.view_own_vehicle`. Associate (non-staff) members are not `users`/`employees` in this ERP at all and **confirmed have no self-service portal** — an officer uses the member's statement PDF (§2.3 `StatementService`) to print or email it to them on request.

`super_admin` continues to bypass everything, per the existing layered-authorization pattern.

### 2.2 Data model

One deliberate simplification vs. the spreadsheets: **shares and savings are modeled as one ledger table** with an `account_type` column, not two tables. The two spreadsheets already treat them as structurally identical (date, in, out, running balance) — collapsing them avoids duplicating every rule twice.

```
credit_union_members
  id, member_type (string: staff|associate, indexed — associate = a member of the association who
    is not GWL staff; drives which contribution/repayment sources are even allowed, see below)
  member_number (string, unique, indexed — the account/ID number shown on statements. For
    member_type=staff this defaults to the linked employee's staff_id. For member_type=associate,
    MemberRegistrationService assigns the next sequential P-prefixed code — P0001, P0012, ... —
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
    status=pending until a credit_union_committee member approves it (credit_union.approve_membership
    — NOT the officer-level manage_members, since this is the gate on who can hold union assets at
    all); associate_manual: an officer hand-entered a non-staff associate, goes straight to active
    since an officer already vetted it)
  applied_by (FK users, nullable, nullOnDelete — who submitted a self_applied application)
  approved_by (FK users, nullable, nullOnDelete), approved_at (timestamp, nullable — set when a
    pending self-applied membership is approved; MembershipApplicationService blocks approved_by
    from equalling applied_by's user, per the self-approval guard in §2.1)
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
  no_guarantor_limit (decimal 15,2 — savings_balance_at_application × config
    ('gwl.credit_union_loan_multiple_without_guarantor', 2), snapshotted at application)
  guarantor_shortfall (decimal 15,2, default 0 — greatest(0, principal_amount - no_guarantor_limit);
    this is the amount that accepted guarantors' guaranteed_amount must sum to before the loan can
    leave awaiting_guarantor)
  requires_guarantor (boolean, default false — true when guarantor_shortfall > 0)
  status (string: pending|awaiting_guarantor|approved|rejected|disbursed|active|completed|defaulted,
    default pending, indexed)
  purpose (text, nullable)
  applied_at, applied_by; approved_by/approved_at, rejection_reason
  disbursed_at, disbursement_reference
  outstanding_balance (decimal 15,2 — service-maintained, replaces the "DIFF. LEFT" column)
  timestamps
  index [member_id, status]

credit_union_loan_guarantors        -- the guarantor path required beyond 2x savings; multiple rows
                                        per loan are expected and normal (confirmed: guarantors can split)
  id, loan_id (FK credit_union_loans, cascadeOnDelete)
  guarantor_member_id (FK credit_union_members, restrictOnDelete — must be an existing member)
  guaranteed_amount (decimal 15,2 — the portion of the shortfall this guarantor is covering;
    required, not nullable, precisely because guarantors can split a loan between them and the
    system needs to sum these to check the shortfall is fully covered)
  guarantor_asset_balance_at_guarantee (decimal 15,2 — snapshot of the guarantor's OWN combined
    shares+savings balance at the moment they're added; must be >= guaranteed_amount, per the
    confirmed rule that a guarantor "must be able to stand in trust for the borrower to the sum
    of cash being requested" even without handing over cash)
  status (string: pending|accepted|declined, default pending, indexed)
  disqualified_reason (string, nullable — set by MemberEligibilityService when a proposed guarantor
    fails either check: "has_active_loan" or "insufficient_balance")
  eligibility_checked_at (timestamp, nullable), was_in_good_standing (boolean, nullable — snapshot
    of both eligibility checks at the time they were added, so a guarantor whose own situation
    changes later doesn't retroactively invalidate an already-approved loan)
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

credit_union_interest_distributions        -- annual pro-rata distribution of loan interest income
  id, period_label (string, e.g. "2025/2026" — matches the fiscal-year style already used in the
    REFUNDS sheet header), period_end_date (date — the fiscal year-end date the balance snapshot
    in credit_union_interest_distribution_lines is taken as of; confirmed the snapshot is a
    year-end balance, not an average or monthly accrual)
  total_interest_pool (decimal 15,2 — confirmed source: interest ACCRUED on loans for the period,
    i.e. sum(credit_union_loans.interest_amount) for loans whose straight-line interest is
    attributable to the period — not merely the interest portion actually collected via
    repayments so far. The exact period-attribution rule — e.g. "loans disbursed within the
    fiscal year," full interest counted at issuance since it's a fixed straight-line amount —
    is worth a numeric sanity-check with the committee once real figures are run, but the
    accrued-vs-collected basis itself is settled.)
  credit_account_type (string: shares|savings, default savings — confirmed: distributions are
    credited to the member's savings account)
  status (string: draft|computed|approved|posted, default draft, indexed)
  computed_by/computed_at, approved_by/approved_at, posted_by/posted_at
  notes (text, nullable)
  timestamps

credit_union_interest_distribution_lines
  id, interest_distribution_id (FK credit_union_interest_distributions, cascadeOnDelete)
  member_id (FK credit_union_members, cascadeOnDelete)
  asset_balance_at_computation (decimal 15,2 — the member's combined shares+savings balance AS OF
    period_end_date — confirmed year-end snapshot, used to compute their proportional share)
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

**Note on associate (non-staff) members**: members of the association who are not GWL staff pay everything (contributions and loan repayments) in cash, deposited directly into the association's bank account; they are never in a payroll deduction file, and are identified by a `P`-prefixed `member_number` (e.g. `P0012`) rather than a staff ID. Every ledger posting for an associate member therefore goes through `credit_union_manual_receipts`/`credit_union_loan_repayments` with `source=cash`, never through `credit_union_deduction_batches`. They also have no self-service login, so the entire member record — registration, statements, everything — is officer-mediated: registration is `associate_manual`, and statements are printed/emailed by an officer from `StatementService`'s PDF output rather than viewed by the member directly.

**Note on how someone becomes a member**: two paths for staff, one for associates. (1) An officer with `credit_union.manage_members` browses the employee directory (reusing `Services/Staff/EmployeeDirectory`, the same role-scoped visibility service UAC's employee search already uses) and registers a chosen employee directly — `application_source=hr_added`, immediately `active`. (2) Any employee with the baseline `credit_union.apply_membership` permission can submit their own application from `/credit-union/apply` — `application_source=self_applied`, starts at `status=pending` and only becomes `active` once a `credit_union_committee` member (`credit_union.approve_membership`) approves it — deliberately a committee action, not an officer one, since it's the gate on who can hold union assets. (3) An officer manually keys in a non-staff associate's details — `application_source=associate_manual`, immediately `active`.

All financial child tables use `cascadeOnDelete` on `member_id`/`loan_id` (per `CLAUDE.md`'s "child/detail rows" rule); `credit_union_members` itself uses soft deletes, so financial history can never silently vanish — a deliberate, documented exception to "only `Vehicle` uses `SoftDeletes`," justified because this is money, not equipment. `employee_id` is `nullOnDelete` rather than `restrictOnDelete` because it's nullable for associate members; a staff member's underlying `employees` row is restricted from deletion elsewhere in the app anyway (employees are deactivated, not deleted), so this doesn't weaken protection in practice.

Every mutating action (member registration, application approval, batch posting, loan/guarantor approval, withdrawal payout, refund, interest distribution) calls `Audit::log(...)` / `AuditLog::record(...)` with `module: 'credit_union'`, exactly like every other module — this is what lets you answer "which of the three committee members approved this specific loan" later, something the spreadsheet can never answer.

### 2.3 Services (`app/Services/CreditUnion/`)

Following the "controllers/Livewire stay thin" rule:

- `MemberRegistrationService` — create a member via any of the three paths above. Assigns `member_number`: the employee's `staff_id` for staff members, or the next sequential `P####` code for associates. Captures the 20-cedi membership form fee (recorded, not ledgered) and auto-posts the 200-cedi initial share as a `shares` ledger entry once the member is `active`.
- `MembershipApplicationService` — the committee-level approve/reject step for `status=pending` members: on approval (`credit_union.approve_membership`, blocked from being the applicant themselves), flips `status` to `active`, stamps `approved_by`/`approved_at`, and triggers the fee/initial-share posting; on rejection, leaves a note and the member row inactive.
- `LedgerService` — post a ledger entry and maintain `balance_after` transactionally (replaces per-row Excel `SUM` formulas).
- `MemberEligibilityService` — the guarantor-eligibility check, now concretely specified as two tests, both of which must pass: (1) **not currently serving a loan** — the candidate guarantor has no `credit_union_loans` row in `disbursed`/`active` status of their own; (2) **sufficient own holdings** — the candidate's own combined shares+savings balance is `>= guaranteed_amount`. A guarantor doesn't have to hand over cash; their own balance just has to be able to "stand in trust" for the sum. Both checks are snapshotted onto `credit_union_loan_guarantors` at the moment they're added, so a guarantor's situation changing afterward doesn't retroactively unwind an already-approved loan.
- `DeductionImportService` — parse an uploaded payroll-deduction file, preview/validate against `config('gwl.max_import_failure_percent')` (reusing the existing threshold pattern from `Services/Import/DataImportService`), match rows to members by `staff_id`.
- `DeductionPostingService` — transactionally fan a validated batch out into `credit_union_ledger_entries` + `credit_union_loan_repayments`, update `amount_posted`, flag `reconciled` vs `variance` against `amount_received`, and reject any row matched to an `associate` member as `invalid_associate_member`.
- `LoanService` — apply, approve/reject, disburse, record repayments, maintain `outstanding_balance`.
  - **Eligibility cap**: `no_guarantor_limit = savings_balance_at_application × config('gwl.credit_union_loan_multiple_without_guarantor', 2)`. If `principal_amount` exceeds that, `guarantor_shortfall = principal_amount - no_guarantor_limit` and the loan sits in `awaiting_guarantor` until the sum of `guaranteed_amount` across all `accepted` guarantors (each of whom must pass `MemberEligibilityService`) is `>= guarantor_shortfall` — multiple guarantors splitting the shortfall between them is expected and supported.
  - **Interest**: straight-line at `config('gwl.credit_union_loan_annual_interest_rate_percent', 15.0)` per annum — `interest_amount = principal_amount × (annual_rate / 100) × (term_months / 12)`, `total_repayable = principal_amount + interest_amount`, `monthly_installment_amount = total_repayable / term_months`. Captured onto the loan row at application time.
  - **Repayment source**: `payroll_deduction` only for `member_type=staff` loans; `associate` members repay via `credit_union_manual_receipts`/cash only.
  - **Self-approval guard**: `approved_by` cannot equal the loan's own `applied_by`/member user, even though any one of the three committee members can otherwise approve.
- `WithdrawalService` — request, approve/reject (same self-approval guard), validate against the member's actual ledger balance, mark paid.
- `RefundService` — record a refund, post the offsetting ledger entry.
- `InterestDistributionService` — for a fiscal period ending `period_end_date`: sum `interest_amount` on loans attributable to that period into `total_interest_pool` (interest *accrued*, per the confirmed basis — not interest collected), snapshot every active member's shares+savings balance **as of `period_end_date`**, compute each member's proportional share (`asset_balance_at_computation / sum(all members' asset_balance_at_computation)`), write `credit_union_interest_distribution_lines`, and on approval post each line as an `interest` ledger entry against `credit_account_type`.
- `StatementService` — build a member's shares + savings + loans statement (the digital, always-current replacement for `INDIVIDUAL PRIVATE.xlsx`) and render it to PDF via Dompdf. For associate members this PDF is the entire "statement experience" — an officer generates and hands/emails it, since there's no portal.
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
            Route::get('/members/{member}', [CreditUnionModuleController::class, 'memberShow'])->name('members.show');
            Route::get('/members/{member}/statement/pdf', [CreditUnionModuleController::class, 'memberStatementPdf'])->name('members.statement.pdf');
        });

        Route::get('/members/applications', [CreditUnionModuleController::class, 'applications'])
            ->middleware('permission:credit_union.manage_members,credit_union.approve_membership')
            ->name('members.applications');

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

Route-level middleware only gates *page access* (e.g. an officer with `manage_members` can see the applications queue); the Livewire component's actual "approve" action additionally checks specifically for `credit_union.approve_membership` (and the self-approval guard), same pattern for the loans/withdrawals/interest-distribution "approve" buttons vs. their "manage" list views.

Each `GET` route wraps a Livewire component, matching how `TransportModuleController`/`AssetModuleController` delegate to `resources/views/livewire/credit-union/*`. `app/Support/ErpNavigation.php` needs the new module + its sidebar entries added, following its existing per-module pattern (a 25KB file — read it directly when implementing rather than guessing its exact structure).

### 2.5 Migrations

Phased rather than one big upfront migration, so each delivery phase (§4, and the kickoff prompts in §5–§7) is buildable and testable on its own without reaching ahead into a later phase's tables. Every migration still follows the Transport precedent exactly: `Schema::hasTable()`-guarded, explicit FK delete behavior, permission/role/module-access seeding done via `updateOrInsert`/`insertOrIgnore` inside a migration (not only `database/seeders`).

- **Phase 1** — `2026_09_10_000001_create_credit_union_module_tables.php`: `credit_union_members`, `credit_union_ledger_entries` only. `2026_09_10_000002_seed_credit_union_module_access.php`: `credit_union_officer`/`credit_union_committee` roles, and every permission that exists as of Phase 1 (`view_dashboard`, `view_own_statement`, `apply_membership`, `manage_members`, `approve_membership`) — later phases add their own permission rows in their own seeding migrations rather than editing this one, per the "never edit a merged migration" rule.
- **Phase 2** — `2026_1x_xx_000001_create_credit_union_deduction_tables.php`: `credit_union_deduction_batches`, `credit_union_deduction_batch_lines`. Note: `deduction_batch_lines.loan_repayment_amount` is captured on the line either way, but until Phase 3's `credit_union_loans`/`credit_union_loan_repayments` tables exist there's no loan to post it against — `DeductionPostingService` posts `shares_amount`/`savings_amount` to `credit_union_ledger_entries` normally and leaves `loan_repayment_amount` on the line unposted (a `has_unposted_loan_repayment` flag, or simply: nonzero `loan_repayment_amount` with no corresponding `credit_union_loan_repayments` row yet). Phase 3 then includes a one-time job/console command to walk already-posted batch lines and post any outstanding loan-repayment amounts once loans exist.
- **Phase 3** — `2026_1x_xx_000001_create_credit_union_loan_tables.php`: `credit_union_loans`, `credit_union_loan_guarantors`, `credit_union_loan_repayments`, plus its own permission-seeding migration for `manage_loans`/`approve_loans`.
- **Phase 4** — `credit_union_withdrawal_requests`, `credit_union_refunds`, `credit_union_manual_receipts` + their permissions.
- **Phase 5** — `credit_union_interest_distributions`, `credit_union_interest_distribution_lines` + their permissions.

`credit_union_committee` gets every `approve_*` permission as each phase introduces one; `credit_union_officer` gets everything else. Actually assigning the `credit_union_committee` role to the three named committee members is a post-migration UAC action (role assignment), not something any migration does.

### 2.6 Config

Add to `config/gwl.php` (not `gwcl.php`, per the "prefer `gwl.php` for new settings" rule):
```php
'credit_union_loan_annual_interest_rate_percent' => (float) env('GWL_CREDIT_UNION_LOAN_INTEREST_RATE', 15.0),
'credit_union_loan_multiple_without_guarantor' => (float) env('GWL_CREDIT_UNION_LOAN_MULTIPLE_WITHOUT_GUARANTOR', 2),
'credit_union_membership_form_fee' => (float) env('GWL_CREDIT_UNION_MEMBERSHIP_FORM_FEE', 20),
'credit_union_initial_share_amount' => (float) env('GWL_CREDIT_UNION_INITIAL_SHARE_AMOUNT', 200),
```

### 2.7 Testing

`tests/Feature/CreditUnion/*`, `Livewire::test()` + `RefreshDatabase` per convention. Unlike the older modules, I'd recommend **factories** for these models (`CreditUnionMemberFactory`, `CreditUnionLoanFactory`, etc.) rather than inline builders — the ledger/interest/balance math benefits from generated data the way Transport's tests already do, and Transport is the closest precedent for "new module gets factories."

---

## 3. Open questions still worth a quick confirm

Everything from round 1 is resolved in §2.1–§2.3; both round-2 leftovers (self-approval guard, distribution destination account) were confirmed in round 3. Two small items remain, neither blocking:

1. **Interest-accrual period boundary** — confirmed the pool is *accrued* interest, not collected; the precise rule for which loans' interest counts toward a given year's pool (loans disbursed within that fiscal year, full straight-line interest at issuance, is the working assumption above) is worth a numeric sanity-check against real figures once it's built, rather than a blocker now.
2. **Existing records migration** — migrating `LEDGER 2026`, `LOAN DEDUCTIONS 2020-2026`, and the per-member passbook files into the new tables (backfilling `member_type`, `member_number`, and the legacy ~3%-rate loans that predate the 15% policy) is a one-time data-import job, out of scope for this design; worth scoping separately once the schema is agreed.

---

## 4. Suggested phased delivery

1. **Foundation** — migrations, permission/role/module-access seeding, `credit_union_members` CRUD covering all three registration paths (officer-added from the employee directory, employee self-application with a `pending` → committee-approved queue, manually-entered associates with `P`-prefixed member numbers), membership form fee capture, auto-posted initial share, manual ledger entry (deposit/withdrawal) screens, on-demand PDF statement.
2. **Deduction import & reconciliation** — the batch upload/match/post pipeline, reconciliation report. Highest-value phase — eliminates the triple-retyping.
3. **Loans** — application, the 2×-savings/guarantor eligibility check (both guarantor tests: not currently servicing a loan, sufficient own balance), multi-guarantor splitting, committee approval with the self-approval guard, disbursement, 15%-straight-line repayment tracking (payroll-deduction for staff, cash for associates).
4. **Withdrawals, refunds, manual receipts** — the remaining day-to-day workflows.
5. **Interest distribution** — the annual pro-rata run at fiscal year-end, once §3's destination-account point is settled.
6. **Reporting & exports** — dashboard, Excel/PDF exports, reconciliation dashboard.

---

## 5. Phase 1 kickoff prompt — foundation (ready to paste into Claude Code)

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
  app/Livewire/CreditUnion/, service classes app/Services/CreditUnion/LedgerService.php,
  app/Services/CreditUnion/MemberRegistrationService.php, and
  app/Services/CreditUnion/MembershipApplicationService.php.
- Add the route group to routes/web.php following the existing module block shape
  (see the transport. and staff. groups for the pattern), including /apply,
  /apply (POST), /members, and /members/applications for this phase. The
  applications queue route accepts permission:credit_union.manage_members OR
  credit_union.approve_membership for viewing, but the Livewire component's actual
  approve action must check specifically for credit_union.approve_membership and
  block a user from approving their own application.
- Register the module in app/Support/ErpNavigation.php following its existing
  per-module pattern.
- Use both EnforcesModuleAccess traits (controller-level AND Livewire-level) on the
  new controller/components, per the belt-and-suspenders pattern CLAUDE.md describes.
- Call Audit::log(...) on member creation, application approval, and every ledger
  entry post.
- Add config/gwl.php entries for credit_union_membership_form_fee (default 20) and
  credit_union_initial_share_amount (default 200), env-driven, per existing gwl.php
  style.
- Write tests/Feature/CreditUnion/MemberRegistrationTest.php,
  MembershipApplicationTest.php, and LedgerEntryPostingTest.php using
  Livewire::test() + RefreshDatabase; add database/factories/CreditUnionMemberFactory.php
  (and a ledger-entry factory) since this is a new module — follow the Transport
  module's factory style rather than the older modules' inline-builder style. Cover:
  officer-added staff members (linked to an employee, member_number = staff_id,
  status=active immediately); employee self-applications (status=pending until a
  credit_union.approve_membership holder approves — and assert the applicant
  themselves cannot approve their own application); manually-entered associate
  members (no employee link, member_number auto-assigned as the next P#### code,
  status=active immediately); and assert the initial share auto-posts as a `shares`
  ledger entry while the membership form fee does NOT create a ledger entry.

Scope for this phase only: member registration/edit via all three paths described
above; registration captures the membership form fee (recorded on the member, not
ledgered) and auto-posts the one-time initial share as a shares ledger entry once
a member is active. Also build manual ledger entry (deposit/withdrawal, shares or
savings — cash/cheque source only for associate members), member list + detail
view, a pending-applications queue with committee-only approval, and a PDF
statement (shares + savings only — loans come in a later phase) via Dompdf.
Do not build the deduction-batch import, loans/guarantors, withdrawals, refunds,
manual receipts, or interest distribution yet — those are later phases.
```

---

## 6. Phase 2 kickoff prompt — deduction import & reconciliation

Assumes Phase 1 has shipped (`credit_union_members`, `credit_union_ledger_entries`, `LedgerService`, `MemberRegistrationService` all exist).

```
Implement Phase 2 of the Credit Union module — the payroll deduction import and
reconciliation pipeline — per credit-union-module-design.md §2.2 (credit_union_
deduction_batches and credit_union_deduction_batch_lines only — do NOT create the
loan tables, those are Phase 3), §2.3 (DeductionImportService, DeductionPostingService),
§2.5 (Phase 2 migration), and CLAUDE.md conventions:

- New migration database/migrations/<today>_000001_create_credit_union_deduction_
  tables.php creating credit_union_deduction_batches and credit_union_deduction_
  batch_lines, Schema::hasTable-guarded, FKs exactly as specified (batch_lines.
  member_id is nullable/nullOnDelete — null means unmatched).
- app/Services/CreditUnion/DeductionImportService.php: parse an uploaded xlsx/csv
  of payroll deductions (columns: staff_id, name, shares_amount, savings_amount,
  loan_repayment_amount), match each row to a credit_union_members row by staff_id,
  and validate against config('gwl.max_import_failure_percent') — reuse the
  preview/validate/failure-threshold pattern from app/Services/Import/
  DataImportService.php (read that file first) rather than inventing a new one.
  A row matching a member_type=associate member is match_status=invalid_associate_
  member, not a normal match (associates are never on GWL payroll).
- app/Services/CreditUnion/DeductionPostingService.php: transactionally post a
  validated batch. For each matched line: post shares_amount and savings_amount as
  credit_union_ledger_entries (source=payroll_deduction, deduction_batch_id set,
  entry_type=contribution) via LedgerService. loan_repayment_amount is captured on
  the batch line but NOT posted anywhere yet — credit_union_loans doesn't exist
  until Phase 3 — leave it as an unposted amount on the line (add a boolean
  loan_repayment_posted column, default false, to credit_union_deduction_batch_
  lines for this). Update the batch's amount_posted (sum of what was actually
  posted, i.e. shares+savings only for now) and set status to reconciled if
  amount_posted == amount_received, else variance.
- Livewire components app/Livewire/CreditUnion/Deductions.php (list + upload/
  preview/run, permission:credit_union.manage_deductions) and
  DeductionBatchShow.php (batch detail: matched/unmatched/invalid_associate_member
  line breakdown, reconciliation status, resolution_notes editing for unmatched
  rows). Add the /deductions and /deductions/{batch} routes to the existing
  credit-union route group in routes/web.php.
- Both EnforcesModuleAccess traits on the new controller/components; Audit::log(...)
  on batch import and on batch posting (module: credit_union).
- Add config/gwl.php reuse: no new keys needed here, this phase reuses the existing
  gwl.max_import_failure_percent.
- tests/Feature/CreditUnion/DeductionImportTest.php and DeductionPostingTest.php
  (Livewire::test() + RefreshDatabase + factories): cover a clean match-and-post,
  an unmatched staff_id, an associate-member row correctly rejected as
  invalid_associate_member, a batch where amount_posted < amount_received flagged
  as variance, and confirm loan_repayment_amount is captured but not posted to any
  ledger entry or loan record.

Do not build loans, guarantors, withdrawals, refunds, manual receipts, or interest
distribution in this phase.
```

## 7. Phase 3 kickoff prompt — loans

Assumes Phases 1–2 have shipped.

```
Implement Phase 3 of the Credit Union module — loans, guarantors, and repayments —
per credit-union-module-design.md §2.1 (manage_loans/approve_loans permissions),
§2.2 (credit_union_loans, credit_union_loan_guarantors, credit_union_loan_
repayments), §2.3 (MemberEligibilityService, LoanService), §2.5 (Phase 3
migration), §2.6 (the two loan-related config keys), and CLAUDE.md conventions:

- New migration database/migrations/<today>_000001_create_credit_union_loan_
  tables.php creating credit_union_loans, credit_union_loan_guarantors,
  credit_union_loan_repayments, Schema::hasTable-guarded, FKs exactly as specified
  in §2.2 (member_id restrictOnDelete on loans; loan_id cascadeOnDelete on
  guarantors/repayments).
- Companion migration seeding credit_union.manage_loans and credit_union.
  approve_loans permissions and granting approve_loans to credit_union_committee,
  manage_loans to credit_union_officer, following the Transport seeding-migration
  pattern (updateOrInsert into permissions/role_permissions).
- Add to config/gwl.php: credit_union_loan_annual_interest_rate_percent (default
  15.0) and credit_union_loan_multiple_without_guarantor (default 2), env-driven.
- app/Services/CreditUnion/MemberEligibilityService.php: isEligibleGuarantor(
  CreditUnionMember $candidate, float $guaranteedAmount): the candidate fails if
  (a) they have any credit_union_loans row of their own with status in
  [disbursed, active], or (b) their own combined shares+savings ledger balance
  (sum credit_union_ledger_entries.balance_after per account_type, i.e. latest
  balance per account) is less than $guaranteedAmount. Return a reason string
  ("has_active_loan" or "insufficient_balance") alongside the boolean so callers
  can store it as credit_union_loan_guarantors.disqualified_reason.
- app/Services/CreditUnion/LoanService.php implementing exactly the rules in
  §2.3: no_guarantor_limit = savings_balance_at_application × config(
  'gwl.credit_union_loan_multiple_without_guarantor'); guarantor_shortfall =
  max(0, principal_amount - no_guarantor_limit); requires_guarantor = shortfall > 0;
  a loan in awaiting_guarantor only moves to approved once sum(guaranteed_amount)
  across accepted guarantors >= guarantor_shortfall (multiple guarantors splitting
  the shortfall must work — write a test with two guarantors each covering part of
  it); interest_amount = principal_amount × (rate/100) × (term_months/12) using
  config('gwl.credit_union_loan_annual_interest_rate_percent') captured onto the
  loan row at application time; total_repayable = principal + interest_amount;
  monthly_installment_amount = total_repayable / term_months; repayments with
  source=payroll_deduction are rejected for member_type=associate loans (cash/
  cheque only for those); approve()/reject() must throw/refuse if approved_by
  equals the loan's own applicant (self-approval guard — see §2.1/§2.3, this is a
  hard rule, not optional).
- Also implement the Phase-2-deferred loan repayment posting: a console command
  or a step inside LoanService that, when a loan is created for a member who has
  unposted deduction_batch_lines (loan_repayment_posted=false, loan_repayment_
  amount>0), posts those historical amounts as credit_union_loan_repayments
  against the newly-created loan and marks the lines loan_repayment_posted=true.
- Livewire components app/Livewire/CreditUnion/Loans.php (apply/list, permission:
  credit_union.manage_loans,credit_union.approve_loans) and LoanShow.php (detail:
  guarantor requests/accept-decline, approve/reject action gated specifically on
  credit_union.approve_loans + the self-approval guard, disbursement, repayment
  entry). Add /loans and /loans/{loan} routes to the existing route group.
- Both EnforcesModuleAccess traits; Audit::log(...) on application, guarantor
  accept/decline, approval/rejection, disbursement, and every repayment.
- tests/Feature/CreditUnion/LoanApplicationTest.php, LoanGuarantorTest.php,
  LoanApprovalTest.php, LoanRepaymentTest.php using Livewire::test() +
  RefreshDatabase + factories (CreditUnionLoanFactory, CreditUnionLoanGuarantor
  Factory). Cover: a loan under the 2x-savings cap needing no guarantor; one over
  the cap correctly computing guarantor_shortfall and staying awaiting_guarantor
  until covered; a guarantor who is themselves mid-loan being rejected with
  has_active_loan; a guarantor with insufficient balance being rejected with
  insufficient_balance; two guarantors splitting a shortfall between them; a
  committee member blocked from approving their own loan; correct straight-line
  interest/installment math at the confirmed 15% rate; an associate-member loan
  rejecting a payroll_deduction repayment attempt.

Do not build withdrawals, refunds, manual receipts, or interest distribution yet.
```

---

*Prepared from a direct read of `app/Models/Permission.php`, `app/Models/ModuleAccess.php`, `app/Models/AuditLog.php`, `app/Support/Audit.php`, both `EnforcesModuleAccess` traits, `config/gwl.php`/`gwcl.php`, `routes/web.php`, `app/Models/Employee.php`, and the Transport module's two migrations, via the linked device bridge into `C:\laragon\www\erp_project`.*
