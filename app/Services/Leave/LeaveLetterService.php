<?php

namespace App\Services\Leave;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveLetter;
use App\Models\LeaveLetterhead;
use App\Models\LeaveRequest;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Leave approval letters: what they say, who signs, who may see and change them, and what a print records.
 *
 * The wording, the date format ("Wednesday, May 6, 2026") and the number-to-words ("thirty-six (36)") all live here, not in
 * Blade. Everything printed is frozen into `leave_letters.snapshot` when the letter is generated (at final approval), so a
 * later change to the letterhead, the board or the entitlement never alters an issued letter. Until the first print HR in
 * scope may change the reference number, the cc list, the signatory mode and the Christmas line; after it the letter is locked
 * and a reprint is identical (apart from a revoked signature, which is then never drawn).
 *
 * Wording by leave type (the RE line and the body):
 *   Annual     "RE: ANNUAL LEAVE", or "RE: REQUEST FOR PART LEAVE" when fewer days than the net entitlement are approved;
 *              "...from your <year> annual leave entitlement of <n> working days..."; Christmas line and balance for the year
 *   Casual / Paternity / Maternity / Sick
 *              "RE: <TYPE> LEAVE"; "...to enable you spend <n> working days on <type> leave..."; a balance line for the
 *              types that have an entitlement (Sick has none)
 *
 * Signatory modes: `self` (the approver), `acting` (the approver acting in the post: "AG. <TITLE>") and `for` (the HR
 * signatory of the location signs for the chief manager). The default is `acting` when the final approver acted in an acting
 * capacity, else `self`; HR may switch to `for` and back before the first print.
 */
class LeaveLetterService
{
    public const DATE_FORMAT = 'l, F j, Y';

    /** "Your application dated May 5, 2026": the template gives the application date without the weekday. */
    public const APPLICATION_DATE_FORMAT = 'F j, Y';

    /** The date at the top right of the letter: "6 May 2026" (the template leaves the day to be filled in). */
    public const LETTER_DATE_FORMAT = 'j F Y';

    public const MODE_SELF = 'self';

    public const MODE_FOR = 'for';

    public const MODE_ACTING = 'acting';

    protected const ORGANISATION = 'GHANA WATER LIMITED';

    public function __construct(
        protected WorkingDaysCalculator $workingDays,
        protected AnnualEntitlementService $entitlements,
        protected LeaveBalanceService $balances,
        protected LeaveApprovalChainResolver $chain,
        protected LeaveHrContactService $hrScopes,
        protected LeaveLetterSettingsService $settings,
        protected SignatureService $signatures,
    ) {}

    // =================================================================== text helpers (pure)

    public function formatDate(CarbonInterface|string $date): string
    {
        return Carbon::parse($date)->format(self::DATE_FORMAT);
    }

    /** 0-999 in lowercase words, hyphenated as written: 36 -> "thirty-six", 105 -> "one hundred and five". */
    public function numberToWords(int $number): string
    {
        $units = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen',
            'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
        $tens = [2 => 'twenty', 3 => 'thirty', 4 => 'forty', 5 => 'fifty', 6 => 'sixty', 7 => 'seventy', 8 => 'eighty', 9 => 'ninety'];

        if ($number < 0 || $number > 999) {
            return (string) $number;
        }

        if ($number < 20) {
            return $units[$number];
        }

        if ($number < 100) {
            return $tens[intdiv($number, 10)].($number % 10 ? '-'.$units[$number % 10] : '');
        }

        $rest = $number % 100;

        return $units[intdiv($number, 100)].' hundred'.($rest ? ' and '.$this->numberToWords($rest) : '');
    }

    /** "thirty-six (36)": the words in lowercase with the figure in brackets. */
    public function figure(int $number): string
    {
        return $this->numberToWords($number).' ('.$number.')';
    }

    /** "five (5) working days", "one (1) working day". */
    public function workingDaysPhrase(int $days): string
    {
        return $this->figure($days).' working '.($days === 1 ? 'day' : 'days');
    }

    /** "Dear Sir,", "Dear Madam," or, when neither the title nor the gender says, "Dear Sir/Madam,". */
    public function salutation(Employee $employee): string
    {
        $title = trim((string) $employee->title);

        return match (true) {
            $title === 'Mr.' => 'Dear Sir,',
            in_array($title, ['Mrs.', 'Ms.', 'Miss', 'Hajia'], true) => 'Dear Madam,',
            strcasecmp((string) $employee->gender, 'Male') === 0 => 'Dear Sir,',
            strcasecmp((string) $employee->gender, 'Female') === 0 => 'Dear Madam,',
            default => 'Dear Sir/Madam,',
        };
    }

    public function displayName(Employee $employee, bool $upper = false): string
    {
        $name = $upper ? mb_strtoupper((string) $employee->full_name) : (string) $employee->full_name;

        return trim(($employee->title ? $employee->title.' ' : '').$name);
    }

    // =================================================================== access

    /** Approved requests only. The applicant, the approvers on it, HR in scope, Global Admin and super_admin. */
    public function canView(User $user, LeaveRequest $request): bool
    {
        if ($request->leave_status !== 'Approved') {
            return false;
        }

        $employee = $this->chain->employeeOf($user);
        $requester = $request->requester;

        if (($employee && $employee->id === (int) $request->requester_id)
            || ($requester && $this->chain->userOf($requester)?->id === $user->id)) {
            return true;
        }

        if ((int) $request->manager_user_id === $user->id
            || (int) $request->chief_user_id === $user->id
            || ($employee && ((int) $request->manager_id === $employee->id || (int) $request->approved_by_id === $employee->id))) {
            return true;
        }

        return $this->canEdit($user, $request);
    }

    /** HR in scope: Head Office HR, Global Admin and super_admin anywhere; regional HR for their region's staff only. */
    public function canEdit(User $user, LeaveRequest $request): bool
    {
        if ($user->hasRoles('super_admin', 'admin', 'hr_headoffice')) {
            return true;
        }

        if ($user->hasRoles('hr_region') && $request->requester) {
            $region = $this->chain->employeeOf($user)?->region_id;

            return $region !== null && $this->hrScopes->scopeFor($request->requester) === (int) $region;
        }

        return false;
    }

    // =================================================================== generation

    /**
     * The letter for a finally-approved request (one per request; calling again returns the existing one).
     *
     * @param  User|null  $approver  who gave the final approval (defaults to the request's chief_user_id)
     * @param  bool  $applySignature  the approver chose "Apply my saved signature": it goes on only if they have one
     */
    public function generate(LeaveRequest $request, ?User $approver = null, bool $applySignature = false): LeaveLetter
    {
        if ($existing = $request->letter()->first()) {
            return $existing;
        }

        $letter = LeaveLetter::query()->create($this->build($request, $approver, $applySignature));

        AuditLog::record('leave_letter_generated', 'leave', 'leave_letters', $letter->id, null, null, [
            'leave_request_id' => $request->id,
            'signatory_mode' => $letter->signatory_mode,
            'signature_authorized' => $letter->signature_authorized,
        ]);

        return $letter;
    }

    /**
     * HR rebuilds a letter that was never generated (generation failed) or has not been printed yet, from the request as it is
     * now. A printed letter can't be regenerated.
     *
     * @throws AuthorizationException
     * @throws RuntimeException
     */
    public function regenerate(User $actor, LeaveRequest $request): LeaveLetter
    {
        if (! $this->canEdit($actor, $request)) {
            throw new AuthorizationException('You are not allowed to regenerate this letter.');
        }

        if ($request->leave_status !== 'Approved') {
            throw new RuntimeException('Only an approved leave request has a letter.');
        }

        $existing = $request->letter()->first();

        if ($existing?->isLocked()) {
            throw new RuntimeException('This letter has been printed; it can no longer be regenerated.');
        }

        $attributes = $this->build($request, $request->chiefUser, false);

        if ($existing) {
            $attributes['reference_no'] = $existing->reference_no;
            $existing->update($attributes);
            $letter = $existing->fresh();
        } else {
            $letter = LeaveLetter::query()->create($attributes);
        }

        AuditLog::record('leave_letter_regenerated', 'leave', 'leave_letters', $letter->id, null, null, ['leave_request_id' => $request->id, 'actor_user_id' => $actor->id]);

        return $letter;
    }

    /**
     * Change what HR may change before the first print.
     *
     * @param  array{reference_no?: ?string, cc?: array<int, string>|string, signatory_mode?: string, christmas?: bool}  $data
     *
     * @throws AuthorizationException
     * @throws RuntimeException
     * @throws ValidationException
     */
    public function update(User $actor, LeaveLetter $letter, array $data): LeaveLetter
    {
        $request = $letter->leaveRequest()->with(['requester.jobTitle', 'requester.department', 'chiefUser'])->firstOrFail();

        if (! $this->canEdit($actor, $request)) {
            throw new AuthorizationException('You are not allowed to edit this letter.');
        }

        if ($letter->isLocked()) {
            throw new RuntimeException('This letter has been printed; its details are locked.');
        }

        $old = $this->editable($letter);
        $snapshot = $letter->snapshot;
        $attributes = [];

        if (array_key_exists('reference_no', $data)) {
            $reference = trim((string) $data['reference_no']);

            if (mb_strlen($reference) > 100) {
                throw ValidationException::withMessages(['reference_no' => 'The reference number is too long.']);
            }

            $attributes['reference_no'] = $reference === '' ? null : $reference;
        }

        if (array_key_exists('cc', $data)) {
            $snapshot['cc'] = $this->settings->lines($data['cc']);
        }

        if (array_key_exists('christmas', $data)) {
            // Only a letter the deduction applies to has the line at all.
            $snapshot['compulsory']['include'] = (bool) ($snapshot['compulsory']['applies'] ?? false) && (bool) $data['christmas'];
        }

        if (isset($data['signatory_mode']) && $data['signatory_mode'] !== $letter->signatory_mode) {
            $mode = $data['signatory_mode'];
            $default = $this->defaultMode($request);

            if (! in_array($mode, [$default, self::MODE_FOR], true)) {
                throw ValidationException::withMessages(['signatory_mode' => 'Choose the approver, or HR signing for the chief manager.']);
            }

            $lh = $this->settings->letterheadFor($this->hrScopes->scopeFor($request->requester));
            $signatory = $this->signatory($request, $mode, $lh);

            $snapshot['signatory'] = $signatory;
            $attributes['signatory_mode'] = $mode;
            $attributes['signer_user_id'] = $signatory['signer_user_id'];
            // A different person signs now: nobody's signature stays authorised on their behalf.
            $attributes['signature_authorized'] = false;
            $attributes['signature_id'] = null;
        }

        $letter->update($attributes + ['snapshot' => $snapshot]);
        $letter = $letter->fresh();

        AuditLog::record('leave_letter_updated', 'leave', 'leave_letters', $letter->id, $old, $this->editable($letter), ['leave_request_id' => $request->id]);

        return $letter;
    }

    /**
     * The signer puts their own saved signature on the letter, before the first print. Only the signer can: the approver (self,
     * acting) or, in "for" mode, the HR signatory. Nobody else, an admin included, can apply it for them.
     *
     * @throws AuthorizationException
     * @throws RuntimeException
     */
    public function applySignature(User $user, LeaveLetter $letter): LeaveLetter
    {
        if ($letter->isLocked()) {
            throw new RuntimeException('This letter has been printed; its signature can no longer be changed.');
        }

        if ($letter->signer_user_id === null || $letter->signer_user_id !== $user->id) {
            throw new AuthorizationException('Only the person signing this letter can apply their signature.');
        }

        $signature = $this->signatures->activeFor($user);

        if (! $signature) {
            throw new RuntimeException('You have no saved signature. Add one under My Signature first.');
        }

        $letter->update(['signature_authorized' => true, 'signature_id' => $signature->id]);

        AuditLog::record('leave_letter_signature_applied', 'leave', 'leave_letters', $letter->id, null, null, [
            'leave_request_id' => $letter->leave_request_id,
            'signer_user_id' => $user->id,
        ]);

        return $letter->fresh();
    }

    /** A print or reprint: counts it, locks the letter and audits it. */
    public function recordPrint(LeaveLetter $letter, User $user): LeaveLetter
    {
        $first = $letter->first_printed_at === null;

        $letter->update([
            'printed_count' => $letter->printed_count + 1,
            'first_printed_at' => $letter->first_printed_at ?? now(),
            'last_printed_by' => $user->id,
        ]);

        AuditLog::record($first ? 'leave_letter_printed' : 'leave_letter_reprinted', 'leave', 'leave_letters', $letter->id, null, null, [
            'leave_request_id' => $letter->leave_request_id,
            'printed_count' => $letter->printed_count + 0,
        ]);

        return $letter->fresh();
    }

    // =================================================================== rendering

    /**
     * What the print view needs: the frozen snapshot, the paragraphs built from it, and the signature as a data URI only when
     * it was authorised and has not been revoked (otherwise the signing space stays blank).
     *
     * @return array{snapshot: array<string, mixed>, paragraphs: array<string, string|null>, reference_no: ?string, signature: ?string, signature_revoked: bool}
     */
    public function render(LeaveLetter $letter): array
    {
        $letter->loadMissing('signature');
        $signature = $letter->signature_authorized ? $letter->signature : null;
        $uri = $this->signatures->dataUri($signature);

        return [
            'snapshot' => $letter->snapshot,
            'paragraphs' => $this->paragraphs($letter->snapshot),
            'reference_no' => $letter->reference_no,
            'signature' => $uri,
            'signature_size' => $uri && $signature ? $this->signatureSize($signature->width, $signature->height) : null,
            'signature_revoked' => $letter->signature_authorized && $signature !== null && $signature->isRevoked(),
            'logo' => $this->logoDataUri(),
        ];
    }

    /** The print view as HTML (what the PDF is made from). */
    public function html(LeaveLetter $letter): string
    {
        return view('leave.letters.print', $this->render($letter))->render();
    }

    /** Millimetres the signature takes on the page: at most 60 wide by 25 tall, in proportion. @return array{0: float, 1: float} */
    public function signatureSize(int $width, int $height): array
    {
        $scale = min(60 / max(1, $width), 25 / max(1, $height));

        return [round($width * $scale, 1), round($height * $scale, 1)];
    }

    protected function logoDataUri(): ?string
    {
        $path = public_path('images/gwlnew.png');

        return is_file($path) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($path)) : null;
    }

    /**
     * The body of the letter from the snapshot's facts.
     *
     * @param  array<string, mixed>  $s
     * @return array<string, string|null> application, approval, dates, christmas (nullable), balance (nullable)
     */
    public function paragraphs(array $s): array
    {
        $leave = $s['leave'];
        $retro = $leave['retrospective'] ? 'retrospective ' : '';
        $days = $this->workingDaysPhrase($leave['days']);

        $approval = $leave['type'] === 'Annual'
            ? sprintf(
                'Approval is given to enable you spend %s from your %d annual leave entitlement of %s with %seffect from %s.',
                $days, $leave['year'], $this->workingDaysPhrase($leave['entitlement']), $retro, $this->formatDate($leave['start'])
            )
            : sprintf(
                'Approval is given to enable you spend %s on %s leave with %seffect from %s.',
                $days, mb_strtolower($leave['type']), $retro, $this->formatDate($leave['start'])
            );

        $dates = $leave['ended']
            ? sprintf('Your %s leave ended on %s, and you were expected to resume duty on %s.', $days, $this->formatDate($leave['end']), $this->formatDate($leave['resume']))
            : sprintf('Your %s leave will end on %s, and you are expected to resume duty on %s.', $days, $this->formatDate($leave['end']), $this->formatDate($leave['resume']));

        $compulsory = $s['compulsory'] ?? [];
        $christmas = ($compulsory['applies'] ?? false) && ($compulsory['include'] ?? false)
            ? sprintf('Please note that the %s days Christmas break granted by management has also been deducted from your %d annual leave.', $this->figure($compulsory['days']), $leave['year'])
            : null;

        $balance = $s['balance'] ?? [];
        $remaining = ($balance['show'] ?? false)
            ? sprintf(
                'You now have %s of %sleave remaining for %d.',
                $this->workingDaysPhrase($balance['remaining']),
                $leave['type'] === 'Annual' ? '' : mb_strtolower($leave['type']).' ',
                $leave['year']
            )
            : null;

        return [
            'application' => sprintf('Your application dated %s, on the above subject refers.', Carbon::parse($s['application_date'])->format(self::APPLICATION_DATE_FORMAT)),
            'approval' => $approval,
            'dates' => $dates,
            'christmas' => $christmas,
            'balance' => $remaining,
        ];
    }

    /** The modes HR can pick between for a request: its default, and "for" when the location has an HR signatory. @return list<string> */
    public function modesFor(LeaveRequest $request): array
    {
        $lh = $this->settings->letterheadFor($this->hrScopes->scopeFor($request->requester));

        return array_values(array_filter([$this->defaultMode($request), $lh->hr_signatory_user_id ? self::MODE_FOR : null]));
    }

    // =================================================================== building the snapshot

    /** @return array<string, mixed> the attributes of a leave_letters row */
    protected function build(LeaveRequest $request, ?User $approver, bool $applySignature): array
    {
        $request->loadMissing(['requester.jobTitle', 'requester.department', 'requester.district', 'requester.region', 'requester.user.roles', 'requester.userByStaffId.roles', 'approvedBy', 'chiefUser']);

        $employee = $request->requester;
        $approver ??= $request->chiefUser;
        $year = (int) $request->request_year;
        $approvedOn = ($request->decided_at ?? now())->copy()->startOfDay();
        $days = (int) $request->total_days_applied;
        $start = $request->start_date->copy()->startOfDay();
        $end = $this->lastWorkingDayOnOrBefore($request->end_date, $start);
        $resume = $this->nextWorkingDayAfter($request->end_date);
        $type = (string) $request->leave_type;

        $lh = $this->settings->letterheadFor($this->hrScopes->scopeFor($employee));
        $mode = $this->defaultMode($request);
        $signatory = $this->signatory($request, $mode, $lh);

        $figures = $type === 'Annual' ? $this->entitlements->figuresFor($employee, $year) : null;
        $net = $figures['net'] ?? null;
        $compulsoryDays = (int) ($figures['compulsory'] ?? 0);
        $remaining = $type === 'Sick' ? null : $this->balances->getVirtualRemaining($employee, $type, $year);

        $thro = $request->is_single_stage ? null : $this->throLine($this->chain->recommenderRoleFor($employee, $this->chain->userOf($employee)));

        $snapshot = [
            'version' => 1,
            'letter_date' => $approvedOn->toDateString(),
            'letterhead' => [
                'region_name' => $lh->region_name,
                'address_lines' => array_values((array) $lh->address_lines),
                'is_head_office' => $lh->isHeadOffice(),
            ],
            'company' => $this->settings->company(),
            // As in the template: name, designation and station in capitals. Regional office staff are addressed to their
            // region ("ACCRA WEST REGION") without the company line; district and Head Office staff to the company and their
            // district, unit or department.
            'addressee' => [
                'name' => $this->displayName($employee, upper: true),
                'designation' => filled($employee->jobTitle?->job_title_name) ? mb_strtoupper($employee->jobTitle->job_title_name) : null,
                'thro' => $thro,
                'organisation' => $employee->location_type === 'Region' ? null : self::ORGANISATION,
                'station' => ($station = $employee->location_type === 'Region' ? $lh->region_name : $this->station($employee)) ? mb_strtoupper($station) : null,
                'salutation' => $this->salutation($employee),
            ],
            'application_date' => ($request->submitted_at ?? $request->created_at ?? $approvedOn)->toDateString(),
            'subject' => $this->subject($type, $days, $net),
            'leave' => [
                'type' => $type,
                'year' => $year,
                'days' => $days,
                'entitlement' => $figures['gross'] ?? null,
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'resume' => $resume->toDateString(),
                'retrospective' => $start->lt($approvedOn),
                'ended' => $end->lt($approvedOn),
            ],
            // The Christmas line is on by default wherever a compulsory deduction applies; HR can untick it before the first print.
            'compulsory' => [
                'applies' => $compulsoryDays > 0,
                'days' => $compulsoryDays,
                'include' => $compulsoryDays > 0,
            ],
            'balance' => [
                'show' => $remaining !== null,
                'remaining' => $remaining,
            ],
            'signatory' => $signatory,
            'cc' => array_values((array) $lh->default_cc),
        ];

        $authorize = $applySignature && $signatory['signer_user_id'] !== null && $signatory['signer_user_id'] === $approver?->id;
        $signature = $authorize ? $this->signatures->activeFor($approver) : null;

        return [
            'leave_request_id' => $request->id,
            'reference_no' => null,
            'issued_at' => $approvedOn,
            'snapshot' => $snapshot,
            'signatory_mode' => $mode,
            'signer_user_id' => $signatory['signer_user_id'],
            'signature_authorized' => $signature !== null,
            'signature_id' => $signature?->id,
        ];
    }

    protected function defaultMode(LeaveRequest $request): string
    {
        return $request->final_approver_capacity === 'acting' ? self::MODE_ACTING : self::MODE_SELF;
    }

    /**
     * Who signs and how the block under "Yours faithfully," reads.
     *
     * @return array{mode: string, name: string, title: string, for_line: ?string, signer_user_id: ?int}
     *
     * @throws RuntimeException when "for" is asked and the location has no HR signatory
     */
    protected function signatory(LeaveRequest $request, string $mode, LeaveLetterhead $lh): array
    {
        $applicant = $request->requester;
        $approver = $request->chiefUser;
        $approverEmployee = $this->chain->employeeOf($approver) ?? $request->approvedBy;
        $role = $this->chain->approverRoleFor($applicant, $this->chain->userOf($applicant));
        $post = $this->postTitle($role, $approverEmployee ?? $applicant);

        if ($mode === self::MODE_FOR) {
            $hr = $lh->hr_signatory_user_id ? User::query()->with(['employee.jobTitle', 'employeeByStaffId.jobTitle'])->find($lh->hr_signatory_user_id) : null;

            if (! $hr) {
                throw new RuntimeException('No HR signatory is set for this location. Set one in Letter Settings first.');
            }

            $hrEmployee = $this->chain->employeeOf($hr);

            return [
                'mode' => self::MODE_FOR,
                'name' => mb_strtoupper($hrEmployee ? $this->displayName($hrEmployee) : (string) $hr->full_name),
                'title' => mb_strtoupper((string) ($hrEmployee?->jobTitle?->job_title_name ?? 'Human Resources')),
                'for_line' => 'For: '.$post,
                'signer_user_id' => $hr->id,
            ];
        }

        return [
            'mode' => $mode,
            'name' => mb_strtoupper($approverEmployee ? $this->displayName($approverEmployee) : (string) $approver?->full_name),
            'title' => $mode === self::MODE_ACTING ? 'AG. '.$post : $post,
            'for_line' => null,
            'signer_user_id' => $approver?->id,
        ];
    }

    protected function postTitle(string $role, Employee $approver): string
    {
        return match ($role) {
            'regional_chief_manager' => 'REGIONAL CHIEF MANAGER',
            'managing_director' => 'MANAGING DIRECTOR',
            default => 'CHIEF MANAGER'.(($department = $approver->loadMissing('department')->department?->department_name) ? ', '.mb_strtoupper($department) : ''),
        };
    }

    protected function throLine(?string $role): ?string
    {
        return match ($role) {
            'district_manager' => 'THRO’ THE DISTRICT MANAGER',
            'departmental_manager' => 'THRO’ THE DEPARTMENTAL MANAGER',
            'manager' => 'THRO’ THE UNIT MANAGER',
            default => null,
        };
    }

    /** The district or station line: a district's name, or at Head Office the unit (else the department). */
    protected function station(Employee $employee): ?string
    {
        if ($employee->location_type === 'HeadOffice') {
            return filled($employee->unit) ? $employee->unit : $employee->department?->department_name;
        }

        return $employee->district?->district_name;
    }

    protected function subject(string $type, int $days, ?int $netEntitlement): string
    {
        if ($type === 'Annual') {
            return $netEntitlement !== null && $days < $netEntitlement ? 'RE: REQUEST FOR PART LEAVE' : 'RE: ANNUAL LEAVE';
        }

        return 'RE: '.mb_strtoupper($type).' LEAVE';
    }

    protected function isWorkingDay(Carbon $date): bool
    {
        return $this->workingDays->workingDays($date, $date) === 1;
    }

    /** The last day of the leave that is a working day (the stored end date may be a weekend or a holiday). */
    protected function lastWorkingDayOnOrBefore(CarbonInterface $endDate, Carbon $floor): Carbon
    {
        $date = Carbon::parse($endDate)->startOfDay();

        while ($date->gt($floor) && ! $this->isWorkingDay($date)) {
            $date->subDay();
        }

        return $date;
    }

    /** The next working day after the stored end date. */
    protected function nextWorkingDayAfter(CarbonInterface $endDate): Carbon
    {
        $date = Carbon::parse($endDate)->startOfDay()->addDay();

        for ($guard = 0; $guard < 60 && ! $this->isWorkingDay($date); $guard++) {
            $date->addDay();
        }

        return $date;
    }

    /** @return array<string, mixed> */
    protected function editable(LeaveLetter $letter): array
    {
        return [
            'reference_no' => $letter->reference_no,
            'cc' => $letter->snapshot['cc'] ?? [],
            'signatory_mode' => $letter->signatory_mode,
            'christmas' => (bool) ($letter->snapshot['compulsory']['include'] ?? false),
        ];
    }
}
