# Data Model Reference

## Identity and Access

- `users`
- `employees`
- `roles`
- `permissions`
- `user_roles`
- `role_permissions`
- `module_access`
- `audit_logs`

## Leave

- `leave_requests`
- `leave_balances`
- `compulsory_leave_deductions`
- `holidays`

## Letters

- `mail_letters`
- `letter_status_logs`
- `routing_histories`
- `letter_remarks`
- `letter_notifications`

## Visitors

- `visitors`

## Notifications

- `notifications`

## Relationship Notes

- User accounts may link to employee via `employee_id` and are also reconciled by `staff_id`.
- Role access is split into module-level (`module_access`) and action-level (`role_permissions`).
- Leave requests reference requester, manager, and final approver as employee records.
- Letters record per-secretariat statuses and explicit route hops.
