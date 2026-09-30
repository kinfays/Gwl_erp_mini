# Module: Leave

## Scope

Employee leave application, approval workflow, balances, dashboards, and reporting.

## Main Features

- Apply for leave (planned and submit)
- My history (view/edit/delete planned/reopen denied)
- Approvals queue
- All requests viewer
- HR dashboard and manager dashboard
- Compulsory leave deductions
- Excel exports and reports

## Workflow Statuses

- `Planned`
- `Pending Approval`
- `Approved`
- `Denied`

### Stage timestamps and turnaround

`LeaveWorkflowService` stamps each stage on the request; the HR and manager dashboards time
approvals from these, not from `created_at`/`updated_at`:

- `submitted_at`: when the request entered the queue (a planned request gets it when submitted;
  editing a waiting request keeps it; reopening a denied request clears all three).
- `recommended_at`: when the manager recommended or rejected it. A manager's own request skips that
  step and is recommended at submission, so it counts no manager time.
- `decided_at`: the final approval or denial (a manager's rejection is also the decision).

Dashboard figures: manager response = submitted → recommended; final approval = recommended →
decided (approver decisions only); total cycle and SLA breaches (> 72h) = submitted → decided.
Requests from before these columns existed (migration `2026_09_28_000001`) were backfilled from
`created_at`/`updated_at` only where the stage time is knowable; unknown stages stay null and are
left out of the averages, and a figure with nothing measured shows "No data yet".

## Approval Chain

`LeaveApprovalChainResolver::route()` decides who recommends and who gives the final approval, from the
applicant's `location_type` **and their own role** (a manager applies to the level above). Head Office is a
district (its staff have `location_type = HeadOffice` and still carry a real `region_id`), so "in the
department" for Head Office also means "at Head Office".

| Applicant | Recommends | Final approval |
|---|---|---|
| District staff | `district_manager` (same district) | `regional_chief_manager` (same region) |
| Regional-office staff | `departmental_manager` (same dept + region, at the regional office) | `regional_chief_manager` |
| `district_manager` / `departmental_manager` in a region or district | — (single stage) | `regional_chief_manager` |
| Head Office staff in a unit | that unit's `manager` (same dept + unit) | `chief_manager` (same department) |
| Head Office staff, no unit | `departmental_manager` (same dept, at Head Office) | `chief_manager` |
| Head Office `manager` / `departmental_manager` | — (single stage) | `chief_manager` |
| `chief_manager` / `regional_chief_manager` (anywhere) | — (single stage) | `managing_director` |

Rules for every flow:

- Only **active** users with an **active** employee record are considered. Several people can hold a role in a
  scope: all are notified, whoever acts first wins, and a second action is refused
  (`LeaveAlreadyActionedException`, decided on a `lockForUpdate` re-read inside the transaction).
- The applicant is never their own recommender/approver, not even a `super_admin`. A recommender stage that
  would resolve only to the applicant is skipped.
- If a needed role has no active holder, submission is blocked with a `ValidationException` (key `leave_type`)
  naming the role and scope, and audited as `leave_submission_blocked`. Planned drafts need no approver — they
  are resolved on submission. No Managing Director user, or an MD applying (nobody is above them), also blocks.
- The resolved approvers are snapshotted on the request: `manager_user_id` (recommender, null for single
  stage), `chief_user_id` (final approver — replaced by whoever actually decides), `is_single_stage`.
  `manager_id`/`approved_by_id` remain employee ids. A single-stage request has
  `manager_recommendation = 'Recommended'` from submission (nobody performs a recommend step), waits at the
  final stage and goes `Pending Approval -> Approved/Denied`.
- Who may act at a stage: the snapshotted user (still active and still holding a role for that stage) or anyone
  the resolver finds for the applicant at action time; a role holder out of scope gets a 403
  (`AuthorizationException`). `super_admin` bypasses scope, never the stage/state checks.
- Balance is deducted only on final approval, in the same transaction as the status change.

## Notifications

`LeaveNotificationService` sends every leave notification (in-app bell + email) after the state change has
committed. Email is switched off centrally with `GWL_LEAVE_EMAIL_NOTIFICATIONS_ENABLED` (all leave emails) and
`GWL_LEAVE_HR_EMAIL_NOTIFICATIONS_ENABLED` (HR notice only; defaults to the first, and never overrides it); the
in-app notices are unaffected. A failing email is logged and skipped — it never breaks or rolls back the workflow.
Mail is sent synchronously (the queue is only mandatory for MDM), one message per recipient.

- Submitted / recommended: the users who can act at the next stage (in-app + email).
- Approved / denied / rejected: the applicant (approval copies the recommender).
- After a **final approval**, HR of the applicant's scope is told: Head Office staff (by `location_type`) →
  the Head Office contact and `hr_headoffice` users; everyone else → their region's contact and `hr_region`
  users of that region. The email goes to the configured address (`LeaveHrNotificationMail`); the in-app
  notice goes to every active HR user of the scope. With no active contact the in-app notice still goes out and
  a warning is logged.

### HR contacts (`/leave/hr-contacts`)

`leave_hr_contacts` holds one contact per scope: a region, or `region_id = NULL` for Head Office (the unique
index can't police the NULL row, so `LeaveHrContactService` keeps it to one). The screen needs
`leave.manage_hr_contacts` (granted to `super_admin`, `admin`, `hr_headoffice`, `hr_region`); Head Office HR,
admin and super_admin manage every scope, `hr_region` only their own region. Every create/update/delete is
audited (`leave_hr_contact_*`). This is the only place HR emails are configured: the old per-region "HR Email" was removed from Staff → Regions and the region import, and the `regions.hr_email` column was dropped (migration `2026_09_29_100004`).

## Special Rules

- Casual leave submission is blocked if annual balance is still positive.
- Working days exclude weekends and holidays.
- Annual carry-over expires after configured days (default 1 April). Leave dated before the expiry uses carry-over first; whatever is unused then is forfeited by the daily `leave:forfeit-expired-carry-over` job.
- Sick leave uses effectively unlimited virtual entitlement.

## Compulsory Deductions

- Applies bulk annual leave deduction to selected categories.
- Supports exclusion by location type.
- Requires override confirmation if same-year deduction exists.
- Deducted days are charged to `leave_balances` (no leave request is created) and count against the Annual balance everywhere, including the apply form and the casual-leave rule.

## Key Files

- `app/Services/Leave/LeaveWorkflowService.php`
- `app/Services/Leave/LeaveApprovalChainResolver.php` (+ `LeaveApprovalRoute`)
- `app/Services/Leave/LeaveNotificationService.php`
- `app/Services/Leave/LeaveHrContactService.php`, `app/Livewire/Leave/HrContacts.php`
- `app/Services/Leave/LeaveBalanceService.php`
- `app/Services/Leave/WorkingDaysCalculator.php`
- `app/Livewire/Leave/ApplyForm.php`
- `app/Livewire/Leave/Approvals.php`
- `app/Livewire/Leave/CompulsoryDeductions.php`
