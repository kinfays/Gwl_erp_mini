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

- `leave_requests` (approvers snapshotted in `manager_user_id`, `chief_user_id`, `is_single_stage`)
- `leave_hr_contacts` (one HR email per region; `region_id` NULL = Head Office)
- `leave_balances`
- `compulsory_leave_deductions`
- `holidays`

## Letters

- `mail_letters` (`closed_at` / `closed_by_id`: closed is a state of the letter, `nullOnDelete` to employees)
- `letter_status_logs` (one row per holder per stay; `is_closed` is no longer written)
- `routing_histories` (one row per hop; `received_confirm` plus `confirmed_at` / `confirmed_by_id` say when and by whom
  it was confirmed; `batch_id` links it to a transmittal; `resolution`, `resolved_at`, `resolution_note` are reserved
  for recall/reject and not written yet)
- `letter_dispatch_batches` (transmittals: see below)
- `letter_remarks`
- `letter_notifications` (`letter_id` is nullable; `batch_id` is set on the one notification a transmittal sends)

### `letter_dispatch_batches`

One hand-over of several letters from one holder to one recipient. It only groups hops: each letter in it is still an
ordinary `routing_histories` row, so timelines, status logs and audit rows work as for a single dispatch.

| Column | Notes |
|---|---|
| `batch_no` | `TR-<year>-<id, 6 digits>`, unique, set from the id inside the creating transaction (nullable only until then) |
| `from_secretariat_id`, `to_secretariat_id` | employees, `restrictOnDelete` |
| `note` | optional, 500 characters |
| `letters_count` | fixed at dispatch |
| `confirmed_count` | recomputed from the hops on every confirmation, never incremented |
| `dispatched_at`, `completed_at` | `completed_at` is set once no hop of the batch is left unconfirmed |

Deleting a batch nulls `routing_histories.batch_id` (the hops and their history stay) and cascades its notification.

## Visitors

- `visitors`

## Notifications

- `notifications`

## Relationship Notes

- User accounts may link to employee via `employee_id` and are also reconciled by `staff_id`.
- Role access is split into module-level (`module_access`) and action-level (`role_permissions`).
- Leave requests reference requester, manager, and final approver as employee records; the approvers chosen at
  submission are also kept as user ids (`manager_user_id`, `chief_user_id`, both nullable, `nullOnDelete`).
- Letters record per-secretariat statuses and explicit route hops; a transmittal groups several hops.
