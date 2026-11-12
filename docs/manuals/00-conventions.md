# 00 – Conventions Used in These Manuals

## How each manual is built

Every module manual has the same twelve sections, in this order, so you can find things quickly:

1. **Purpose** – what the module is for and who uses it.
2. **Who can do what** – roles against actions, what each role cannot do, and which locations it reaches.
3. **How to open it** – where it sits in the navigation and any condition (a feature that must be switched on, a role needed).
4. **Screen-by-screen guide** – what each screen shows, filters, columns, buttons and form fields (which are required, and the rules).
5. **Step-by-step tasks** – the things you do, in order, including the common refusals and their messages.
6. **Workflow and status lifecycle** – the statuses, who moves a record on, notices sent, what is locked afterwards, with a simple diagram.
7. **Reports, exports and printing** – what is available, who may run it, formats and row limits.
8. **Imports** – file format, columns, the preview step and typical errors.
9. **Rules and gotchas** – the rules that surprise people.
10. **Troubleshooting and FAQ** – realistic "why can't I…?" questions.
11. **Glossary** – words used in that module.
12. **Screenshot placeholders** – the pictures still to be added.

Each manual ends with a **To confirm** list: points that looked unfinished, ambiguous, or different from older design notes. They are not described as working features.

Some manuals (Assets and Commercial) are longer because they contain a second part (MDM; Customer List and SMS) that has its own on/off switch. Credit Union has no design notes at all; its manual is the thinnest and has the longest "To confirm" list.

## Writing style

- Plain language, second person ("you"), numbered steps.
- Button, tab, field and message names are written exactly as on screen, in **bold** for buttons and fields and in quotation marks for messages.
- Examples use invented data only (staff ID 12345, "Demo Supplier Ltd"). No real names, numbers or credentials appear.
- Diagrams are small Mermaid flow charts; if your viewer does not draw them they read as plain text.
- Screenshot placeholders look like `[Screenshot: Screen name – what to capture]`.

## Terms used across all modules

| Term | Meaning |
|---|---|
| **Module** | A main area of the portal, shown as a tab in the header. |
| **Role** | A job-type label that opens modules and carries permissions. |
| **Permission** | A single right inside a module (for example "export"). |
| **Head Office** | A district of its own; its staff share a region code with the Head Office region but are always treated separately. |
| **Region / District** | The second and third levels of location. |
| **Location** | In Transport, G-Water, Finance and Audit: Head Office, or a region without Head Office. |
| **Reach** | Which records a person can see or change, decided by location and role. |
| **Not found** | The message shown for a record outside your reach (it looks the same as a record that does not exist). |
| **Void** | Cancel an entry with a reason; the entry stays on record and its effects are reversed. |
| **Deactivate** | Switch something off without deleting it. |
| **Draft** | Saved but not yet sent. |
| **Preview** | The check step of an import: nothing is saved until you confirm. |
| **Bell** | The notification icon in the header. |
| **Audit trail** | The permanent record of who did what and when. |
| **Pesewas** | One hundredth of a cedi; some modules store money as whole pesewas but show cedis. |
| **Switched off** | A module or part of a module that the ICT team has not enabled; it shows no tab and its addresses answer "not found". |
| **Scheduler / queue worker** | Background services on the server that send reminders, process big files and deliver commands; if they are not running, reminders and large uploads do not happen. |

## Keeping the manuals up to date

When a screen or rule changes, update the matching manual and its "To confirm" list. Role and module tables in the README were taken from the default role set-up; if the organisation changes roles in Access Control, adjust the README matrix.
