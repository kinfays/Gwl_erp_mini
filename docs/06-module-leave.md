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

Resolved by `LeaveApprovalChainResolver` based on `location_type`:

- HeadOffice: manager/departmental manager -> chief manager
- Region: departmental manager -> regional chief manager
- District: district manager -> regional chief manager

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
- `app/Services/Leave/LeaveBalanceService.php`
- `app/Services/Leave/WorkingDaysCalculator.php`
- `app/Livewire/Leave/ApplyForm.php`
- `app/Livewire/Leave/Approvals.php`
- `app/Livewire/Leave/CompulsoryDeductions.php`
