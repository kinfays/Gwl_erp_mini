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
