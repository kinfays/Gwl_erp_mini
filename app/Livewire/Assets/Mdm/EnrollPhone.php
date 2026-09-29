<?php

namespace App\Livewire\Assets\Mdm;

use App\Livewire\Assets\Mdm\Concerns\AuthorizesMdm;
use App\Models\MdmPolicy;
use App\Services\Assets\Mdm\EnrollmentService;
use App\Services\Assets\Mdm\Exceptions\MdmException;
use App\Services\Assets\Mdm\QrCodeRenderer;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Pick a phone and a policy, get the one-time QR code, and follow the four rollout steps.
 *
 * The asset id comes from a <select> the user could have edited, so generate() never trusts it: the service resolves it
 * again through MdmAccessGuard::enrollableAssets(), which applies the viewer's region scope. The QR is held only in
 * locked, display-only properties and is never stored.
 */
class EnrollPhone extends Component
{
    use AuthorizesMdm;

    public ?int $assetId = null;

    public ?int $policyId = null;

    #[Locked]
    public ?string $qrSvg = null;

    #[Locked]
    public ?string $expiresAtLabel = null;

    #[Locked]
    public ?string $expiresAtIso = null;

    #[Locked]
    public ?string $qrAssetLabel = null;

    public function mount(): void
    {
        $this->bootMdmScreen('assets.mdm_enroll');

        // A single published policy is the obvious choice.
        $only = MdmPolicy::query()->whereNotNull('google_policy_name')->whereNotNull('published_at')->get();

        if ($only->count() === 1) {
            $this->policyId = $only->first()->id;
        }
    }

    public function generate(EnrollmentService $enrollment, QrCodeRenderer $qr): void
    {
        $this->authorizeMdm('assets.mdm_enroll');

        $this->validate([
            'assetId' => ['required', 'integer'],
            'policyId' => ['required', 'integer'],
        ], [
            'assetId.required' => 'Choose the phone to enroll.',
            'policyId.required' => 'Choose a policy.',
        ]);

        $this->clearQr();

        try {
            $result = $enrollment->generate($this->mdmUser(), (int) $this->assetId, (int) $this->policyId);
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (MdmException $e) {
            $this->addError('policy', $e->getMessage());

            return;
        }

        $asset = $result['token']->asset()->first();

        $this->qrSvg = $qr->svg($result['qr_payload']);
        $this->expiresAtIso = $result['expires_at']->toIso8601String();
        $this->expiresAtLabel = $result['expires_at']->format('H:i');
        $this->qrAssetLabel = $asset?->asset_name;

        $this->dispatch('toast', type: 'success', message: 'Enrollment code ready.');
    }

    /** Finish with this phone: hide the QR and get ready for the next one. */
    public function newCode(): void
    {
        $this->clearQr();
        $this->assetId = null;
        $this->resetErrorBag();
    }

    public function render()
    {
        $assets = $this->mdmGuard()->enrollableAssets($this->mdmUser())
            ->with(['assignedTo', 'district'])
            ->orderBy('asset_name')
            ->limit(300)
            ->get();

        $policies = MdmPolicy::query()
            ->whereNotNull('google_policy_name')
            ->whereNotNull('published_at')
            ->orderBy('name')
            ->get();

        return view('livewire.assets.mdm.enroll-phone', [
            'assets' => $assets,
            'policies' => $policies,
            'tokenMinutes' => max(1, (int) config('gwl.mdm_enrollment_token_minutes', 60)),
        ]);
    }

    private function clearQr(): void
    {
        $this->qrSvg = null;
        $this->expiresAtIso = null;
        $this->expiresAtLabel = null;
        $this->qrAssetLabel = null;
    }
}
