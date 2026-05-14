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
- Annual carry-over expires after configured days.
- Sick leave uses effectively unlimited virtual entitlement.

## Compulsory Deductions

- Applies bulk annual leave deduction to selected categories.
- Supports exclusion by location type.
- Requires override confirmation if same-year deduction exists.

## Key Files

- `app/Services/Leave/LeaveWorkflowService.php`
- `app/Services/Leave/LeaveBalanceService.php`
- `app/Services/Leave/WorkingDaysCalculator.php`
- `app/Livewire/Leave/ApplyForm.php`
- `app/Livewire/Leave/Approvals.php`
- `app/Livewire/Leave/CompulsoryDeductions.php`
