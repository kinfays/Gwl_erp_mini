<?php

namespace App\Livewire\Leave;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\User;
use App\Services\Leave\SignatureService;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * My Signature: draw or upload the signature that goes on the approval letters this person signs. It is theirs alone: this
 * page only ever handles the signed-in user's own signature, and saving or replacing it asks for their password.
 */
class MySignature extends Component
{
    use EnforcesModuleAccess;
    use WithFileUploads;

    /** "data:image/png;base64,..." from the drawing pad. */
    public string $drawn = '';

    public $upload = null;

    public string $password = '';

    public string $tab = 'draw';

    public function mount(SignatureService $signatures): void
    {
        $this->enforceLivewireModule('leave');
        abort_unless($signatures->canSign($this->user()), 403);
    }

    public function saveDrawn(SignatureService $signatures): void
    {
        $this->guard(function () use ($signatures) {
            $signatures->saveDrawn($this->user(), $this->drawn, $this->password);
        }, 'Signature saved.');

        $this->drawn = '';
    }

    public function saveUpload(SignatureService $signatures): void
    {
        $this->validate([
            'upload' => ['required', 'file', 'mimes:png,jpg,jpeg', 'max:'.(int) config('gwl.signature_max_upload_kb', 1024)],
        ], [
            'upload.required' => 'Choose a PNG or JPG image first.',
            'upload.mimes' => 'Upload a PNG or JPG image of your signature.',
            'upload.max' => 'The signature must be '.(int) config('gwl.signature_max_upload_kb', 1024).' KB or smaller.',
        ]);

        $this->guard(function () use ($signatures) {
            $signatures->saveUploaded($this->user(), $this->upload, $this->password);
        }, 'Signature saved.');

        $this->upload = null;
    }

    public function deleteSignature(SignatureService $signatures): void
    {
        $signatures->deleteOwn($this->user());

        $this->dispatch('toast', type: 'success', message: 'Your signature was deleted.');
    }

    public function render(SignatureService $signatures)
    {
        $current = $signatures->activeFor($this->user());

        return view('livewire.leave.my-signature', [
            'current' => $current,
            'currentImage' => $signatures->dataUri($current),
        ]);
    }

    /** Run a save: a bad image or password shows on the form, a good one clears the password. */
    protected function guard(callable $save, string $success): void
    {
        try {
            $save();
        } catch (ValidationException $e) {
            $this->password = '';

            throw $e;
        }

        $this->password = '';
        $this->resetValidation();
        session()->flash('success', $success);
        $this->dispatch('toast', type: 'success', message: $success);
    }

    protected function user(): User
    {
        $user = auth()->user();

        abort_unless($user, 403);

        return $user;
    }
}
