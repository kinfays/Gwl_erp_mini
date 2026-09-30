# Module: Letters

## Scope

Letter intake, routing, hardcopy confirmation, remarks, and closure.

## Main Features

- Create internal/external letters
- Auto serial number generation per region prefix/year (see Serial numbers)
- Active and closed queues
- Dispatch between secretariats, one letter at a time or many at once as a transmittal
- Hardcopy receipt confirmation, singly or in bulk (partial receipt allowed)
- Printable hand-over sheet (PDF) per transmittal
- Add and edit remarks
- Close and reopen letter lifecycle

## Workflow Behavior

On create:

- Creates `mail_letters` record
- Creates initial `letter_status_logs` entry as `Received`

## Serial numbers

`PREFIX-YYYY-NNN`, e.g. `GA-2026-001`, issued inside `create()`'s transaction by `LetterWorkflowService::nextSnNumber()`.

- **Prefix.** `regions.letter_prefix` (unique), edited on the Staff > Regions screen (`staff.manage_regions`). A region
  that has none (seeders, imports, fresh installs) is given one the first time a letter needs it: the initials of its
  name, made unique by appending the region id, and it keeps it from then on, so renaming a region does not change it.
  A name with no words falls back to `REG`. Logic lives in `Region::assignLetterPrefix()`.
- **Counter.** `letter_sn_counters` holds the last number for each (prefix, year). The row is `lockForUpdate`d, created
  on demand from the highest number already issued (parsed numerically in PHP), and incremented; a number that is already
  taken is skipped. It is keyed on the prefix, so regions never share a sequence by accident. The year rolls the
  sequence back to `001`.
- **Format.** The number is zero-padded to three digits and continues past them: `...-999`, `...-1000`, `...-1001`.
  Serial numbers are never ordered as strings.
- **Changing a prefix** starts that prefix's own counter and leaves every issued number alone; going back to an old
  prefix resumes where it stopped.

On dispatch:

- Creates `routing_histories` record with `received_confirm=false`
- Creates status log for recipient
- Creates `letter_notifications` inbox entry

On hardcopy confirmation:

- Marks route as received and records `confirmed_at` / `confirmed_by_id`
- Promotes status to `In Review` where applicable
- `confirmHardcopies()` is the only code path; `confirmHardcopy()` is a one-letter wrapper. It only ever touches
  pending hops addressed to the actor; anything else in the list of ids is ignored, and it throws only when nothing
  qualified.

## Transmittals (batch dispatch and confirmation)

A transmittal (`letter_dispatch_batches`, number `TR-<year>-<id>`) hands several letters from one holder to one
recipient at once. Each letter is still an ordinary hop that points at the batch (`routing_histories.batch_id`), so
every existing rule keeps working: the recipient must confirm each hop before reviewing, remarking on or dispatching
that letter.

`LetterWorkflowService::dispatchBatch($from, $to, $letterIds, $note)`:

- 1 to `config('gwl.letters_max_batch_size')` letters (env `GWL_LETTERS_MAX_BATCH_SIZE`, default 50); recipient
  different from the sender and an eligible recipient (see Who can hold a letter; re-checked in the service, not just
  the picker).
- **All-or-nothing.** Ids are re-read `lockForUpdate` through `visibleLettersQuery($from)` and every letter must pass
  the same check as a single dispatch. If any fails, nothing is created and the message lists the letters that failed.
  Ids the actor cannot see get a generic message that names nothing.
- Creates the batch, one hop + recipient log per letter (sender logs become `Dispatched`), **one** notification for
  the recipient (`letter_notifications.batch_id`, no `letter_id`), and audit rows: one `dispatch_letter` per letter
  (`metadata`: `batch_id`, `batch_no`) plus one `dispatch_letter_batch`.
- `confirmed_count` and `completed_at` on the batch are recomputed from its hops after every confirmation.

Screens:

- **Active Letters** - a tick column (only rows the actor can act on are tickable), header select-all for the page,
  quick filters *Awaiting my confirmation* / *Ready to dispatch* (both in SQL; `whereReadyToDispatch()` is the SQL twin
  of `deskState()`), a sticky bar (*Dispatch selected*, *Confirm hardcopies*, *Clear*) and a dispatch drawer
  (recipient, optional note). Each button acts on its eligible subset and says how many it skipped. The selection is
  cleared on tab, filter, search or page change and after every bulk action; a single-letter action in the letter
  drawer unticks only the rows it made non-actionable (and closing/reopening, which switches tab, clears it). The drawer freezes the list it shows, so a
  letter that changed meanwhile refuses the whole dispatch rather than sending fewer letters than were confirmed.
  The selection is client state: every action re-resolves it through `visibleLettersQuery` / the actor's own hops.
- **Transmittals** (`/letters/transmittals`) - *Incoming*: pending hops grouped by transmittal (single dispatches under
  "Individual letters"), pre-ticked, with *Confirm all* / *Confirm ticked*; unticked lines stay pending. *Sent*:
  transmittals the actor created, `confirmed of total`, per-line status and how long a line has been unconfirmed.
  The sidebar entry shows the number of hops waiting for the actor.
- **Sheet** (`/letters/transmittals/{batch}/sheet`) - landscape A4 PDF (Dompdf) with the letters and signature lines;
  only the sender, the recipient and `super_admin` may open it. The sheet is optional: the digital confirmation is
  what unlocks a letter.
- The bell shows one notification per transmittal and opens the Transmittals page focused on it.

No new permission: dispatching needs `letters.forward` (Livewire check, plus holder state in the service); confirming
needs none, as before.

## Who can hold a letter (managers and secretaries)

Secretaries remain the default holders, and managers and chief managers can hold letters too; both work on the same
letter, so an office whose managers do not use the portal keeps working secretary to secretary.

- **Eligibility is by permission, not role name.** `LetterWorkflowService::recipientsQuery(?string $search, Employee
  $actor, ?string $scope = null)` lists active, `visibleInErp()` employees whose user (linked by `employee_id` or by
  `staff_id`, and able to sign in) has a role that holds `letters.view` **and** gives access to the Letters module - so
  nobody is handed a letter they cannot open. Today that is `secretary`, `manager`, `departmental_manager`,
  `district_manager`, `chief_manager` and `regional_chief_manager` (`LettersRolePermissionSeeder`,
  `ModuleAccessSeeder`); an admin can extend it from the role editor. The actor is never their own recipient.
  `dispatch()` and `dispatchBatch()` call it again and refuse an ineligible id ("The selected recipient cannot receive
  letters."), so a crafted id from the picker never works.
- **The Managing Director does not hold letters** (decided 2026-09-30): `managing_director` has the Leave module only and
  no `letters.*` permission, so they can never be returned or dispatched to. Letters for the MD are held by the MD's
  office secretary and *Deliver to addressee* records the hand-over. `ManagingDirectorGuardTest` runs the real seeders
  and fails if a later change grants it by accident.
- **Picker** (single dispatch tab and the dispatch-selected drawer): *Return to previous holder* (when the same person
  handed every selected letter to you) and up to five recent recipients are pinned on top; then the matches for the
  search (name, staff ID, department) grouped *Secretaries* (people who can record letters, `letters.create`) and
  *Managers* (everyone else eligible). Each label is "Name · Department · Location". Chips: *My location* (default: your
  own office, see below), *Head Office*, *Any*.
- **Reviewers (D6).** The Manager / Chief Manager lists (`regionalManagersQuery()` / `regionalChiefManagersQuery()`) are
  scoped the way Leave resolves its chain: by `location_type` first, then the department at Head Office, or the region
  for regional offices and districts. Head Office shares a `region_id` with its regional office, so it is never scoped by
  region alone. "My location" in the picker uses the same office rule without the department.
- **Remarks by a manager who holds the letter.** `reviewerTier()` is `manager` (unit / departmental / district manager),
  `chief` ((regional) chief manager) or null (secretaries and everyone else). A tier holder's form shows themselves,
  locked, in the Manager or Chief Manager field and has no *Secretary remarks*; whatever the client sends, the remark is
  recorded as theirs. The service is the authority: it rejects another reviewer, a missing reviewer, a secretary remark
  or an empty remark from a tier holder. Secretaries keep the original form and rules.
- **Bell and badge.** The letters bell renders on every ERP page for users with the Letters module (the general bell is
  unchanged and still excludes letters notifications), and the Letters tab in the top navigation (and the mobile module
  list) carries the number of hops waiting for the user to confirm. Users with a single module have no module tabs; the
  Transmittals sidebar count covers them inside Letters.

## Deliver to addressee

The last step of a letter. The **current, confirmed holder** (not only the creator) opens the *Deliver* tab in the drawer
and records who took the hardcopy and when; the addressee needs no login, the holder records the paper signature.
`LetterWorkflowService::deliver($letter, $holder, $data)`:

- Requires that the holder has the letter on their desk (confirmed, nothing pending, letter open); otherwise the
  usual messages ("Confirm hardcopy receipt before delivering this letter.", "Only the current holder ...", "This letter
  is already closed.").
- Exactly one of `delivered_to_employee_id` (a staff member) or `delivered_to_name` (an outside party); `delivered_at`
  defaults to now and cannot be in the future; `note` is optional (500 characters).
- Writes a `letter_deliveries` row, closes the letter at letter level (`closed_at`, `closed_by_id` = the deliverer) and
  audits `deliver_letter`. Manual close and reopen stay creator-only; a delivered letter can still be reopened by its
  creator, and a later delivery adds another row (history is kept).
- No new permission: being the confirmed holder is the rule.

## Letter scans (optional, off by default)

An optional scanned copy of the hardcopy (PDF, JPG, PNG), so a manager can read a letter while the paper is still in the
courier's bag and there is a picture of the handwritten comment next to the typed remark. **A scan never replaces
custody:** the signed hand-over and the confirm-before-acting rule are unchanged. Everything is behind
`config('gwl.letters_scans_enabled')` (env `GWL_LETTERS_SCANS_ENABLED`, default false); with it off the Scans tab and the
file pickers are hidden, `LetterScanService` refuses and `GET /letters/scans/{scan}` is a 404.

- **Who can attach.** `LetterScanService::add()` needs the same holder rule as remarks: the actor has the letter on their
  desk (confirmed, nothing pending, letter open). At intake that is the creator; later it is the current confirmed
  holder. The service re-checks; the drawer only offers the form to the holder.
- **What is accepted.** PDF, JPG or PNG, judged by the file's content (the `mimes` rule and the content-sniffed mime
  type, not the extension), at most `letters_scan_max_kb` (10240) each and `letters_scan_max_files` (10) active scans per
  letter; the same file (`sha256`) cannot be attached twice to a letter (a voided one no longer counts).
- **Where it is stored.** On the private disk `letters_scan_disk` (default `local`, i.e. `storage/app/private`) at
  `letters/scans/{yyyy}/{mm}/{letter_id}/{uuid}.{ext}`; the extension comes from the content type and the client's file
  name is kept only as display text. The service refuses the `public` disk (Transport uses it, letters must not) and any
  disk configured with public visibility. **Back up `storage/app/private/letters` together with the database.**
  Keep `letters_scan_max_kb` at or below PHP's `upload_max_filesize` / `post_max_size` and Livewire's temporary-upload
  limit (about 12 MB; there is no `config/livewire.php`).
- **How it is served.** Only by `LetterScanController` (`letters.scans.show`): the scan is resolved through
  `visibleLettersQuery($actor)` (a scan of a letter you cannot see is a 404), a voided scan is a 404, and a recipient
  who has not confirmed the hardcopy gets a 403 **unless** `letters_scan_preview_before_confirm` is on. The response is
  `Storage::disk(...)->response()` with `Content-Disposition: inline`, `X-Content-Type-Options: nosniff` and
  `Cache-Control: private, no-store`. There is no public URL. Views are not audited.
- **Voiding.** `void()` (with a reason) hides a scan and keeps the file and the row: the person who attached it while
  they still hold the letter, the letter's creator, or a `super_admin`. Nothing is hard-deleted by the app.
- **Audit:** `add_letter_scan` (scan id, kind, name, size, sha256) and `void_letter_scan` (scan id, name, sha256, reason).
- **Screens.** A *Scans* tab in the letter drawer (multiple files via Livewire uploads, kind *Original / With comments /
  Enclosure*, note, void with a reason); an optional picker on *New Letter* (files are attached after the letter is
  saved; a file that cannot be attached is reported and never loses the letter); a paperclip count on the list row. On a
  phone the picker uses the camera (`accept="image/*,application/pdf" capture="environment" multiple`), with a button to
  pick files instead.
- **Touchpoints.** The transmittal sheet gets a *Scan* column (Yes / -); the Incoming checklist gets *View scan* /
  *View scans (n)* when reading before confirming is allowed; the register gets a *Scanned* column (all only while scans
  are on); on the confirm prompt the drawer lists the scans when the preview switch is on.
- **Not built:** OCR, thumbnails, virus scanning, or stripping EXIF data from phone photos (it stays in the file).

## My register (Excel and PDF)

Each holder can get their own register back from the app, so the parallel Excel sheet can be retired. One register row
is one `letter_status_logs` row of the holder, i.e. one stay of one letter on their desk. A letter that comes back to
the same desk makes a second row.

`LetterRegisterService::rows(Employee $holder, CarbonInterface $from, CarbonInterface $to, string $scope = 'all')` feeds
the Livewire preview, the Excel export and the PDF, so they cannot disagree. It loads the holder's logs, the letters,
their hops, their remarks and their deliveries in one query each and matches them in PHP (no per-row queries).

- **What is a row.** The holder's own intake (the creator's first log), or a hand-over they **confirmed**. Hops that are
  unconfirmed, recalled or rejected were never received and are not rows. A log is linked to its hop through
  `letter_status_logs.routing_history_id`; hops confirmed before that link existed (it was only backfilled for hops
  still awaiting) are matched by time, each hop claimed once. A hop-less log that is not the creator's intake (for
  example the log an old reopen added) is not a receipt.
- **Columns:** No. · Date received · SN · Ref no. · Type · Date on letter · Sender · Received from · Subject · Date out ·
  Sent to · Transmittal no. · Status · Remarks I recorded. *Date received* is the incoming hop's `confirmed_at` (the
  recording date for intake). *Received from* is the person who handed it over (the letter's sender for intake).
  *Date out*, *Sent to* and *Transmittal no.* come from the hand-over that ended the stay (a single dispatch has no
  transmittal). *Remarks I recorded* are the holder's own remarks made during the stay, joined with ` / ` (a
  secretary note is shown as `Secretary: ...`).
- **Status:** *With me*, *Dispatched*, *Closed*, or *Delivered to ...* when the holder delivered the letter (Phase 3's
  `letter_deliveries`). A *Scanned* column (Yes when the letter has an active scan) is added only while letter scans are
  enabled.
- **Filters:** a date range on *date received* (default: the current month) and scope chips *All / Still with me /
  Dispatched / Closed*. `config('gwl.letters_register_max_rows')` (env `GWL_LETTERS_REGISTER_MAX_ROWS`, default 5000)
  caps the rows after the filters; over it the user is told to narrow the range (the preview shows the message, the
  exports redirect back to the page with it).
- **Screens and routes** (Letters group, all behind `permission:letters.export`): `letters.register` ("My register", a
  sidebar entry shown with `letters.export`) with the filters, a 25-row preview and *Export Excel* / *Export PDF* links
  that carry the filters; `letters.register.excel` and `letters.register.pdf`
  (`LetterRegisterExportController`, modelled on the visitors exports). The PDF is A4 landscape (Dompdf, DejaVu Sans,
  remote resources off) with the holder, office, period, time generated, row count and *Prepared by / Checked by*
  lines; remarks are shortened to 120 characters there (the Excel file has them in full). Both download as
  `letter_register_<staff id>_<from>_to_<to>.<ext>`.
- **Own register only.** An `employee_id` request parameter is honoured for `super_admin` and answered with 403 for
  everyone else, even for their own id.
- **Spreadsheet formula injection.** Subjects, senders and remarks are typed by people, and PhpSpreadsheet's default
  value binder turns a cell that starts with `=` into a formula. `LetterRegisterExport` is its own string value binder
  (`WithCustomValueBinder`), so such text stays text; the row number stays numeric. `LetterRegisterTest` reads the
  generated `.xlsx` to prove it.
- **Audit:** `export_letter_register_excel` / `export_letter_register_pdf` (`letter_status_logs`, no target id) with the
  holder, range, scope and row count. A refused or over-the-cap request audits nothing.
- **Permission:** `letters.export` is still seeded for `secretary` only; a manager who should have a register gets it
  from the role editor.

## Recall, reject and remind (exceptions and aging)

A hand-over can be taken back only while it is **awaiting confirmation** (`received_confirm = 0` and no `resolution`,
the `RoutingHistory::awaiting()` scope). Once the recipient has confirmed, custody has changed and they must dispatch
the letter back themselves. Every operation re-reads the hop `lockForUpdate` inside a transaction, so when a confirm
and a recall/reject race, whoever takes the lock first wins and the other gets a message (shown as a toast).

- **Recall** (`recall()`, `recallBatch()`): the sender only. The hop gets `resolution = recalled`, `resolved_at` and an
  optional note; the recipient's never-held `Received` log is deleted (found through `letter_status_logs.routing_history_id`,
  or by matching for hops that predate the link), so they stop seeing the letter; the sender's log goes back to
  `Received` with `out_date` cleared, so `canDispatch()` is true again; a single dispatch's notification is deleted, and
  a transmittal's notification is updated (or marked read when nothing is left to confirm). `recallBatch()` recalls
  every still-unconfirmed line of a transmittal in one all-or-nothing transaction; confirmed lines stay.
- **Reject** (`reject()`, `rejectLines()`): the recipient only, with a mandatory reason (5+ characters). Same effects
  with `resolution = rejected`, plus one notification to the sender per transmittal (or per letter for single
  dispatches) carrying the reason. `rejectLines()` only ever touches the recipient's own awaiting hops and ignores
  anything else in the list.
- **Remind** (`remind()`): the sender only, awaiting hops only, for one hop or every awaiting line of a transmittal.
  Sets `reminded_at`, notifies the recipient, and is refused inside `letters_remind_cooldown_hours`
  (env `GWL_LETTERS_REMIND_COOLDOWN_HOURS`, default 24; 0 switches the limit off).
- **Batch counters:** `confirmed_count` counts confirmations only; `completed_at` is set once no line is awaiting, that
  is when every line is confirmed or resolved.
- **Audit:** `recall_letter` / `reject_letter` / `remind_letter_recipient` per letter (`metadata`: `batch_id`, `note`),
  plus `recall_letter_batch` / `reject_letter_batch` / `remind_letter_batch` once per transmittal touched.
- **Never pending:** `awaiting()` is used by `pendingIncomingRoute()`, `deskState()`, the quick filters, the Incoming
  tab, the sidebar badge, the bell, the batch counters, the close-while-in-transit guard and the overdue counts.

**Aging.** A hop that has waited `letters_unconfirmed_alert_days` (env `GWL_LETTERS_UNCONFIRMED_ALERT_DAYS`, default 2)
is overdue: amber, and red at twice that (`agingTone()`).

Screens:

- **Transmittals > Sent** - per-line *Recall*, *Recall unconfirmed lines*, *Remind* (disabled inside the cooldown, the
  tooltip says when it was last sent), an age pill and "waiting N days" on each line, recalled/rejected lines with who,
  when and why, and an **Overdue** filter (also opened from the dashboard tile with `?tab=sent&filter=overdue`). Single
  dispatches that are still unconfirmed are listed in an "Individual letters" card so they can be recalled and reminded
  too, and so the filter and the tile count the same hops.
- **Transmittals > Incoming** - "waiting N days" on each line and *Reject ticked…* with a mandatory reason (only the
  ticked lines).
- **Active Letters** - the sender's row shows "awaiting confirmation by X · N d" and a *Recall* link; the letter drawer's
  timeline shows recalled/rejected hops with who, when and the note.
- **Dashboard** - tile *Unconfirmed > N days*: hops the viewer sent that are awaiting and at least that old.

Recall and remind need `letters.forward` and being the sender; reject, like confirm, needs only being the recipient.

On close / reopen:

- Closed is a state of the letter (`mail_letters.closed_at`, `closed_by_id`); the Active/Closed tabs and the
  Dashboard counts read it. No holder's `letter_status_logs` row is changed (`letter_status_logs.is_closed` is no
  longer written).
- Close is refused while any hop is still unconfirmed, and on an already-closed letter; reopen only works on a
  closed letter. Both are creator-only.
- Reopening a letter closed before `closed_at` existed (every log rewritten to `Closed`) gives it back to the
  creator as `In Review`, as before.

On remark:

- Add/edit requires that the actor currently holds the open letter (latest log `Received`/`In Review`) and has no
  unconfirmed incoming hop. Editing is also author-only and audited (`update_letter_remark`).

Service rules that a stale tab or double click can trip (`RuntimeException`) are shown as an error toast by
`ActiveLetters`, not as a server error.

## Authorization Highlights

- Create: `letters.create`
- Dispatch: `letters.forward`
- Remark: `letters.remark` (plus holding the letter, above)
- Close/reopen/edit: creator ownership checks

## Key Files

- `app/Services/Letters/LetterWorkflowService.php`
- `app/Livewire/Letters/ActiveLetters.php`
- `app/Livewire/Letters/NewLetter.php`
- `app/Livewire/Letters/Dashboard.php`
- `app/Livewire/Letters/Transmittals.php`
- `app/Http/Controllers/Letters/TransmittalSheetController.php` and `resources/views/letters/exports/transmittal-sheet.blade.php`
- `app/Models/LetterDispatchBatch.php`
