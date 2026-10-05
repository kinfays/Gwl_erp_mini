<?php

namespace App\Livewire\Leave;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Leave\LeaveLetterService;
use App\Services\Leave\SignatureService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use RuntimeException;

/**
 * The approval letter of one leave request: what it says, who signs, and (for HR in scope, until the first print) the few
 * things that can still change. Printing opens the PDF, which counts as a print and locks the letter.
 */
class ApprovalLetter extends Component
{
    use EnforcesModuleAccess;

    public int $leaveRequestId;

    public string $referenceNo = '';

    /** One cc line per row. */
    public string $cc = '';

    public string $mode = LeaveLetterService::MODE_SELF;

    public bool $christmas = true;

    public function mount(int $leaveRequestId, LeaveLetterService $letters): void
    {
        $this->enforceLivewireModule('leave');

        $this->leaveRequestId = $leaveRequestId;
        $request = $this->request();
        abort_unless($letters->canView($this->user(), $request), 403);

        $this->loadFrom($request);
    }

    public function save(LeaveLetterService $letters): void
    {
        $request = $this->request();
        $letter = $request->letter()->firstOrFail();

        $this->attempt(function () use ($letters, $letter) {
            $letters->update($this->user(), $letter, [
                'reference_no' => $this->referenceNo,
                'cc' => $this->cc,
                'signatory_mode' => $this->mode,
                'christmas' => $this->christmas,
            ]);
        }, 'Letter details saved.');

        $this->loadFrom($this->request());
    }

    /** The signer's own saved signature goes on (before the first print). */
    public function applySignature(LeaveLetterService $letters): void
    {
        $letter = $this->request()->letter()->firstOrFail();

        $this->attempt(fn () => $letters->applySignature($this->user(), $letter), 'Your signature will appear on the printed letter.');
    }

    /** HR makes the letter when it was never generated, or rebuilds it before it has been printed. */
    public function regenerate(LeaveLetterService $letters): void
    {
        $request = $this->request();

        $this->attempt(fn () => $letters->regenerate($this->user(), $request), 'The letter was generated.');

        $this->loadFrom($this->request());
    }

    public function render(LeaveLetterService $letters, SignatureService $signatures)
    {
        $request = $this->request();
        abort_unless($letters->canView($this->user(), $request), 403);

        $letter = $request->letter()->with(['signature', 'signer'])->first();
        $user = $this->user();
        $canEdit = $letters->canEdit($user, $request);

        $signature = null;

        if ($letter) {
            $isSigner = $letter->signer_user_id === $user->id;
            $signature = [
                'authorized' => $letter->signature_authorized,
                'revoked' => $letter->signature_authorized && $letter->signature?->isRevoked(),
                'signer' => $letter->snapshot['signatory']['name'] ?? null,
                'is_signer' => $isSigner,
                'signer_has_signature' => $isSigner && $signatures->activeFor($user) !== null,
                'can_apply' => $isSigner && ! $letter->isLocked() && ! $letter->signature_authorized,
            ];
        }

        return view('livewire.leave.approval-letter', [
            'request' => $request,
            'letter' => $letter,
            'paragraphs' => $letter ? $letters->paragraphs($letter->snapshot) : null,
            'canEdit' => $canEdit,
            'canEditNow' => $canEdit && $letter && ! $letter->isLocked(),
            'modes' => $letter ? $letters->modesFor($request) : [],
            'signature' => $signature,
        ]);
    }

    protected function loadFrom(LeaveRequest $request): void
    {
        $letter = $request->letter()->first();

        if (! $letter) {
            return;
        }

        $this->referenceNo = (string) $letter->reference_no;
        $this->cc = implode("\n", $letter->snapshot['cc'] ?? []);
        $this->mode = $letter->signatory_mode;
        $this->christmas = (bool) ($letter->snapshot['compulsory']['include'] ?? false);
    }

    /** Run an action, showing a refusal as a message instead of an error page. */
    protected function attempt(callable $action, string $success): void
    {
        try {
            $action();
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (ValidationException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            $this->addError('letter', $e->getMessage());
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return;
        }

        $this->resetErrorBag('letter');
        session()->flash('success', $success);
        $this->dispatch('toast', type: 'success', message: $success);
    }

    protected function request(): LeaveRequest
    {
        return LeaveRequest::query()
            ->with(['requester.jobTitle', 'requester.department', 'requester.district', 'requester.user', 'requester.userByStaffId', 'letter'])
            ->findOrFail($this->leaveRequestId);
    }

    protected function user(): User
    {
        $user = auth()->user();

        abort_unless($user, 403);

        return $user;
    }
}
