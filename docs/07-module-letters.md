# Module: Letters

## Scope

Letter intake, routing, hardcopy confirmation, remarks, and closure.

## Main Features

- Create internal/external letters
- Auto serial number generation per region/year
- Active and closed queues
- Dispatch between secretariats
- Hardcopy receipt confirmation
- Add and edit remarks
- Close and reopen letter lifecycle

## Workflow Behavior

On create:

- Creates `mail_letters` record
- Creates initial `letter_status_logs` entry as `Received`

On dispatch:

- Creates `routing_histories` record with `received_confirm=false`
- Creates status log for recipient
- Creates `letter_notifications` inbox entry

On hardcopy confirmation:

- Marks route as received
- Promotes status to `In Review` where applicable

## Authorization Highlights

- Create: `letters.create`
- Dispatch: `letters.forward`
- Remark: `letters.remark`
- Close/reopen/edit: creator ownership checks

## Key Files

- `app/Services/Letters/LetterWorkflowService.php`
- `app/Livewire/Letters/ActiveLetters.php`
- `app/Livewire/Letters/NewLetter.php`
- `app/Livewire/Letters/Dashboard.php`
