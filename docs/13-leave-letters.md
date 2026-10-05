# Leave approval letters

A printable approval letter is made for every finally-approved leave request, in the layout of the company template
(`leave templetes.docx`): letterhead, addressee block, the approval paragraphs, the signing space and the Board of Directors footer.
Code: `App\Services\Leave\LeaveLetterService` (wording, dates, numbers in words, access, edits, prints),
`SignatureService`, `LeaveLetterSettingsService`, `LeaveActingAssignmentService`; Livewire `Leave\ApprovalLetter`, `MySignature`,
`LetterSettings`, `ActingAssignments`; controller `LeaveLetterController`; print view `resources/views/leave/letters/print.blade.php`.

## When it is made and what it holds

`LeaveWorkflowService::finalDecision()` makes the letter inside the approval's transaction, in its own savepoint: if it can't be
made the failure is logged and the approval stands (HR regenerates it from the letter screen). All approval flows get one
(two-stage, single-stage, Managing Director). `leave_letters` holds a **frozen snapshot** of everything printed (addressee,
dates, entitlement, compulsory days, balance, letterhead, company block with the board, signatory block, cc): editing the
letterhead or board later never changes an issued letter. It stores the id of the signature used, never the image.

## Wording

| Part | Text |
|---|---|
| RE line | Annual: `RE: REQUEST FOR PART LEAVE` when fewer days than the net entitlement are approved, else `RE: ANNUAL LEAVE`. Other types: `RE: CASUAL LEAVE`, `PATERNITY LEAVE`, `MATERNITY LEAVE`, `SICK LEAVE` |
| Application | `Your application dated May 5, 2026, on the above subject refers.` (the template gives this date without the weekday) |
| Approval | Annual: `Approval is given to enable you spend five (5) working days from your 2026 annual leave entitlement of thirty-six (36) working days with [retrospective ]effect from Monday, May 11, 2026.` Other types: `...spend one (1) working day on casual leave with [retrospective ]effect from ...` |
| Dates | End before the approval date: `Your five (5) working days leave ended on <date>, and you were expected to resume duty on <date>.` Otherwise `...will end on <date>, and you are expected to resume duty on <date>.` |
| Christmas line | Annual leave where a compulsory deduction applies, on by default, HR can untick it before the first print: `Please note that the eleven (11) days Christmas break granted by management has also been deducted from your 2026 annual leave.` |
| Balance | `You now have thirty-one (31) working days of leave remaining for 2026.` (`...of casual leave remaining...` for other types; none for Sick, which has no entitlement) |

"Retrospective" is used only when the start date is before the approval date. The end date is the last working day of the leave and
the resume date the next working day after it (`WorkingDaysCalculator`: weekends and holidays are skipped). The entitlement in the
approval paragraph is the **gross** entitlement; the balance is the entitlement less the compulsory days less the days used
(this request included). Date formats: body `l, F j, Y` ("Wednesday, May 6, 2026"), application date `F j, Y`, letterhead date `j F Y`.

The addressee block follows the template: honorific and name, designation (job title) and station in capitals,
`THRO’ THE DISTRICT/DEPARTMENTAL/UNIT MANAGER` (left out when the applicant applied straight to the chief manager or MD),
then `GHANA WATER LIMITED` and the district, or at Head Office the unit (else the department). Regional office staff are addressed to
their region (`ACCRA WEST REGION`) with no company line, as in the template. `Dear Sir,` / `Dear Madam,` follows the title
(`Mr.` / `Mrs.`, `Ms.`, `Miss`, `Hajia`), then the gender, else `Dear Sir/Madam,`. The honorific is `employees.title` (staff form,
import column `title`, profile, drawer).

## Who signs

| Mode | Block under "Yours faithfully," |
|---|---|
| `self` | `NAME` / `REGIONAL CHIEF MANAGER`, `CHIEF MANAGER, <DEPARTMENT>` or `MANAGING DIRECTOR` |
| `acting` | `NAME` / `AG. <TITLE>`; the default when the final approver acted through an acting assignment |
| `for` | the location's HR signatory: `NAME` / `<HR job title>` / `For: <CHIEF MANAGER TITLE>` |

`leave_requests.final_approver_capacity` records `substantive` or `acting` at final approval. HR in scope may switch a letter between
its default mode and `for` (when the location has an HR signatory) until the first print.

## Printing

`GET /leave/letters/{request}` is the letter screen (text preview, Print Letter, Download PDF, and for HR the editable fields);
`GET /leave/letters/{request}/pdf` is the A4 PDF (Dompdf, as for transmittal sheets), with `no-store` cache headers. **Each PDF
response counts as a print**: it locks the letter, increments `printed_count` and is audited (`leave_letter_printed`, then
`leave_letter_reprinted`). Who may open a letter: the applicant, the approvers on the request, regional HR for their region's staff
(Head Office staff are Head Office HR's), Head Office HR, Global Admin and super_admin. Before the first print HR in scope may edit the
reference number, the cc list, the signatory mode and the Christmas line (`leave_letter_updated`, old and new values); after it they are
locked. The approval notification and email link to the letter.

## Signatures

`user_signatures` (private disk `leave_signatures`, no URL; PNG bytes encrypted with the app key; never logged or audited):

- **My Signature** (Leave sidebar, `leave.signature`): draw on a canvas (pointer events, undo and clear) or upload a PNG/JPG up to 1 MB.
  The content is checked (not the extension), re-encoded to PNG (metadata dropped), scaled to at most 600x200 and its near-white
  background made transparent; a blank image is refused. Saving or replacing asks for the user's password. Delete = revoke.
- **Who has the page**: chief managers, regional chief managers, the Managing Director, regional and Head Office HR (permission
  `leave.sign_letters`) and whoever holds an active acting assignment. Nobody can view, upload or apply another person's signature.
  Global Admin and super_admin can only **revoke** one (Letter Settings, "Signatures on file").
- **Attaching**: at final approval the approver sees "Apply my saved signature to the approval letter" (ticked when they have one; a link
  to My Signature when they don't). In `for` mode only the HR signatory can authorise theirs, from the letter screen before the first print.
  Switching mode withdraws any authorisation. A signer who had none at approval can apply later, before the first print.
- **Rendering**: only in the PDF, under "Yours faithfully," (at most 60 x 25 mm), as a data URI in the response, never in emails, lists,
  exports or audit rows. A revoked signature is never drawn again (blank space; the letter screen tells HR). Replacing keeps earlier
  letters on the version they were printed with; an old version no letter uses is deleted.

## Letter Settings and acting assignments

Both are under Staff Management -> HR Tools. **Letter Settings** (`leave.letter-settings`, `leave.manage_letter_settings`): the letterhead
(heading, address lines, HR signatory, default cc) of each location (a region, or Head Office as the null region): regional HR edit only their
own region, Head Office HR, Global Admin and super_admin any; the company block (bankers, board, registered office, telephone, website, e-mail):
Head Office HR, Global Admin and super_admin only. A region with no address is flagged "address not set". Both audit old and new values.
**Acting Assignments** (`leave.acting`, `leave.manage_acting`): user, post (chief manager, regional chief manager, Managing Director), region
or department, dates (both days included). Head Office HR and Global Admin set any; regional HR only a regional chief manager of their own
region. An active assignment makes its user a valid approver for the post alongside the holder (`LeaveApprovalChainResolver`): only resolved
approvers may act, the first action wins, out-of-scope users get a 403.

## Seeded from the template

The migration seeds the bankers (GCB Bank Limited, Societe Generale Ghana, National Investment Bank), the registered office (28th February
Road, (Near Independence Square)), telephone 233-508-300-537, www.gwcl.com.gh, info@gwcl.com.gh, the Board of Directors, and the Accra West
Region letterhead (Post Office Box DC 998, Dansoman, Accra - Ghana, West Africa) for a region whose name contains "Accra West". The template
carries two board lists; the one with Hon. Patrick Yaw Boamah (Chairman) is seeded. Change either in Letter Settings.
