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
  different from the sender and an active secretary (re-checked in the service, not just the picker).
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
