# Health & Safety Module — Incident Reporting, PPE, First Aid Kits and Fire Extinguishers: Design and Phase 1 Kickoff

> **2026-10-07 — first draft.** Scope: a new `health_safety` module that (1) replaces the four Microsoft Forms **INCIDENT REPORT ACCRA WEST** forms (Regional Office, District Office, Pay Point, Field Work) with an in-app report that goes through triage, investigation, actions and closure; (2) keeps an **inventory of PPE** (stock, issue to staff, replacement dates); (3) keeps registers of **first aid kits** and **fire extinguishers** with **expiry dates**, checks and service records; and (4) introduces a **Health & Safety Officer** role (plus a Health & Safety Manager for sign-off and oversight).
>
> **How this was checked.** Built from the four form printouts (`INCIDENT_REPORT_*.pdf`), `CLAUDE.md`, `APP_DOCUMENTATION.md`, and the three earlier design docs (`credit-union`, `commercial`, `ict-assets-analytics-roadmap`) for conventions. **It was not checked against the live repository** — only the docs were available. Every file/class named below that comes from those docs is flagged in the Phase 1 prompt as "read first and confirm"; anything the design depends on that is not yet known is in §10 rather than assumed.
>
> Follows the same shape as `commercial-module-design.md`: permissions → data model → derived rules → screens → phases → a paste-ready kickoff prompt per phase (Phase 1 now; later phases are written against the real schema once Phase 1 is built, as with Commercial).

---

## 1. What the four forms actually are

All four are the same Microsoft Form with a different second question. The first screen asks where the incident happened (Regional Office / District Office / Pay Point / Field Work); the rest is common.

| Form | Q1 "Location where incident occurred" | Q2 | Notes |
|---|---|---|---|
| Regional Office | Department: HR, Internal Audit, Materials, Finance, PR, ICT, EHS, GIS, Distribution, Commercial, LICSD | "Regional Office" (text) | 11 departments |
| District Office | District: Sowutuom, Darkuman, Amasaman, Odokor, Kaneshie | "District Office" (text) | 5 districts |
| Pay Point | *(no list)* | "Name of the Pay Point" (text, **required**) | Free text |
| Field Work | District list, identical to the District Office form | "District Office" | **Identical to the district form** — a field incident has no place or description of where, only a district |

Common to all four: **Q3** Witness contact (required) · **Q4** What are you reporting on: Near Miss, Injury, Property damage, Environmental, Incident, Other · **Q5** When did it occur (dd/MM/yyyy, required) · **Q6** Please describe what happened (**not** required) · **Q7** Was first aid given: Yes / No / No need (required).

### 1.1 What this tells us (checked against the printouts, not assumed)

| Observation | Consequence for the module |
|---|---|
| The form states it does **not** collect name or email unless typed in. There is no reporter field. | Reports are anonymous today. The in-app version knows who is logged in. That is a real behaviour change and may reduce near-miss reporting — see the confidential-reporting flag (§3.4) and §10 Q1. |
| Witness contact is **required**, but it is the *witness*, not the reporter. | People with no witness must type something. Make it optional with a "No witness" tick. |
| Description (Q6) is **optional**. | A report can contain only a date and a category. Make it required. |
| No time of day, severity, person affected, injury type/body part, treatment, photo, or immediate action taken. | Capture the cheap ones on the form (time, photo); capture the rest at triage by the officer (§3.2) so the reporter's form stays short. |
| Pay point is free text. | "Kaneshie Market PP", "Kaneshie pay point" and a typo are three different places. A `hs_sites` register (§2.2) fixes this; reporters pick from it, with an "Other" fallback an officer later links. |
| Field work has only a district. | Add a free-text "where exactly". |
| It is a one-way inbox: nothing acknowledges, assigns, investigates, closes, or tells the reporter what happened. | The whole of §3 — status, owner, actions, closure note back to the reporter. |
| Spelling: `REIONAL`, `LOCAION`, `SOWUTUOM`, `ODOKOR`; the Commercial design also has `ODORKOR` and `DARKUMAN/GBAWE`. | The in-app form selects from the `districts` / `departments` master data, not typed text. Any historical import (§5) needs the same alias treatment the Commercial module uses. |
| "Incident" as a type overlaps every other type. | Keep it (continuity with past data) but let the officer reclassify at triage; the change is logged. |

**What is worth keeping:** the four contexts, the six types, the three-valued first-aid answer, dd/MM/yyyy dates, and above all **how short it is**. Adoption depends on the in-app form staying about this short on a phone: about six required fields, everything else optional or completed later by the officer.

---

## 2. Proposed module: `health_safety`

Module slug `health_safety`, title **Health & Safety**. Behind a feature flag like Credit Union and Commercial: `config('gwl.health_safety_module_enabled')` (env `GWL_HEALTH_SAFETY_MODULE_ENABLED`, default `false`), filtered in `ErpNavigation::moduleDefinitions()`. Turn it on when the officer is ready and the Microsoft Forms are being retired.

Unlike Commercial, **every staff member needs this module** (to report). So `module_access` is `true` for `employee` and every other role, and the sidebar for a plain employee shows only *Report an incident*, *My reports* and (Phase 3) *My PPE*. Leave is the precedent for a module everyone sees.

### 2.1 Permissions & roles

Add to `app/Models/Permission.php`: `public const MODULE_HEALTH_SAFETY = 'health_safety';` (append to `MODULES`).

| Slug | Purpose |
|---|---|
| `health_safety.report_incident` | Submit a report; see **own** reports. Granted to **every** role. |
| `health_safety.view_incidents` | See the incident register within scope (§2.4) |
| `health_safety.view_injury_details` | See affected-person, injury, treatment and lost-time fields (health data — tighter than `view_incidents`) |
| `health_safety.manage_incidents` | Acknowledge, triage (severity, type, owner), investigate, add actions, close, cancel, reopen |
| `health_safety.approve_closure` | Sign off closure of **High/Critical** incidents (separation of duties: the officer investigates, someone senior closes) |
| `health_safety.record_on_behalf` | Submit a report for someone else (pay point attendant or casual with no login) |
| `health_safety.view_dashboard` | Overview KPIs |
| `health_safety.view_equipment` | View PPE stock, first aid kit and extinguisher registers in scope |
| `health_safety.record_checks` | Record a monthly check or restock on kits/extinguishers (also allowed for the equipment's named responsible person without this permission) |
| `health_safety.manage_equipment` | Add/edit/decommission kits and extinguishers; record service and refills |
| `health_safety.manage_ppe` | PPE stock receipts, issue, return, write-off |
| `health_safety.manage_master_data` | Sites, PPE types, kit contents templates, PPE entitlements |
| `health_safety.export_reports` | Excel/PDF export |
| `health_safety.manage_settings` | Thresholds, intervals, escalation recipients (**proposed: `hs_manager` and `super_admin` only**, as with the Assets replacement policy) |

**New roles**

- **`hs_officer` — "Health & Safety Officer"** (the role requested). Region-scoped. Receives every new report for the region, acknowledges and triages it, investigates, raises actions, runs the equipment registers, checks, PPE stock and issue, and exports. Does **not** have `approve_closure` or `manage_settings`. Assign to staff in the EHS department.
- **`hs_manager` — "Health & Safety Manager"** (optional but recommended). Everything the officer has, plus `approve_closure`, `manage_settings`, and **all-region** visibility. If there is no separate head-office EHS lead, drop this role and give `approve_closure` to `regional_chief_manager` only (already planned below) and `manage_settings` to `super_admin`.

**Existing roles**

| Role | Gets |
|---|---|
| `regional_chief_manager` | `view_incidents`, `approve_closure`, `view_dashboard`, `view_equipment`, `export_reports` — own region |
| `district_manager` | `view_incidents`, `view_dashboard`, `view_equipment`, `record_checks`, `record_on_behalf` — own district |
| `hr_headoffice`, `hr_region` | Open decision (§10 Q6): injuries drive compensation and return-to-work, so HR may need `view_incidents` + `view_injury_details` |
| `chief_manager`, `departmental_manager`, `manager` | `report_incident` only |
| `employee` | `report_incident` only |
| `admin`, `ict_team` | `report_incident` only (`admin` is not a safety role; `super_admin` bypasses everything as elsewhere) |

Seeding follows the Credit Union / Commercial precedent exactly: **inside a migration** using `DB::table(...)->updateOrInsert(...)` for roles, permissions, `module_access` (every existing role listed explicitly) and `insertOrIgnore` for `role_permissions`; idempotent on re-run.

### 2.2 Data model

Numeric ids (UUIDs are Transport-only), no soft deletes: incidents are **cancelled**, equipment is **decommissioned**, sites are **deactivated** — history is never deleted. Flat models under `app/Models/` prefixed `Hs` (so `HsIncident` → `hs_incidents` with no `$table` override). `Schema::hasTable()` guards and explicit FK delete behaviour in every migration: `restrictOnDelete` for people/lookup refs, `cascadeOnDelete` for child rows, `nullOnDelete` for optional links.

**Phase 1 — core and incidents**

**`hs_sites`** — a place where things happen and equipment lives.

| Column | Notes |
|---|---|
| `name`, `kind` | `kind`: `head_office` \| `regional_office` \| `district_office` \| `pay_point` \| `depot` \| `other` |
| `region_id` (restrict), `district_id` (nullable, restrict), `address`, `is_active`, `created_by` | |
| unique `(region_id, kind, name)` | |

**`hs_incidents`**

| Column | Notes |
|---|---|
| `reference` | `HS-<REGION_INITIALS>-<YEAR>-<NNNN>`, unique; same generation approach as the Letters serial |
| `incident_type` | `near_miss` \| `injury` \| `property_damage` \| `environmental` \| `incident` \| `other` (exactly the form's six); `other_type_text` |
| `severity` | nullable until triage: `low` \| `medium` \| `high` \| `critical` |
| `context` | `regional_office` \| `district_office` \| `pay_point` \| `field_work` (the form's first question) |
| `region_id`, `district_id`, `department_id` | region from the reporter by default; `department_id` only for regional/head-office context |
| `site_id` (nullOnDelete), `site_name_raw` | picked site; or the typed pay-point/place name when "Other" was chosen (officer links later) |
| `location_detail` | "where exactly" (essential for field work) |
| `occurred_on` (date), `occurred_time` (nullable) | form is dd/MM/yyyy; not in the future |
| `description` | **required** |
| `first_aid` | `yes` \| `no` \| `no_need` (the form's three values) |
| `witness_name`, `witness_contact`, `no_witness` | optional |
| `reported_by_user_id`, `reported_by_employee_id` | the logged-in reporter |
| `recorded_by_user_id` | set only when recorded on behalf of someone else |
| `is_confidential` | hides the reporter from everyone except `hs_officer` / `hs_manager` / `super_admin` (§3.4) |
| `status` | `reported` \| `acknowledged` \| `investigating` \| `pending_closure` \| `closed` \| `cancelled` (indexed) |
| `owner_user_id` | officer responsible |
| `acknowledged_at/_by` | |
| `root_cause_category` | `human` \| `equipment` \| `process` \| `environment` \| `management` (nullable) |
| `findings` | investigation notes — **internal**, never shown to the reporter |
| `closure_note` | plain-language outcome — **shown to the reporter** ("closing the loop") |
| `closed_at/_by`, `approved_by/_at`, `cancel_reason`, `reopened_count` | |
| `reportable_externally` (bool), `external_ref`, `external_reported_on` | tracks statutory notification if the EHS dept confirms one applies (§10 Q5) |

Indexes: `(region_id, status)`, `(district_id, occurred_on)`, `(reported_by_user_id)`, `(incident_type, occurred_on)`.

**`hs_incident_persons`** — one row per person affected (an incident can have several). Restricted by `view_injury_details`.
`incident_id` (cascade), `employee_id` (nullable, restrict), `name_raw`, `person_type` (`staff` \| `contractor` \| `visitor` \| `public`), `injury_type`, `body_part`, `treatment` (`none` \| `first_aid` \| `clinic` \| `hospital`), `first_aider_name`, `lost_time_days` (default 0), `returned_to_work_on`.

**`hs_incident_actions`** — corrective/preventive actions.
`incident_id` (cascade), `description`, `assigned_to_employee_id` (restrict), `due_on`, `status` (`open` \| `done` \| `verified`), `completed_on`, `completion_note`, `verified_by/_at`, `created_by`. (Generalised to inspections in Phase 5 by a *new* migration adding a nullable `inspection_id`; nothing speculative now.)

**`hs_incident_attachments`** — `incident_id` (cascade), `path` (private disk, `health_safety/incidents/…`), `original_name`, `mime`, `size`, `uploaded_by`.

**`hs_incident_status_logs`** — `incident_id` (cascade), `from_status`, `to_status`, `note`, `user_id`, `created_at`. Same idea as `letter_status_logs`: the timeline on the detail screen.

**Phase 2 — fire extinguishers and first aid kits**

Each piece of equipment sits **either** at a site **or** in a vehicle (Transport fleet; a vehicle without a kit and extinguisher is a common compliance gap — confirm in §10 Q12): `site_id` and `vehicle_id` both nullable, **exactly one required** (validated in the service and tested; SQLite has no portable CHECK to rely on).

**`hs_fire_extinguishers`**
`asset_code` (unique, e.g. `FE-ACW-0001`), `serial_number`, `extinguisher_type` (`water` \| `foam` \| `dry_powder` \| `co2` \| `wet_chemical`), `capacity` (string, e.g. `9 L`, `6 kg`), `manufacturer`, `manufactured_on`, `site_id`, `vehicle_id`, `location_detail`, `responsible_employee_id`, `status` (`in_service` \| `out_for_service` \| `discharged` \| `decommissioned` — **manual lifecycle only**), and the dates that drive alerts: **`expiry_date`** (the one requested), `last_serviced_on`, `next_service_due`, `last_hydro_test_on`, `next_hydro_test_due`, `last_checked_on`, `notes`.

**`hs_extinguisher_checks`** — monthly visual check: `extinguisher_id` (cascade), `checked_on`, `checked_by`, `in_place`, `accessible`, `seal_intact`, `pressure_ok`, `no_damage`, `signage_ok`, `result` (`pass` \| `fail`), `notes`. Saving a check updates `last_checked_on`.

**`hs_extinguisher_services`** — `extinguisher_id` (cascade), `serviced_on`, `service_type` (`inspection` \| `refill` \| `recharge` \| `hydro_test` \| `replacement`), `vendor`, `certificate_path` (private disk), `new_expiry_date`, `next_service_due`, `notes`. Saving one updates the dates on the extinguisher in the same transaction. **No cost columns** — same call as Assets (no procurement tracking).

**`hs_first_aid_kits`** — `asset_code` (unique, `FAK-…`), `kit_type` (`small` \| `medium` \| `large` \| `vehicle`), `site_id`, `vehicle_id`, `location_detail`, `responsible_employee_id`, `status` (`in_service` \| `missing` \| `decommissioned`), `last_checked_on`, `notes`.
**`hs_first_aid_item_templates`** — `kit_type`, `item_name`, `required_qty`, `has_expiry`, `sort_order`. New kits are created pre-filled from the template for their type.
**`hs_first_aid_kit_items`** — `kit_id` (cascade), `item_name`, `required_qty`, `current_qty`, `expiry_date` (nullable).
**`hs_first_aid_kit_checks`** — `kit_id` (cascade), `checked_on`, `checked_by`, `result`, `restocked` (bool), `notes`. Saving a check (with item quantity/expiry updates) updates `last_checked_on`.

**Phase 3 — PPE**

- **`hs_ppe_types`** — `name`, `category` (head, eye/face, hearing, respiratory, hand, foot, body/hi-vis, fall/water), `has_sizes` (bool), `sizes` (JSON list when sized), `replacement_months` (service life), `has_expiry` (e.g. respirator filters), `unit`, `is_active`.
- **`hs_ppe_stock_movements`** — **the ledger and the source of truth**: `site_id` (the store), `ppe_type_id`, `size` (nullable), `movement_type` (`receipt` \| `issue` \| `return` \| `write_off` \| `adjustment` \| `transfer_in` \| `transfer_out`), `quantity` (**signed**), `reference`, `issue_id` (nullable), `notes`, `created_by`, `occurred_on`. Balance = `SUM(quantity)`. Nothing is edited; corrections are `adjustment` rows. This is deliberately the Credit Union posture (immutable lines) rather than a mutable counter that can drift.
- **`hs_ppe_reorder_levels`** — unique `(site_id, ppe_type_id)`, `level`.
- **`hs_ppe_issues`** — `employee_id` (restrict), `ppe_type_id`, `size`, `quantity`, `issued_on`, `issued_by`, `replace_due_on` (= issued_on + type's `replacement_months`), `status` (`issued` \| `returned` \| `lost` \| `damaged` \| `worn_out`), `returned_on`, `return_note`, `acknowledged_at` (the employee confirms receipt in *My PPE* — no signature pad needed unless §10 Q9 says otherwise). Creating an issue posts the ledger row in the same transaction.
- **`hs_ppe_entitlements`** — `job_title_id` (restrict), `ppe_type_id`, `quantity`; unique pair. Drives "who is missing PPE / overdue replacement" (the compliance gap report).

**Phase 4 — alerts**

- **`hs_expiry_alerts`** — dedupe log so the daily job notifies **once per item per threshold**: `item_type` (`extinguisher` \| `kit_item` \| `ppe_issue` \| `service`), `item_id`, `due_on`, `threshold_days`, `sent_at`; unique `(item_type, item_id, due_on, threshold_days)`. A string type with no FK is acceptable for a log table.

### 2.3 Derived rules (computed, never stored, so they cannot go stale)

Windows come from `config('gwl.…')` and are editable later (§6.2).

**Extinguisher state** — first match wins: `expired` (expiry_date < today) → `service_overdue` (next_service_due < today) → `hydro_overdue` → `expiring` (expiry within *warning days*) → `service_due_soon` → `check_overdue` (last_checked_on older than the check interval, or never) → `ok`. Decommissioned/discharged units are excluded from all "needs attention" lists.

**Kit state** — `item_expired` (any item expiry < today) → `item_expiring` → `incomplete` (any `current_qty < required_qty`) → `check_overdue` → `ok`.

**PPE issue state** — `overdue` (replace_due_on < today) → `replacement_due` (within warning days) → `ok`.

Implement as query scopes (`scopeExpired()`, `scopeExpiringWithin($days)`, `scopeNeedsAttention()`) on the models, used by **one** `EquipmentExpiryService` that feeds the screens, the dashboard, the daily command and the exports — a number and its export can never disagree, and every dashboard number drills into the real filtered rows (the Assets lesson).

**Incident lifecycle**

```
reported ──acknowledge──> acknowledged ──start──> investigating ──┬─(Low/Medium)──────────────> closed
                                                                  └─(High/Critical)─> pending_closure ──approve──> closed
any open status ──cancel (reason, e.g. duplicate)──> cancelled          closed ──reopen (reason)──> investigating
```

Closing rules (stated so they can be challenged in §10, not hidden): Near Miss / Other may close after acknowledgement with a note. Injury, Property damage, Environmental and Incident need a root-cause category and findings first. **Open actions do not block closure** — actions have their own lifecycle and overdue list — but a High/Critical incident cannot close without `approve_closure`.

### 2.4 Scoping and visibility

- A reporter always sees **their own** reports (status, severity, the timeline, and `closure_note` — **not** `findings`).
- `hs_officer`, `regional_chief_manager`: own region. `district_manager`: own district. `hs_manager`, `super_admin`, and head-office users holding a view permission: all regions. Region/district come from `$user->employee ?? $user->employeeByStaffId`.
- A new **`ScopesHealthSafetyByActor`** trait, modelled on `Assets\Concerns\ScopesAssetsByActor` — **a separate trait**, as `CLAUDE.md` warns against assuming shared code between the two `EnforcesModuleAccess` traits.
- Injury details (`hs_incident_persons`, first-aider name, lost time) are omitted for users without `view_injury_details`, on screen, in PDFs and in Excel alike. The Ghana Data Protection Act, 2012 (Act 843) treats health information as sensitive; it is worth the EHS/legal team confirming the retention period (§10).
- **One visibility service decides what each actor may see** (reporter identity under `is_confidential`, injury fields). The list, the detail screen, the print copy, the notifications and the exports all call it, so they cannot disagree. This is the lesson of Commercial's Phase 5b (small-group suppression done in the service, not in Blade).
- Layered authorization per `CLAUDE.md`: route middleware (`module:health_safety`, `permission:…`) **and** `enforceLivewireModule(Permission::MODULE_HEALTH_SAFETY)` plus a per-action guard in each Livewire component.

---

## 3. Incident reporting (replaces the four forms)

### 3.1 The report form (one screen on a phone, a short wizard on desktop)

1. **Where did it happen?** Four big choices — Regional Office · District Office · Pay Point · Field Work — exactly the form's own first question.
2. **Which place?** Depends on the choice:
   - *Regional Office* → department (from `departments`; the 11 on the form are the starting list). Region defaults from the reporter's employee record.
   - *District Office* → district (from `districts`), defaulted from the reporter.
   - *Pay Point* → pick from `hs_sites` of kind `pay_point` in that district, or **Other** and type the name.
   - *Field Work* → district + "where exactly" (street/landmark).
3. **What are you reporting?** The six types, with one line of plain-language help under each. Near Miss is explained as "Nothing happened this time, but it could have" — near misses are the most valuable reports and the least likely to be filed.
4. **When?** Date (default today, not in the future) and optional time.
5. **What happened?** Required, short text.
6. **Was first aid given?** Yes / No / No need. If the type is Injury and the answer is *No*, show a gentle prompt: "Does anyone need help now?"
7. **Witness** — name and contact, with a **No witness** tick.
8. **Optional:** photo (up to a few, size-limited), "I'd like my name kept confidential", and "I'm reporting for someone else" (only for users with `record_on_behalf`).

At the top of the form, an **emergency strip** with the emergency numbers (editable in settings; seed with the national numbers the team confirms). On submit: a reference number, a confirmation screen, and a **printable/PDF copy** — the Microsoft Form promised "you can print a copy of your answer", so this is an existing expectation to keep.

### 3.2 What the officer adds (so the reporter's form stays short)

On the incident screen the officer: **acknowledges**; sets **severity** (Low: no injury; Medium: first aid only; High: medical treatment or lost time; Critical: fatality, permanent disability or major spill — definitions editable in text, §10); may **reclassify the type**; adds **persons affected** (injury type, body part, treatment, lost-time days); records **findings and root-cause category**; creates **actions** with an assignee and due date; marks statutory notification if applicable; writes the **closure note** for the reporter; and closes (or sends for approval).

### 3.3 Notifications (Laravel database notifications = the general bell, plus mail where it matters)

Recipient resolution lives in one `IncidentNotificationService` (the `TransportNotificationService` precedent), triggered by `IncidentReported` / `IncidentStatusChanged` events and listeners (the `Events/Transport` + `Listeners/Transport` precedent).

| Event | Who |
|---|---|
| Any new report | `hs_officer`(s) of the region; the district manager of the district |
| Type **Injury** or **Environmental**, or the reporter ticked urgent | **plus mail** to the officer(s), `regional_chief_manager`, and `hs_manager` immediately |
| Severity set to High/Critical | `regional_chief_manager` and `hs_manager` |
| Sent for approval | approvers (`regional_chief_manager`, `hs_manager`) |
| Action assigned / due soon / overdue | the assignee; overdue also the officer |
| Closed or cancelled | the reporter (with `closure_note`) — unless the report was recorded on behalf of someone with no login |
| Not acknowledged within `hs_ack_hours` | the officer, then `hs_manager` (daily job, Phase 4) |

### 3.4 Confidential reporting

Because the Forms were anonymous, going to named reports could suppress honest reporting, especially near misses and anything involving a supervisor. The module therefore: (a) never shows the reporter's identity to district managers or department managers when `is_confidential` is ticked; (b) shows the reporter to the officer/manager only (they need to follow up); (c) says on the form, in one line, that the purpose is prevention. Whether to go further and allow fully anonymous submission is a decision for the team (§10 Q1), because it removes the ability to feed back to the reporter.

---

## 4. Inventory screens (Phases 2–4)

- **Fire extinguishers** — list with filters (district, site, type, state, expiring-in-N-days) and click-through from every dashboard number; detail with history (checks, services); *Record check* (the six-item checklist on a phone); *Record service* (updates expiry/next due, optional certificate upload); decommission with a reason. **Expiry date is a first-class column and a first-class filter.**
- **First aid kits** — list and detail with the contents table (required vs current quantity, expiry per item); *Record check* with quantity/expiry updates and a "restocked" flag.
- **PPE** — *Stock* (balance per store/type/size with low-stock highlighting from reorder levels); *Receive*, *Issue to staff* (employee picker → size → quantity; posts the ledger row), *Return/write-off*; *Types*; *Entitlements* (matrix by job title); *Gaps* (staff missing PPE or overdue replacement). Staff see **My PPE** (what they hold, replacement dates, confirm receipt).
- **Expiry register** (Phase 4) — one screen unioning extinguishers, kit items, PPE replacements and service dues, sorted by urgency, exportable to PDF for a site walk-round.
- **Daily command** `gwl:hs-daily-checks` (scheduler in `routes/console.php`, next to the visitor and transport entries): crossing 60 / 30 / 7 / 0 days before an expiry or service date notifies the region's officers and the equipment's responsible person once per threshold (`hs_expiry_alerts` prevents repeats); also the overdue-acknowledgement and overdue-action reminders. **Mirror the existing transport expiry command** (read it first) rather than inventing a second pattern.
- **Excel import** of the existing registers (Phase 2): a template for extinguishers and kits with preview → validate → confirm, in the shape of the Credit Union deduction import. Entering a few hundred existing extinguishers by hand is the single biggest obstacle to adoption, so this is part of Phase 2, not an afterthought. Do not reuse `DataImportService` (the Commercial doc makes the same call).

---

## 5. Beyond the brief — what else belongs in a Health & Safety module

You asked me to add anything I think would be good. Ranked, with a recommendation so you can cut:

| Addition | Why it matters | Recommendation |
|---|---|---|
| **Corrective actions tracker** | An incident register without follow-through is a filing cabinet | **In (Phase 1)**, incident-linked |
| **Feedback to the reporter** (`closure_note`) | Staff stop reporting if nothing visibly happens | **In (Phase 1)** |
| **Confidential-reporting flag** | Protects near-miss reporting after leaving anonymous forms | **In (Phase 1)** |
| **Printable copy of each report** | The form promised one | **In (Phase 1)** |
| **Monthly checks with a checklist** (extinguishers, kits) | Registers are only as good as their last check | **In (Phase 2)** |
| **Service records + certificate upload** | Proof for an audit or insurer | **In (Phase 2)** |
| **Excel import of existing registers** | Adoption | **In (Phase 2)** |
| **Equipment in vehicles** (link to Transport) | Fleet kits and extinguishers are a classic gap | **In (Phase 2)** if §10 Q12 is yes |
| **PPE entitlement by job title + gap report** | Turns an inventory into compliance ("who is not protected?") | **In (Phase 3)** |
| **QR labels** on kits/extinguishers (scan → record or check) | Fast checks on a phone; fewer mistakes | **Phase 3**, needs a **new composer QR package — your OK first** (login-required route, not public) |
| **Daily expiry alerts + expiry register** | The reason for the expiry date | **In (Phase 4)** |
| **Dashboard**: incidents by type/district/month, near-miss to injury ratio, **days since last injury / last lost-time injury** per district, overdue acknowledgements and actions, equipment compliance % | The management view | **In (Phase 4)** |
| **Site safety inspections** with checklist templates, findings feeding the same actions list | Finds problems before they become incidents | **Phase 5** |
| **Training and certificates** (first aiders, fire wardens, chlorine handling, working at heights, defensive driving) with expiry | A first aid kit means little without a valid first aider | **Phase 6** |
| **Fire / evacuation drills and toolbox talks** (attendance) | Evidence for field crews and offices | **Phase 6** |
| **Emergency contacts and assembly points per site** | Cheap and useful | **Phase 6** |
| **Hazard / risk register** | Proactive; bigger than it looks | Later, if asked |
| **Chemical Safety Data Sheets** | Only if treatment plants or chemical stores are in scope | Only if §10 Q10 says yes |
| **LTIFR / incident rates** | Needs hours worked | After §10 Q13 |
| **Import of the historical Microsoft Forms responses** | Continuity of trend data | **Phase 1b**, only if an Excel export of responses can be supplied |

---

## 6. Build notes

### 6.1 Services (`app/Services/HealthSafety/`) — thin Livewire, logic in services, per `CLAUDE.md`

`IncidentWorkflowService` (submit, acknowledge, triage, investigate, send for approval, close, cancel, reopen; writes the status log and calls Audit) · `IncidentReferenceGenerator` · `IncidentVisibility` (§2.4) · `IncidentNotificationService` · `EquipmentExpiryService` · `FireExtinguisherService` · `FirstAidKitService` · `PpeStockService` (post ledger rows, balances, issue/return) · `PpeComplianceService` · `HealthSafetyDashboardService` · `HealthSafetyReportData` (one class feeding screens, Excel and PDF, as `CommercialReportData` does). Excel exports in `app/Exports/HealthSafety/`, PDFs through Dompdf.

### 6.2 Config (`config/gwl.php`, mirrored in `.env.example`)

`health_safety_module_enabled` (false) · `hs_expiry_warning_days` (60) · `hs_expiry_critical_days` (30) · `hs_check_interval_days` (30) · `hs_extinguisher_service_months` (12) · `hs_ack_hours` (24) · `hs_investigation_due_days` (14) · `hs_attachment_max_mb` (5) · `hs_attachments_per_incident` (5) · `hs_export_max_rows`. Placeholders until §10 confirms them. A Settings screen reading these (as Commercial's Phase 5 did) is Phase 4, with `manage_settings`.

### 6.3 Audit (`App\Support\Audit::log`)

`health_safety.incident_reported`, `.incident_acknowledged`, `.incident_triaged`, `.incident_closed`, `.incident_reopened`, `.incident_cancelled`, `.action_created`, `.action_completed`, `.site_saved`, later `.extinguisher_serviced`, `.ppe_issued`, `.export`. Viewing injury details is **not** audited in v1; consider it if §10 Q6 gives HR access.

### 6.4 Tests (plain PHPUnit, `RefreshDatabase`, inline fixtures)

Each phase lists its own; common to all: a user without the permission gets 403 on the route **and** is blocked inside the Livewire action; a regional officer cannot see another region's data; the module is hidden when the flag is off; the seed migration is idempotent.

---

## 7. Suggested phased delivery

| Phase | Scope | Why this order |
|---|---|---|
| **1** | Foundation (permissions, roles, flag, nav, `hs_sites`) + **incident reporting end to end**: report form, My reports, register, triage, persons, investigation, actions, closure/approval, notifications, print copy | The Microsoft Forms are live today and collecting reports into an inbox nobody works. This retires them. |
| **2** | **Fire extinguishers + first aid kits**: registers, checks, service records, expiry states, vehicle link, Excel import | Your explicit expiry-date requirement; depends only on `hs_sites` from Phase 1 |
| **3** | **PPE**: types, stock ledger, issue/return, My PPE, entitlements and gaps, QR labels | |
| **4** | **Dashboard, expiry register, daily alerts, exports/PDF, Settings screen** | Needs data from 1–3 to be worth looking at |
| **5** | Site inspections; actions generalised | |
| **6** | Training, drills, toolbox talks, emergency info | |

Phases 2 and 3 can be swapped with Phase 1 if the equipment registers matter more right now; they only need `hs_sites` and the permissions, which Phase 1 creates. If you want that order, Phase 1 shrinks to "foundation + sites" and incidents move to Phase 3.

---

## 8. Phase 1 kickoff prompt — foundation + incident reporting (ready to paste into Claude Code)

```
Implement Phase 1 of the Health & Safety module for this ERP, per health-safety-module-design.md
(sections 1, 2 (Phase 1 tables only), 3 and the Phase 1 line of section 7). Read first: CLAUDE.md,
then the closest precedents — the Commercial and Credit Union modules:
database/migrations/2026_09_10_000002_seed_credit_union_module_access.php and the Commercial
seed migration; app/Models/Permission.php; app/Support/ErpNavigation.php (moduleDefinitions(),
sidebarFor(), and the creditUnionSidebar()/commercialSidebar() functions); the credit-union and
commercial route groups in routes/web.php; config/gwl.php; app/Support/Audit.php;
app/Livewire/Concerns/EnforcesModuleAccess.php; app/Livewire/Assets/Concerns/ScopesAssetsByActor.php;
app/Services/Letters/LetterWorkflowService.php (serial-number generation and status logs);
app/Events/Transport, app/Listeners/Transport and app/Services/Transport/TransportNotificationService.php
(event + listener + notification pattern); the Employee, District, Region, Department, JobTitle
models and app/Observers/EmployeeObserver.php. Several of these file names come from the docs
and were not verified against the repo — confirm each exists and adapt to what is really there;
say so in your as-built note if anything differs. Follow CLAUDE.md conventions throughout.

Scope — this phase is ONLY: migrations, permissions/roles/module access, navigation + feature
flag, sites, and incident reporting end to end. No equipment (extinguishers, first aid kits,
PPE), no dashboards, no scheduled commands, no exports other than the single-incident print
copy.

1. Migrations (new files; never edit existing ones; Schema::hasTable()/hasColumn() guards;
   explicit FK delete behaviour: restrictOnDelete for people/lookup refs, cascadeOnDelete for
   child rows, nullOnDelete for optional links; numeric ids, no UUIDs, no soft deletes):
   - create_health_safety_core_tables: hs_sites, hs_incidents, hs_incident_persons,
     hs_incident_actions, hs_incident_attachments, hs_incident_status_logs — exactly the columns,
     uniques and indexes in design §2.2 (Phase 1 block).
   - seed_health_safety_module_access, modelled on the Credit Union seed migration: roles
     hs_officer ("Health & Safety Officer") and hs_manager ("Health & Safety Manager"); the
     permissions in design §2.1 (all fourteen, module column = Permission::MODULE_HEALTH_SAFETY);
     a module_access row for EVERY existing role (TRUE for all of them, including employee,
     because every staff member must be able to report; the sidebar and permissions limit what
     they see); role_permissions via insertOrIgnore per the role table in design §2.1, with
     health_safety.report_incident granted to EVERY existing role. hr_headoffice/hr_region get
     report_incident only for now (design §10 Q6 is open). Use Permission::MODULE_HEALTH_SAFETY,
     not string literals. Idempotent on re-run.
2. Add Permission::MODULE_HEALTH_SAFETY ('health_safety') to the constants and MODULES.
3. config/gwl.php + .env.example: health_safety_module_enabled (GWL_HEALTH_SAFETY_MODULE_ENABLED,
   default false), hs_ack_hours (24), hs_investigation_due_days (14), hs_attachment_max_mb (5),
   hs_attachments_per_incident (5). In ErpNavigation::moduleDefinitions() add the module (slug
   health_safety, title "Health & Safety", an icon already in use, route health_safety.home) and
   extend the existing array_filter so it is hidden unless the flag is on, exactly like credit_union.
   Add healthSafetySidebar($user) and its arm in sidebarFor(): Overview (health_safety.home,
   needs view_dashboard OR view_incidents), Report an incident (report_incident), My reports
   (report_incident), Incidents (view_incidents), Actions (view_incidents), Sites
   (manage_master_data); each item with a 'can' closure using permission slugs or super_admin.
   health_safety.home for a user WITHOUT view_incidents redirects to the report form.
4. Models (flat under app/Models/, prefix Hs, no factories): HsSite, HsIncident, HsIncidentPerson,
   HsIncidentAction, HsIncidentAttachment, HsIncidentStatusLog, with string constants for
   statuses/types/severities/contexts in the style of CreditUnionDeductionBatch, and relationships.
5. Scoping: app/Livewire/HealthSafety/Concerns/ScopesHealthSafetyByActor.php (a SEPARATE trait;
   do not reuse the Assets one) implementing design §2.4: region from
   $user->employee ?? $user->employeeByStaffId; officers/regional chief = own region; district
   manager = own district; hs_manager, super_admin and head-office users with a view permission
   = all; reporter always sees own reports.
6. Services in app/Services/HealthSafety/: IncidentReferenceGenerator (HS-<REGION_INITIALS>-<YEAR>-<NNNN>,
   unique, retry on collision; reuse the initials logic from the Letters serial by copying, not
   refactoring), IncidentVisibility (single place deciding what an actor may see: reporter
   identity when is_confidential, and the injury fields in hs_incident_persons without
   view_injury_details; the list, detail, print copy and notifications must all call it),
   IncidentWorkflowService (submit, acknowledge, triage, start investigation, send for approval,
   approve+close, close, cancel with reason, reopen with reason; every transition writes an
   hs_incident_status_logs row and an Audit::log entry; enforce the closing rules in design §2.3:
   Injury/Property damage/Environmental/Incident need root_cause_category and findings before
   close; High/Critical go to pending_closure and need approve_closure; open actions do NOT block
   closure), IncidentNotificationService (recipients per design §3.3; database notifications for
   all, mail only for Injury/Environmental/urgent and High/Critical). Use Events
   (IncidentReported, IncidentStatusChanged) + a listener, following the Transport pattern.
   Notifications under app/Notifications/HealthSafety/.
7. Livewire (app/Livewire/HealthSafety/, thin Blade wrappers in resources/views/health_safety/,
   real templates in resources/views/livewire/health_safety/), mobile-first (most reporters
   will be on phones):
   - Home (counts for officers/managers; redirect for plain employees)
   - ReportIncident per design §3.1: four context choices; place selection by context
     (department / district / hs_sites pay point or "Other" free text / district + location_detail);
     the six types with one-line help; date (default today, not future) + optional time; required
     description; first aid Yes/No/No need; witness name+contact with a "No witness" tick;
     optional photos (private disk, limits from config); confidential tick; "reporting for someone
     else" only for record_on_behalf holders; emergency strip at the top (text from config for
     now). On submit show reference + confirmation + link to the printable copy. Region,
     district and department default from the reporter's employee record.
   - MyReports (own reports, status, closure_note once closed; never findings)
   - IncidentIndex (filters: status, type, severity, district, date range, reference/search; scoped
     by the trait; confidential reporters shown as "Confidential" to those not entitled)
   - IncidentShow (details, timeline from status logs, triage panel for manage_incidents,
     persons-affected section gated by view_injury_details, investigation fields, actions CRUD
     with assignee/due date, attachments, closure note, close/send-for-approval/approve/cancel/
     reopen with required reasons where the design says so)
   - Actions (list scoped like incidents; an assignee may mark their OWN action done without
     manage_incidents; verification needs manage_incidents)
   - Sites (list/create/edit/deactivate; manage_master_data; seed nothing)
   - A print view + Dompdf PDF of a single incident (reporter or entitled viewer), built through
     IncidentVisibility so it never leaks what the screen hides.
   Use Livewire\Concerns\EnforcesModuleAccess (enforceLivewireModule(Permission::MODULE_HEALTH_SAFETY))
   AND a per-action permission guard in every component. Routes: a health_safety. group in
   routes/web.php with module:health_safety and permission: middleware, in the shape of the
   existing module blocks. Attachments are served through an authorised controller route, never
   a public URL.
8. Audit: health_safety.incident_reported, .incident_acknowledged, .incident_triaged,
   .incident_closed, .incident_reopened, .incident_cancelled, .action_created, .action_completed,
   .site_saved.
9. Tests under tests/Feature/HealthSafety/ (plain PHPUnit, RefreshDatabase, inline fixture
   builders in the style of tests/Feature/Staff/EmployeeDeactivationTest.php, Notification::fake()).
   Cover: an employee can submit a report for each of the four contexts and gets a unique
   reference; description is required and a future date is rejected; "No witness" lets the
   witness fields be empty; an employee sees only their own reports and 403s on the register;
   a regional officer sees their region only; a district manager sees their district only;
   is_confidential hides the reporter from a district manager but not from hs_officer;
   injury details are hidden without view_injury_details on screen AND in the print copy;
   triage sets severity and writes a status log; Low/Medium incidents close, High/Critical stop at
   pending_closure and only an approver can close; Injury cannot close without root cause and
   findings; open actions do not block closure; an assignee can complete their own action but
   not someone else's; cancel and reopen require a reason; the reporter sees closure_note but
   never findings; notifications go to the right recipients (officer + district manager on
   every report; mail only for Injury/Environmental/urgent); reference numbers do not collide
   under two quick submissions; a user without health_safety.report_incident gets 403 on the
   report route; the module is hidden when the flag is off and visible when on; the seed migration
   is idempotent on re-run and grants report_incident to every role.
   Run: php artisan test --filter=HealthSafety. Report back anything in design §10 you had to
   assume, and add "8.1 Phase 1 as built" to docs/health-safety-module-design.md (decisions the
   design did not state, what you skipped, and anything you are unsure about).
   Do not change real data and do not commit.
```

### 8.1 Phase 1 as built

**Built 2026-10-07**, uncommitted, on `feat/assets-mdm` alongside the Commercial work. 86 tests in `tests/Feature/HealthSafety/` pass on in-memory SQLite (`php artisan test --filter=HealthSafety`). The module is behind `GWL_HEALTH_SAFETY_MODULE_ENABLED` (off by default; `phpunit.xml` turns it on). Nothing was run against the real MySQL database and no migration was applied there.

**What was checked against the repo.** Every file the prompt named exists and matches the docs: `ErpNavigation` (`moduleDefinitions()`, `sidebarFor()`, `creditUnionSidebar()`/`commercialSidebar()`), the Credit Union and Commercial seed migrations, `Permission`, `Audit`, both `EnforcesModuleAccess` traits, `ScopesAssetsByActor`, the Transport event/listener/notification-service trio, `Employee`/`District`/`Region`/`Department`/`JobTitle` and `EmployeeObserver`. Differences worth knowing:

- The design file was first saved as `docs/claude_health-safety-module-design.md` and has since been renamed to `docs/health-safety-module-design.md`, the name the build prompt uses; this section lives here.
- Letters serials come from `LetterWorkflowService::nextSnNumber()` over a `letter_sn_counters` table and `Region::assignLetterPrefix()`. Incident references reuse that **public region prefix method** (no copy, no refactor) but not the counter table, which would be a seventh Phase 1 table: see "Decisions" below.
- Transport notifies through the shared `App\Notifications\GeneralDatabaseNotification`, so Health & Safety does the same instead of adding classes under `app/Notifications/HealthSafety/`.
- The `employee` role is the implicit base role (never listed in UAC) and every login has it, so granting `report_incident` to every role reaches everyone.

**Files.** Migrations `2026_10_07_000001_create_health_safety_core_tables` and `2026_10_07_000002_seed_health_safety_module_access`; `HealthSafetyRolePermissionSeeder` (plus `RoleSeeder`, `PermissionSeeder`, `ModuleAccessSeeder`, `DatabaseSeeder` entries, so fresh installs and tests match the migration); models `HsSite`, `HsIncident`, `HsIncidentPerson` (table named explicitly: Laravel would pluralise to `people`), `HsIncidentAction`, `HsIncidentAttachment`, `HsIncidentStatusLog`; `app/Services/HealthSafety/` (`IncidentVisibility`, `IncidentWorkflowService`, `IncidentNotificationService`, `IncidentReferenceGenerator`); events `IncidentReported`, `IncidentStatusChanged`, `IncidentSeverityRaised` with one listener class `NotifyIncidentStakeholders` (registered in `AppServiceProvider`); Livewire `Home`, `ReportIncident`, `MyReports`, `IncidentIndex`, `IncidentShow`, `Actions`, `Sites` plus `Concerns/ScopesHealthSafetyByActor`; controllers `HealthSafetyModuleController` and `IncidentDocumentController` (print, PDF, photos); routes in the `health_safety.` group in `routes/web.php`.

**Decisions the design did not state**

1. **Two columns added to `hs_incidents`:** `is_urgent` (section 3.3 and the prompt both mail on "urgent", but 2.2 had nowhere to store the tick) and `reporter_name_raw` (a report recorded for someone with no login needs to say who it is for).
2. **Reference numbers** are one more than the highest issued for that region prefix and year (found by length then value, so 9999 sorts below 10000), with the unique index as the final guard and `submit()` retrying with a fresh number on a collision. No counter table. This is race-safe, not lock-based; if volume ever makes retries common, copy the Letters counter table.
3. **Visibility lives in one service.** `IncidentVisibility` decides scope, who sees the reporter, injury details, findings and timeline notes, and builds the payload the print copy and PDF render from. `ScopesHealthSafetyByActor` is only the Livewire wrapper and is a separate trait from the Assets and Commercial ones.
4. **Scope.** super_admin and `hs_manager`: every region. Anyone at Head Office holding `view_incidents`: every region (recognised by `location_type`, as in Commercial). `hs_officer`, `regional_chief_manager` and any other role holding `view_incidents`: own region. `district_manager`: own district. A reporter (or whoever recorded it) always sees their own reports. No employee record or no region means nothing.
5. **Confidential reports** also hide the reporter from the "by" column of the timeline (it shows "Reporter"), and notices never name any reporter. This was a real leak found by a test while building it.
6. **The reporter's timeline** shows moves between statuses only, never notes (a cancel or reopen reason is internal). The closure note is shown to the reporter only once the incident is **closed**; the draft written while it waits for approval stays hidden.
7. **Closing rules beyond section 2.3.** A severity is required before closing or sending for approval (otherwise a High incident could skip its approver); a closure note is always required; the incident must be acknowledged; only High and Critical can be sent for approval. Triage is refused while an incident is `pending_closure` (otherwise lowering severity would walk it past the approver). An approver may **return it for rework** (`pending_closure` -> `investigating`, with a reason): the design had no way back for an approver who disagrees.
8. **Acknowledging.** Triage and "start investigation" acknowledge a still-new report themselves.
9. **No second-person rule.** `hs_manager` holds both the officer rights and `approve_closure`, so one person can send a High incident for approval and approve it. Say if that should be blocked.
10. **Global Admin** (`admin`) now reaches the module: `User::getAccessibleModules()` limits that role to UAC and Assets, so `health_safety` was added to its allow-list (every member of staff must be able to report). It still holds `report_incident` only.
11. **`hs_officer` / `hs_manager` are not `ict_assignable`**, like the Commercial roles: only Global Admin and super_admin assign them.
12. **Photos:** JPG, PNG or WebP only, on the private `local` disk under `health_safety/incidents/<id>/`, served through an authorised route (`health_safety.attachments.show`) with `nosniff` and no caching. The reporter adds photos on the form; afterwards only an officer can add more. Photos are listed in the print copy and PDF but not embedded.
13. **Emergency strip.** `GWL_HS_EMERGENCY_CONTACTS` (`Ambulance: 193 | Fire: 192`) is **empty by default and the strip is hidden while it is**; I did not invent numbers for a safety form. The team must confirm and set them.
14. **People affected.** Entered by staff ID (linked to the employee) or by name, only while the incident is open and not yet awaiting approval; needs both `manage_incidents` and `view_injury_details`.
15. **Actions.** The Actions screen shows actions on incidents in the viewer's scope plus any assigned to them, so an assignee with no safety permission can mark their own done and sees an "Actions" item in the sidebar. A reporter does not see an incident's actions.
16. **Overview** is counts that link to the filtered register (`?status=reported`, `in_progress`, `pending_closure`; actions `?filter=overdue`). Charts and rates are Phase 4. `hs_ack_hours` and `hs_investigation_due_days` only drive an "overdue" badge and an "investigation due" date for now; the reminder job is Phase 4.
17. **UI:** two `status-pill` domains (`hs-incident`, `hs-action`, documented in `docs/11-ui-components.md`); the module uses the existing `triangle-alert` icon.

**Section 10 answers assumed.** Q1 named reports with the confidentiality tick; Q2 `record_on_behalf` for district managers and officers; Q3 one officer role per region, no district focal role; Q4 `hs_manager` exists; Q5 external notification only recorded, no reminders; Q6 HR gets `report_incident` only; Q14 severity levels and wording as proposed (the definitions are in the triage hint); Q15 the form reads districts from master data, so whichever spelling HR holds is what shows.

**Skipped on purpose.** Equipment, PPE, dashboards with charts, the daily command and acknowledgement / overdue-action reminders, Excel/PDF exports of lists (only the single-incident print copy and PDF exist), QR labels, importing the old Microsoft Forms responses, Settings screen. Not built either: editing a person row (remove and re-add), a completion note box on the Actions list (the service takes one; the incident screen does not ask for it yet), a "closed by" display for approvers.

**Unsure or unverified**

- The screens were not looked at in a browser. They are built from existing `x-ui.*` components and CSS classes (checked to exist) and every screen renders in the tests, but spacing and mobile behaviour on a phone are unchecked. A local browser check needs the Laragon host (HTTPS is forced in `AppServiceProvider`) and that points at the real MySQL database.
- The suite runs on SQLite. The reference query uses `LENGTH()` and `lockForUpdate()`, which are valid on MySQL but untested there.
- `Livewire` multi-file upload (`wire:model` on an array) is tested with fakes; real phone uploads of large photos depend on PHP `upload_max_filesize` and Livewire's temporary-upload limit.
- Mail goes out synchronously (the notification is not queued), as for Transport. A slow mail server would slow the "Submit" click for an urgent report.

**To switch it on.** Set `GWL_HEALTH_SAFETY_MODULE_ENABLED=true`, run `php artisan migrate` (adds the tables, the two roles, the permissions and the module access rows; nothing is seeded into the tables), give the EHS staff the `hs_officer` / `hs_manager` roles in UAC, add the pay points under Health & Safety > Sites, and set `GWL_HS_EMERGENCY_CONTACTS`. No queue worker or scheduler is needed yet.

8.2 Phase 1 review notes (2026-10-07)

Read against the design: nothing in the as-built contradicts it, and the additions are the right ones. In particular:

Decision 5 (confidential reporter leaking through the timeline "by" column) is the kind of leak that only shows up when a test looks for it. Good catch, and it confirms that one visibility service was the right call.
Decision 7 (severity required before close or approval; triage refused while pending_closure) closes two ways a High incident could have walked past its approver. The "return for rework" path was a genuine gap in the design.
Decision 13 (emergency strip hidden while empty) is correct. Nobody should ship invented numbers on a safety form.

One decision is yours: the second-person rule (as-built decision 9). Today hs_manager can send a High/Critical incident for approval and then approve it themselves. The design's intent was separation of duties. Phase 2 adds GWL_HS_REQUIRE_SECOND_APPROVER (default false, so behaviour does not change until you decide): when true, the user who sent an incident for approval cannot approve it. My recommendation is to turn it on once there are at least two people who can approve in each region (regional_chief_manager counts). If one person is the only approver, leave it off.

Carried into Phase 2 as small follow-ups: a completion-note box on the Actions list (the service already accepts one), and "closed by / approved by" on the incident screen.

8.3 Before Phase 2: checks (about 20 minutes, high value)

A. The real database. Do these first and in this order.

The suite only ran on SQLite, but the reference-number query uses LENGTH() and lockForUpdate(). Run the HS tests once against a throwaway empty MySQL database (for example erp_test in Laragon), by setting DB_CONNECTION=mysql and DB_DATABASE=erp_test for that run only. RefreshDatabase wipes whatever database it points at, so never point it at the real one. Check the name before pressing Enter. If anything fails on MySQL, fix it before Phase 2 builds on it.
On the real database: back it up, run php artisan migrate --pretend, read the SQL, then migrate. The work is uncommitted on feat/assets-mdm next to the Commercial work; when you are happy, commit Health & Safety on its own (git add the specific paths) so the two can be reviewed and reverted separately.

B. A look in a browser (set GWL_HEALTH_SAFETY_MODULE_ENABLED=true; the Laragon host is needed because HTTPS is forced):

As a plain employee: only Report an incident and My reports in the sidebar. Submit one report for each of the four contexts; each gets a unique reference.
As hs_officer: acknowledge, set severity, add a person affected and an action, close. For an Injury, confirm it refuses to close without root cause and findings.
A High/Critical report stops at pending closure. The officer cannot close it; regional_chief_manager can.
Tick confidential, open it as a district manager: the reporter shows as "Confidential", including in the timeline.
Open the print copy as a user without view_injury_details: no injury fields.
As the employee, open a closed report: closure note visible, findings never.
On a real phone: the report form fits without awkward scrolling, and a real photo from the camera uploads. If it fails, check PHP upload_max_filesize / post_max_size and Livewire's temporary-upload limit before blaming the code.
Submit one Injury report and confirm the officer and district manager get the bell notification, and that mail goes out only for Injury/Environmental/urgent. Note how long the Submit click takes; mail is synchronous, so a slow mail server shows up here.

C. Not code, but needed before go-live: set GWL_HS_EMERGENCY_CONTACTS once the team confirms the numbers; give the EHS staff hs_officer / hs_manager in UAC; add the pay points under Health & Safety > Sites (Phase 2 equipment is placed at these sites, so the list matters).

8.4 Phase 2 scope — fire extinguishers and first aid kits

Assumptions I am making because three §10 questions are still open (tell me if any is wrong and I will change the prompt):

Q8 (extinguisher dates): all three are stored — expiry_date, next_service_due, next_hydro_test_due — and each gets its own state and filter. Nothing breaks if the team only tracks one; the others stay empty and are ignored.
Q7 (existing registers): the Excel import uses the columns proposed below, with tolerant header matching. When a sample file arrives, the template changes, not the design.
Q12 (vehicles): equipment can sit in a Transport vehicle instead of a site. If the answer is no, hide the vehicle picker; nothing else changes.

Where Phase 2 deliberately departs from design §2.2 (each one explained, so they are decisions, not accidents):

hs_fire_extinguishers and hs_first_aid_kits also get region_id and district_id, copied from the site or vehicle when the item is saved. Without them, scoping a list that is also filtered by state needs joins to two tables and gets fragile. Cost: they can drift, so saving a site whose region/district changed updates its equipment in the same transaction (tested).
Both tables get last_check_result (pass/fail, nullable) so a failed check can be a filterable state in SQL. Extinguishers also get decommissioned_on and decommission_reason; kits get the same.
Service type replacement is dropped. Replacing a unit means decommissioning the old one (with a reason) and adding a new one, so each physical unit keeps its own history. The types are inspection, refill, recharge, hydro_test.
The state lists in §2.3 gain check_failed and, for kits, missing (see below).

States (computed, first match wins; SQL scopes and a PHP accessor must agree). Only in_service and out_for_service extinguishers, and in_service kits, are evaluated; discharged and decommissioned items are excluded from every "needs attention" count.

Extinguisher: expired → service_overdue → hydro_overdue → check_failed → expiring (expiry within hs_expiry_warning_days) → service_due_soon → hydro_due_soon → check_overdue → ok.
Kit: missing (status) → item_expired → item_expiring → incomplete (any item below required quantity) → check_failed → check_overdue → ok.
Check baseline: last_checked_on, or the record's creation date if never checked. Newly imported items are therefore not all "overdue" on day one; they become overdue one interval after they were added.
critical is a display flag layered on top (expiry within hs_expiry_critical_days), not a separate state.

Screens (sidebar under Health & Safety, view_equipment; officers also see Import and Kit templates):

Fire extinguishers: list with filters (district, site/vehicle, type, state, "expiring in N days") and an expiry date column that sorts; a detail screen with the three dates, check history and service history; actions Record check, Record service, Out for service, Discharged, Decommission (reason required); create/edit form.
First aid kits: list and detail with the contents table (required vs current, expiry); Record check (update quantities/expiry, tick restocked, result); create/edit; Missing and Decommission with a reason.
Kit templates (manage_master_data): per kit type, the standard items. Nothing is seeded. A Load suggested starter items button fills an editable list; its wording says to confirm contents with the EHS department or a first aid trainer, because the standard contents are a safety judgement, not mine to hard-code.
Import (manage_equipment): see the prompt.
Overview gets a small Equipment row for anyone with view_equipment: expired, expiring within 30 days, check overdue, each linking to the filtered list.

Rules: a responsible person may record a check on their own item without record_checks; checks and services are immutable (a mistake is corrected by a new record); no cost columns, as designed.

8.5 Phase 2 kickoff prompt (ready to paste into Claude Code)
Implement Phase 2 of the Health & Safety module for this ERP, per health-safety-module-design.md
(sections 2.2 Phase 2 block, 2.3, 4, and 8.4 of that file, which lists where this phase
deliberately departs from 2.2 — follow 8.4 where they differ). Phase 1 is built (see 8.1).
Read first: CLAUDE.md; 8.1 Phase 1 as built; app/Services/HealthSafety/IncidentVisibility.php and
app/Livewire/HealthSafety/Concerns/ScopesHealthSafetyByActor.php (how actor scope is resolved),
app/Livewire/HealthSafety/Sites.php and IncidentShow.php (screen, permission-guard and audit
patterns), app/Models/HsSite.php, the Phase 1 migrations, app/Models/Vehicle.php,
app/Policies/VehiclePolicy.php and app/Repositories/Transport/VehicleRepository.php (how vehicles
are scoped, whether they carry region/district, uuid route key, soft deletes),
app/Console routes/console.php and the transport expiry-check code (so the expiry windows here
use the same idea), app/Services/Credit-Union DeductionImportService.php and
app/Imports/RawRowsImport.php (preview -> validate -> confirm shape and cell normalisation;
copy the normalising behaviour, do not refactor those files and do not reuse DataImportService),
and config/gwl.php (gwl.max_import_failure_percent). Confirm every file exists and adapt to what
is really there; say so in the as-built note if anything differs. Follow CLAUDE.md throughout.

Scope — this phase is ONLY: the extinguisher and first aid kit registers, checks, service
records, computed states, kit templates, vehicle link, Excel import, an equipment row on the
Overview, and the Phase 1 follow-ups in item 10. No PPE, no dashboard charts, no scheduled
command or alerts, no exports of lists, no QR labels.

1. Migration (new file, e.g. 2026_10_08_000001_create_health_safety_equipment_tables; never edit
   existing ones; hasTable/hasColumn guards; restrictOnDelete for people/lookup refs,
   cascadeOnDelete for child rows, nullOnDelete for optional links; numeric ids, no soft deletes):
   hs_fire_extinguishers, hs_extinguisher_checks, hs_extinguisher_services, hs_first_aid_kits,
   hs_first_aid_item_templates (unique kit_type+item_name), hs_first_aid_kit_items,
   hs_first_aid_kit_checks — the columns in design 2.2 Phase 2 PLUS the departures in 8.4:
   region_id and district_id on both equipment tables; last_check_result on both;
   decommissioned_on and decommission_reason on both. site_id and vehicle_id both nullable,
   exactly one required (enforced in the service and tested; do not rely on a CHECK constraint).
   vehicle_id references vehicles.id (numeric PK), nullOnDelete; check how Vehicle soft-deletes
   and say what you did. Indexes: (region_id, status), (expiry_date), (next_service_due),
   (site_id), (vehicle_id) on extinguishers; (region_id, status), (site_id) on kits;
   (kit_id, expiry_date) on kit items. asset_code unique on both tables. No permissions are
   needed: the Phase 1 seed already created manage_equipment, record_checks, view_equipment,
   manage_master_data and granted them. Do not add a seed migration unless something is
   actually missing (check, and say what you found).
2. config/gwl.php + .env.example: hs_expiry_warning_days (60), hs_expiry_critical_days (30),
   hs_check_interval_days (30), hs_extinguisher_service_months (12),
   hs_extinguisher_hydro_years (5, only used to PRE-FILL next_hydro_test_due, always editable;
   note in a comment that the real interval depends on extinguisher type and must be confirmed),
   hs_equipment_attachment_max_mb (5). Read them with config('gwl.…') everywhere; no literals.
3. Models (flat under app/Models, prefix Hs, string constants for statuses/types/states in the
   Phase 1 style, relationships, no factories): HsFireExtinguisher, HsExtinguisherCheck,
   HsExtinguisherService, HsFirstAidKit, HsFirstAidItemTemplate, HsFirstAidKitItem,
   HsFirstAidKitCheck. Put the state logic in query scopes (scopeExpired, scopeExpiringWithin,
   scopeServiceOverdue, scopeCheckOverdue, scopeNeedsAttention, scopeWithState($state)) AND a PHP
   state() accessor, both implementing the first-match-wins orders in 8.4 with the check
   baseline = last_checked_on ?? created_at. Only in_service/out_for_service extinguishers and
   in_service kits are evaluated.
4. Services in app/Services/HealthSafety/: EquipmentScope (or extend what IncidentVisibility
   already resolves — do NOT duplicate the region/district/head-office rules; if the existing
   resolution is private, extract it into a small shared class used by both and let the 86
   existing tests prove nothing changed), EquipmentExpiryService (counts and filtered queries by
   state for the screens and the Overview; this is the ONE place Phase 4 will reuse),
   FireExtinguisherService (create/update, record check, record service, change status,
   decommission), FirstAidKitService (create pre-filled from templates, update items, record
   check, decommission), EquipmentImportService (item 7). Rules:
   - region_id/district_id come from the site (or the vehicle, as far as Vehicle carries them —
     if it does not, the form asks for them) at save time; saving a site whose region or
     district changed updates its equipment in the same transaction (add this to the Sites save).
   - recording a service: one transaction that writes hs_extinguisher_services and updates
     last_serviced_on, expiry_date (if new_expiry_date given), next_service_due (given, else
     serviced_on + hs_extinguisher_service_months), and for hydro_test also last_hydro_test_on
     and next_hydro_test_due (given, else + hs_extinguisher_hydro_years); returns an
     out_for_service unit to in_service. service_type is one of inspection, refill, recharge,
     hydro_test (no 'replacement'). Certificate upload: PDF/JPG/PNG, private disk
     health_safety/equipment/<id>/, served through an authorised route with nosniff, no caching.
   - recording a check updates last_checked_on and last_check_result in the same transaction.
     Checks and services are immutable (no edit/delete in the UI or service).
   - a kit is created pre-filled by COPYING the templates for its kit_type (editing a template
     later never changes existing kits); with no templates the kit is created empty and the
     screen links to Kit templates.
   - decommission requires a reason and writes decommissioned_on.
   - asset_code: if blank on create, generate FE-<REGION_PREFIX>-<NNNN> / FAK-<REGION_PREFIX>-<NNNN>
     with the same "highest + 1 by length then value, unique index as the guard, retry on
     collision" approach as IncidentReferenceGenerator and Region::assignLetterPrefix(); if the
     officer types an existing tag, keep it (unique).
   - every mutation calls Audit::log: health_safety.extinguisher_saved, .extinguisher_checked,
     .extinguisher_serviced, .extinguisher_status_changed, .extinguisher_decommissioned,
     .kit_saved, .kit_checked, .kit_decommissioned, .kit_template_saved, .equipment_imported.
5. Authorization (layered, as in Phase 1): routes under the health_safety. group with
   module:health_safety and permission: middleware (view_equipment for lists/details;
   manage_equipment for create/edit/service/status/decommission/import; record_checks for
   checks; manage_master_data for kit templates), PLUS enforceLivewireModule(...) and a
   per-action guard in every component. A user named as responsible_employee_id on an item may
   record a check on THAT item even without record_checks (ownership rule), and nothing else.
   Scope every list, detail and action by the actor's region/district (officer and regional
   chief: region; district manager: district; hs_manager, super_admin and head-office viewers:
   all), through the shared scope class, not inline.
6. Livewire (app/Livewire/HealthSafety/, thin Blade wrappers in resources/views/health_safety/,
   templates in resources/views/livewire/health_safety/, mobile-first, built from the existing
   x-ui.* components and status-pill, adding status-pill domains hs-extinguisher and hs-kit
   to docs/11-ui-components.md): Extinguishers (list; filters district, site/vehicle, type,
   state, "expiring within N days"; sortable expiry column; ?state= and ?expiring= query
   parameters so Overview counts can link to them), ExtinguisherShow (three dates, history of
   checks and services, Record check, Record service, Out for service, Discharged, Decommission),
   ExtinguisherForm, FirstAidKits, FirstAidKitShow (contents table, Record check with item
   quantity/expiry edits, restocked tick, Missing, Decommission), FirstAidKitForm, KitTemplates
   (per kit type; "Load suggested starter items" button that fills an EDITABLE list and shows
   a note to confirm contents with EHS or a first aid trainer; do NOT seed any template rows),
   EquipmentImport. The check forms must work one-handed on a phone: large toggles for the
   yes/no items, one screen. Extend healthSafetySidebar($user) with Fire extinguishers and
   First aid kits (view_equipment) and, for officers, Kit templates and Import. Extend the
   Overview with an Equipment row for view_equipment holders: expired, expiring within
   hs_expiry_critical_days, check overdue (counts from EquipmentExpiryService, each linking to
   the filtered list). Where a vehicle is the location, show the registration/fleet number
   and link to the vehicle only if the actor can see that vehicle.
7. Excel import (EquipmentImportService + EquipmentImport component; app/Imports/HealthSafety/;
   two templates, extinguishers and first aid kits, downloadable; CREATE-ONLY):
   - extinguisher columns: asset_code (optional), serial_number, type (water, foam,
     dry_powder, co2, wet_chemical; accept case-insensitive and a few obvious spellings such as
     "dry powder" and "CO2"), capacity, manufacturer, manufactured_on, site (name), district
     (optional, to disambiguate), location_detail, expiry_date, last_serviced_on,
     next_service_due, last_hydro_test_on, next_hydro_test_due, responsible_staff_id, notes.
     Kit columns: asset_code (optional), kit_type, site, location_detail, responsible_staff_id,
     last_checked_on, notes (items are NOT imported; they are created from templates).
   - header matching is tolerant (trim, case, spaces/underscores). Dates accept real Excel dates
     and dd/MM/yyyy text (the app's convention), reject anything ambiguous with a row error.
   - preview -> validate -> confirm, in the shape of the Credit Union deduction import
     (errors, warnings, counts, blocked) with one transactional insert on confirm and no
     persisted batch table. Row errors: unknown site (list the distinct unmatched names so the
     officer can add them under Sites and re-upload), unknown type, unparseable date, staff id
     not found, duplicate asset_code in the file. An asset_code that already exists in the
     database is SKIPPED with a warning (create-only). Block the run above
     gwl.max_import_failure_percent. Scoped: an officer can only import into sites of their own
     region; the import never creates sites. Audit one equipment_imported row with counts.
   - generate SMALL synthetic .xlsx fixtures in the tests; do not use or commit real registers.
8. Routes: extend the health_safety. group in routes/web.php (names such as
   health_safety.extinguishers.index/show/create/edit, health_safety.kits.*,
   health_safety.kit-templates, health_safety.equipment-import, health_safety.equipment-files.show
   for certificates).
9. Tests under tests/Feature/HealthSafety/ (plain PHPUnit, RefreshDatabase, inline builders, no
   factories, same style as the Phase 1 tests). Cover: each state in 8.4 is reached by the right
   fixture and the order is respected (expired beats expiring; check_failed beats check_overdue;
   missing beats everything for a kit); THE SQL SCOPES AND THE PHP state() ACCESSOR AGREE across a
   grid of fixtures (one test, many rows — this is what keeps the Overview counts, the list
   filters and Phase 4 consistent); decommissioned and discharged items are excluded from
   needs-attention; the check baseline (a never-checked item created yesterday is not overdue,
   one created a full interval ago is); recording a service updates the three dates correctly,
   pre-fills next_service_due and next_hydro_test_due from config, and returns out_for_service
   to in_service; recording a check updates last_checked_on and last_check_result; exactly one of
   site_id/vehicle_id is required; region_id/district_id are copied from the site, and changing
   a site's district updates its equipment; a kit is pre-filled by copying templates and a later
   template edit does not touch it; no template rows exist after migrate/seed; a regional officer
   cannot see, edit or import into another region; a district manager sees only their district;
   a user without view_equipment gets 403 on the lists; record_checks can record but not edit;
   a responsible person can check their own item but not another; checks and services cannot be
   edited; a decommission without a reason is refused; asset_code is unique and an auto-generated
   one does not collide; the import reads a valid fixture, blocks above the failure threshold,
   skips an existing asset_code with a warning, reports unmatched sites, rejects an ambiguous
   date, and never creates a site; certificate download is authorised; the Overview equipment
   counts match the filtered list lengths; the module is still hidden when the flag is off.
   Run: php artisan test --filter=HealthSafety (all Phase 1 tests must still pass).
10. Phase 1 follow-ups (small, include in this phase):
   a) Actions list: add a completion-note box when an assignee marks an action done (the
      service already takes the note); show the note on the incident screen.
   b) IncidentShow: display "closed by / approved by" (and when) once set, through
      IncidentVisibility so a confidential reporter is still not leaked.
   c) config hs_require_second_approver (env GWL_HS_REQUIRE_SECOND_APPROVER, default FALSE, in
      .env.example): when true, the user who sent an incident for approval cannot approve it
      (super_admin included — say so in the as-built note); when false, behaviour is unchanged.
      Tests for both values.
Report back anything in design section 10 you had to assume, and add "8.6 Phase 2 as built" to
docs/health-safety-module-design.md in the same shape as 8.1 (decisions the design did not state,
what you skipped, anything unsure or unverified, and the switch-on steps). Run the HS tests
against SQLite as usual; say plainly whether anything could not be run. Do not change real data
and do not commit.

### 8.6 Phase 2 as built

**Built after Phase 1**, uncommitted, on the same branch. 100 tests were added under `tests/Feature/HealthSafety/`; the whole group (Phase 1's 86 included) is **186 tests, all passing on in-memory SQLite** (`php artisan test --filter=HealthSafety`). The **whole application suite also passes (1,697 tests)**. Nothing was run against the real MySQL database, no migration was applied there, and the screens were not looked at in a browser.

**Section 8.4 is not in this file.** The prompt said to follow 8.4 where it departs from 2.2, but this document has 8.1 and then jumps to section 9: there is no 8.2 to 8.5. I built from the departures the prompt itself lists (region and district on both tables, `last_check_result`, `decommissioned_on` / `decommission_reason`, no `replacement` service type, the vehicle link, the indexes) and from section 2.3. Where 8.4 may have said something different, the choices are in one place each (the `states()` table in `HsFireExtinguisher` and `HsFirstAidKit`, and the list below), so correcting them is a small change. **Please compare the state order below with your 8.4.**

**What was checked against the repo**

- `Vehicle` carries **no region or district** (so the form asks for them for a vehicle), has a **uuid route key** (irrelevant here: equipment references `vehicles.id`) and **soft-deletes**. `VehiclePolicy` and `VehicleRepository` are as the prompt describes. There is **no per-vehicle page**, only the Transport vehicles list, so the link goes there.
- The transport expiry code is the `transport:check-expiries` command in `routes/console.php` (a fixed 90-day window, `today` to `today + N`). The state windows here use the same idea, but from config.
- `DeductionImportService` and `RawRowsImport` exist as described (`app/Services/CreditUnion/`, not `Credit-Union`); `gwl.max_import_failure_percent` is in `config/gwl.php`. Their cell normalising was copied into `EquipmentImportService`, not shared.
- `IncidentVisibility::scopeOf()` was public but written for `view_incidents` only. The rules were extracted into `ActorScope` (one definition of all / region / district / none) and both `IncidentVisibility` and the new `EquipmentScope` call it. The 86 Phase 1 tests pass unchanged apart from one expectation: the sidebar test, whose label lists grew.
- **No seed migration was needed.** `view_equipment`, `manage_equipment`, `record_checks` and `manage_master_data` exist and are granted as in section 2.1: officer and manager all four; district manager `view_equipment` and `record_checks`; regional chief manager `view_equipment`.

**The computed states** (first match wins; `check baseline = last_checked_on ?? created_at`; windows from `gwl.hs_expiry_warning_days` = 60 and `gwl.hs_check_interval_days` = 30; written once as an SQL condition and a PHP test side by side, with a test that runs both over about a thousand fixtures)

| # | Extinguisher (in service or out for service) | First aid kit (in service) |
|---|---|---|
| 1 | `expired`: expiry date before today | `missing`: status is missing (beats everything) |
| 2 | `service_overdue`: next service due before today | `item_expired`: any item's expiry before today |
| 3 | `hydro_overdue`: next hydrostatic test due before today | `item_expiring`: an item expires today to +60 days |
| 4 | `expiring`: expiry today to +60 days | `incomplete`: an item holds less than it should |
| 5 | `service_due_soon`: next service **or** next hydrostatic test today to +60 days | `check_failed`: the latest check failed |
| 6 | `check_failed`: the latest check failed | `check_overdue`: baseline + interval is today or earlier |
| 7 | `check_overdue`: baseline + interval is today or earlier | `ok` |
| 8 | `ok` | |

A discharged or decommissioned extinguisher and a decommissioned kit have **no state** and are left out of every attention list. `check_failed` sits just before `check_overdue` (the one placement I had to choose); a failed result stays until the next check records a pass.

**Decisions the design did not state**

1. **Overdue means "on or after the due date" for checks only.** A check is overdue once baseline + interval has arrived (so an item added exactly one interval ago is overdue, one day short is not); expiry and service dates are overdue only after the date has passed.
2. **Check results are computed, not typed.** An extinguisher check passes only if all six points are yes; a kit check passes only if, after the quantities and dates entered, nothing is short or expired. A failure needs a note.
3. **A check or service dated before the unit's latest is kept in the history and does not move the unit's dates.** A hydrostatic test also updates the service dates (it is a service). A refill or recharge returns an out-for-service unit to service but not a discharged one (set that by hand).
4. **Fields added to section 2.2:** `has_expiry` and `sort_order` on kit items, and `certificate_name` / `certificate_mime` on services.
5. **Vehicles.** The vehicle is picked by plate from the fleet (anyone who manages equipment may search it, whatever their Transport rights); the region defaults to the officer's own and the district is chosen. The vehicle link is shown only if Transport's own `VehiclePolicy` lets the user see it and they have the Transport module. `vehicle_id` is `nullOnDelete`, but `Vehicle` soft-deletes, so a removed vehicle keeps the link and the register still shows its plate.
6. **The responsible person.** Item pages are open to anyone with `report_incident` (every role) and the component decides, so a named person can open their item and record a check with no equipment permission; they cannot edit, service, change status, decommission, see the service history or open certificates. Because they have no list to find it from, I added a small **My equipment** screen (and sidebar item, shown only to people named on something).
7. **Overview counts** are the count of the very query the linked list runs. "Expiring within 30 days" filters on the expiry date alone (not on the state), so a unit can be in that figure and also be `service_overdue`.
8. **Import.** Confirming reads the file again on the server; the preview is only shown (and the property is Locked). The failure percentage counts rows with at least one error; skipped existing codes and warnings do not count. Usable rows are imported when below the limit and the bad rows left out. A `district` column (optional) also exists for kits so a site name used twice can be told apart; a file over 2,000 rows is refused; extinguisher types accept the obvious spellings; a number is read as a date only if it falls where an Excel date does.
9. **Starter kit items** are twelve generic items in a constant on `HsFirstAidItemTemplate`. They only fill the editable list; nothing is seeded, and the screen says to confirm them with EHS or a first aid trainer.
10. **Followups from Phase 1.** (a) The completion note box is on the Actions list and on the incident screen. (b) "Closed by / approved by" comes from `IncidentVisibility::closureFor()`, only for those entitled to the file. (c) `GWL_HS_REQUIRE_SECOND_APPROVER` (default false): when true the user who last sent the incident for approval cannot approve it, **super_admin included**, and the screen hides the button. While doing (b) a test found two more places a confidential reporter could show (the incident's "Owner" line and the triage timeline note, both when the reporter is an officer who then owns or closes their own report); every such label now goes through `IncidentVisibility::nameFor()` and the note no longer carries a name.

**Skipped on purpose.** The daily command and alerts, the expiry register and Excel/PDF exports of lists (Phase 4); PPE (Phase 3); QR labels; dashboard charts; importing vehicle-based equipment (the import is site-based); editing or deleting a check or service (they are immutable); photos on checks; per-item history of a kit's contents; a per-vehicle page link.

**Unsure or unverified**

- The state order above (see the 8.4 note). The hydrostatic default of 5 years is a placeholder: the real interval depends on the extinguisher type.
- The import template columns are my reading of the prompt: I have not seen a real register (section 10, question 7).
- Screens were not seen in a browser, and the one-handed phone check form (large switches, one screen) is built from existing components but untested on a phone.
- SQLite only. The state queries use `COALESCE(DATE(...))`, `whereDate` and nested `and not` wheres, which are valid on MySQL but untested there.
- Dates read from a real `.xlsx` are tested; dates in a CSV are read as text, so only dd/MM/yyyy and yyyy-MM-dd work there.
- A very large import is read into memory, hence the 2,000-row limit.

**To switch it on.** Run `php artisan migrate` (adds the seven equipment tables; no permission change). Then: confirm the interval placeholders with EHS (`GWL_HS_EXPIRY_WARNING_DAYS`, `_CRITICAL_DAYS`, `GWL_HS_CHECK_INTERVAL_DAYS`, `GWL_HS_EXTINGUISHER_SERVICE_MONTHS`, and especially `GWL_HS_EXTINGUISHER_HYDRO_YEARS`); set up **Kit templates** (confirm the contents first) before adding kits; add the sites; import the existing registers (templates are on the Import equipment screen); name a responsible person on each item; decide whether to switch on `GWL_HS_REQUIRE_SECOND_APPROVER`. Nothing here needs a queue worker or the scheduler yet.

---
### 8.2 Phase 1 review notes (2026-10-07)

Read against the design: nothing in the as-built contradicts it, and the additions are the right ones. In particular:

- **Decision 5 (confidential reporter leaking through the timeline "by" column)** is the kind of leak that only shows up when a test looks for it. Good catch, and it confirms that one visibility service was the right call.
- **Decision 7 (severity required before close or approval; triage refused while `pending_closure`)** closes two ways a High incident could have walked past its approver. The "return for rework" path was a genuine gap in the design.
- **Decision 13 (emergency strip hidden while empty)** is correct. Nobody should ship invented numbers on a safety form.

**One decision is yours: the second-person rule (as-built decision 9).** Today `hs_manager` can send a High/Critical incident for approval and then approve it themselves. The design's intent was separation of duties. Phase 2 adds `GWL_HS_REQUIRE_SECOND_APPROVER` (default **false**, so behaviour does not change until you decide): when true, the user who sent an incident for approval cannot approve it. My recommendation is to turn it on once there are at least two people who can approve in each region (`regional_chief_manager` counts). If one person is the only approver, leave it off.

**Carried into Phase 2 as small follow-ups:** a completion-note box on the Actions list (the service already accepts one), and "closed by / approved by" on the incident screen.

### 8.3 Before Phase 2: checks (about 20 minutes, high value)

**A. The real database. Do these first and in this order.**

1. The suite only ran on SQLite, but the reference-number query uses `LENGTH()` and `lockForUpdate()`. Run the HS tests once against a **throwaway empty MySQL database** (for example `erp_test` in Laragon), by setting `DB_CONNECTION=mysql` and `DB_DATABASE=erp_test` for that run only. **`RefreshDatabase` wipes whatever database it points at, so never point it at the real one. Check the name before pressing Enter.** If anything fails on MySQL, fix it before Phase 2 builds on it.
2. On the real database: back it up, run `php artisan migrate --pretend`, read the SQL, then migrate. The work is uncommitted on `feat/assets-mdm` next to the Commercial work; when you are happy, commit Health & Safety on its own (`git add` the specific paths) so the two can be reviewed and reverted separately.

**B. A look in a browser** (set `GWL_HEALTH_SAFETY_MODULE_ENABLED=true`; the Laragon host is needed because HTTPS is forced):

1. As a plain employee: only *Report an incident* and *My reports* in the sidebar. Submit one report for each of the four contexts; each gets a unique reference.
2. As `hs_officer`: acknowledge, set severity, add a person affected and an action, close. For an Injury, confirm it refuses to close without root cause and findings.
3. A High/Critical report stops at *pending closure*. The officer cannot close it; `regional_chief_manager` can.
4. Tick *confidential*, open it as a district manager: the reporter shows as "Confidential", including in the timeline.
5. Open the print copy as a user without `view_injury_details`: no injury fields.
6. As the employee, open a closed report: closure note visible, findings never.
7. **On a real phone**: the report form fits without awkward scrolling, and a real photo from the camera uploads. If it fails, check PHP `upload_max_filesize` / `post_max_size` and Livewire's temporary-upload limit before blaming the code.
8. Submit one Injury report and confirm the officer and district manager get the bell notification, and that mail goes out only for Injury/Environmental/urgent. Note how long the Submit click takes; mail is synchronous, so a slow mail server shows up here.

**C. Not code, but needed before go-live:** set `GWL_HS_EMERGENCY_CONTACTS` once the team confirms the numbers; give the EHS staff `hs_officer` / `hs_manager` in UAC; add the pay points under Health & Safety > Sites (Phase 2 equipment is placed at these sites, so the list matters).

### 8.4 Phase 2 scope — fire extinguishers and first aid kits

**Assumptions I am making because three §10 questions are still open** (tell me if any is wrong and I will change the prompt):

- **Q8 (extinguisher dates):** all three are stored — `expiry_date`, `next_service_due`, `next_hydro_test_due` — and each gets its own state and filter. Nothing breaks if the team only tracks one; the others stay empty and are ignored.
- **Q7 (existing registers):** the Excel import uses the columns proposed below, with tolerant header matching. When a sample file arrives, the template changes, not the design.
- **Q12 (vehicles):** equipment can sit in a Transport vehicle instead of a site. If the answer is no, hide the vehicle picker; nothing else changes.

**Where Phase 2 deliberately departs from design §2.2** (each one explained, so they are decisions, not accidents):

1. `hs_fire_extinguishers` and `hs_first_aid_kits` also get **`region_id` and `district_id`**, copied from the site or vehicle when the item is saved. Without them, scoping a list that is also filtered by state needs joins to two tables and gets fragile. Cost: they can drift, so saving a site whose region/district changed updates its equipment in the same transaction (tested).
2. Both tables get **`last_check_result`** (`pass`/`fail`, nullable) so a failed check can be a filterable state in SQL. Extinguishers also get `decommissioned_on` and `decommission_reason`; kits get the same.
3. Service type **`replacement` is dropped**. Replacing a unit means decommissioning the old one (with a reason) and adding a new one, so each physical unit keeps its own history. The types are `inspection`, `refill`, `recharge`, `hydro_test`.
4. The state lists in §2.3 gain **`check_failed`** and, for kits, **`missing`** (see below).

**States (computed, first match wins; SQL scopes and a PHP accessor must agree).** Only `in_service` and `out_for_service` extinguishers, and `in_service` kits, are evaluated; discharged and decommissioned items are excluded from every "needs attention" count.

- Extinguisher: `expired` → `service_overdue` → `hydro_overdue` → `check_failed` → `expiring` (expiry within `hs_expiry_warning_days`) → `service_due_soon` → `hydro_due_soon` → `check_overdue` → `ok`.
- Kit: `missing` (status) → `item_expired` → `item_expiring` → `incomplete` (any item below required quantity) → `check_failed` → `check_overdue` → `ok`.
- **Check baseline:** `last_checked_on`, or the record's creation date if never checked. Newly imported items are therefore not all "overdue" on day one; they become overdue one interval after they were added.
- `critical` is a display flag layered on top (expiry within `hs_expiry_critical_days`), not a separate state.

**Screens** (sidebar under *Health & Safety*, `view_equipment`; officers also see Import and Kit templates):

- *Fire extinguishers*: list with filters (district, site/vehicle, type, state, "expiring in N days") and an **expiry date column that sorts**; a detail screen with the three dates, check history and service history; actions *Record check*, *Record service*, *Out for service*, *Discharged*, *Decommission* (reason required); create/edit form.
- *First aid kits*: list and detail with the contents table (required vs current, expiry); *Record check* (update quantities/expiry, tick restocked, result); create/edit; *Missing* and *Decommission* with a reason.
- *Kit templates* (`manage_master_data`): per kit type, the standard items. **Nothing is seeded.** A **Load suggested starter items** button fills an editable list; its wording says to confirm contents with the EHS department or a first aid trainer, because the standard contents are a safety judgement, not mine to hard-code.
- *Import* (`manage_equipment`): see the prompt.
- Overview gets a small Equipment row for anyone with `view_equipment`: expired, expiring within 30 days, check overdue, each linking to the filtered list.

**Rules:** a **responsible person** may record a check on their own item without `record_checks`; checks and services are immutable (a mistake is corrected by a new record); no cost columns, as designed.

### 8.5 Phase 2 kickoff prompt (ready to paste into Claude Code)

```
Implement Phase 2 of the Health & Safety module for this ERP, per health-safety-module-design.md
(sections 2.2 Phase 2 block, 2.3, 4, and 8.4 of that file, which lists where this phase
deliberately departs from 2.2 — follow 8.4 where they differ). Phase 1 is built (see 8.1).
Read first: CLAUDE.md; 8.1 Phase 1 as built; app/Services/HealthSafety/IncidentVisibility.php and
app/Livewire/HealthSafety/Concerns/ScopesHealthSafetyByActor.php (how actor scope is resolved),
app/Livewire/HealthSafety/Sites.php and IncidentShow.php (screen, permission-guard and audit
patterns), app/Models/HsSite.php, the Phase 1 migrations, app/Models/Vehicle.php,
app/Policies/VehiclePolicy.php and app/Repositories/Transport/VehicleRepository.php (how vehicles
are scoped, whether they carry region/district, uuid route key, soft deletes),
app/Console routes/console.php and the transport expiry-check code (so the expiry windows here
use the same idea), app/Services/Credit-Union DeductionImportService.php and
app/Imports/RawRowsImport.php (preview -> validate -> confirm shape and cell normalisation;
copy the normalising behaviour, do not refactor those files and do not reuse DataImportService),
and config/gwl.php (gwl.max_import_failure_percent). Confirm every file exists and adapt to what
is really there; say so in the as-built note if anything differs. Follow CLAUDE.md throughout.

Scope — this phase is ONLY: the extinguisher and first aid kit registers, checks, service
records, computed states, kit templates, vehicle link, Excel import, an equipment row on the
Overview, and the Phase 1 follow-ups in item 10. No PPE, no dashboard charts, no scheduled
command or alerts, no exports of lists, no QR labels.

1. Migration (new file, e.g. 2026_10_08_000001_create_health_safety_equipment_tables; never edit
   existing ones; hasTable/hasColumn guards; restrictOnDelete for people/lookup refs,
   cascadeOnDelete for child rows, nullOnDelete for optional links; numeric ids, no soft deletes):
   hs_fire_extinguishers, hs_extinguisher_checks, hs_extinguisher_services, hs_first_aid_kits,
   hs_first_aid_item_templates (unique kit_type+item_name), hs_first_aid_kit_items,
   hs_first_aid_kit_checks — the columns in design 2.2 Phase 2 PLUS the departures in 8.4:
   region_id and district_id on both equipment tables; last_check_result on both;
   decommissioned_on and decommission_reason on both. site_id and vehicle_id both nullable,
   exactly one required (enforced in the service and tested; do not rely on a CHECK constraint).
   vehicle_id references vehicles.id (numeric PK), nullOnDelete; check how Vehicle soft-deletes
   and say what you did. Indexes: (region_id, status), (expiry_date), (next_service_due),
   (site_id), (vehicle_id) on extinguishers; (region_id, status), (site_id) on kits;
   (kit_id, expiry_date) on kit items. asset_code unique on both tables. No permissions are
   needed: the Phase 1 seed already created manage_equipment, record_checks, view_equipment,
   manage_master_data and granted them. Do not add a seed migration unless something is
   actually missing (check, and say what you found).
2. config/gwl.php + .env.example: hs_expiry_warning_days (60), hs_expiry_critical_days (30),
   hs_check_interval_days (30), hs_extinguisher_service_months (12),
   hs_extinguisher_hydro_years (5, only used to PRE-FILL next_hydro_test_due, always editable;
   note in a comment that the real interval depends on extinguisher type and must be confirmed),
   hs_equipment_attachment_max_mb (5). Read them with config('gwl.…') everywhere; no literals.
3. Models (flat under app/Models, prefix Hs, string constants for statuses/types/states in the
   Phase 1 style, relationships, no factories): HsFireExtinguisher, HsExtinguisherCheck,
   HsExtinguisherService, HsFirstAidKit, HsFirstAidItemTemplate, HsFirstAidKitItem,
   HsFirstAidKitCheck. Put the state logic in query scopes (scopeExpired, scopeExpiringWithin,
   scopeServiceOverdue, scopeCheckOverdue, scopeNeedsAttention, scopeWithState($state)) AND a PHP
   state() accessor, both implementing the first-match-wins orders in 8.4 with the check
   baseline = last_checked_on ?? created_at. Only in_service/out_for_service extinguishers and
   in_service kits are evaluated.
4. Services in app/Services/HealthSafety/: EquipmentScope (or extend what IncidentVisibility
   already resolves — do NOT duplicate the region/district/head-office rules; if the existing
   resolution is private, extract it into a small shared class used by both and let the 86
   existing tests prove nothing changed), EquipmentExpiryService (counts and filtered queries by
   state for the screens and the Overview; this is the ONE place Phase 4 will reuse),
   FireExtinguisherService (create/update, record check, record service, change status,
   decommission), FirstAidKitService (create pre-filled from templates, update items, record
   check, decommission), EquipmentImportService (item 7). Rules:
   - region_id/district_id come from the site (or the vehicle, as far as Vehicle carries them —
     if it does not, the form asks for them) at save time; saving a site whose region or
     district changed updates its equipment in the same transaction (add this to the Sites save).
   - recording a service: one transaction that writes hs_extinguisher_services and updates
     last_serviced_on, expiry_date (if new_expiry_date given), next_service_due (given, else
     serviced_on + hs_extinguisher_service_months), and for hydro_test also last_hydro_test_on
     and next_hydro_test_due (given, else + hs_extinguisher_hydro_years); returns an
     out_for_service unit to in_service. service_type is one of inspection, refill, recharge,
     hydro_test (no 'replacement'). Certificate upload: PDF/JPG/PNG, private disk
     health_safety/equipment/<id>/, served through an authorised route with nosniff, no caching.
   - recording a check updates last_checked_on and last_check_result in the same transaction.
     Checks and services are immutable (no edit/delete in the UI or service).
   - a kit is created pre-filled by COPYING the templates for its kit_type (editing a template
     later never changes existing kits); with no templates the kit is created empty and the
     screen links to Kit templates.
   - decommission requires a reason and writes decommissioned_on.
   - asset_code: if blank on create, generate FE-<REGION_PREFIX>-<NNNN> / FAK-<REGION_PREFIX>-<NNNN>
     with the same "highest + 1 by length then value, unique index as the guard, retry on
     collision" approach as IncidentReferenceGenerator and Region::assignLetterPrefix(); if the
     officer types an existing tag, keep it (unique).
   - every mutation calls Audit::log: health_safety.extinguisher_saved, .extinguisher_checked,
     .extinguisher_serviced, .extinguisher_status_changed, .extinguisher_decommissioned,
     .kit_saved, .kit_checked, .kit_decommissioned, .kit_template_saved, .equipment_imported.
5. Authorization (layered, as in Phase 1): routes under the health_safety. group with
   module:health_safety and permission: middleware (view_equipment for lists/details;
   manage_equipment for create/edit/service/status/decommission/import; record_checks for
   checks; manage_master_data for kit templates), PLUS enforceLivewireModule(...) and a
   per-action guard in every component. A user named as responsible_employee_id on an item may
   record a check on THAT item even without record_checks (ownership rule), and nothing else.
   Scope every list, detail and action by the actor's region/district (officer and regional
   chief: region; district manager: district; hs_manager, super_admin and head-office viewers:
   all), through the shared scope class, not inline.
6. Livewire (app/Livewire/HealthSafety/, thin Blade wrappers in resources/views/health_safety/,
   templates in resources/views/livewire/health_safety/, mobile-first, built from the existing
   x-ui.* components and status-pill, adding status-pill domains hs-extinguisher and hs-kit
   to docs/11-ui-components.md): Extinguishers (list; filters district, site/vehicle, type,
   state, "expiring within N days"; sortable expiry column; ?state= and ?expiring= query
   parameters so Overview counts can link to them), ExtinguisherShow (three dates, history of
   checks and services, Record check, Record service, Out for service, Discharged, Decommission),
   ExtinguisherForm, FirstAidKits, FirstAidKitShow (contents table, Record check with item
   quantity/expiry edits, restocked tick, Missing, Decommission), FirstAidKitForm, KitTemplates
   (per kit type; "Load suggested starter items" button that fills an EDITABLE list and shows
   a note to confirm contents with EHS or a first aid trainer; do NOT seed any template rows),
   EquipmentImport. The check forms must work one-handed on a phone: large toggles for the
   yes/no items, one screen. Extend healthSafetySidebar($user) with Fire extinguishers and
   First aid kits (view_equipment) and, for officers, Kit templates and Import. Extend the
   Overview with an Equipment row for view_equipment holders: expired, expiring within
   hs_expiry_critical_days, check overdue (counts from EquipmentExpiryService, each linking to
   the filtered list). Where a vehicle is the location, show the registration/fleet number
   and link to the vehicle only if the actor can see that vehicle.
7. Excel import (EquipmentImportService + EquipmentImport component; app/Imports/HealthSafety/;
   two templates, extinguishers and first aid kits, downloadable; CREATE-ONLY):
   - extinguisher columns: asset_code (optional), serial_number, type (water, foam,
     dry_powder, co2, wet_chemical; accept case-insensitive and a few obvious spellings such as
     "dry powder" and "CO2"), capacity, manufacturer, manufactured_on, site (name), district
     (optional, to disambiguate), location_detail, expiry_date, last_serviced_on,
     next_service_due, last_hydro_test_on, next_hydro_test_due, responsible_staff_id, notes.
     Kit columns: asset_code (optional), kit_type, site, location_detail, responsible_staff_id,
     last_checked_on, notes (items are NOT imported; they are created from templates).
   - header matching is tolerant (trim, case, spaces/underscores). Dates accept real Excel dates
     and dd/MM/yyyy text (the app's convention), reject anything ambiguous with a row error.
   - preview -> validate -> confirm, in the shape of the Credit Union deduction import
     (errors, warnings, counts, blocked) with one transactional insert on confirm and no
     persisted batch table. Row errors: unknown site (list the distinct unmatched names so the
     officer can add them under Sites and re-upload), unknown type, unparseable date, staff id
     not found, duplicate asset_code in the file. An asset_code that already exists in the
     database is SKIPPED with a warning (create-only). Block the run above
     gwl.max_import_failure_percent. Scoped: an officer can only import into sites of their own
     region; the import never creates sites. Audit one equipment_imported row with counts.
   - generate SMALL synthetic .xlsx fixtures in the tests; do not use or commit real registers.
8. Routes: extend the health_safety. group in routes/web.php (names such as
   health_safety.extinguishers.index/show/create/edit, health_safety.kits.*,
   health_safety.kit-templates, health_safety.equipment-import, health_safety.equipment-files.show
   for certificates).
9. Tests under tests/Feature/HealthSafety/ (plain PHPUnit, RefreshDatabase, inline builders, no
   factories, same style as the Phase 1 tests). Cover: each state in 8.4 is reached by the right
   fixture and the order is respected (expired beats expiring; check_failed beats check_overdue;
   missing beats everything for a kit); THE SQL SCOPES AND THE PHP state() ACCESSOR AGREE across a
   grid of fixtures (one test, many rows — this is what keeps the Overview counts, the list
   filters and Phase 4 consistent); decommissioned and discharged items are excluded from
   needs-attention; the check baseline (a never-checked item created yesterday is not overdue,
   one created a full interval ago is); recording a service updates the three dates correctly,
   pre-fills next_service_due and next_hydro_test_due from config, and returns out_for_service
   to in_service; recording a check updates last_checked_on and last_check_result; exactly one of
   site_id/vehicle_id is required; region_id/district_id are copied from the site, and changing
   a site's district updates its equipment; a kit is pre-filled by copying templates and a later
   template edit does not touch it; no template rows exist after migrate/seed; a regional officer
   cannot see, edit or import into another region; a district manager sees only their district;
   a user without view_equipment gets 403 on the lists; record_checks can record but not edit;
   a responsible person can check their own item but not another; checks and services cannot be
   edited; a decommission without a reason is refused; asset_code is unique and an auto-generated
   one does not collide; the import reads a valid fixture, blocks above the failure threshold,
   skips an existing asset_code with a warning, reports unmatched sites, rejects an ambiguous
   date, and never creates a site; certificate download is authorised; the Overview equipment
   counts match the filtered list lengths; the module is still hidden when the flag is off.
   Run: php artisan test --filter=HealthSafety (all Phase 1 tests must still pass).
10. Phase 1 follow-ups (small, include in this phase):
   a) Actions list: add a completion-note box when an assignee marks an action done (the
      service already takes the note); show the note on the incident screen.
   b) IncidentShow: display "closed by / approved by" (and when) once set, through
      IncidentVisibility so a confidential reporter is still not leaked.
   c) config hs_require_second_approver (env GWL_HS_REQUIRE_SECOND_APPROVER, default FALSE, in
      .env.example): when true, the user who sent an incident for approval cannot approve it
      (super_admin included — say so in the as-built note); when false, behaviour is unchanged.
      Tests for both values.
Report back anything in design section 10 you had to assume, and add "8.6 Phase 2 as built" to
docs/health-safety-module-design.md in the same shape as 8.1 (decisions the design did not state,
what you skipped, anything unsure or unverified, and the switch-on steps). Run the HS tests
against SQLite as usual; say plainly whether anything could not be run. Do not change real data
and do not commit.
```

### 8.6 Phase 2 as built

(To be added by Claude Code when Phase 2 is done.)

---

### 8.7 After Phase 2

Phase 3 (PPE) and Phase 4 (dashboard, expiry register, daily alerts, exports, Settings screen) follow. Phase 4 should be small because Phase 2 puts the states and counts in one service. Before Phase 3, the team needs to answer §10 Q9 (one central PPE store or one per district, sizes, replacement intervals, who is entitled to what by job title, and whether a signature is needed). Those answers decide the Phase 3 schema.
### 8.8 Before Phase 3: Phase 2 check (10 minutes)

I have not seen the "8.6 Phase 2 as built" note yet, so I have not reviewed Phase 2. The Phase 3 prompt below reuses three things Phase 2 creates: the shared scope class, `EquipmentExpiryService`, and the Excel-import pattern. If 8.6 shows they are named or shaped differently, Claude Code adapts (the prompt says so), but please paste 8.6 here when you have it so I can review it properly.

A quick check in the browser, with the flag on:

1. Add one extinguisher at a site and one in a vehicle (if you kept the vehicle link). Both should list under your region only.
2. **Record a service** with a new expiry date. The three dates on the unit should update, and the state should change accordingly.
3. Record a **failed check**. The unit should show *check failed* and appear in the Overview "needs attention" counts.
4. Open the list with `?state=expired` and confirm the Overview number equals the number of rows.
5. Create a kit **without** templates (it should be empty with a link to Kit templates), then use *Load suggested starter items*, **edit** the list, and create a second kit. Confirm the first kit did not change.
6. Upload a small Excel file with one good row, one unknown site and one ambiguous date. The preview should flag the bad rows and the import should create only the good one.
7. **On a phone:** the check form works one-handed.

### 8.9 Phase 3 scope — PPE

Not all of §10 Q9 is answered, so these are my assumptions (tell me which is wrong and I will change the prompt, not the design):

- **Stores:** any site can be flagged as a PPE store, so one central store and a store per district both work. Stock is held per store, per type, per size.
- **Sizes:** optional per PPE type (a type either has a size list or does not).
- **Replacement intervals:** no numbers are invented. A type's `replacement_months` is blank until the team enters it; blank means "no automatic replacement date".
- **Entitlement:** by job title and quantity. Job titles with no entitlement rows are not evaluated.
- **Acknowledgement:** the employee confirms receipt in *My PPE* (no signature). Every employee has a login (the `EmployeeObserver` creates one), so this reaches everyone.
- **Existing holdings:** staff already hold boots and helmets today. Without a way to record that, every entitled person shows as "missing" on day one and the gap report is useless. So an issue can be flagged **already held** (it records who has what and when it was issued, and does not touch stock), with an optional Excel import for the same.

**Where Phase 3 departs from design §2.2 (explained so they are decisions, not accidents):**

1. `hs_ppe_issues` uses **`closed_on` / `close_note`** instead of `returned_on` / `return_note`, because the same fields close an issue that was worn out, lost or damaged, not only returned.
2. `hs_sites` gains **`is_ppe_store`** (boolean). The Issue and Receive forms list only stores.
3. `hs_ppe_issues` gains **`expires_on`** (for types such as respirator filters, entered from the pack), **`is_historic`** (the "already held" flag, no ledger row) and **`replaced_by_issue_id`** (self-FK, `nullOnDelete`: which new issue closed this one). `replace_due_on` is the earlier of `issued_on + replacement_months` and `expires_on`.
4. `has_expiry` on a type now only means "ask for `expires_on` when issuing". **Expiry of stock in the store (lots) is not tracked in this phase**; if the team needs it, it is its own phase.
5. **Sign rules on the ledger** are enforced in the service: `receipt`, `return`, `transfer_in` are positive; `issue`, `write_off`, `transfer_out` are negative; `adjustment` is non-zero either way. **A balance may never go negative after any movement.** `adjustment` and `write_off` require a reason.

**Stock rules**

- Balance is `SUM(quantity)` per (store, type, size); nothing is edited, corrections are `adjustment` rows.
- Issuing creates the `issue` row in the same transaction, after re-checking the balance. To stay race-safe on MySQL without locking a SUM, the service takes `lockForUpdate()` on the `hs_ppe_types` row it is moving (low volume, so serialising per type is fine).
- **Closing an issue:** `returned` puts the item back into the store (a `return` ledger row, positive). `worn_out`, `damaged` and `lost` change status only (the item already left stock when it was issued).
- **Replacement:** when issuing a type an employee already holds, the form shows their current issued rows of that type with a closing outcome per row (default *worn out* for rows that are due or overdue). Choosing one closes it in the same transaction and sets `replaced_by_issue_id`. This stops old rows sitting "issued and overdue" forever and double counting.
- **Transfer** between stores writes `transfer_out` and `transfer_in` in one transaction under a shared reference.
- **Low stock:** total balance across sizes at or below the `hs_ppe_reorder_levels` level for that (store, type). No row means no alert.

**Compliance evaluator (one PHP implementation, deliberately).** Phase 2's states are single-row tests, so the SQL scopes and a PHP accessor have to agree. This rule is a multi-row aggregate per employee and type, so Phase 3 uses **one** evaluator (`PpeComplianceService`) for the gaps screen, *My PPE*, and the Overview counts, with no second SQL version to drift. For each active employee in scope whose job title has entitlement rows, per entitled type: let *in-date held* = sum of `quantity` of `issued` rows whose `replace_due_on` is null or not before today. Then, first match wins:
`missing` (nothing issued) → `overdue` (something issued, none in date) → `short` (in-date held < entitled quantity) → `replacement_due` (the earliest in-date `replace_due_on` is within `hs_expiry_warning_days`) → `ok`.

**Screens** (sidebar under *Health & Safety*):

- *PPE stock* (`view_equipment`): balance matrix by store/type/size with low-stock highlighting, filters (store, category, low only), per-type movement history; actions *Receive*, *Adjust*, *Write off*, *Transfer*, *Issue* (`manage_ppe`).
- *PPE issues* (`view_equipment`): list and filters (employee, type, status, state `overdue`/`replacement_due`), *Close issue*, *Issue to staff* with the replacement step.
- *PPE gaps* (`view_equipment`): staff × type with the five states, filters (district, job title, state), and a header line "N job titles have staff but no entitlements" so the team can see rollout progress.
- *PPE types* and *Entitlements* and *Reorder levels* (`manage_master_data`). Types have a **Load suggested types** button (names, categories, has_sizes only, no replacement months), editable, and **nothing is seeded**.
- *My PPE* (everyone with an employee record): what I hold, when it is due, **Confirm receipt**, and my own gaps.
- Overview gets a PPE row for `view_equipment` holders: low-stock items, overdue replacements, staff with gaps, each linking to the filtered list.

Visibility: an employee sees only their own issues; `view_equipment` holders see issues and stock in their scope (officer/regional chief: region; district manager: district, view only; `hs_manager`, `super_admin`, head-office viewers: all); only `manage_ppe` can post movements or issue.

### 8.10 Phase 3 kickoff prompt (ready to paste into Claude Code)

```
Implement Phase 3 of the Health & Safety module for this ERP, per health-safety-module-design.md
(sections 2.2 Phase 3 block, 4, and 8.9 of that file, which lists where this phase deliberately
departs from 2.2 — follow 8.9 where they differ). Phases 1 and 2 are built (see 8.1 and 8.6).
Read first: CLAUDE.md; 8.1 and 8.6 (as built); the Phase 2 services and screens — the shared
equipment scope class, app/Services/HealthSafety/EquipmentExpiryService.php,
app/Services/HealthSafety/EquipmentImportService.php, app/Livewire/HealthSafety/EquipmentImport.php,
FireExtinguishers/FirstAidKit screens and their tests (patterns for scope, permission guards, audit,
status pills, import preview); app/Livewire/HealthSafety/Sites.php and app/Models/HsSite.php;
app/Models/Employee.php, app/Models/JobTitle.php, app/Observers/EmployeeObserver.php and
app/Services/Staff/EmployeeDirectory.php (how employees are looked up and which fields exist:
job_title_id, region_id, district_id, active flag, staff_id — confirm the real names; do NOT
widen EmployeeDirectory for Health & Safety, build the employee picker inside this module,
restricted to ACTIVE employees in the actor's scope); how the visitors kiosk and the Credit Union
member lookup pick an employee (reuse the UX idea, not the code); config/gwl.php. Confirm every
file exists and adapt to what is really there; say so in the as-built note if anything differs.
Follow CLAUDE.md throughout.

Scope — this phase is ONLY: PPE types, stores, the stock ledger, issue/close/replace, My PPE with
confirm receipt, entitlements, reorder levels, the compliance gaps screen, an optional historic
issues import, and an Overview row. No QR labels, no alerts or scheduled command, no list
exports, no tracking of expiry per stock lot.

1. Migrations (new files, e.g. 2026_10_09_000001_create_health_safety_ppe_tables; never edit
   existing ones; hasTable/hasColumn guards; restrictOnDelete for people/lookup refs,
   cascadeOnDelete for child rows, nullOnDelete for optional links; numeric ids, no soft deletes):
   - hs_ppe_types (name unique, category, has_sizes bool, sizes JSON nullable, replacement_months
     nullable unsigned, has_expiry bool, unit, is_active), hs_ppe_stock_movements,
     hs_ppe_reorder_levels (unique site_id+ppe_type_id), hs_ppe_issues, hs_ppe_entitlements
     (unique job_title_id+ppe_type_id, quantity >= 1) — design 2.2 Phase 3 block PLUS the
     departures in 8.9: closed_on and close_note (not returned_on/return_note) on issues;
     expires_on, is_historic, replaced_by_issue_id (self-FK, nullOnDelete) on issues;
     is_ppe_store boolean default false added to hs_sites in a separate guarded alter.
   - Ledger indexes: (site_id, ppe_type_id, size), (issue_id), (occurred_on). Issue indexes:
     (employee_id, ppe_type_id, status), (replace_due_on), (status).
   - No new permissions are needed: manage_ppe, manage_master_data and view_equipment exist and
     are already granted (verify and say what you found). Do not add a seed migration unless
     something is missing.
2. config/gwl.php + .env.example: nothing new is required; use gwl.hs_expiry_warning_days for
   "replacement due". If you find you need a key, add it there with a comment.
3. Models (flat under app/Models, prefix Hs, constants in the Phase 1/2 style, relationships,
   casts: sizes as array): HsPpeType, HsPpeStockMovement, HsPpeReorderLevel, HsPpeIssue,
   HsPpeEntitlement. HsPpeIssue gets a PHP state() (overdue / replacement_due / ok, only for
   status issued) using replace_due_on and the warning window, plus scopes for the list filters.
4. Services in app/Services/HealthSafety/ (thin Livewire, logic here):
   - PpeStockService: balances (per store/type/size, and per store/type for low-stock), post
     movements with the sign rules in 8.9 (receipt/return/transfer_in > 0; issue/write_off/
     transfer_out < 0; adjustment non-zero), reasons required for adjustment and write_off, and
     NEVER let any balance go negative (check inside the transaction). Take lockForUpdate() on
     the hs_ppe_types row being moved to serialise concurrent issues of that type. Transfer =
     transfer_out + transfer_in in one transaction with a shared reference. Destination and
     source must be PPE stores (is_ppe_store) inside the actor's scope.
   - PpeIssueService: issue (store, employee, type, size required iff the type has sizes,
     quantity, issued_on not in the future; replace_due_on = the EARLIER of issued_on +
     replacement_months and expires_on, either may be null; expires_on is required iff the type
     has_expiry); creates the 'issue' ledger row in the same transaction unless is_historic
     (the "already held" flag: records who holds what and when, no ledger row, no store needed,
     issued_on may be any past date). Replacement step: the call accepts a list of existing
     issued rows of the same employee and type with a closing outcome per row (returned,
     worn_out, damaged, lost); each is closed in the same transaction and gets
     replaced_by_issue_id. close(issue, outcome, closed_on, note, store?) — 'returned' posts a
     positive 'return' ledger row to the chosen store (required for returned, unless the issue
     was historic, in which case no stock row); worn_out/damaged/lost post nothing. An issue can
     only be closed once. acknowledge(issue, actor): only the employee it was issued to, once.
     Closed issues are immutable.
   - PpeComplianceService: the ONE evaluator (do NOT add a SQL twin) for the rule in 8.9, over
     ACTIVE employees in the actor's scope whose job title has entitlement rows; returns per
     employee x entitled type the state (missing, overdue, short, replacement_due, ok) with
     entitled quantity, in-date held quantity and next due date; also the count of job titles
     that have staff in scope but no entitlements. Eager-load issues to avoid N+1. Paginate the
     result collection for the screen with a LengthAwarePaginator. The gaps screen, My PPE and
     the Overview counts must all call it.
   - PpeTypeService / entitlement and reorder-level save helpers if that keeps components thin.
   - Every mutation calls Audit::log: health_safety.ppe_type_saved, .ppe_received,
     .ppe_adjusted, .ppe_written_off, .ppe_transferred, .ppe_issued, .ppe_issue_closed,
     .ppe_acknowledged, .ppe_entitlement_saved, .ppe_reorder_level_saved, .ppe_issues_imported.
5. Authorization (layered, as in Phases 1-2): routes under the health_safety. group with
   module:health_safety and permission: middleware (view_equipment for stock/issues/gaps
   screens; manage_ppe for any movement, issue or close; manage_master_data for types,
   entitlements, reorder levels and the store flag on Sites), PLUS enforceLivewireModule(...)
   and a per-action guard in every component. My PPE needs no permission beyond module access
   and an employee record, and shows ONLY the signed-in employee's own issues. Scope every
   stock, issue and gap query by the actor through the shared scope class (officer/regional
   chief: region; district manager: district and view-only; hs_manager, super_admin and
   head-office viewers: all); an employee is "in scope" through their own region/district.
6. Livewire (app/Livewire/HealthSafety/, wrappers in resources/views/health_safety/, templates
   in resources/views/livewire/health_safety/, mobile-first, existing x-ui.* components and
   status-pill, adding domains hs-ppe-issue and hs-ppe-state to docs/11-ui-components.md):
   PpeStock (matrix + history + Receive/Adjust/Write off/Transfer/Issue modals), PpeIssues (list,
   filters employee/type/status/state, Close issue, Issue to staff with the replacement step
   showing the employee's currently held rows of that type, default outcome worn_out for rows
   that are due or overdue, and the "Already held (do not deduct from stock)" option), PpeGaps
   (staff x type, five states, filters district/job title/state, header line for job titles
   without entitlements), PpeTypes (with "Load suggested types": names, categories and has_sizes
   ONLY, no replacement months, shown as an EDITABLE list, NOTHING seeded), PpeEntitlements
   (matrix by job title), PpeReorderLevels (per store/type), MyPpe (what I hold, due dates,
   Confirm receipt, my own gaps). Extend Sites with the is_ppe_store tick (manage_master_data).
   Extend healthSafetySidebar($user): PPE stock, PPE issues, PPE gaps (view_equipment); PPE
   types and Entitlements (manage_master_data); My PPE for everyone with an employee record.
   Extend the Overview with a PPE row for view_equipment holders: low-stock items, overdue
   replacements, staff with gaps, each linking to the filtered list (query parameters such as
   ?low=1, ?state=overdue). The employee picker searches by staff ID or name among ACTIVE
   employees in the actor's scope only.
7. OPTIONAL — historic issues import (only if the Phase 2 import pattern makes this small; if
   not, SKIP it and say so in the as-built note): reuse the preview -> validate -> confirm
   shape. Create-only, historic only (is_historic = true, no ledger rows). Columns: staff_id,
   ppe_type (name), size, quantity, issued_on (Excel date or dd/MM/yyyy text), expires_on
   (optional). Row errors: staff id not found or outside the actor's scope, unknown type, size
   missing for a sized type or not in the type's list, ambiguous date, quantity < 1. Block above
   gwl.max_import_failure_percent. Synthetic fixtures only.
8. Routes: extend the health_safety. group in routes/web.php (names such as
   health_safety.ppe.stock, .ppe.issues, .ppe.gaps, .ppe.types, .ppe.entitlements,
   .ppe.reorder-levels, .my-ppe, .ppe.import).
9. Tests under tests/Feature/HealthSafety/ (plain PHPUnit, RefreshDatabase, inline builders,
   no factories, the style of Phases 1-2). Cover: ledger sign rules for every movement type;
   a balance can never go negative (issue, write_off, adjustment and transfer_out all refused
   when short); adjustment and write_off need a reason; balances are correct per store/type/
   size after a mixed sequence; transfer posts both rows and the shared reference; transfer to
   a non-store or an out-of-scope store is refused; an issue posts the ledger row and sets
   replace_due_on from replacement_months, from expires_on, and the EARLIER of both; a type with
   no replacement_months and no expires_on gets a null due date and is never flagged; size is
   required exactly when the type has sizes and must be in the list; expires_on is required
   exactly when the type has_expiry; is_historic posts no ledger row and may be backdated while
   a normal issue may not be; closing 'returned' posts a positive return to the chosen store,
   worn_out/damaged/lost post nothing; an issue closes only once and a closed issue cannot be
   edited; the replacement step closes the old rows and sets replaced_by_issue_id in one
   transaction (and rolls back entirely if the new issue fails); only the employee can
   acknowledge their own issue, once; the compliance evaluator returns missing, overdue, short,
   replacement_due and ok for the right fixtures and in that precedence; job titles with no
   entitlement rows are not evaluated and are counted in the header line; inactive employees
   are excluded; the gaps screen, My PPE and the Overview counts agree for the same fixture;
   a regional officer cannot see or post into another region; a district manager sees stock and
   issues in their district but cannot post; an employee sees only their own issues in My PPE
   and 403s on the stock screen; users without manage_ppe get 403 on movement and issue
   actions even through Livewire; reorder level flags low stock on the total across sizes and
   no level means no flag; nothing is seeded into hs_ppe_types by migrate/seed; the import (if
   built) reads a valid fixture, blocks above the threshold, reports unknown types/staff and
   rejects ambiguous dates; the module is still hidden when the flag is off. Run:
   php artisan test --filter=HealthSafety (all Phase 1 and 2 tests must still pass).
Report back anything in design section 10 you had to assume, and add "8.11 Phase 3 as built" to
docs/health-safety-module-design.md in the same shape as 8.1 (decisions the design did not state,
what you skipped including whether the optional import was built, anything unsure or unverified,
and the switch-on steps: mark the stores, enter the PPE types and replacement months, enter
entitlements by job title, record opening stock as receipts, then record existing holdings with
"already held" or the import). Run the HS tests against SQLite as usual and say plainly whether
anything could not be run. Do not change real data and do not commit.
```

### 8.11 Phase 3 as built

Built against §8.9, §8.10 and the §8.10.1 amendment (PPE stores are the regional offices). All of it is behind `GWL_HEALTH_SAFETY_MODULE_ENABLED`; nothing was applied to the real database and nothing was committed.

**What exists.**

- Migration `2026_10_10_000001_create_health_safety_ppe_tables`: `hs_ppe_types`, `hs_ppe_issues` (with `closed_on`, `close_note`, `expires_on`, `is_historic`, `replaced_by_issue_id`), `hs_ppe_stock_movements`, `hs_ppe_reorder_levels`, `hs_ppe_entitlements`, and a guarded alter adding `hs_sites.is_ppe_store`. Nothing is seeded into `hs_ppe_types`.
- Models `HsPpeType`, `HsPpeIssue` (`state()` and list scopes), `HsPpeStockMovement`, `HsPpeReorderLevel`, `HsPpeEntitlement`; `HsSite` gained `is_ppe_store` and `scopePpeStores`.
- Services in `app/Services/HealthSafety/`: `PpeStockService` (ledger, sign rules, balance never negative, `lockForUpdate` on the type row, transfers under a shared reference), `PpeIssueService` (issue, replacement step, close, acknowledge), `PpeComplianceService` (the one evaluator, no SQL twin), `PpeSetupService` (types, entitlements, reorder levels), `PpeImportService` (historic issues).
- Screens: PPE stock, PPE issues (issue, replace, close), PPE gaps, PPE types (with "Load suggested types"), PPE entitlements, PPE reorder levels, My PPE (confirm receipt), PPE import (a button on the issues screen), the Sites "PPE store" tick, and a PPE row on the Overview (low stock, overdue replacements, staff with gaps, each linking to the filtered list).
- Pills `hs-ppe-issue` and `hs-ppe-state` are in `docs/11-ui-components.md`.

**Checks against the repo (§8.10 asked for these).**

- `manage_ppe`, `manage_master_data` and `view_equipment` already existed and were already granted, so there is **no seed migration**.
- Employee fields as named: `job_title_id` (required), `is_active`, `region_id`, `district_id`, `staff_id`. `EmployeeDirectory` was not widened; the picker is `EquipmentScope::employees()` (active employees in the actor's scope). `JobTitle` has no entitlements relation, so entitlements are queried from `HsPpeEntitlement`.
- The Excel reading code in `EquipmentImportService` was moved into a trait, `Concerns/ReadsImportFiles`, so the PPE import could share it; its 25 tests are unchanged and pass.

**Decisions the design did not state.**

1. **Compliance rule: §8.9 as written.** In-date means an open issue with no replacement date or one not before today. States: `missing` (nothing issued) → `overdue` (something issued, none in date) → `short` (in-date quantity below entitled) → `replacement_due` (earliest in-date replacement date within `hs_expiry_warning_days`) → `ok`. (My first version used a different rule, written before §8.9 existed; it was changed to §8.9 and the tests updated.) A "gap" (Overview count, `?state=gap`) is `missing`, `overdue` or `short`; `replacement_due` is a warning, not a gap.
2. A normal issue is dated **today** (the prompt says "not in the future" and "may not be backdated"); only "already held" rows take any past date.
3. Only a **non-historic** issue posts a ledger row when closed "returned"; a historic one posts nothing and needs no store.
4. Low stock is the total across sizes **at or below** the level, and only where a level row exists.
5. PPE type names are unique **ignoring case** (the import matches types by name). Sizes, and sized/not sized, cannot change once a type has stock or issues.
6. On Sites, a store that still holds stock cannot be un-flagged or deactivated. Ticking `is_ppe_store` on a site that is not a regional or head office is allowed, with the note "PPE stores are normally the regional office." (§8.10.1).
7. **Store scope (§8.10.1).** Stores are listed through the scope class, so an officer sees the regional-office site of their region only (pre-selected in the Receive/Adjust/Write off/Transfer panel and in the Issue and Close forms when there is one). A district manager has no store in scope (a regional office has no district), so they see no balances, but they do see issues and gaps for staff of their own district and cannot post. Consequence worth knowing: a store in another region can be issued from only by users whose scope covers it (hs_manager, super_admin, head-office viewers).
8. My PPE needs only module access and an employee record, and shows only the signed-in employee's issues.
9. An extra sidebar item, "PPE reorder levels" (`manage_master_data`), next to PPE types and PPE entitlements.
10. `PpeIssues` opens its Issue panel with `?issue=1`, which the PPE stock screen links to; the Issue action lives on the issues screen, not in a stock modal.
11. The employee picker takes a staff ID prefix or a name fragment, active employees in scope only.

**The optional import was built.** Historic issues only, create-only, `is_historic = true`, no ledger rows. Columns `staff_id`, `ppe_type`, `size`, `quantity`, `issued_on`, `expires_on`. Row errors for unknown or out-of-scope staff, unknown type, missing or unlisted size, ambiguous date, quantity below 1; blocked above `gwl.max_import_failure_percent`; capped at 3,000 rows; a holding already recorded for the same person, type, size and date is skipped with a warning (not counted as a failure). One audit entry, `ppe_issues_imported`. No duplicate detection inside one file.

**Skipped on purpose.** QR labels, alerts and any scheduled command, list exports, per-lot expiry of stock, signatures, any pricing or cost.

**Unsure or unverified.**

- **Tests only, no browser.** I did not open the screens in a browser or on a phone.
- Tested on in-memory SQLite only. `lockForUpdate()` on the type row is correct on MySQL by design but was not exercised under real concurrent requests, and the SQLite run cannot show it.
- The import was tested with real generated `.xlsx` files (Excel dates and dd/MM/yyyy text); a CSV was not tried.
- The names of the template columns are my guess until a real register is seen (same caveat as the Phase 2 import).

**Assumed from design §10.** Q9: the answer in §10.1 says one central store, §8.10.1 says regional offices; I followed §8.10.1, which the code handles either way (any site can be flagged). Q10: no chemicals, so there are no respirator or SCBA suggestions in "Load suggested types". Q13 and Q11 are not touched by this phase. No replacement months, entitlements or reorder levels were invented.

**Switch-on steps.**

1. Under Sites, tick **PPE store** on each region's regional-office site (and the head-office store, if there is one).
2. Under PPE types, enter the types (or "Load suggested types", then edit) and the **replacement months** for each; leave blank for no automatic replacement.
3. Under PPE entitlements, enter what each job title is entitled to.
4. Record **opening stock as receipts** at each store (PPE stock → Receive).
5. Record what staff already hold: Issue to staff with **Already held (do not deduct from stock)**, or use **Import** on the PPE issues screen. Until this is done every entitled person shows as "missing".
6. Optionally set reorder levels per store and type.

### 8.12 Phase 3b — QR labels (needs your go-ahead) and what comes after

**QR labels** for the extinguishers and first aid kits from Phase 2: scan the sticker with a phone, land on that item's record, record the monthly check. For a few hundred extinguishers this is what makes checks actually happen. It needs **one new composer package** to generate the codes, plus the PHP `gd` extension enabled in Laragon if PNG codes are embedded in the Dompdf label sheet. The link is the normal login-required item route, so scanning without logging in shows the login page, never data. I recommend doing it. If you agree, say so and I will write the 3b prompt (label sheet PDF per site, with name, asset code and QR), and I will check the package choice against `composer.lock` first.

**After Phase 3:** Phase 4 pulls it together: dashboard with charts and days-since-last-injury, the single expiry register (extinguishers, kit items, PPE replacements, service dues), the daily command that sends each alert once per threshold, list exports to Excel and PDF, and the Settings screen. Phases 2 and 3 put every state and count behind one service each, so Phase 4 should be mostly presentation and the scheduled job.
### 8.10.1 Amendment to Phase 3 from the Q9 answer (PPE stores are at the regional offices)

Paste this into Claude Code **together with** the Phase 3 prompt if it has not started yet, or as a follow-up if it has. It changes nothing in the schema.

- **A store is a regional-office site.** The team ticks `is_ppe_store` on each region's regional-office site (normally one per region). The Receive, Issue, Return and Transfer dropdowns list only `is_ppe_store` sites inside the actor's scope, so for an officer that is one entry and should be pre-selected. If someone ticks `is_ppe_store` on a site that is not a regional or head office, allow it but show a one-line note ("PPE stores are normally the regional office").
- **District managers do not see store balances.** A regional-office site has no district (`district_id` is null), so district-scoped users have nothing in scope on the stock screen. They still see PPE **issues and gaps for staff in their own district**, and still cannot post anything. In the tests, replace "a district manager sees stock and issues in their district but cannot post" with "a district manager sees issues and gaps for staff in their district, sees no store balances, and cannot post".
- **Issuing across districts** already works: the officer's employee picker is region-scoped, so staff from any district in the region can be issued from the regional store.
- **Transfers** stay (head office to a regional store, or between regions for `hs_manager`), but expect them to be rare.

### 8.12 Phase 3b — QR labels for equipment (and, optionally, site posters)

*(This replaces the placeholder §8.12 from the Phase 3 insert. Keep that insert's "after Phase 3" paragraph as §8.15.)*

**What it does.** Officers print a sheet of stickers for extinguishers and first aid kits. Each sticker carries a QR code and the asset code. Scanning it with a phone camera opens that item in the app, with the **Record check** form already open if the person is allowed to record one.

**Decisions**

1. **The code holds a link, never data.** The link is login-required. Scanning while logged out goes to the login page and then to the item (Laravel's "intended" redirect). Someone with no access gets a plain "not available to you" page that does not say whether the item exists.
2. **The link uses the numeric id, not the asset code.** Asset codes can be typed by the officer, so an existing tag with a slash or space would break a URL.
3. **The base URL is the main trap.** The QR encodes an absolute URL. If sheets are printed from a Laragon machine, every sticker will point at `https://erp_project.test` and be useless on a wall. So: a setting `GWL_HS_QR_BASE_URL` (blank means use the app URL), the label screen **shows the exact host being encoded** before you download, and the team prints labels **from production only**.
4. **No dates are printed on a label.** Expiry and service dates change; a sticker would go stale. It carries the code, the item type, the site and location, and "Scan to record monthly check".
5. **`label_printed_at`** is stored per item (a new nullable column), so during rollout the officer can filter "no label yet". It means *printed*, not *stuck on*.
6. **One new composer package** is needed. The prompt tells Claude Code to choose the smallest one that works with this repo's PHP version, installed extensions and Dompdf, and to report the choice. The production server must be able to `composer install` it and have the same PHP extension (probably `gd`) enabled; that is added to the switch-on steps.
7. **Optional extra I am proposing, not something you asked for: site posters.** One A4 page per site (a pay point, a district office) with a large QR that opens the incident report form with that site and district already filled in. That removes the biggest friction at pay points, where the Microsoft Form asked staff to type the name. Reporters still log in, so it only helps people with accounts. It is one item in the prompt (item 8) and can be deleted if you do not want it.

### 8.13 Phase 3b kickoff prompt (ready to paste into Claude Code)

```
Implement Phase 3b of the Health & Safety module: QR labels for fire extinguishers and first aid
kits, per health-safety-module-design.md section 8.12 (decisions 1-7 there). Phases 1, 2 and 3 are
built (see 8.1, 8.6 and 8.11). Read first: CLAUDE.md; 8.6 and 8.11 (as built); the Phase 2
screens and routes for extinguishers and kits (list, show, the Record check form, route names,
scope class, permission guards); app/Http/Controllers/IncidentDocumentController.php and its
Blade view (how Dompdf PDFs are produced in this repo: fonts, paper, memory); the report form
component app/Livewire/HealthSafety/ReportIncident.php; composer.json and composer.lock (PHP
version, existing packages, whether dompdf is a direct dependency, which version); the output of
`php -m` (is gd or imagick enabled?); config/app.php and app/Providers/AppServiceProvider.php
(APP_URL and forced HTTPS); config/gwl.php. Confirm every file exists and adapt to what is really
there; say so in the as-built note if anything differs. Follow CLAUDE.md throughout.

Scope — this phase is ONLY: the QR/label service, a label-sheet PDF for extinguishers and kits,
a scan landing route, a label_printed_at column with a list filter, and (item 8) optional site
posters. No change to states, alerts or exports.

1. Dependency. Add the SMALLEST QR package that works here. Decide after checking composer.lock,
   the PHP version and the enabled extensions: prefer a pure-PHP SVG output if Dompdf in this
   repo renders an SVG data URI correctly (test it, do not assume); otherwise PNG, which needs
   gd. Do not add imagick unless it is already installed and enabled. Do not add a second
   package. Report the package, version and why in the as-built note, and list composer.json and
   composer.lock as changed files. Keep all QR code behind ONE class so the package can be
   swapped.
2. Migration (new file, e.g. 2026_10_10_000001_add_label_printed_at_to_health_safety_equipment;
   hasColumn guards): nullable label_printed_at timestamp on hs_fire_extinguishers and
   hs_first_aid_kits. No new permissions: manage_equipment (officers, hs_manager) prints labels;
   manage_master_data prints site posters. Verify both exist and are granted, and say what you
   found.
3. config/gwl.php + .env.example: hs_qr_base_url (env GWL_HS_QR_BASE_URL, default null = use
   config('app.url')), hs_labels_per_pdf_max (120). Keep a comment that labels must be printed
   from production because the URL is baked into the code.
4. Services in app/Services/HealthSafety/:
   - QrCodeGenerator: the single wrapper around the package; returns an image usable in a
     Dompdf view (SVG or PNG data URI).
   - EquipmentLabelService: builds the scan URL for an item from the configured base
     (route health_safety.scan with a type of 'extinguisher' or 'kit' and the numeric id;
     never the asset code), builds the label data (QR, asset code, item type, site name and
     location_detail or vehicle registration/fleet number, region name, the line "Scan to
     record monthly check"; NO dates), renders the sheet PDF, and stamps label_printed_at on the
     items included in ONE transaction only after the PDF rendered successfully. Refuses more
     than gwl.hs_labels_per_pdf_max items per PDF with a clear message. Scope every item by the
     actor through the shared scope class; an item outside the scope is silently excluded and
     counted, never printed. Writes one Audit entry health_safety.labels_printed with the item
     type, the count, the first and last asset code, and the base URL used (not the full id list).
     Layouts: 'standard' (3 columns, 8 rows per A4) and 'large' (2 columns, 5 rows), CSS in the
     view, avoid features Dompdf does not support. Make sure the PDF stays within memory for the
     max count (test with the maximum).
5. Scan landing: a route health_safety.scan under module:health_safety AND the auth
   middleware, so a logged-out scan goes to login and returns to the scan URL afterwards
   (verify the intended-URL redirect works with this app's login, including any forced HTTPS
   and any 2FA or password-change interstitial; say what happens). Resolve the item inside the
   actor's scope. Outcomes:
   - the actor may record a check on this item (record_checks in scope, OR named as
     responsible_employee_id on THAT item): redirect to the item's show screen with the Record
     check form open (add a ?check=1 handling to ExtinguisherShow and FirstAidKitShow that opens
     the existing form; do not build a second form);
   - the actor may view it (view_equipment in scope): redirect to the show screen;
   - otherwise, AND when the id does not exist, AND when it is outside the scope: render the SAME
     plain "This item is not available to you" page with the same status code, so existence is
     not revealed;
   - a decommissioned item still resolves for anyone allowed to view it, and its show screen
     shows a clear banner "This unit is decommissioned" (stickers outlive units). If the show
     screens do not already show one, add it.
6. Livewire/UI: on Extinguishers and FirstAidKits lists add (manage_equipment only) row
   checkboxes, "Print labels for selected", and "Print labels for all N in this filter" (refused
   with a message above the max); a layout choice (standard/large); and, BEFORE the download, a
   confirmation line showing the exact base URL the codes will contain ("Labels will open
   https://… — print labels from production"). Add a "no label yet" filter (?label=none, items
   where label_printed_at is null) and a label printed column or marker. On each show screen add
   "Print label" for a single item. Downloads go through a normal authorised controller action
   (like IncidentDocumentController), not a public URL. Build from existing x-ui.* components.
7. Routes: health_safety.scan, health_safety.labels.extinguishers, health_safety.labels.kits
   (PDF download actions), plus the poster route in item 8 if built.
8. OPTIONAL — site posters (skip this item entirely if you think it risks the rest, and say so):
   a "Print poster" action on the Sites screen (manage_master_data) producing one A4 page per
   selected active site with a large QR, the site name and district, and the text "Report an
   incident or near miss". The QR opens the existing report form with the site already chosen:
   add a ?site=<id> parameter to ReportIncident that pre-fills context (pay_point for a pay-point
   site, district_office for a district-office site, regional_office for a regional-office
   site), region, district and the site, ignores an unknown, inactive or out-of-scope id without
   error, and never lets the parameter bypass any validation or permission. Same base-URL rule
   and the same confirmation line as item 6. Same PDF cap. Audit health_safety.posters_printed.
9. Tests under tests/Feature/HealthSafety/ (plain PHPUnit, RefreshDatabase, inline builders, the
   style of the earlier phases; Storage/Notification fakes as needed). Do not try to decode QR
   images: test the URL the service builds and that the PDF is produced. Cover: the scan URL
   uses the configured base and falls back to the app URL when it is blank, uses the numeric id,
   and is the same for an asset_code containing a slash or space; the PDF response is a PDF
   (content type and %PDF header) for both layouts, for one item and for the maximum allowed
   count; more than the maximum is refused with a message and prints nothing; label_printed_at
   is set only for printed, in-scope items and only if rendering succeeded (a forced failure
   leaves it null); an out-of-scope item is excluded and counted, not printed; a regional
   officer cannot print another region's labels; a user without manage_equipment gets 403 on
   the label routes even through Livewire; the ?label=none filter returns exactly the unlabeled
   items; scan route: a logged-out request redirects to login, an officer lands on the show
   screen with the check form open, a named responsible person lands with the check form open on
   their own item but only the view/no-access outcome on another item, a view-only user lands
   on the show screen without the form, an out-of-scope id and a nonexistent id return an
   identical page and status, a decommissioned item shows the banner; the audit entry records the
   base URL used and count but not the full id list; the module is still hidden when the flag is
   off. If item 8 is built: the poster PDF is produced; ?site= pre-fills context, region,
   district and site correctly for each site kind; an unknown, inactive or out-of-scope site id
   is ignored without error; a user without manage_master_data gets 403 on the poster action;
   the parameter does not bypass validation. Run: php artisan test --filter=HealthSafety (all
   earlier tests must still pass).
Report back anything in design section 10 you had to assume, and add "8.14 Phase 3b as built" to
docs/health-safety-module-design.md in the same shape as 8.1: the package chosen and why, whether
SVG or PNG was used, what the logged-out scan does with this app's login, whether item 8 was
built, anything unsure or unverified (in particular: how the PDF looks when PRINTED — it has only
been generated, not seen on paper — and a real phone camera scan), and the switch-on steps: set
GWL_HS_QR_BASE_URL (or confirm APP_URL) on PRODUCTION, run composer install there and confirm
the needed PHP extension is enabled, generate labels only from production, print one test sheet
and scan it with a phone before printing the full set. Run the HS tests against SQLite as usual
and say plainly whether anything could not be run. Do not change real data and do not commit.
```

### 8.14 Phase 3b as built

Built against §8.12 (decisions 1-7) and the §8.13 prompt, **including item 8 (site posters)**. Behind `GWL_HEALTH_SAFETY_MODULE_ENABLED` like the rest. Nothing was applied to the real database and nothing was committed.

**The package: none was added.** `bacon/bacon-qr-code` (`^3.0`, locked at v3.1.1) is already a dependency, installed for the MDM enrolment codes. I used it, so `composer.json` and `composer.lock` are **unchanged** and the production server needs nothing new from `composer install`. All use of it is in one class, `QrCodeGenerator`, so it can be swapped. **Output is SVG** (a data URI), not PNG: I tested Dompdf 3.1.6 with an SVG data URI and it draws the code as vector paths, so it stays sharp at any size. The **`gd` extension is not needed** (it is enabled here anyway; PHP is 8.5.5). Bacon's PNG back end needs Imagick, which is not installed, so SVG was also the only package-only route.

**What exists.**

- Migration `2026_10_11_000001_add_label_printed_at_to_health_safety_equipment`: nullable `label_printed_at` on `hs_fire_extinguishers` and `hs_first_aid_kits` (hasColumn guards). It means *printed*, not *stuck on*.
- Permissions: `manage_equipment` (officers, hs_manager, super_admin) prints labels and `manage_master_data` (same roles) prints posters; both already existed and were granted, so no seed migration.
- Config: `hs_qr_base_url` (`GWL_HS_QR_BASE_URL`, blank = `APP_URL`) and `hs_labels_per_pdf_max` (`GWL_HS_LABELS_PER_PDF_MAX`, 120), both in `.env.example`, with the "print from production" comment.
- Services: `QrCodeGenerator`, `QrLinks` (the absolute links; numeric id, never the asset code), `EquipmentLabelService` (sheet PDF, scope, stamping, audit), `SitePosterService`, `LabelBatch`, trait `RendersLabelPdf`.
- `LabelController`: the label PDFs, the poster PDF and the scan page. Routes `health_safety.scan`, `.labels.extinguishers`, `.labels.kits`, `.posters`.
- Screens: on the extinguisher and kit lists (manage_equipment only) a "Label" column with a tick box and the printed date, "Print labels for selected", "Print labels for all N in this filter", a layout choice, the line "Labels will open https://... Print labels from production only", and a **No label printed yet** filter (`?label=none`). A **Print label** button on each item. On Sites, ticks and "Print posters for selected" with the same base-URL line. The "decommissioned" banner now shows on both item pages. `ExtinguisherShow` and `FirstAidKitShow` open the existing check form for `?check=1`; the form was not duplicated.
- `ReportIncident` handles `?site=<id>`.

**Labels.** Two layouts: *standard* 3 x 8 (24 per A4 page) and *large* 2 x 5 (10 per page). Each label has the QR, the asset code, the item type, the site (or vehicle plate) and location detail, the region, and "Scan to record monthly check". **No dates.** Long location text is cut (site 50, location 60 characters, and 44 or 90 in the printed line) so a label never overflows its cell. A sheet takes at most 120 items; more is refused with a message and nothing is printed.

**What is stamped and audited.** `label_printed_at` is set for the items on a sheet in one transaction *after* the PDF has rendered, so a rendering failure leaves them unstamped. Items outside the user's scope (and decommissioned items) are left out of the sheet and **counted** (`excluded`), never printed. One audit entry per sheet, `health_safety.labels_printed`: type, count, excluded, first and last asset code, layout, and the **base URL used**, not the id list. Posters: `health_safety.posters_printed`, same shape.

**The scan page.** `GET /health-safety/scan/{extinguisher|kit}/{id}` sits behind `auth`, `active` and the module. The officer, or anyone with `record_checks` in scope, **or the named responsible person for that item**, is sent to the item with `?check=1`, which opens the existing Record check form. Anyone who may only view is sent to the item without the form. Everything else, **including an id that does not exist, one outside the person's scope and a responsible person's other items**, gets the same "This item is not available to you" page with the same status (403), so existence is not revealed. A decommissioned item still opens for anyone allowed to see it, with the banner "This unit is decommissioned" and no check form.

**What a logged-out scan does with this app's login.** It goes to the login page; after a correct login the standard `intended` redirect returns the person to the scan URL (tested through the real login route), and from there to the item. Two caveats. (1) A user whose `must_change_password` is set is sent to the profile page by `EnsureUserIsActive` first, and the intended address has already been used up by then, so after changing the password they land on the normal post-change page and have to scan again once. I left that shared middleware alone. (2) There is no two-factor step in this app. `APP_URL` / `GWL_HS_QR_BASE_URL` should start with `https://`: the app forces HTTPS, and a code with `http://` would add a redirect.

**Posters (item 8).** One A4 page per selected active site: a large QR, the site name, kind, district and region, and "Report an incident or near miss". The code opens `/health-safety/report?site=<id>`. The form uses `?site=` only to **pre-fill**: a pay point sets the context, the district and the site; a district office sets the context and the district; a regional office sets the context. Head Office, depot and "other" sites pre-fill nothing (the form has no matching context). An unknown, inactive, non-numeric or out-of-reach id (another region's site, for a user who does not see all regions) is ignored without an error. Nothing is bypassed: the submit validates exactly as before, and moving the district afterwards clears the pay point as it always did. Region is not a field on the form (it follows from the district), so nothing sets it.

**Decisions the design did not state.**

1. The list screens do not send the ids in a URL. Ticked rows (or "all in this filter") go into the cache under a random **one-use token tied to the user** (10 minutes), and the download route takes `?batch=`. A token is useless to anyone else, or a second time (HTTP 410). The download re-checks permission and scope itself. A single item uses `?id=`. The download is a `GET` that stamps `label_printed_at`, which is unusual; the token and the login requirement are the safeguard.
2. A failed validation on the download route (nothing selected, over the limit, nothing in scope) answers HTTP 422 with the message. The list screens catch the same cases beforehand and show the message on the page.
3. Decommissioned items are not printed. Stickers outlive units, but a new sticker for a dead unit makes no sense.
4. The scan page answers 403 (not 404) for "not available", for both a missing and a hidden item.
5. I added a `qr-code` icon to `x-ui.icon` (Lucide, same licence as the others).

**Verified.**

- 37 new tests in `QrLabelsTest` (25) and `SitePostersTest` (12); the whole `HealthSafety` filter passes (346 tests) and so does the full suite (1857 tests) on in-memory SQLite. One earlier full run showed two `LeaveLetterTest` failures (HTTP 500) that I could not reproduce: that class passes alone, together with all the Health & Safety tests, and in a second full run. I have no explanation for the one-off. The test of 120 labels in both layouts measured about 37 MB above the application's baseline for the standard layout (PHP's CLI limit here is 128 MB).
- **The codes were decoded.** I rendered the generated PDFs to images and read the QR codes back with OpenCV: the standard and large labels decode to `https://erp.gwcl.com.gh/health-safety/scan/extinguisher/1` and the poster to `.../report?site=1` (the host was a test value). I also looked at page images to fix cut-off text and a spill-over page; the layouts above are the corrected ones.

**Unsure or unverified.**

- **Not printed on paper and not scanned with a phone.** The PDF has only been generated and viewed on screen as an image. Cell sizes, the 25 mm and 36 mm code sizes and the dashed cut lines have not been seen on paper or on a wall, nor tried with a phone camera or on a glossy, curved or dirty sticker. Print one test sheet first.
- The memory figure comes from the CLI; the production PHP `memory_limit` and PDF time for 120 labels have not been checked there.
- In a real browser I did not click through "Print labels ..." (a Livewire redirect to a PDF attachment; the tests assert the redirect and the download, not the browser behaviour), nor a logged-out scan.
- To check the codes and layout I installed `pymupdf` and `opencv-python-headless` into the local Python; they are not part of the project.

**Switch-on steps.**

1. On **production**, set `GWL_HS_QR_BASE_URL` (or confirm `APP_URL`) to the public address, with `https://`. Run `php artisan config:cache` again if config is cached. Run `php artisan migrate` (adds `label_printed_at`). `composer install` needs nothing new.
2. Generate labels **only from production**; the Labels screen shows the address that will be encoded before you download.
3. Print one test sheet (standard or large), stick one label, and scan it with a phone, logged out and logged in. Only then print the full set.
4. Use the "No label printed yet" filter to work through the rollout.
5. For pay points and district offices, tick the sites on the Sites screen and print posters.

### 8.16 Review of Phases 2, 3 and 3b (2026-10-07)

All three are built to the prompts, with tests, and the decisions Claude Code recorded are sound. Points that need an answer or an action, most important first.

**1. State order for extinguishers: one change recommended.** You asked me to compare with 8.4. What I had specified was `expired → service_overdue → hydro_overdue → check_failed → expiring → service_due_soon → hydro_due_soon → check_overdue → ok`. Two differences from what was built:

- **`check_failed` sits after `expiring` and `service_due_soon`; I intended it before.** A unit that failed its check and also expires in 50 days would show only as "expiring", hiding the more urgent fact (it may not work today). I recommend moving it up, to just after `hydro_overdue`. The kit order matches what I specified exactly.
- **`hydro_due_soon` was merged into `service_due_soon`.** Fine; that is a display label and the dates are still separate.

The change is small (the one `states()` table in `HsFireExtinguisher`, its SQL twin, and the parity-test fixtures) and it should be made **before** Phase 4, because the dashboard's compliance percentages are built on these states. It is item 2 of the prompt below.

**2. Your design doc is missing sections 8.2 to 8.5.** Claude Code said so itself ("this document has 8.1 and then jumps to section 9"). The Phase 2 insert I gave you earlier (review of Phase 1, pre-Phase 2 checks, §8.4 scope and the Phase 2 prompt, plus §8.7) was never pasted in, so Phase 2 was built from the prompt alone. It still came out right, but please paste that insert in now, so the doc is complete and a future reader can see why Phase 2 looks the way it does.

**3. Your doc contradicts itself on PPE stores.** Claude Code found that §10.1 says "one central store" while §8.10.1 says the regional offices, and followed §8.10.1. Update §10 Q9 to match what you told me: **stores are at the regional offices**.

**4. A flaw in my Phase 3 design: a normal PPE issue can only be dated today.** I wrote "not in the future" and "may not be backdated" into the tests, and Claude Code followed them. In practice an officer who hands out boots on Friday and records them on Monday cannot do so correctly. The only alternative is "already held", which does not deduct stock, so the ledger drifts. Fix: allow a normal issue (and a close) to be dated up to `hs_issue_backdate_days` earlier, default 7. It is item 3 of the prompt below.

**5. A test flake to look at before committing.** The Phase 3b note mentions one full run where two `LeaveLetterTest` cases returned HTTP 500 and could not be reproduced. A 500 in an unrelated test class, after heavy PDF tests have run in the same process, looks like it could be memory exhaustion. I don't know that. The cause should be found, not assumed (item 1 of the prompt tells Claude Code to run the full suite three times and read `storage/logs/laravel.log` for the stack trace).

**6. Things I'd like to acknowledge as good decisions.**

- **The QR work reused `bacon/bacon-qr-code`** that was already installed, so there is no new dependency and nothing new for production. Rendering SVG instead of PNG also removes the `gd` question.
- **The labels were decoded back with OpenCV**, so we know the URLs inside the codes are what the service says they are.
- **The scan page returns the same 403 page for a missing item and a hidden one**, so existence is not revealed; and the responsible-person rule extends sensibly to "My equipment".
- **Decision 10 in the Phase 2 note**: a test found two more places a confidential reporter could show, and they were closed. This is the third time the one-visibility-service rule has paid for itself.

**Still unverified across all four phases:** nothing has run on MySQL, nothing has been seen on a phone, and labels have not been printed on paper. §8.17 covers these.

### 8.17 Before Phase 4: checks (half an hour, in this order)

1. **MySQL.** Run the whole HS test group once against a **throwaway empty MySQL database**, never the real one (`RefreshDatabase` wipes it; check the database name before you press Enter). The state queries use `COALESCE(DATE())` and nested wheres, the reference generator uses `LENGTH()`, and the PPE ledger uses `lockForUpdate()`. All were only run on SQLite. Fix anything that fails before Phase 4 builds on it.
2. **Real database.** Back up, `php artisan migrate --pretend`, read it, then migrate. Phases 1 to 3b added many tables and several guarded alters.
3. **Browser, on a phone.** Report an incident; add an extinguisher; record a check (one-handed); issue PPE; upload a photo from the phone camera. Fix layout problems before Phase 4 adds more screens to the same sidebar.
4. **Print one test label sheet** (from production, never Laragon, because the URL is baked into the code), stick one on a unit and scan it logged out and logged in.
5. **Scheduler and mail.** Phase 4 is the first phase that needs the scheduler. Confirm the production cron for `schedule:run` is really running (the Transport expiry command already depends on it; look for its recent output), and that mail works from production.
6. **Fix §10 Q9** in the doc (item 3 of the review) and paste in the missing §8.2 to §8.5.

### 8.18 Phase 4 scope — expiry register, alerts, dashboard, exports and Settings

**Principle.** Phases 2 and 3 put every state and count behind one service each. Phase 4 adds **no second definition** of any of them. Every dashboard number, every alert and every export reads the same query as the screen it comes from, so the number on the dashboard, the rows in the list, the rows in the export and the items in an alert can never disagree. The tests prove this.

**A. The expiry register** (`view_equipment`). One screen of **dated items**, one row per due date, not per unit (a single extinguisher can have an expiry date, a service due date and a hydrostatic test date, and each is its own row; this also stops the "a unit is in two buckets" confusion Phase 2's Overview note warns about). Five row types:

| Type | Date |
|---|---|
| Extinguisher expiry | `expiry_date` |
| Extinguisher service | `next_service_due` |
| Extinguisher hydrostatic test | `next_hydro_test_due` |
| First aid kit item | item `expiry_date` (items that have expiry) |
| PPE replacement | `replace_due_on` of an open issue |

Only active units count (not decommissioned or discharged, not closed issues). Each row shows: what, site/vehicle/employee, due date, **days to due** (negative = overdue), responsible person, and a bucket (*overdue*, *critical* within `hs_expiry_critical_days`, *due soon* within `hs_expiry_warning_days`, *later*). Filters: region (for all-region viewers), district, site, type, bucket, horizon (30/60/90/180 days or "overdue only"). Sorted by urgency. **Checks are not rows** (a 30-day check interval would put every unit on the register every month); overdue checks stay on the Overview and list filters.

**B. Alerts** (one scheduled command, no new queue needed).

- **Equipment dates:** thresholds in days before the due date (default `60, 30, 7, 0, -7, -30`; negative = overdue). For each register row, the **lowest threshold already crossed and not yet sent** triggers a notification. Higher thresholds already crossed are recorded silently, so an item first seen 20 days from expiry produces one alert (the 30), not a late "60" followed by a "30". Dedupe key includes the due date, so renewing the date restarts the cycle.
- **Digest, not one-per-item.** Importing three hundred extinguishers must not send three hundred notifications. Each recipient gets **one notification per run** that summarises counts by type and links to the register filtered to their items.
- **Recipients:** the `hs_officer`(s) of the region; the **responsible person** for their own items only; `hs_manager` gets only the overdue (zero and below) items across regions.
- **Channels:** the bell for every digest, mail for digests containing thresholds 7, 0 or overdue. Same `GeneralDatabaseNotification` as the rest of the module.
- **Incident and action reminders**, promised in §3.3, run in the same command and the same dedupe log:
  - report not acknowledged within `hs_ack_hours` → the officer(s); at twice that → `hs_manager` and the regional chief manager;
  - investigation past its due date (`hs_investigation_due_days`) → the officer, then `hs_manager` a week later;
  - action due in 3 days, due today, and a week overdue → the assignee (and the officer at +7).
- **Two schedules:** the **acknowledgement check hourly** (a daily run would let a 24-hour limit slip to nearly 48 hours), and everything else daily at 06:30 (the app's timezone). The same command takes an option to choose the group.
- **Dry run:** a `--dry-run` mode that prints who would be told what and writes nothing, for the first production run.
- **The dedupe log is `hs_alert_log`** (this replaces the table I called `hs_expiry_alerts` in §2.2, because it now covers more than expiry): `alert_type`, `item_id`, `due_key` (a string; the date, or `n/a`, so that the unique index works on MySQL, which treats NULLs as distinct), `threshold`, `sent_at`; unique on the first four.

**C. Dashboard** (`view_dashboard`; replaces the counts-only Overview but keeps its links). Period filter (this month, quarter, year, custom) and scope by actor; all-region viewers also get region and district filters. **Definitions, written down because this is where arguments start:**

- Incidents are counted by `occurred_on` in the period and exclude `cancelled`.
- **Days since last injury** = today minus the latest `occurred_on` of a non-cancelled incident of type *Injury* in scope, shown per district. With none on record it says "None on record since <date of the module's first record>", never a huge number.
- **Days since last lost-time injury** needs injury details, so it is shown only to people with `view_injury_details`; others see the general injury figure only.
- **Near misses per injury** = near-miss count ÷ injury count in the period, "—" when there are no injuries.
- **Overdue:** acknowledgement past `hs_ack_hours`; investigation past its due date; open actions past `due_on`.
- **Equipment compliance** = units in state `ok` ÷ units evaluated (in service, not decommissioned or discharged), separately for extinguishers and kits, and "checks up to date" = units not in `check_overdue` ÷ units evaluated.
- **PPE compliance** = staff with no gap state (`missing`, `overdue`, `short`) ÷ staff evaluated, from `PpeComplianceService`; plus low-stock count.
- **No LTIFR** (needs hours worked; §10 Q13 is open). Aggregates only: no names, no confidential reporters, no person-level injury data anywhere on the dashboard.

Charts: incidents by month (last 12, by type), by type, by district, by severity. Every card and chart segment links to the filtered list, and every chart has a table behind it (color is never the only carrier of meaning).

**D. Exports** (`export_reports`; officers, `hs_manager`, regional chief manager). Excel from the **same filtered query** as the screen, with a row cap (`hs_export_max_rows`, default 5,000: refuse above it with a message): incidents, actions, extinguishers, kits (with an item summary), PPE stock balances, PPE issues, PPE gaps, expiry register. Plus a **PDF of the expiry register for a site walk-round**: grouped by site, sorted by due date, with a blank "checked / notes" column.

- **Incident export holds no free text.** No description, witness contact, or names of people affected: those fields carry names and phone numbers. Injury columns (type, body part, treatment, lost days) appear only for people with `view_injury_details`; a confidential reporter reads "Confidential" for anyone not entitled, via `IncidentVisibility`. The print copy remains the way to read one incident in full.
- **Spreadsheet safety:** any cell that starts with `=`, `+`, `-` or `@` is neutralised. Copy whatever the Commercial and Credit Union exports already do.
- Every export writes `health_safety.export` to the audit log: report, row count, filters, user.

**E. Settings** (`manage_settings`: `hs_manager` and `super_admin` only). The values that are in `.env` today become editable: expiry warning and critical days, check interval, extinguisher service months and hydrostatic years, acknowledgement hours, investigation days, alert thresholds, issue backdate days, export row cap, the second-approver switch, and the **emergency contacts** text for the report form. Follow how the Commercial module's Settings screen stores and resolves its values (a saved value wins, else the config default). **All existing reads of these keys across Phases 1 to 3b must go through one accessor**, so there is one place that decides the current value. Validation (for example, critical days below warning days; thresholds positive and distinct), and every change is audited with old and new value.

**Not in Phase 4:** a weekly digest of overdue *checks* (the Overview and list filters already show them), lot-level PPE stock expiry, per-site emergency contacts, LTIFR, severity wording editable in Settings (§10 Q14), reminders about `reportable_externally` (§10 Q5 is open).

### 8.19 Phase 4 kickoff prompt (ready to paste into Claude Code)

```
Implement Phase 4 of the Health & Safety module for this ERP, per health-safety-module-design.md
section 8.18 (A to E) and the review in 8.16. Phases 1, 2, 3 and 3b are built (see 8.1, 8.6, 8.11,
8.14). Read first: CLAUDE.md; 8.1, 8.6, 8.11 and 8.14 (as built); 8.16 and 8.18; the Phase 2
equipment services and models (EquipmentExpiryService, ActorScope, EquipmentScope,
HsFireExtinguisher and HsFirstAidKit states()), the Phase 3 PPE services (PpeComplianceService,
HsPpeIssue::state()), app/Services/HealthSafety/IncidentVisibility.php, app/Livewire/HealthSafety/
Home.php and IncidentIndex.php (query parameters and filters), how the Commercial and Assets
dashboards draw charts (use the SAME chart approach; add NO new JavaScript or composer
dependency), how the Commercial module built its Settings screen (storage, resolution order,
audit; copy the approach, not the tables), how Commercial and Credit Union exports are produced
(Maatwebsite classes, the formula-injection handling, row caps; reuse the helper), routes/
console.php and the transport:check-expiries command (naming and scheduling style),
app/Notifications/GeneralDatabaseNotification.php and the Transport listeners (notification
pattern), config/gwl.php. Confirm every file exists and adapt to what is really there; say so in
the as-built note if anything differs. Follow CLAUDE.md throughout.

Scope — this phase is ONLY: the housekeeping in items 1 to 3, the expiry register, the alert
command and its log, the dashboard, the exports, and the Settings screen. No new data about
equipment or incidents, no QR work, no LTIFR, no lot-level PPE expiry.

1. Test health first. Run the full application suite three times and read storage/logs/
   laravel.log for the cause of the two LeaveLetterTest HTTP 500 failures seen once during
   Phase 3b (a suspected cause is memory growth from the PDF-heavy label tests, but verify, do
   not assume). Report the cause. If it is memory or the heavy label tests, fix it (smaller
   fixture, explicit gc, or splitting the test) without weakening what the test proves. If you
   cannot reproduce or explain it after three runs, say so plainly and leave the code alone.
2. Extinguisher state order: move check_failed to sit immediately after hydro_overdue and
   BEFORE expiring (design 8.16 item 1). Change it in ONE place each for the SQL condition and
   the PHP test in HsFireExtinguisher::states(), update the parity test fixtures and the unit
   tests that assert the order (a failed check beats expiring and service_due_soon, loses to
   expired, service_overdue and hydro_overdue), and make sure Overview counts, list filters and
   the Phase 2 import/label code that read states still agree. Kit order is unchanged. Do not
   re-split service_due_soon.
3. PPE backdating (design 8.16 item 4): a normal issue and a close may be dated up to
   hs_issue_backdate_days earlier (config key, default 7, never in the future); the ledger row's
   occurred_on is that date. "Already held" rows keep accepting any past date. Update the
   Phase 3 tests that said a normal issue cannot be backdated: within the window is accepted,
   beyond it is refused, future is refused, replace_due_on is computed from the given date.
4. Settings accessor FIRST (it is needed by everything below): create
   app/Services/HealthSafety/HealthSafetySettings.php (or what the Commercial approach
   dictates) that resolves each key as: saved value, else config('gwl.…') default. Keys:
   hs_expiry_warning_days, hs_expiry_critical_days, hs_check_interval_days,
   hs_extinguisher_service_months, hs_extinguisher_hydro_years, hs_ack_hours,
   hs_investigation_due_days, hs_alert_thresholds (equipment, default 60,30,7,0,-7,-30),
   hs_action_alert_thresholds (default 3,0,-7), hs_issue_backdate_days, hs_export_max_rows
   (5000), hs_require_second_approver, hs_emergency_contacts. Then grep the whole codebase for
   every read of these config keys (config('gwl.hs_ …) and replace them with the accessor.
   Existing tests set config values: the accessor must keep honouring a config override when
   no saved value exists, so the tests need no change beyond what the accessor requires. If a
   new migration is needed for storage, add it (guarded, new file); no other schema change in
   this item. Validation rules: critical < warning; all day counts and hours positive
   integers; thresholds a list of distinct integers; the emergency contacts a short text. Every
   save writes Audit health_safety.setting_changed with the key, old and new value. Screen
   Settings (manage_settings only: hs_manager and super_admin, verify the grants) in the
   sidebar, built from existing x-ui.* components, with each field showing its default and what
   it affects in one line.
5. Expiry register: app/Services/HealthSafety/ExpiryRegisterService.php — the ONE source for
   the five row types in 8.18 A (extinguisher expiry, service, hydrostatic test; kit item;
   PPE replacement), built by per-source queries limited to the horizon, scoped by the actor
   through the shared scope class, merged, sorted by days to due then by type, returned as a
   paginated collection. Active units/open issues only. Provide counts(scope) by bucket and
   by type from the same query objects. Livewire ExpiryRegister (view_equipment) with the
   filters in 8.18 A, row links to the item (extinguisher/kit page, or the PPE issue screen),
   query-string parameters for every filter so dashboard numbers can link to it, and status
   pills (reuse or add domains in docs/11-ui-components.md). Also add the register's PDF and
   Excel exports (item 8).
6. Alerts: a command in the repo's naming style (check the existing names and report the one
   you chose, e.g. health-safety:alerts) with an option to choose the group (equipment,
   actions, incidents; default all) and --dry-run (prints who would be told what, writes
   nothing). Migration for hs_alert_log (alert_type, item_id, due_key string, threshold smallint
   signed, sent_at; unique alert_type+item_id+due_key+threshold; index sent_at). Logic:
   - equipment: for each register row take the thresholds from the accessor; compute the lowest
     threshold with days_to_due <= threshold that has no log row; if any, record ALL crossed
     thresholds in the log and notify only for the lowest. due_key is the due date, so a
     renewed date restarts the cycle.
   - actions: same mechanism with hs_action_alert_thresholds on open actions' due_on.
   - incidents: not acknowledged within hs_ack_hours (tier 1), twice that (tier 2);
     investigating past its due date (tier 1), a week later (tier 2). due_key is 'n/a' or the
     status-entry date as the design needs; keep the unique key valid on MySQL.
   - recipients: hs_officer(s) of the region; the responsible person for their OWN equipment
     items only; hs_manager gets only overdue (<= 0) equipment items and tier 2 incident
     reminders across regions; regional_chief_manager gets tier 2 incident reminders for the
     region; action reminders go to the assignee and, at the overdue threshold, the officer.
   - ONE digest notification per recipient per run (counts by type, a few examples, a link to the
     register or list filtered to that recipient's items), via GeneralDatabaseNotification;
     mail only if the digest contains a threshold of 7 days or less. No per-item notification.
   - never notify for decommissioned or discharged units, closed issues, done actions or closed
     or cancelled incidents; respect IncidentVisibility (no confidential reporter names in any
     text).
   - schedule in routes/console.php: the incidents group hourly, the other groups daily at 06:30
     in the app's timezone (check config('app.timezone') and report it), both withoutOverlapping.
   - wrap each recipient's send so one failure cannot stop the run; log it; the command exit
     code reflects partial failure.
7. Dashboard: app/Services/HealthSafety/HealthSafetyDashboardService.php and a single data class
   (HealthSafetyReportData or the repo's equivalent) feeding the screen. Replace the counts on
   Home for view_dashboard holders (keep the existing counts and links working for others).
   Period filter (this month, quarter, year, custom) and scope by the actor; all-region viewers
   get region and district filters. Implement EXACTLY the definitions in 8.18 C; take equipment
   and PPE figures from EquipmentExpiryService and PpeComplianceService, never from a second
   query. Per-district table: days since last injury (and, only for view_injury_details
   holders, days since last lost-time injury). Charts via the app's existing approach: incidents
   by month (12 months, by type), by type, by district, by severity; a table behind every chart;
   not colour-only. Every number links to a filtered list: add URL-bound filter parameters to
   IncidentIndex where missing (type, district, date_from, date_to, overdue=ack|investigation)
   WITHOUT changing existing behaviour, and to the equipment, actions and PPE lists where a link
   needs one. Aggregates only: no names, no free text, no confidential reporters anywhere.
8. Exports (export_reports; verify officer, hs_manager and regional_chief_manager hold it and
   district_manager does not): Excel for incidents, actions, extinguishers, kits (with an item
   summary), PPE stock balances, PPE issues, PPE gaps and the expiry register, each built from
   the SAME query object as its screen with the current filters, capped by
   hs_export_max_rows (refuse above it with a clear message, never silently truncate); plus a
   PDF of the expiry register for a site walk-round (grouped by site, sorted by due date, a
   blank "checked / notes" column), through the repo's Dompdf pattern. Incident export rules:
   NO description, witness contact or names of people affected; injury columns only for
   view_injury_details holders; reporter shown as "Confidential" via IncidentVisibility for
   those not entitled. Neutralise cells that start with = + - @ using the existing helper.
   Export buttons on each screen (permission-gated). Downloads through authorised controller
   actions, not public URLs. Audit health_safety.export (report, rows, filters).
9. Sidebar: add Expiry register (view_equipment) and Settings (manage_settings); Overview stays
   the dashboard. Routes in the health_safety. group with module:health_safety and permission:
   middleware, plus enforceLivewireModule(...) and per-action guards in every component, as in
   the earlier phases.
10. Tests under tests/Feature/HealthSafety/ (plain PHPUnit, RefreshDatabase, inline builders,
   Notification::fake(), Carbon::setTestNow for dates; the style of the earlier phases). Cover:
   Settings: accessor returns saved value, else a config override, else the default; validation
   rules; every change audited with old/new; only hs_manager and super_admin can open it, a
   user without manage_settings gets 403 even through Livewire; the grep leaves no direct
   config('gwl.hs_…') reads outside the accessor. Order and backdating: check_failed ordering
   in both SQL and PHP and the parity grid still agrees; backdating within/beyond/future.
   Register: each row type appears with the right date; decommissioned, discharged, closed
   issues and kit items without expiry are excluded; days to due and buckets are right at the
   boundaries (today, +critical, +warning, overdue); scope per role; the extinguisher-expiry
   count within hs_expiry_critical_days equals the Phase 2 Overview figure for the same data;
   register counts equal the number of rows the filtered screen lists. Alerts: lowest crossed
   threshold notifies once and records all crossed; a second run sends nothing; a renewed due
   date restarts the cycle; a newly imported item 20 days out gets exactly one alert (the 30),
   never a later 60; digest: 300 due items produce ONE notification per recipient; recipient
   rules (officer by region, responsible person own items only, hs_manager overdue only);
   mail only when a threshold of 7 or less is in the digest; incident ack tiers 1 and 2;
   investigation tiers; action reminders and the officer at the overdue threshold; closed,
   cancelled, done and decommissioned things never notify; no confidential reporter name in
   any text; --dry-run writes nothing and sends nothing; one failing recipient does not stop
   the others and sets a non-zero exit code; the hs_alert_log unique index prevents a double
   send even if two runs overlap. Dashboard: every definition in 8.18 C with fixtures (days
   since last injury, none on record, lost-time visible only with view_injury_details, near
   misses per injury with zero injuries, overdue counts, compliance percentages equal the
   service counts, cancelled excluded, period boundaries); scope per role; no names or free text
   in the payload; links carry filter parameters that reproduce the same number on the list.
   Exports: each export has exactly as many rows as the screen for the same filters; the cap
   refuses; incident export has no description/witness/person columns and hides injury columns
   without view_injury_details and shows Confidential; formula-looking cells are neutralised;
   a user without export_reports gets 403; the PDF is a PDF; every export writes an audit row.
   The module is still hidden when the flag is off. Run: php artisan test --filter=HealthSafety
   and then the full application suite; all earlier tests must pass.
Report back anything in design section 10 you had to assume, and add "8.20 Phase 4 as built" to
docs/health-safety-module-design.md in the same shape as 8.1: decisions the design did not state,
what you skipped, anything unsure or unverified (say plainly if MySQL, a browser, the scheduler
or mail could not be run), and the switch-on steps: run the migration, check the scheduler cron,
run the alert command with --dry-run in production and read the output before the first real run,
then review Settings values with the EHS department. Do not change real data and do not commit.
```

### 8.20 Phase 4 as built

(To be added by Claude Code when Phase 4 is done.)

### 8.21 After Phase 4: go-live and what is left

**Go-live checklist.** The full HS test group passes on MySQL (§8.17.1). A phone check of the report form and the check form. One printed test label sheet scanned from production. The scheduler confirmed running, and a `--dry-run` of the alert command read before the first real run. Roles assigned in UAC (`hs_officer`, `hs_manager`). Sites and pay points entered; kit templates confirmed with EHS; PPE types, replacement months, entitlements, opening stock and existing holdings entered. Emergency contacts set in Settings. Then **retire the Microsoft Forms**: export their responses to Excel first (the history is not imported yet; §10 Q11), and turn the forms off or replace their intro with the new address.

**Left, in rough order of value.** Importing the old Forms responses (needs the Excel export). Site safety inspections with checklists, feeding the same actions list (Phase 5). Training and certificates, drills, toolbox talks and emergency info (Phase 6). Hazard register. LTIFR once hours worked are settled. A fully anonymous reporting route, if the team decides it is needed after seeing how named, confidential reporting is received.

**The questions still open from §10:** Q5 (statutory reporting rules), Q6 (HR visibility and retention), Q7 (a sample register to tune the import), Q8 (which extinguisher dates the team actually tracks), Q10 (chemicals), Q11 (Forms export), Q13 (hours worked), Q14 (severity wording), Q15 (district spelling). None blocks Phase 4.

## 9. Out of scope

Integration with any external safety, insurance or government reporting system (the module only *tracks* whether an external notification was made); a public/anonymous web form (see §10 Q1/Q2); biometric or GPS capture; vendor and procurement management; cost tracking of PPE, repairs or services (same decision as Assets); a full risk-assessment/permit-to-work system; offline-first mobile app.

---

## 10. Questions to confirm (before or during Phase 1)

1. **Anonymous or named?** The Forms are anonymous today. Is a named report with a confidentiality option (as designed) acceptable, or must a fully anonymous option remain? Anonymous submission means no feedback to the reporter and no follow-up questions.
2. **Do all reporters have ERP accounts?** Pay point attendants, casuals, contractors and field crews may not. If not, `record_on_behalf` (district managers, officers) covers it for now; a public QR-code report link (like the visitor kiosk) could come later.
3. **Officer coverage.** One Health & Safety Officer per region, or also district focal persons? The design gives district managers `record_checks`; a named "safety focal person" per district is easy to add as a role if needed.
4. **Head-office EHS.** Is there a head-office EHS lead who should be `hs_manager` with all-region visibility and `approve_closure`? If not, the role is dropped (§2.1).
5. **Statutory reporting.** Which incidents must be reported externally, to whom and by when? The module only has fields to track it (`reportable_externally`, `external_ref`); the EHS department should confirm the rules before any reminder logic is built.
6. **HR visibility.** Should `hr_headoffice`/`hr_region` see injury details (lost time, return-to-work, compensation)? And what is the retention period for injury records?
7. **Existing registers.** Where do the extinguisher, first aid kit and PPE lists live now (Excel?), and do items already carry asset numbers? A sample file decides the Phase 2 import template and the `asset_code` scheme.
8. **Extinguisher dates.** Is the "expiry date" you mean the manufacturer's expiry, the annual service certificate date, or both? The design stores expiry, next service due and next hydrostatic test separately and alerts on each; tell me which ones the team actually tracks.
9. **PPE specifics.** One central store or a store per district? Are sizes tracked? Replacement intervals by item (e.g. safety boots yearly) and who is entitled to what (by job title)? Is an in-app "confirm receipt" enough, or is a signature required?
10. **Chemicals and treatment plants.** Are chlorine or other treatment chemicals handled in the region, so that respirators/SCBA and Safety Data Sheets belong in scope?
11. **Historical data.** Can an Excel export of the existing Microsoft Forms responses be provided, to import past incidents for trend continuity (with the same spelling-alias treatment as the Commercial module)?
12. **Vehicles.** Should kits and extinguishers in Transport-module vehicles be tracked here (linked to `vehicles`)?
13. **Incident rates.** For LTIFR-style rates we need hours worked. Is headcount × standard hours an acceptable approximation, or is there real timesheet data? Until then the dashboard shows counts and days-since-last-injury only.
14. **Severity wording.** Are the four proposed levels (Low / Medium / High / Critical) and their one-line definitions in §3.2 acceptable, or does GWCL already have a safety classification to use?
15. **District naming.** Which spelling goes into master data — Odokor or Odorkor, Darkuman or Darkuman/Gbawe? The forms and the Commercial reports disagree.

### 10.1 Answers received, and what each one changed

| # | Answer | Effect on the build |
|---|---|---|
| 1 | A **fully anonymous option** must exist. | **Built** (migration `2026_10_09_000001`, `hs_incidents.is_anonymous`). A "Report anonymously" tick on the form: the report, its first timeline row and its audit entry carry no user, no IP and no employee; photos are re-encoded to drop their metadata (GPS, device, time) and renamed; it never appears under My reports and the filer cannot reopen it; no feedback can reach them. It cannot be combined with "keep my name confidential" or "reporting for someone else". The confidential option stays for people who want the outcome. The limit is stated on the screen: the application hides the filer inside the system, but cannot hide that someone was signed in from a server administrator who reads raw web-server logs. |
| 2 | Yes, `record_on_behalf` is enough for people without accounts. | As built. A public QR-code report link stays a later option. |
| 3 | One official officer per region; some districts have trained **ordinary staff** who act as Health & Safety persons, with no official role. | The region officer is as built. For the trained staff, nothing is added: they are named as the **responsible person** on the equipment they look after (they may then record checks on it), and that is all they get for now (decided, see the end of this section). |
| 4 | Yes, a head-office lead. | `hs_manager` stays (all regions, approves High and Critical closures, settings). |
| 5 | **No need** for tracking outside notification. | **Removed** from the triage screen, the print copy and the workflow. The `reportable_externally`, `external_ref` and `external_reported_on` columns stay in the table, unused (shipped migrations are never edited). |
| 6 | No, HR does not see injury details. | As built: `hr_headoffice` and `hr_region` hold `report_incident` only. |
| 7 | Existing registers are in **Excel**. | The Phase 2 import and its templates are built for Excel. No sample file has been seen, so the column names are still my guess. |
| 8 | Both the manufacturer's expiry and the annual service date (and the hydrostatic test). | As built: expiry date, next service due and next hydrostatic test are all tracked and all drive a state. |
| 9 | **One central store** for PPE. | For Phase 3: a single store (one site) instead of a store per district. |
| 10 | No chemicals or treatment plants in scope. | Respirators/SCBA and Safety Data Sheets stay out. |
| 11 | Not sure about importing the old Forms responses. | Not built; the answer is still open. |
| 12 | Yes, vehicles. | As built in Phase 2 (equipment at a site or in a vehicle). |
| 13 | Headcount × standard hours is acceptable for incident rates. | For Phase 4; the dashboard can show rates on that approximation, labelled as one. |
| 14 | The four severity levels are fine for now. | As built. |
| 15 | Spellings **Odorkor** and **Darkuman/Gbawe**; Darkuman/Gbawe is expected to be **split** later. | Nothing in the module fixes a spelling: the forms read the districts HR keeps (Staff > Locations), so those are the names that show. When Darkuman/Gbawe splits, equipment follows its **site**, so re-pointing a site to the new district (Sites screen) moves its extinguishers and kits with it; **incidents already filed keep the old district** (an incident records where it happened at the time), and the district manager for each new district then sees only new reports. |

**Question 3, decided: option (a) for now.** The trained district staff have no role of their own. They are ordinary staff who can be named as the **responsible person** on an extinguisher or kit, which lets them open it from My equipment and record a check on it, and nothing else. If that proves too little, the cheap additions are: also get the new-report notices for their district; also record reports for colleagues (`record_on_behalf`); also record checks on any equipment in their district. None of them would let these staff triage, close or see injury details.
