# Letters Module — Logic Review, Alternatives & Batch Dispatch / Confirmation Design

Status: **proposal, nothing implemented** · Prepared: 2026-09-30 · Verified against the code on disk (`app/Services/Letters/LetterWorkflowService.php`, `app/Livewire/Letters/*`, `resources/views/livewire/letters/*`, `app/Models/{MailLetter,RoutingHistory,LetterStatusLog,LetterRemark,LetterNotification}.php`, `database/migrations/2026_04_28_000002_create_letters_tables.php`, `2026_05_08_000001_…letter_remarks…`, `database/seeders/LettersRolePermissionSeeder.php`, `routes/web.php`, `app/Support/ErpNavigation.php`, `app/Models/{Employee,AuditLog}.php`, `config/gwl.php`) via the linked device bridge into `C:\laragon\www\erp_project`. Line numbers below refer to those files as of today. **There are no Letters tests** (`tests/Feature/` has no `Letters/` folder), so Phase 0 below starts by pinning current behaviour.

> **2026-09-30, revision 2** — two decisions folded in: **(1) managers hold letters in the app (Phase 3)** — now specified in §6, including a prerequisite found in the code (the letters notification bell only renders inside the Letters module, so a manager working in Leave would never see a hand-over); **(2) letter scans are added as an optional, feature-flagged attachment (Phase 5)** — §7. Newly verified for this revision: `database/seeders/ModuleAccessSeeder.php`, `resources/views/layouts/erp.blade.php`, `app/Livewire/Notifications/GeneralBell.php`, `app/Notifications/GeneralDatabaseNotification.php`, `config/filesystems.php`, `.env.example`, `composer.json`, and the existing upload code (`app/Livewire/Transport/{Issues,Vehicles}.php`, `app/Services/Transport/TransportService.php`, `app/Livewire/CreditUnion/Deductions.php`). Sections 8-10 were renumbered (Phased delivery, Kickoff prompts, Open decisions).

> **2026-09-30, revision 3** — **(1) the Managing Director does not hold letters** (recorded in §6; Phase 3 carries a guard test; the open question of where the MD's written comment goes is §10 item 1). **(2) The kickoff-prompt set is now complete:** Phases 0, 0b (serial numbers), 1, 2, 3, 4 and 5 are all in §9, with an overview table in §8 and design notes for the three phases that had none (§8.1 serial numbers, §8.2 exceptions & aging, §8.3 register export). **The Phase 0 and Phase 1 prompts are unchanged** — they were already pasted into Claude Code — so anything those phases do not cover was moved to Phase 2 or later (for example the `letter_status_logs.routing_history_id` link and `routing_histories.reminded_at`). Every prompt after Phase 1 begins by telling Claude Code to read what the earlier phases really built. Newly read for this revision: `VisitorExportController`, `ApprovedLeavesExport`, `RegionsManager`, the Letters `dashboard.blade.php` and the visitors export routes.

---

## 0. Summary

1. **Keep the core model.** One `mail_letters` record that travels, a per-holder `letter_status_logs` row (this is each secretary's Excel register), and a `routing_histories` hop that the receiver must acknowledge (this is "sign for it"). It is the digital form of how a registry works; replacing it with a rigid workflow engine would fight the way letters actually move (the route is decided by the CM's comment, not by letter type).
2. **Four places the app does not match how GWCL works:** (a) recipients must be secretaries, but in the regions letters go straight to the Materials manager / ICT and finally to the staff member; (b) no batching — the ask; (c) no way to undo a wrong or lost dispatch; (d) not enough timestamps, and no register export, so secretaries will keep typing Excel in parallel.
3. **Batch dispatch + batch confirmation** is best built as a **transmittal**: a numbered, printable hand-over sheet that groups several hops to one recipient. The receiver ticks off what physically arrived and confirms once (partial receipt allowed). It is additive — three small migrations, one new service method pair, one multi-select on the existing list, one new "Transmittals" page. The existing single-letter flow keeps working unchanged. (§5)
4. **Fix a handful of real defects first/alongside** (§3.3): uncaught exceptions on stale clicks, close/reopen rewriting every holder's history, serial-number generation that can 500, the "confirm before commenting" rule only enforced in the UI, reviewer lists scoped by region only.
5. **Decided: managers hold letters (Phase 3, §6); the Managing Director does not.** It is mostly a recipient-rule change (eligibility by the existing `letters.view` permission, which all five manager tiers already have, and they already have the Letters module), plus making the hand-over visible to them outside the Letters module, a remark form that knows the holder *is* the reviewer, and a terminal "deliver to addressee" step.
6. **Letter scans are an optional add-on (Phase 5, §7):** scanned PDF/JPG/PNG copies attached to a letter, stored on the **private** disk and served only through an authorised route, behind `GWL_LETTERS_SCANS_ENABLED` (off by default). A scan never replaces the signed hardcopy hand-over.

---

## 1. How the paper process works (as described by the business)

**Regions** — HR secretary (in-charge) receives hardcopies from staff or outside. She records them (from, to, title, date…) and hands them to the **CM secretary**, who signs for them, records them in her own Excel sheet and takes them to the **CM**. The CM comments. Back to the CM secretary, then to the HR secretary, who records the comment. Depending on the comment the letter goes to the addressed staff, or to a new person/office for action, or back to the CM — "either way it goes the same route". Example: staff writes for a laptop → HR sec → CM sec → CM comments to Materials manager → Materials manager comments to ICT for specifications → the Materials department carries the letter **directly** to ICT, ICT writes a comment and Materials picks it back up. Not every hop goes through a secretary.

**Head Office** — every department has a secretary. Anything that leaves a person or an office passes through the departmental secretary: staff → dept sec (records) → manager → dept sec → dept CM → dept sec → (HR sec, for leave) **or** another department's secretary → its manager/CM → back via its secretary… Only **external** letters enter through the HR secretary.

Letters also move **region ↔ Head Office**.

Invariants that hold everywhere: every hand-over is **signed for**; every holder **records** the letter in their own register; the letter ends with the **addressee** receiving the hardcopy.

---

## 2. How the app models it today (verified)

| Paper concept | App today |
|---|---|
| Sec records the letter | `LetterWorkflowService::create()` → `mail_letters` (+ `sn_number` `<REGION_INITIALS>-<YEAR>-<NNN>`) and a first `letter_status_logs` row `Received` for the creator |
| The sec's Excel register | One `letter_status_logs` row **per holder per stay**: `status` (`Received`/`In Review`/`Dispatched`/`Closed`), `out_date` (date only). "Date received" in the UI is that row's `created_at` |
| Hand-over + "sign for it" | `dispatch()` creates a `routing_histories` hop (`received_confirm=false`), a `Received` log for the recipient and one `letter_notifications` row; recipient must click **Confirm Hardcopy** (`confirmHardcopy()`) |
| CM's comment, typed by the secretary | `letter_remarks`: `manager_id` *or* `chief_manager_id` (who commented), `remark_content`, `secretary_remark_content`; author = the secretary |
| Sending on | `dispatch()` — **recipient must have the `secretary` role** (`secretaryQuery()`, org-wide, `limit(30)`) |
| Filing / end of life | Only the **creator** can `close()` / `reopen()` |
| Visibility | A letter is visible only to employees who have a status log for it (`visibleLettersQuery()`); the creator always has one, so the creator can always see "Current Location" |

Gating: `letters.create` (secretary only), `letters.forward`, `letters.remark` are checked in Livewire; route group is only `module:letters` (+ `permission:letters.create` on `/new`).

---

## 3. Review findings

### 3.1 What is right (keep)

- Single record + custody chain + receiver acknowledgement before the receiver can act (service-enforced for dispatch: `canDispatch()` requires no pending incoming hop).
- Holder is always a *desk* (a secretary), managers are recorded as reviewers on remarks — this mirrors "letters leave an office only through its secretary".
- Per-holder history gives each secretary their own register, and the creator a live "where is it now?".
- Audit rows on create / dispatch / confirm / close / reopen / update / add-remark; per-recipient notification bell with sound.

### 3.2 Gaps against the real process

**G1 — Recipients are secretaries only, so the regional flow cannot be represented.** `secretaryQuery()` filters on the `secretary` role. Materials manager, ICT, and the final staff addressee can never hold a letter, so the app cannot show "with ICT" — the secretary has to dispatch to *some* secretary and remember the truth. The seeder hints at a different original intent: `LettersRolePermissionSeeder` gives `manager`, `departmental_manager`, `district_manager`, `chief_manager`, `regional_chief_manager` the `letters.forward` and `letters.remark` permissions — but since they can never be recipients and (as seeded) aren't granted `letters.create`, **they never have a status log and so never see any letter**. Those permissions are dead today unless someone has hand-assigned them the `secretary` role or `letters.create`.

**G2 — No terminal "delivered to addressee" step.** The paper flow ends with the staff member signing for the hardcopy. The app ends with the creator clicking Close; the addressee's acknowledgement is not recorded anywhere.

**G3 — No undo.** If a secretary dispatches to the wrong person, or the hardcopy is lost in transit, the sender cannot recall it (their log is `Dispatched`, `canDispatch()` is false) and the recipient cannot reject it — the only way forward is for the recipient to click "Confirm Hardcopy" for a letter they never received and dispatch it back.

**G4 — Timing and registers.** Nothing records *when* a hop was confirmed or *by whom* (only `routing_histories.updated_at` moves as a side effect); `out_date` is date-only; "Date Received" in the drawer is when the hop was *dispatched* to you (`created_at`), not when you confirmed it. There is no "in transit for N days" view, and `letters.export` is seeded but no export exists — secretaries keep doing Excel because the app cannot give them their register back.

### 3.3 Defects and risks

| # | Sev | Where | What happens | Fix |
|---|---|---|---|---|
| D1 | Med | `ActiveLetters.php` `dispatchLetter` (141-159), `confirmHardcopy` (129-139), `closeLetter`/`reopenLetter`; service throws `RuntimeException` (`LetterWorkflowService` 57-63, 102-104, 116-118, 130-132) | The UI hides the buttons, but a stale tab / double-click / second device reaches the service and the exception is **uncaught** → 500 / Livewire error modal instead of a message | Catch `\RuntimeException` in each action, toast the message; the new bulk actions do this from day one |
| D2 | Med | `close()` (120-123), `reopen()` (135) | `close()` runs `$letter->statusLogs()->update(['status'=>'Closed','is_closed'=>true])` — **every holder's** log, overwriting their `Dispatched` status. `reopen()` flips `is_closed=false` on all logs but leaves `status='Closed'`, and adds one `In Review` log only for the creator. Result: every past holder sees the reopened letter in their **Active** tab with a "Closed" pill. `close()` also works while a hop is still unconfirmed or the letter is on someone else's desk | Make "closed" a **letter-level** state (`mail_letters.closed_at`, `closed_by_id`) and stop mutating other people's logs; block close while any hop is unresolved |
| D3 | Med | `nextSnNumber()` (275-291), `regionPrefix()` (293-301) | (a) Prefix = initials of the region name's words, so one-word region names get one letter (`Accra West` → `AW`, but two regions such as *Ashanti* and *Ahafo* would both be `A`). `sn_number` is **globally unique** while the counter is **per region**, so the second region's first letter of the year hits the unique index → 500 on save. Check your real region list. (b) `orderByDesc('sn_number')` is a **string** sort: once a region passes 999 letters in a year, `…-999` sorts above `…-1000`, so the next number is `1000` again → duplicate → 500. (c) No lock, so two secretaries saving at once can collide | Per region/year counter row locked inside the transaction (or numeric `MAX` + retry on unique violation), and an explicit, admin-editable, unique region prefix |
| D4 | Med | `ActiveLetters::addRemark` (177-244), view line 310 | "Confirm receipt before commenting" is enforced **only by hiding the form** (`confirmPrompt`). `addRemark` checks just `letters.remark` and that the actor has *any* status log, so (i) an unconfirmed recipient can call the action directly, and (ii) a secretary who already dispatched the letter can keep adding remarks | In the service: holder + not closed + no pending incoming hop (default), decide explicitly whether past holders may annotate |
| D5 | Low | `LetterWorkflowService::updateRemark` (190-204) | The only mutating letter action with **no audit row**, contrary to the CLAUDE.md audit rule | `AuditLog::record('update_letter_remark', …)` |
| D6 | Med* | `regionalRoleQuery()` (303-315) | Remark reviewers (manager / chief manager dropdowns) are scoped by `region_id` only (and `whereNull('region_id')` when the actor has none). CLAUDE.md says Head Office is a district whose staff share a `region_id` and must never be scoped by region alone; also a Head Office department secretary gets every manager in HQ, not her own department's manager/CM. Leave already solves this with `location_type` + department. *Verify against your real data.* | Scope by `location_type`, then department (Head Office) / region (regions & districts). `DemoDataSeeder.php` (1578-1579) also calls both query methods — keep the signatures or update it |
| D7 | Low | `active-letters.blade.php` 55-61, 84 | Per row: `currentLog()`, `pendingIncomingRoute()` and `canDispatch()` (which repeats both) → up to 4 queries × 15 rows, although `statusLogs` and `routingHistories` are already eager-loaded. `MailLetter::latestStatusFor()` exists (the dashboard uses it) but this view doesn't | One set-based `deskState()` helper (2 queries for the page); also needed by bulk select |
| D8 | Low | `ActiveLetters::openLetter` (105-115) | Two identical branches (`$fromNotification && pending` and `pending`) | Collapse |
| D9 | Low | seeder vs code | `letters.view`, `letters.close`, `letters.export` are seeded and granted but not checked in any Letters file I read (components, service, routes, sidebar); close is creator-only instead | Either wire them or drop them; `letters.export` becomes live in Phase 4 |

### 3.4 Recipient picker

The picker lists `Name · staff_id` only (view line 355) from an org-wide, alphabetical `limit(30)` — with secretaries at every department and region, names alone are ambiguous. Add department and location to the label and filter by them; add **Return to previous holder** (one click — the most common hop is back to whoever sent it), **recent recipients**, and the creator.

---

## 4. Alternatives considered

**A. Status quo + fixes + batch (person-to-person between secretaries).** Smallest change. Leaves G1/G2 open.

**B. Transmittals (batch hand-over sheets) on top of A — recommended first step.** The registry-standard answer to "sign for it" when volumes are high: one sheet, many letters, one signature. It also gives a natural place for partial receipt, a printable/signable sheet, one notification instead of fifteen, and sender-side tracking. Fully compatible with A and with C.

**C. Desk-based addressing (a `letter_desks` table).** Recipients become *desks* ("CM Secretary — Accra West", "ICT Dept Secretary — HQ", "HR Secretary — Region X") with members (primary + cover). Survives leave and transfers, turns a 30-name picker into a short desk list, allows per-desk stats ("how long does a letter sit at the CM desk?") and "suggested next desk" from the remark's *directed-to* person. Cost: two tables, desk admin UI, holder columns become `desk_id` (+ acting employee), data migration of existing holders. Worth doing **after** B proves out; B's batch `to_secretariat_id` would become `to_desk_id` mechanically.

**D. Static route templates / workflow engine (e.g. leave-style chain resolver).** Rejected as the primary model: here routing is decided by the CM's comment ("to a new staff or back to CM"), not by letter type. Useful only as *suggestions* (a pre-selected recipient), which C enables cheaply.

**E. Optional letter scans** (attach a scanned copy so a manager can read a letter before the hardcopy reaches them, and there is an image of the handwritten comments). Independent of A-D; specified in §7.

**Recommendation:** Phase 0 (tests + defects) → Phase 0b (serial numbers) → Phase 1 (B: batch) → Phase 2 (undo + in-transit) → Phase 3 (**decided:** managers hold letters + deliver-to-addressee; desks (C) decided afterwards) → Phase 4 (register export) → Phase 5 (optional scans, can start any time after Phase 0).

---

## 5. Batch dispatch & batch confirmation — design

### 5.1 Concept

A **transmittal** = one hand-over of *N* letters from one holder to one recipient at one time, with a number (`TR-2026-000123`), a printable sheet and a single notification. Each letter in it is still an ordinary `routing_histories` hop, so every existing rule, status log, timeline, audit row and report keeps working; the transmittal only *groups* hops. Batch confirmation is the receiver acknowledging some or all lines of a transmittal in one action. Confirmation is also offered for **un-batched** pending letters (multi-select on the list), so receiving a pile of individually dispatched letters is fast too.

### 5.2 Screens and flow

**Active Letters (existing page)**
- New first column: checkbox (disabled with a tooltip on rows you can't act on). Header checkbox = all actionable rows on this page. `public array $selected` is cleared on page / tab / filter change and after every action.
- New quick filters that are applied in SQL (so counts and pagination are right): **Awaiting my confirmation**, **Ready to dispatch**.
- Sticky bulk bar when something is selected: `3 selected — [Dispatch selected…] [Confirm hardcopies (2)] [Clear]`. Each button acts on its eligible subset and says so ("1 of 3 is awaiting your confirmation and was skipped").
- **Dispatch selected** opens a drawer: list of the letters (SN, subject, ref), recipient picker (with the §3.4 improvements), optional note ("Morning mail", "CM minutes"), and **Dispatch N letters**. On success: "Transmittal TR-… created" with **Print sheet**.

**Transmittals (new page, `letters.transmittals`, one component, two tabs)**
- **Incoming** — pending hops addressed to me, grouped by transmittal (un-batched ones under "Individual letters"). Each group is a checklist, all pre-ticked, with **Confirm all (12)** / **Confirm ticked (10)**. Unticked lines simply stay pending and remain visible to the sender as "10 of 12 confirmed". Optional: an SN scan/type box that ticks lines (barcode/QR on the sheet; `bacon/bacon-qr-code` is already installed) — not to be confused with the optional *letter scans* of §7.
- **Sent** — transmittals I created: progress (`confirmed_count / letters_count`), per-line status, age of anything still unconfirmed, **Print sheet**. (Recall arrives in Phase 2.)
- Sidebar entry **Transmittals** with a count of pending incoming lines.

**Notifications bell** — one entry per transmittal ("5 letters dispatched to you — TR-…"); clicking opens Transmittals → Incoming focused on that group.

**Sheet (PDF via Dompdf, `new Dompdf($options)` as elsewhere)** — header (TR number, date/time, from, to, note), table (#, SN, Ref No, date on letter, subject, sender, blank "Remarks" cell), signature lines ("Dispatched by / Received by / Date & time"). It is the paper the receiver signs; the digital confirm is still required before remarks, exactly as today.

### 5.3 Data model (additive; three migrations, none edit an existing migration)

```
letter_dispatch_batches                               -- 2026_10_01_000001
  id
  batch_no            string unique, nullable at insert  -- 'TR-'.year.'-'.str_pad(id,6,'0') set in the same tx (race-free, unlike SN)
  from_secretariat_id FK employees  restrictOnDelete
  to_secretariat_id   FK employees  restrictOnDelete
  note                string(500) nullable
  letters_count       unsignedSmallInteger
  confirmed_count     unsignedSmallInteger default 0      -- service-maintained
  dispatched_at       timestamp
  completed_at        timestamp nullable                   -- set when every hop is confirmed/resolved
  timestamps
  index [to_secretariat_id, completed_at], [from_secretariat_id, dispatched_at]

routing_histories  (add columns)                      -- 2026_10_01_000002
  batch_id        FK letter_dispatch_batches nullable nullOnDelete, indexed
  confirmed_at    timestamp nullable
  confirmed_by_id FK employees nullable nullOnDelete
  resolution      string(20) nullable     -- null | 'recalled' | 'rejected'   (used from Phase 2)
  resolved_at     timestamp nullable
  resolution_note string(500) nullable
  -- in the same migration, backfill: confirmed_at = updated_at, confirmed_by_id = to_secretariat_id
  --   WHERE received_confirm = 1 (only the recipient could ever confirm), guarded by hasColumn

letter_notifications  (alter)                         -- 2026_10_01_000003
  letter_id -> nullable (->change())       -- the one delicate migration: test on SQLite and on the production DB engine
  batch_id  FK letter_dispatch_batches nullable nullOnDelete
```

Two columns this design needs are deliberately **not** in these three migrations: `letter_status_logs.routing_history_id` and `routing_histories.reminded_at` arrive with Phase 2 (§8.2).

`received_confirm` stays (every current query and the timeline use it). Status is **derived** (open = `completed_at IS NULL`; partial = `0 < confirmed_count < letters_count`) rather than stored, so it cannot drift. No new enum values on `letter_status_logs` (SQLite enums are CHECK constraints — avoid touching them).

### 5.4 Service API (`app/Services/Letters/`)

Keep the existing class; extract and share, don't duplicate.

- Extract the body of `dispatch()`'s transaction into `recordHop(MailLetter, Employee $from, Employee $to, ?LetterDispatchBatch $batch = null, bool $notify = true)`. `dispatch()` calls it with no batch (unchanged behaviour, still one notification per letter).
- `dispatchBatch(Employee $from, Employee $to, array $letterIds, ?string $note = null): LetterDispatchBatch`
  - Checks: 1 ≤ count ≤ `config('gwl.letters_max_batch_size')` (default 50 — one printed page); `$from !== $to`; recipient is an eligible active recipient (same rule as single dispatch; re-checked inside the service, not only in the picker).
  - Inside `DB::transaction`: re-read the letters `lockForUpdate()` **through `visibleLettersQuery($from)`** (never `find($clientId)`), verify `canDispatch()` for **every** letter, then create the batch, call `recordHop()` per letter, create **one** `LetterNotification` (`batch_id`, `letter_id` null), write audit. Follows the `LeaveWorkflowService` re-read-and-authorise-on-the-locked-copy pattern from CLAUDE.md.
  - **All-or-nothing.** If any letter is no longer dispatchable, nothing is created and the exception lists the SNs, so the printed sheet can never disagree with what was handed over. The UI then refreshes the selection.
- `confirmHardcopies(Employee $actor, array $letterIds, ?LetterDispatchBatch $batch = null): int` — the only confirm code path. The existing `confirmHardcopy()` becomes a one-line wrapper. Locks the actor's open, unresolved incoming hops, sets `received_confirm`, `confirmed_at`, `confirmed_by_id`, calls `markInReview`, bumps `confirmed_count`, sets `completed_at` when all lines are accounted for, and audits **per letter** (`confirm_letter_hardcopy`, `metadata.batch_id`). Throws the existing "No pending hardcopy receipt confirmation was found." if nothing qualified.
- `deskState(Collection $letters, Employee $actor): Collection` — `{currentLog, pendingRoute, canDispatch}` per letter in two queries; used by the list (fixes D7) and by bulk eligibility.
- Phase 2 (§8.2): `recall(RoutingHistory, Employee $sender, ?string $note)` and `reject(RoutingHistory, Employee $recipient, string $reason)`. Both only while the hop is unconfirmed: set `resolution`/`resolved_at`, **delete the recipient's never-held `Received` log** created by that hop (found through the new `letter_status_logs.routing_history_id`), restore the sender's log to `Received` and clear `out_date`, resolve the recipient's notification, update batch counters, audit. (Deleting an unheld log avoids a new enum value; the hop row keeps the history.)

### 5.5 Authorization, audit, notifications

- Dispatch needs `letters.forward` (same as single) — checked in the Livewire action **and** holder-state-checked in the service. Confirm needs no permission (as today) but the service only touches hops where `to_secretariat_id = actor`. The sheet PDF is available to the sender, the recipient and `super_admin` only. Route `module:letters` + `enforceLivewireModule('letters')`, per the layered pattern.
- Audit: one `dispatch_letter` row per letter (`metadata.batch_id`, `batch_no`) — so "who moved letter X" still answers from a single table — plus one `dispatch_letter_batch` row on the batch. `AuditLog::record()` already takes a `metadata` argument; the Letters code just doesn't use it yet.
- Notifications: one per transmittal; `Notifications::openNotification` (69-92) redirects to Transmittals when `batch_id` is set.
- Config in `config/gwl.php` (not `gwcl.php`): `letters_max_batch_size` (env `GWL_LETTERS_MAX_BATCH_SIZE`, 50); Phase 2 adds `letters_unconfirmed_alert_days` (2) and `letters_remind_cooldown_hours` (24); Phase 4 adds `letters_register_max_rows` (5000).

### 5.6 Edge cases

- **Stale selection** — a letter moved/closed since the page rendered → whole dispatch refused with the SN list; nothing half-done.
- **Double submit** — button disabled while loading; a second call fails `canDispatch()` and shows a friendly message (D1 fix).
- **Recipient deactivated** between pick and submit → rejected inside the service.
- **Partial receipt** — unticked lines stay pending; `confirmed_count < letters_count`; they show as aged items on the sender's **Sent** tab; before Phase 2 the only remedy is the receiver confirming later, afterwards recall/reject.
- **Mixed letters** (internal/external, different regions) are fine in one sheet; the letter's region is its origin, not its location.
- **Legacy hops** (`batch_id` null, pre-migration) show under "Individual letters"; backfilled `confirmed_at` keeps their timing usable.
- **Close while in transit** — block `close()` while the letter has an unresolved hop (part of D2).
- **Cross-page selection** is deliberately not supported (page-size 15; use the quick filters to narrow and "select all on page").

### 5.7 Tests (plain PHPUnit 13, `RefreshDatabase`, `Livewire::test()`)

Add `tests/Feature/Letters/Concerns/BuildsLettersOrg.php` (inline builders like `tests/Feature/Leave/Concerns/BuildsLeaveOrg.php`), then:

- `LetterWorkflowCharacterizationTest` (Phase 0): create → dispatch → unconfirmed recipient can't dispatch → confirm → remark → close/reopen; audit rows written.
- `BatchDispatchTest`: creates N hops + N recipient logs + 1 notification + 1 batch with `letters_count`; sender logs become `Dispatched`; one letter not dispatchable → nothing created; over max size; `from === to`; inactive recipient; non-`letters.forward` user; letter not visible to actor is rejected (not leaked).
- `BatchConfirmationTest`: confirm all; confirm a subset → `confirmed_count`, `completed_at` null; confirm the remainder → `completed_at` set; cannot confirm someone else's hops; wrapper `confirmHardcopy()` still passes the old single-letter behaviour; per-letter audit rows carry `batch_id`; un-batched bulk confirm from the list.
- `TransmittalPageTest`: incoming grouping, sent progress, sheet PDF access (sender/recipient/super_admin yes, others 403).
- `ActiveLettersBulkSelectionTest`: selection cleared on filter/tab change; mixed selection acts on the right subset and reports the skipped count.
- Phase 2: `RecallRejectTest` (log restored, recipient log deleted, counters, cannot recall a confirmed hop).

---

## 6. Phase 3 in detail — managers hold letters (decided 2026-09-30)

**Decision:** managers and chief managers hold letters in the app themselves. Secretaries remain the default holders, and both modes coexist on the same letter, so an office whose managers don't use the portal keeps working secretary-to-secretary. Transmittals (Phase 1) carry over unchanged — a manager can be the `to` of a batch.

**What stops it today, and the change (verified):**

| Blocker | Where | Change |
|---|---|---|
| Recipients must have the `secretary` role | `LetterWorkflowService::secretaryQuery()` (241-256); `ActiveLetters::dispatchLetter` does `secretaryQuery()->findOrFail()` (152); the picker lists `secretaries` (426) | New `recipientsQuery()`: active, `visibleInErp()` employees whose user (by `employee_id` or `staff_id`) has a role holding **`letters.view`**. Eligibility by permission, not a hard-coded role list, so admins can extend it from the role editor. Already true for `secretary`, `manager`, `departmental_manager`, `district_manager`, `chief_manager`, `regional_chief_manager` (`LettersRolePermissionSeeder`). Re-checked **inside** `dispatch()` / `dispatchBatch()`, not only in the picker |
| Managers never get a status log, so the Letters tab is empty for them | — | Nothing to change for access: `ModuleAccessSeeder` (36-40) already gives all five manager tiers the `letters` module. Once they can be recipients, logs, list, drawer and confirm work unchanged |
| **Managers would not see the hand-over.** The letters bell renders only inside the Letters module (`layouts/erp.blade.php` 148-150: `($module ?? null) === 'letters'`), and the general bell deliberately excludes `data->module = 'letters'` (`GeneralBell.php` 61, 80, 105, 135). Managers spend their time in Leave | layout + navigation | Render `<livewire:letters.notifications />` on **every** ERP page for users with Letters module access (keep the general bell as is); add a pending-incoming badge to the **Letters** top-nav tab in `ErpNavigation::moduleDefinitions()`; optional tile on the home summary |
| The remark form assumes the holder is a secretary typing someone else's comment | `ActiveLetters::addRemark`; blade 310-338 | When the holder has a manager tier, pre-select and lock themselves as the reviewer (`manager`, `departmental_manager`, `district_manager` → Manager field; `chief_manager`, `regional_chief_manager` → Chief Manager field) and hide "Secretary remarks" for non-secretaries; the service rejects a reviewer that doesn't match the acting manager. A secretary holder keeps today's form |
| Reviewer lists are scoped by region only (D6) | `regionalRoleQuery()` | Do D6 first or together: a manager's dropdown has to resolve correctly for Head Office and for regions |

**Recipient picker:** grouped *Secretaries* / *Managers*; label `Name · Department · Location`; default filter "my location" with "Head Office" and "Any" chips; **Return to previous holder** and recent recipients pinned on top; search hits name, staff ID and department.

**Terminal step — Deliver to addressee (closes G2).** New table `letter_deliveries`: `letter_id` (FK cascade), `delivered_to_employee_id` (nullable FK employees, `nullOnDelete`), `delivered_to_name` (string, nullable — outside parties), `delivered_by_id` (FK employees, `restrictOnDelete`), `delivered_at`, `note` (string 500, nullable), timestamps. The **current, confirmed holder** (not only the creator) records who took the hardcopy and when; the addressee needs no login — the holder records the paper signature. It closes the letter at letter level using Phase 0's `closed_at` / `closed_by_id` (closer = the deliverer; manual close/reopen stays creator-only). One of the two addressee fields is required, enforced in the service. Audit `deliver_letter`.

**Managing Director does not hold letters (decided 2026-09-30).** `managing_director` has only the Leave module today (`ModuleAccessSeeder` 41) and stays that way: no Letters module, no `letters.*` permission, so `recipientsQuery()` can never return the MD. Letters for the MD are held by the MD's office secretary (an ordinary `secretary` desk) and **Deliver to addressee** records the paper hand-over to the MD; the MD's written comment is recorded by that secretary. The Phase 3 prompt carries a guard test so a later seeder change cannot grant it by accident. Consequence to decide: the remark form only offers *Manager* / *Chief Manager* reviewers, so the MD's comment has no reviewer slot — see §10 item 1.

**Not included:** ordinary `employee`-role staff holding letters (they'd need the Letters module and `letters.view`).

**Tests:** a manager can be dispatched to and confirm; an employee without `letters.view` cannot be chosen even with a crafted id; the remark form pre-selects the acting manager and the service rejects a mismatched reviewer; the bell renders outside the Letters module for users with access and not for users without; deliver-to-addressee by a non-creator holder closes the letter, by a non-holder is refused, and requires one addressee field; `DemoDataSeeder` still runs (it calls `regionalManagersQuery` / `regionalChiefManagersQuery`, 1578-1579).

---

## 7. Optional: letter scans (Phase 5, behind a flag)

**Assumption:** "letter scan" = an optional scanned copy of the hardcopy attached to the letter record (PDF, JPG, PNG). It is not a barcode reader (the SN/QR scan box in §5.2 is a separate, optional item). Off by default.

**Why:** a manager can read a region → Head Office letter while the hardcopy is still in the courier's bag; a scan taken after the CM writes on the letter preserves the handwritten comment next to the typed remark; there is an image to point to if a hardcopy is lost. **What it never does:** replace custody — the signed hand-over and the confirm-before-acting rule stay exactly as they are.

**Where it fits**
- At recording: an optional file picker on **New Letter** (files attach after the letter is saved).
- Any time later: a **Scans** tab in the letter drawer, for the current confirmed holder (same holder rule as remarks, D4). Typical use: the secretary scans the letter after the CM has commented and before it moves on (kind `commented`).
- Capture paths: (1) the office scanner's scan-to-PDF / scan-to-folder, then pick the file — the primary path; (2) a phone camera through `<input type="file" accept="image/*,application/pdf" capture="environment" multiple>`; (3) not included: browser-driven scanner control (TWAIN/WIA).

**Storage and access — deliberately different from Transport.** Transport stores photos on the **public** disk (`TransportService` 63, 183, 209, 228). Letters include leave requests, discipline and medical matters, so scans must go on the **private** disk: the default disk here is `local` = `storage/app/private` (`config/filesystems.php`), which is also where the credit-union deduction uploads go (`Deductions.php` 113). Set a dedicated `gwl.letters_scan_disk` (default `local`) and a path `letters/scans/{yyyy}/{mm}/{letter_id}/{uuid}.{ext}` — never the client's filename. Files are served only through `GET /letters/scans/{scan}` (`letters.scans.show`, inside the `module:letters` group): the controller resolves the scan **through `visibleLettersQuery($actor)`**, then streams with `Storage::disk(...)->response(...)`, `Content-Disposition: inline`, `X-Content-Type-Options: nosniff`. No public URL exists. **Backups must include `storage/app/private/letters`** — a database dump alone will not contain the scans.

**Data model** (one migration, `Schema::hasTable` guarded):

```
letter_scans
  id
  letter_id       FK mail_letters cascadeOnDelete
  kind            string(20) default 'original'   -- original | commented | enclosure
  disk            string(20)
  path            string
  original_name   string
  mime            string(100)
  size_bytes      unsignedInteger
  sha256          char(64), indexed               -- integrity + "already attached" check
  note            string(255) nullable
  uploaded_by_id  FK employees restrictOnDelete
  voided_at       timestamp nullable
  voided_by_id    FK employees nullable nullOnDelete
  void_reason     string(255) nullable
  timestamps
  index [letter_id, created_at]
```

Nothing is hard-deleted in the app: voiding hides a scan and keeps the file (audit trail); removal would be a separate retention job, out of scope.

**Service** — `app/Services/Letters/LetterScanService` (one concern, not more on the workflow class):
- `add(MailLetter, Employee $actor, UploadedFile|TemporaryUploadedFile $file, string $kind = 'original', ?string $note = null)` — requires the flag on; the actor is the creator at intake or the current confirmed holder (the D4 holder rule); file count under the cap; `sha256` not already on this letter; stores on the private disk; audit `add_letter_scan` (`new_values`: scan id, kind, name, size, sha256).
- `void(LetterScan, Employee $actor, string $reason)` — the uploader while still the holder, the letter's creator, or `super_admin`; audit `void_letter_scan`.
- Livewire uses `WithFileUploads` + `TemporaryUploadedFile` exactly like `Transport/Issues.php`, with rules `['file', 'mimes:pdf,jpg,jpeg,png', 'max:'.config('gwl.letters_scan_max_kb')]` (per file via `scans.*`). Laravel's `mimes` rule checks the file content, not just the extension.

**Config** (`config/gwl.php`, plus `.env.example` entries like the existing `GWL_*` ones): `letters_scans_enabled` (env `GWL_LETTERS_SCANS_ENABLED`, **false**), `letters_scan_disk` (`local`), `letters_scan_max_kb` (10240), `letters_scan_max_files` (10 per letter), `letters_scan_preview_before_confirm` (false). With the flag off the tab and file picker are hidden, the service refuses, and the route returns 404.

**Limits to check at build time:** this repo has no `config/livewire.php`, so Livewire's own temporary-upload default (about 12 MB) applies on top of PHP's `upload_max_filesize` / `post_max_size` on the production server; keep `letters_scan_max_kb` at or below the smallest of them.

**Viewing rules (defaults):** anyone who can see the letter may open its non-voided scans **after the hop is confirmed**, matching today's rule that details stay hidden until receipt is confirmed. `letters_scan_preview_before_confirm = true` lets a recipient read the scan while the hardcopy is in transit (remarks still wait for confirmation) — the real gain for letters that take days to arrive.

**Audit:** add and void always; views are not audited by default (decision for confidential letters, §10).

**Touchpoints in other phases:** the transmittal sheet gets a "Scan" column (✓ / –) and the Incoming checklist a "View scan" link (subject to the preview switch); the Letters list shows a paperclip count; the Phase 4 register export gets a "Scanned" column; `MailLetter::scans()` relation.

**Not included:** OCR or auto-filling subject/sender from a scan; virus scanning (none in this stack — uploads are content-type checked, stored outside the web root and never executed; a ClamAV hook can come later); stripping EXIF location data from phone photos (it stays unless images are re-encoded — a decision, §10); thumbnails.

**Tests** (`tests/Feature/Letters/LetterScanTest.php`, `Storage::fake('local')`): add by creator at intake and by the current confirmed holder; refused for an unconfirmed recipient, a past holder and with the flag off; wrong mime and over-size rejected; same `sha256` on the same letter refused; the file lands on the private disk and **not** on `public`; the show route returns 200 with the right headers for the creator / a holder, 403/404 for anyone who cannot see the letter; voiding hides the scan but keeps the file; audit rows written; pre-confirm preview only when the switch is on.

---

## 8. Phased delivery

**Overview** — every phase has a ready-to-paste prompt in §9. Each prompt after Phase 1 starts by telling Claude Code to read what the earlier phases actually built (`git log` plus the real files), because the code on disk, not this document, is the truth once a phase has run.

| Phase | What it delivers | Needs | State |
|---|---|---|---|
| **0** | Safety net + defects: characterization tests, D1, D2 (closed at letter level), D4, D5, D7, D8 | — | Prompt pasted into Claude Code 2026-09-30 |
| **0b** | Serial numbers (D3): prefix per region, locked counter, numeric sequence — §8.1 | Phase 0 test builders | Prompt in §9, not yet run. A latent 500 — run it soon |
| **1** | Batch core: transmittals, multi-select, bulk confirm, sheet PDF — §5 | 0 | Prompt pasted into Claude Code 2026-09-30 |
| **2** | Exceptions & aging: recall, reject, remind, "unconfirmed > N days" — §8.2 | 1 | Prompt in §9 |
| **3** | **Managers hold letters** + Deliver to addressee + D6 reviewer scoping (Managing Director excluded) — §6 | 0-2 | Prompt in §9 |
| **4** | Register export: Excel/PDF "my register", wires `letters.export` — §8.3 | 1-2 | Prompt in §9 |
| **5** | **Letter scans** (optional, flag off by default) — §7 | 0 | Prompt in §9 |

**Order.** 0 → 0b (any time after 0; it touches `create()`, not dispatch, so it does not collide with Phase 1) → 1 → 2 → then 3, 4 and 5 in any order: 3 is the priority, 4 is what lets secretaries retire their Excel sheets, 5 can start right after Phase 0 and ships dark. Desks (Alternative C) are decided after Phase 3. D6 (reviewer scoping) is done first inside Phase 3.

### 8.1 Phase 0b — serial numbers (D3)

- **Prefix.** `regions.letter_prefix` (string 10, nullable, **unique**, `hasColumn` guarded). The migration backfills every region with the prefix today's initials rule produces, de-duplicating clashes deterministically (order by id; append the id on a clash). Already-issued `sn_number`s are never rewritten. Editable in the Regions manager (`staff.manage_regions`, the existing `create_region` / `update_region` audit rows); a region created without one gets a derived, de-duplicated prefix the first time a letter needs it (covers seeders and fresh installs). Validation: uppercase letters/digits, 2-6 characters, unique. Changing a prefix affects future letters only.
- **Counter.** New table `letter_sn_counters` (`prefix` string 10, `year` unsigned small int, `last_number` unsigned int default 0, timestamps, **unique [prefix, year]**), seeded by the migration from existing letters by parsing the numeric suffix of `sn_number` in PHP (no SQL string functions, so it behaves the same on SQLite and the production engine). Keyed on the *prefix*, not the region, so even if two regions ever shared a prefix they share a counter and cannot collide.
- **Allocation.** In `create()`'s existing transaction: `lockForUpdate` the `(prefix, year)` row; if it does not exist create it (seeded from the highest existing suffix) with one retry on a unique-violation; increment; format `PREFIX-YYYY-NNN` with `str_pad(n, 3, '0')` so 1000 follows 999 — numeric, never string-sorted. `lockForUpdate` is a no-op on SQLite (single writer), so in tests the unique index is the guard; on a server database the row lock serialises two secretaries saving at once.
- **Letters with no region** keep today's fallback prefix, with its own counter.
- **Tests** (`tests/Feature/Letters/SerialNumberTest.php`): two one-word regions (*Ashanti*, *Ahafo*) get different backfilled prefixes and both can issue their first letter of the year; legacy letters (`AW-2026-005`) → the next one is `006`; the sequence continues 999 → 1000 → 1001; creating letters in a loop never duplicates; a duplicate or malformed prefix is rejected in the Regions manager; changing a prefix starts the new prefix's own counter and leaves old numbers untouched; a region with a null prefix is given one on first use.

### 8.2 Phase 2 — exceptions & aging

**Schema** (one migration, guarded): `letter_status_logs.routing_history_id` (FK `routing_histories`, nullable, `nullOnDelete`, indexed) — `recordHop()` sets it on the recipient's log from now on, which is what lets recall delete exactly the log that hop created; `routing_histories.reminded_at` (timestamp, nullable). The migration backfills `routing_history_id` **only for currently unconfirmed hops** (the only ones that can be recalled) by matching the recipient's most recent `Received` log on that letter created at or after the hop. Config: `letters_unconfirmed_alert_days` (env `GWL_LETTERS_UNCONFIRMED_ALERT_DAYS`, **2**), `letters_remind_cooldown_hours` (env `GWL_LETTERS_REMIND_COOLDOWN_HOURS`, **24**).

**Service** (`LetterWorkflowService`, same transaction + `lockForUpdate` re-read pattern):
- `recall(RoutingHistory $hop, Employee $sender, ?string $note = null)` — the sender of the hop only, hop still unconfirmed and unresolved. Sets `resolution='recalled'`, `resolved_at`, `resolution_note`; deletes the recipient's never-held `Received` log; restores the sender's log to `Received` and clears `out_date` (check `canDispatch()` is true again afterwards); resolves the recipient's notification; updates the batch (`completed_at` once every line is confirmed **or** resolved); audit `recall_letter` per letter (`metadata.batch_id`, note). `recallBatch(LetterDispatchBatch, Employee)` recalls every still-unconfirmed line in one all-or-nothing transaction ("the whole bag went to the wrong desk").
- `reject(RoutingHistory $hop, Employee $recipient, string $reason)` — the recipient only, same guards, reason required (min 5 characters). Same effects with `resolution='rejected'`, **plus** a notification to the sender (one per action). `rejectLines(Employee, array $hopIds, string $reason)` for "Reject ticked" on the Incoming checklist. Audit `reject_letter`.
- `remind(Employee $sender, RoutingHistory|LetterDispatchBatch $target)` — unconfirmed hops of that sender only; sets `reminded_at`; new notification to the recipient; refused inside the cooldown; audit `remind_letter_recipient`.
- **A recalled or rejected hop must never look pending.** Every place that treats `received_confirm = 0` as "awaiting confirmation" has to exclude resolved hops (`resolution IS NULL`): `pendingIncomingRoute()`, `deskState()`, the Incoming tab, the sidebar badge, the dashboard counts, `Notifications`, and the close-while-in-transit guard. Grep `received_confirm` across `app/` and `resources/` and add one `unresolved()` scope on `RoutingHistory` instead of repeating the condition.
- Not recallable: a **confirmed** hop (custody has changed — the recipient must dispatch it back). Race (recipient confirms while the sender recalls): whoever takes the lock first wins, the other gets the friendly message from the D1 fix.

**Screens.** *Sent tab*: per-line **Recall**, **Recall unconfirmed lines** per transmittal, **Remind** (disabled inside the cooldown with "reminded 3 h ago"), an age pill on unconfirmed lines (amber after the alert days, red at twice that) and a quick filter **Overdue**. *Incoming tab*: "waiting 3 days" on each line and **Reject ticked…** with a mandatory reason. *Active Letters*: a sender's Dispatched row shows "awaiting confirmation by X · 3 d" with a Recall link for single hops. *Letter drawer timeline*: recalled/rejected hops are shown with who, when and the note. *Letters dashboard* (`dashboard.blade.php`, `x-ui.stat-tile`): tile **Unconfirmed > N days** (my sent hops older than the alert days, `tone` warning, `:href` to Sent → Overdue).

**Authorization.** No new permission: recall/remind need `letters.forward` (same as dispatching) plus being the sender; reject, like confirm, needs only being the recipient. Livewire `enforceLivewireModule('letters')` and service-level actor checks.

**Tests** (`RecallRejectTest`, `LetterAgingTest`): recall restores the sender's desk (status, `out_date`, `canDispatch()` true) and removes the recipient's log; hop resolution fields set; batch counters and `completed_at`; recalled/rejected hops absent from Incoming, badge and counts; `close()` allowed again; cannot recall a confirmed hop or someone else's hop; reject needs a reason and notifies the sender; legacy unconfirmed hop handled through the backfill; partial batch (recall only the unconfirmed lines); remind throttled by the cooldown and notifies the recipient; dashboard tile count using `Carbon::setTestNow`; audit rows written.

### 8.3 Phase 4 — register export

**Purpose.** Give each holder their own register back from the app, so the parallel Excel sheet can be retired. One register row = one `letter_status_logs` row of that holder (one stay).

**One query, three outputs.** `app/Services/Letters/LetterRegisterService::rows(Employee $holder, CarbonInterface $from, CarbonInterface $to, string $scope = 'all'): Collection` feeds the Livewire preview, the Excel export and the PDF, so they can never disagree. It loads the letters' hops in **one** query and matches them in PHP (no per-row queries).

**Columns** (map to the real `MailLetter` column names): No. · Date received (the incoming hop's `confirmed_at` via the log's `routing_history_id`; for the creator's own intake, the recording date) · SN · Ref no. · Type · Date on letter · Sender · Received from · Subject · Date out · Sent to · Transmittal no. · Status (*With me* / *Dispatched* / *Closed*, and *Delivered to …* if Phase 3 shipped) · Remarks I recorded (joined; truncated to about 120 characters in the PDF). A *Scanned* column only if Phase 5 shipped. Hops that are unconfirmed, recalled or rejected are **not** register entries — they were never received.

**Filters.** Date range on *date received* (default: current month); scope chips *All / Still with me / Dispatched / Closed*. Row cap `letters_register_max_rows` (env `GWL_LETTERS_REGISTER_MAX_ROWS`, **5000**): over it, ask the user to narrow the range.

**Screens and routes** (existing letters group, `module:letters`): page `letters.register` ("My register", sidebar entry visible with `letters.export`) with the filters, a paginated preview (25 rows) and **Export Excel / Export PDF** links carrying the current filters; `letters.register.excel` and `letters.register.pdf` with `->middleware('permission:letters.export')`, following `VisitorExportController`. Own register only; an `employee_id` parameter is honoured for `super_admin` and answered with 403 for everyone else.

**Implementation pattern (copied from Visitors / Leave).** Controller `app/Http/Controllers/Letters/LetterRegisterExportController`; Excel class `app/Exports/Letters/LetterRegisterExport` (`FromCollection`, `ShouldAutoSize`, `WithHeadings`, like `ApprovedLeavesExport`); PDF through `new Dompdf($options)` with `DejaVu Sans`, `isRemoteEnabled=false`, A4 landscape, view `resources/views/letters/exports/register-pdf.blade.php` (holder, office, period, generated time, row count, "Prepared by / Checked by" lines), `Content-Disposition: attachment`. Copy the small date helpers into the new controller; do **not** refactor the Visitors controller in this phase. **Spreadsheet-formula injection:** subjects, senders and remarks are user text, and PhpSpreadsheet's default value binder turns a cell beginning with `=` into a formula — add `WithCustomValueBinder` with a string binder (or neutralise leading `= + - @`) and prove it with a test that reads the generated `.xlsx`.

**Audit and permissions.** `AuditLog::record('export_letter_register_excel' | 'export_letter_register_pdf', 'letters', 'letter_status_logs', null, null, [employee_id, from, to, scope, rows])`. `letters.export` is seeded for `secretary` only; managers who hold letters in Phase 3 get it from the role editor if wanted — do not change the seeds here.

**Tests** (`LetterRegisterTest`): rows are only the holder's own; the date range applies to date received; a dispatched row shows the recipient, date out and transmittal no.; unconfirmed/recalled hops excluded; scope chips; 403 without `letters.export`; `employee_id` allowed for `super_admin` only; Excel download (`Excel::fake()`) and a PDF that starts with `%PDF` with the right headers; formula-injection test (subject `=1+1` stays text); row cap; audit rows written.

---

## 9. Kickoff prompts (paste into Claude Code)

### Phase 0

```
Phase 0 of the Letters module work, per letters-module-review-and-batch-design.md
(§3.3, §5.7) and CLAUDE.md. Read app/Services/Letters/LetterWorkflowService.php,
app/Livewire/Letters/ActiveLetters.php and resources/views/livewire/letters/
active-letters.blade.php first.

1. Before changing anything, add tests/Feature/Letters/Concerns/BuildsLettersOrg.php
   (inline builders, modelled on tests/Feature/Leave/Concerns/BuildsLeaveOrg.php) and
   tests/Feature/Letters/LetterWorkflowCharacterizationTest.php pinning today's single
   letter flow (create, dispatch, unconfirmed recipient blocked, confirm, remark,
   close/reopen, audit rows). They must pass against the unmodified code.
2. D1: catch \RuntimeException in ActiveLetters::dispatchLetter, confirmHardcopy,
   closeLetter, reopenLetter and updateLetter and show the message with the existing
   toast event instead of letting it 500.
3. D2: add a migration (hasTable/hasColumn guarded) adding closed_at and closed_by_id
   (nullable FK employees, nullOnDelete) to mail_letters, backfilling closed_at from
   letters whose status logs are closed. Make close()/reopen() set/clear those and stop
   rewriting other holders' letter_status_logs; drive the Active/Closed tabs from the
   letter-level flag; refuse close() while the letter has an unconfirmed hop. Update
   Dashboard counts accordingly.
4. D4: in the service, addRemark()/updateRemark() require that the actor currently holds
   the letter (latest log Received/In Review, not closed) and has no pending incoming
   hop; throw RuntimeException otherwise (caught per item 2).
5. D5: AuditLog::record('update_letter_remark', 'letters', 'mail_letters', ...).
6. D7: add LetterWorkflowService::deskState(Collection $letters, Employee $actor) that
   resolves currentLog / pendingRoute / canDispatch for a whole page in two queries and
   use it in active-letters.blade.php instead of the per-row calls. D8: collapse the
   duplicate branch in openLetter().
7. Update tests for every behaviour change; run php artisan test.
Do not start batch dispatch yet. Do not touch serial-number generation or reviewer
scoping in this phase.
```

### Phase 0b — serial numbers (D3)

```
Phase 0b of the Letters module work — serial numbers (defect D3) — per
letters-module-review-and-batch-design.md §3.3 (D3) and §8.1, and CLAUDE.md. Independent of
Phases 1-5; it needs only the Phase 0 test builders. Earlier phases were implemented in
earlier Claude Code runs, so the code on disk is the truth: run git log --oneline -20, then
read LetterWorkflowService (create(), nextSnNumber(), regionPrefix()), app/Models/Region.php,
app/Livewire/Staff/RegionsManager.php and its blade view, tests/Feature/Letters/Concerns/
BuildsLettersOrg.php, and grep for every other use of sn_number (orderBy, LIKE, exports,
views). Where this prompt and the code disagree, follow the code and tell me.

- Migration A (hasTable/hasColumn guarded, never edit an existing migration):
  regions.letter_prefix string(10) nullable + unique index. Backfill each region with the
  prefix today's initials rule produces, de-duplicating clashes deterministically (order by
  id; on a clash append the region id). Never rewrite an already-issued sn_number.
- Migration B: letter_sn_counters (id, prefix string(10), year unsignedSmallInteger,
  last_number unsignedInteger default 0, timestamps, unique [prefix, year]). Seed one row per
  prefix/year from the existing letters by parsing the numeric suffix of sn_number in PHP —
  no SQL string functions, it must behave the same on SQLite and the production engine.
- Service: regionPrefix() returns region->letter_prefix; if null (seeders, fresh installs,
  any path that creates a region) derive it with the existing rule, de-duplicate, persist it
  and return it. Keep today's fallback for a letter with no region, with its own counter.
  nextSnNumber() runs inside create()'s existing transaction: lockForUpdate the counter row
  for (prefix, year); create it (seeded from the highest existing suffix) if missing, with one
  retry on a unique-violation; increment; return PREFIX-YYYY-NNN using str_pad(n, 3, '0') so
  1000 follows 999. No string sort on sn_number anywhere.
- RegionsManager (+ blade): show letter_prefix, let staff.manage_regions users set it on
  create (blank = derived) and on edit; validate uppercase letters/digits, 2-6 chars,
  unique:regions,letter_prefix; audit through the existing create_region / update_region
  records. Add helper text: changing a prefix only affects future letters. Add letter_prefix
  to Region's fillable.
- Tests in tests/Feature/Letters/SerialNumberTest.php per §8.1; run php artisan test; update
  docs/07-module-letters.md and the Staff module doc (find it under docs/).
Do not touch dispatch, batches, reviewer scoping or anything else in this phase.
```

### Phase 1

```
Phase 1 of the Letters module work — batch dispatch and batch hardcopy confirmation
(transmittals) — per letters-module-review-and-batch-design.md §5 and CLAUDE.md.
Assumes Phase 0 has shipped. Read LetterWorkflowService, ActiveLetters (PHP + blade),
Notifications.php, the letters routes in routes/web.php, lettersSidebar() in
app/Support/ErpNavigation.php, and LettersRolePermissionSeeder first.

- Three new migrations (hasTable/hasColumn guarded, explicit FK delete behaviour, never
  edit existing migrations): letter_dispatch_batches; routing_histories += batch_id,
  confirmed_at, confirmed_by_id, resolution, resolved_at, resolution_note (+ backfill
  confirmed_at/confirmed_by_id for rows with received_confirm = 1);
  letter_notifications.letter_id nullable + batch_id. Verify the nullable change works on
  SQLite (tests) and note any production-engine caveat.
- Flat models app/Models/LetterDispatchBatch.php (+ relations; batch_no assigned from the
  id inside the creating transaction); add the new fillable/casts/relations to
  RoutingHistory and LetterNotification.
- LetterWorkflowService: extract recordHop(), keep dispatch() behaviour identical; add
  dispatchBatch() (all-or-nothing, lockForUpdate re-read through visibleLettersQuery,
  max size from config('gwl.letters_max_batch_size', 50), one notification, per-letter
  audit with metadata batch_id + one dispatch_letter_batch audit) and confirmHardcopies()
  (single code path; confirmHardcopy() becomes a wrapper). Never resolve letter ids from
  client input without going through visibleLettersQuery / the actor's own hops.
- config/gwl.php: letters_max_batch_size (env GWL_LETTERS_MAX_BATCH_SIZE, 50).
- ActiveLetters: $selected array, checkbox column, header select-all-on-page, quick
  filters "Awaiting my confirmation" / "Ready to dispatch" applied in SQL, sticky bulk bar,
  Dispatch-selected drawer (recipient picker + note), Confirm-selected; clear selection on
  page/tab/filter change and after actions; catch RuntimeException and show the message.
- New Livewire component app/Livewire/Letters/Transmittals.php + view with Incoming (grouped
  by batch, pre-ticked checklist, Confirm all / Confirm ticked) and Sent (progress,
  per-line status); routes letters.transmittals and letters.transmittals.sheet in the
  existing letters group; sheet is a Dompdf controller (new Dompdf($options)) limited to
  sender, recipient and super_admin; sidebar entry "Transmittals" with pending-incoming
  count; Notifications::openNotification redirects batch notifications to Transmittals.
- Use enforceLivewireModule('letters') and the letters.forward check on dispatch;
  do NOT add a new permission.
- Tests per §5.7 (BatchDispatchTest, BatchConfirmationTest, TransmittalPageTest,
  ActiveLettersBulkSelectionTest), Livewire::test() + RefreshDatabase using the Phase 0
  builders.
- Update docs/07-module-letters.md and docs/09-data-model.md.
Do not build recall/reject, aging, recipient widening, desks or exports in this phase.
```

### Phase 2 — exceptions & aging (recall, reject, remind)

```
Phase 2 of the Letters module work — recall, reject, remind and "unconfirmed > N days" —
per letters-module-review-and-batch-design.md §5.4 and §8.2, and CLAUDE.md. Assumes Phases 0
and 1 have shipped. Those were implemented in earlier Claude Code runs, so the code on disk
is the truth, not the design doc: run git log --oneline -30 and read what they actually
added (recordHop(), dispatchBatch(), confirmHardcopies(), deskState(), closed_at/closed_by_id,
the batch tables, Livewire/Letters/Transmittals + view, the sidebar count, Notifications) before
changing anything. Where this prompt and the code disagree, follow the code and tell me. Then
read resources/views/livewire/letters/dashboard.blade.php and its component, RoutingHistory,
LetterStatusLog, LetterNotification, config/gwl.php and .env.example.

- One migration (hasTable/hasColumn guarded, explicit FK behaviour, never edit an existing
  migration): letter_status_logs.routing_history_id (FK routing_histories nullable,
  nullOnDelete, indexed); routing_histories.reminded_at (timestamp nullable). Backfill
  routing_history_id ONLY for hops with received_confirm = 0, by matching the recipient's most
  recent Received log on that letter created at or after the hop. Update recordHop() to set
  routing_history_id on the recipient's log from now on.
- config/gwl.php + .env.example: letters_unconfirmed_alert_days (GWL_LETTERS_UNCONFIRMED_ALERT_DAYS,
  2) and letters_remind_cooldown_hours (GWL_LETTERS_REMIND_COOLDOWN_HOURS, 24).
- LetterWorkflowService, same DB::transaction + lockForUpdate re-read pattern as the rest:
  recall(RoutingHistory, Employee $sender, ?string $note), recallBatch(LetterDispatchBatch,
  Employee) (all-or-nothing), reject(RoutingHistory, Employee $recipient, string $reason)
  with rejectLines(Employee, array $hopIds, string $reason), remind(Employee $sender,
  RoutingHistory|LetterDispatchBatch) — exactly as specified in §8.2: unconfirmed, unresolved
  hops only; delete the recipient's never-held Received log; restore the sender's log to
  Received and clear out_date (canDispatch() must be true afterwards); set resolution,
  resolved_at, resolution_note; resolve the recipient's notification; notify the sender on
  reject; update confirmed/completed counters (completed_at once every line is confirmed or
  resolved); audit recall_letter / reject_letter / remind_letter_recipient per letter with
  metadata.batch_id, plus one batch-level audit row for batch actions.
- Add a RoutingHistory scope unresolved() (resolution IS NULL) and apply it everywhere
  received_confirm = 0 means "awaiting confirmation": grep received_confirm across app/ and
  resources/ — pendingIncomingRoute(), deskState(), the Incoming tab, the sidebar badge, the
  dashboard counts, Notifications, the close-while-in-transit guard. A recalled or rejected hop
  must never look pending, block canDispatch() or block close().
- UI: Sent tab — per-line Recall, Recall unconfirmed lines, Remind (disabled inside the
  cooldown with a "reminded 3 h ago" tooltip), age pill (amber after the alert days, red at
  twice that) and an Overdue quick filter. Incoming tab — "waiting N days" per line and
  "Reject ticked…" with a mandatory reason. Active Letters — "awaiting confirmation by X · N d"
  with a Recall link on the sender's Dispatched row. Drawer timeline — show recalled/rejected
  hops with who, when and the note. Letters dashboard — stat tile "Unconfirmed > N days"
  (x-ui.stat-tile, tone warning, :href to Transmittals → Sent → Overdue). Catch
  \RuntimeException in every new action and show the message with the existing toast event.
- Authorization: no new permission. recall/remind need letters.forward and being the sender;
  reject (like confirm) needs only being the recipient. Keep enforceLivewireModule('letters').
- Tests per §8.2 (RecallRejectTest, LetterAgingTest) using the Phase 0 builders; run php
  artisan test; update docs/07-module-letters.md and docs/09-data-model.md.
Do not build recipient widening, desks, the register export or scans in this phase.
```

### Phase 3

```
Phase 3 of the Letters module work — managers and chief managers hold letters, plus
deliver-to-addressee — per letters-module-review-and-batch-design.md §6 and CLAUDE.md.
Assumes Phases 0-2 have shipped (Phase 0's letter-level closed_at/closed_by_id and the
holder rule are required). Those phases were implemented in earlier Claude Code runs, so
the code on disk is the truth, not the design doc: run git log --oneline -30 and read what
they actually added (recordHop, dispatchBatch, deskState, recall/reject, the Transmittals
component) before changing anything; where this prompt and the code disagree, follow the
code and tell me. Then read LetterWorkflowService, ActiveLetters (PHP + blade),
Livewire/Letters/Notifications.php, resources/views/layouts/erp.blade.php (bell block,
~146-150), Livewire/Notifications/GeneralBell.php, ErpNavigation::moduleDefinitions(),
LettersRolePermissionSeeder, ModuleAccessSeeder and DemoDataSeeder (~line 1578) first.

- First, D6: scope regionalManagersQuery()/regionalChiefManagersQuery() by location_type,
  then department (Head Office) or region (regions/districts), the way the Leave module
  resolves its chain. Keep the method names/signatures or update DemoDataSeeder.
- Replace secretaryQuery() with recipientsQuery(?string $search, Employee $actor,
  ?string $scope = null): active, visibleInErp employees whose user (employee_id or
  staff_id link) has a role holding the letters.view permission; search by name, staff id,
  department. dispatch() and dispatchBatch() must re-check eligibility inside the service,
  never trust the id from the picker. Do not hard-code role names.
- Picker: group Secretaries / Managers; label "Name · Department · Location"; chips
  "My location / Head Office / Any"; "Return to previous holder" and recent recipients
  pinned on top.
- Remark form: if the holder has manager, departmental_manager or district_manager,
  pre-select and lock them in the Manager field; chief_manager or regional_chief_manager in
  the Chief Manager field; hide "Secretary remarks" for non-secretaries; the service rejects
  a reviewer that does not match an acting manager-tier holder. Secretaries keep the
  current form.
- Bell and badge: render the letters bell on every ERP page for users with Letters module
  access (keep GeneralBell unchanged), and show a pending-incoming count badge on the Letters
  top-nav tab.
- Deliver to addressee: migration creating letter_deliveries (letter_id cascade,
  delivered_to_employee_id nullable nullOnDelete, delivered_to_name nullable,
  delivered_by_id restrictOnDelete, delivered_at, note); LetterWorkflowService::deliver(
  MailLetter, Employee $holder, array $data) — holder with confirmed custody, no pending hop,
  letter not closed, exactly one of the two addressee fields; sets closed_at/closed_by_id
  (closer = holder); audit deliver_letter; action in the drawer for the current holder.
- The Managing Director does NOT hold letters (decided 2026-09-30): do not give
  managing_director the Letters module or any letters.* permission in any seeder or
  migration. Add a test that a seeded managing_director has no Letters module access and is
  never returned by recipientsQuery() and is rejected by dispatch() even with a crafted id.
  Do not add any other roles or permissions in this phase either.
- Tests per §6; run php artisan test; update docs/07-module-letters.md and
  docs/09-data-model.md.
```

### Phase 4 — register export

```
Phase 4 of the Letters module work — the holder's register as Excel and PDF — per
letters-module-review-and-batch-design.md §8.3 and CLAUDE.md. Assumes Phases 0-2 have shipped
(the register needs routing_histories.confirmed_at from Phase 1 and
letter_status_logs.routing_history_id from Phase 2). Those were implemented in earlier Claude
Code runs, so the code on disk is the truth, not the design doc: run git log --oneline -30 and
read what they actually added before changing anything; where this prompt and the code
disagree, follow the code and tell me. Then read app/Http/Controllers/Visitors/
VisitorExportController.php (Excel + Dompdf pattern, audit call), app/Exports/Leave/
ApprovedLeavesExport.php, the export routes in routes/web.php (the visitors group), the
MailLetter / LetterStatusLog / RoutingHistory / LetterRemark models,
LettersRolePermissionSeeder, lettersSidebar() in app/Support/ErpNavigation.php, and
config/gwl.php first.

- app/Services/Letters/LetterRegisterService::rows(Employee $holder, CarbonInterface $from,
  CarbonInterface $to, string $scope = 'all'): Collection — one register row per
  letter_status_logs row of the holder, columns exactly as in §8.3 mapped to the real
  MailLetter column names. Date received = the incoming hop's confirmed_at (via the log's
  routing_history_id; the creator's own intake uses the recording date). Load the hops and
  remarks for the whole result set in one query each and match in PHP — no per-row queries.
  Exclude hops that are unconfirmed, recalled or rejected. Scopes: all / with_me / dispatched /
  closed. Enforce config('gwl.letters_register_max_rows') (env GWL_LETTERS_REGISTER_MAX_ROWS,
  5000; add to config/gwl.php and .env.example) with a clear "narrow the range" message.
- Livewire page app/Livewire/Letters/Register.php + view: date range (default current month),
  scope chips, paginated preview (25 rows) from the same service, and Export Excel / Export PDF
  links carrying the filters. Route letters.register in the existing letters group behind
  permission:letters.export; sidebar entry "My register" shown only with letters.export;
  enforceLivewireModule('letters').
- app/Http/Controllers/Letters/LetterRegisterExportController with excel() and pdf(), routes
  letters.register.excel / letters.register.pdf with ->middleware('permission:letters.export'),
  modelled on VisitorExportController: Excel::download(new LetterRegisterExport($rows), …),
  and new Dompdf($options) with defaultFont DejaVu Sans, isRemoteEnabled false, A4 landscape,
  view resources/views/letters/exports/register-pdf.blade.php (holder, office, period,
  generated time, row count, "Prepared by / Checked by" lines), Content-Disposition
  attachment, filename letter_register_<staff id>_<from>_to_<to>.<ext>. Copy the small date
  helpers into the new controller; do NOT refactor the Visitors controller. Own register only;
  an employee_id parameter is honoured for super_admin and answered with 403 for everyone else.
- app/Exports/Letters/LetterRegisterExport implements FromCollection, ShouldAutoSize,
  WithHeadings (collection(): Collection, headings(): array — as in ApprovedLeavesExport) and
  WithCustomValueBinder with a string binder, because subjects/senders/remarks are user text and
  the default binder turns a cell starting with = into a formula. Prove it with a test that
  reads the generated .xlsx and checks that a subject of "=1+1" is stored as a string.
- Audit: AuditLog::record('export_letter_register_excel' / 'export_letter_register_pdf',
  'letters', 'letter_status_logs', null, null, ['employee_id'=>…, 'from'=>…, 'to'=>…,
  'scope'=>…, 'rows'=>…]).
- Statuses: add "Delivered to …" only if Phase 3's letter_deliveries table exists; add the
  "Scanned" column only if Phase 5 shipped (MailLetter::scans()). Do not build either here.
- Do NOT change LettersRolePermissionSeeder — letters.export stays secretary-only; managers get
  it from the role editor if wanted.
- Tests per §8.3 (LetterRegisterTest) using the Phase 0 builders; run php artisan test; update
  docs/07-module-letters.md.
Do not build recall/reject, recipient widening, desks or scans in this phase.
```

### Phase 5 (optional)

```
Phase 5 of the Letters module work — optional letter scans — per
letters-module-review-and-batch-design.md §7 and CLAUDE.md. Assumes Phase 0 has shipped
(holder rule). Earlier phases were implemented in earlier Claude Code runs, so the code on
disk is the truth: run git log --oneline -30 and read what exists (holder rule, Transmittals,
register export if present) before changing anything; where this prompt and the code
disagree, follow the code and tell me. Read LetterWorkflowService, ActiveLetters, NewLetter (PHP + blade),
app/Livewire/Transport/Issues.php (the WithFileUploads pattern), config/filesystems.php,
config/gwl.php and .env.example first.

- Everything behind config('gwl.letters_scans_enabled') (env GWL_LETTERS_SCANS_ENABLED,
  default false; add it, letters_scan_disk='local', letters_scan_max_kb=10240,
  letters_scan_max_files=10 and letters_scan_preview_before_confirm=false to config/gwl.php
  and .env.example). Flag off: tab and picker hidden, service refuses, route 404.
- Migration creating letter_scans exactly as in §7 (hasTable guarded, explicit FK
  behaviour); flat model app/Models/LetterScan.php; MailLetter::scans() relation.
- app/Services/Letters/LetterScanService: add() and void() as specified (holder rule,
  file cap, sha256 duplicate check, private disk only, path letters/scans/{yyyy}/{mm}/
  {letter_id}/{uuid}.{ext}, audit add_letter_scan / void_letter_scan). NEVER use the
  'public' disk — Transport does, letters must not.
- Streaming controller + route GET /letters/scans/{scan} (letters.scans.show) in the
  existing letters group: resolve the scan through visibleLettersQuery($actor), enforce
  confirmed-hop rule unless letters_scan_preview_before_confirm, return
  Storage::disk(...)->response() with inline disposition and X-Content-Type-Options:
  nosniff; no public URL anywhere.
- Livewire: "Scans" tab in the ActiveLetters drawer (WithFileUploads,
  TemporaryUploadedFile, rules file|mimes:pdf,jpg,jpeg,png|max from config, multiple via
  scans.*), kind select (original/commented/enclosure), note, void action with reason;
  optional file picker on NewLetter; paperclip count in the list row. On mobile the input
  uses accept="image/*,application/pdf" capture="environment" multiple.
- Do not build OCR, thumbnails, AV scanning or EXIF stripping.
- If the Phase 1 transmittal sheet/Incoming checklist or the Phase 4 register export already
  exist, add the touchpoints listed at the end of §7 (sheet "Scan" column, "View scan" link,
  register "Scanned" column); if they do not exist yet, leave them for those phases.
- Tests per §7 (Storage::fake('local')); run php artisan test; update docs.
```

---

## 10. Open decisions (defaults I would take)

1. **Regional direct hand-offs — DECIDED 2026-09-30: managers hold letters (Phase 3, §6); the Managing Director does not.** Still open inside that:
   - **Other non-manager roles** (for example ICT officers) — may they hold letters? *Default: no; grant `letters.view` plus the Letters module to those roles from the role editor only where wanted — recipient eligibility follows the permission automatically.*
   - **Recording the MD's comment.** The remark form's reviewer fields are *Manager* and *Chief Manager* (`letter_remarks.manager_id` / `chief_manager_id`), so an MD comment has no slot. (a) The MD-office secretary types "MD: …" in the *Secretary remarks* field — works today, no change; or (b) add the MD as a third reviewer choice (schema change). *Default: (a); revisit if MD comments need to be reported on.*
2. **Partial receipt** — allowed per line? *Default: yes.*
3. **Sheet** — available but never mandatory? *Default: yes.* Should it carry a QR/SN scan box? *Default: later.*
4. **Max letters per transmittal** — *default 50.*
5. **Should a single-letter dispatch also become a transmittal of one?** One code path and one inbox, but it changes today's flow. *Default: no, revisit after Phase 1.*
6. **Who may annotate a letter** — only the current holder (my default for D4), or any past holder too?
7. **Serial-number prefixes** — are two regions' initials ever the same in your real data (D3)? An admin-editable prefix per region is the safe default, and it is what Phase 0b (§8.1) builds.
8. **Letter scans (§7):**
   - **Where do the files live and who backs them up?** Default: server private disk (`storage/app/private/letters`) included in the server backup; a network share or S3 disk is a config change (`letters_scan_disk`). *The flag stays off until this is answered.*
   - **Preview before confirm** — may a recipient read the scan while the hardcopy is still in transit? *Default: no (same discipline as today); one switch to allow it.*
   - **Who can open a scan** — everyone who can see the letter, including past holders (*default*), or only the current holder and the creator for confidential letters?
   - **Log who opens a scan?** *Default: no, only add/void are audited.*
   - **Phone photos keep EXIF location data** unless re-encoded. *Default: leave as is; internal tool.*
   - **Size/count limits** — *default 10 MB per file, 10 files per letter.*
9. **Register export for managers (Phase 4, §8.3):** `letters.export` is seeded for `secretary` only. Should managers and chief managers who hold letters in Phase 3 also be able to export their own register? *Default: no seed change; grant it per role from the role editor where wanted.*
10. **Unconfirmed-letter thresholds (Phase 2, §8.2):** alert after 2 days, reminders at most every 24 hours? *Default: yes; both are config values (`letters_unconfirmed_alert_days`, `letters_remind_cooldown_hours`).*
