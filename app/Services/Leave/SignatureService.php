<?php

namespace App\Services\Leave;

use App\Models\AuditLog;
use App\Models\LeaveLetter;
use App\Models\User;
use App\Models\UserSignature;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Saved signatures for approval letters.
 *
 * A signature is drawn on a canvas or uploaded, validated as a real image, re-encoded to a PNG (which drops all metadata),
 * scaled down, given a transparent background, encrypted and written to a private disk (config gwl.signature_disk, which has
 * no URL). Nothing here ever returns, logs or audits the image: audit rows carry the event, the actor, the owner and the
 * method only. Each person has at most one active signature; a replaced one is kept, inactive, only while a printed letter
 * still points at it.
 *
 * Only the owner can save, replace or delete their own signature (and must confirm their password to save or replace it).
 * Global Admin and super_admin can only REVOKE one, e.g. for a compromised account; they can never view, upload or apply it.
 * A revoked signature is never rendered again.
 */
class SignatureService
{
    public const PERMISSION = 'leave.sign_letters';

    /** The roles whose holders sign letters (as approver, or as the HR signatory). */
    public const ROLES = ['chief_manager', 'regional_chief_manager', 'managing_director', 'hr_region', 'hr_headoffice'];

    protected const ALLOWED_TYPES = [IMAGETYPE_PNG, IMAGETYPE_JPEG];

    // ------------------------------------------------------------------ who

    /** Can $user keep a signature? A signer role (with the permission) or someone currently acting in a post. super_admin never. */
    public function canSign(User $user): bool
    {
        if ($user->hasRoles('super_admin')) {
            return false;
        }

        return ($user->hasRoles(...self::ROLES) && $user->hasPermission(self::PERMISSION))
            || app(LeaveActingAssignmentService::class)->hasActiveAssignment($user);
    }

    /** Global Admin and super_admin may revoke anyone's signature. */
    public function canRevokeOthers(User $user): bool
    {
        return $user->hasRoles('super_admin', 'admin');
    }

    // ------------------------------------------------------------------ reading

    public function activeFor(User $user): ?UserSignature
    {
        return UserSignature::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->whereNull('revoked_at')
            ->latest('id')
            ->first();
    }

    /** The PNG as a data URI for the print response, or null when it is revoked, missing or unreadable (a blank signing space). */
    public function dataUri(?UserSignature $signature): ?string
    {
        $png = $this->bytes($signature);

        return $png === null ? null : 'data:image/png;base64,'.base64_encode($png);
    }

    protected function bytes(?UserSignature $signature): ?string
    {
        if (! $signature || $signature->isRevoked()) {
            return null;
        }

        try {
            $stored = $this->disk()->get($signature->storage_path);

            return $stored === null ? null : Crypt::decryptString($stored);
        } catch (DecryptException) {
            return null;
        }
    }

    // ------------------------------------------------------------------ writing

    /**
     * @param  string  $dataUrl  "data:image/png;base64,..." from the signature pad
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function saveDrawn(User $user, string $dataUrl, string $password): UserSignature
    {
        $this->authorizeOwner($user);
        $this->confirmPassword($user, $password);

        if (! preg_match('#^data:image/png;base64,([A-Za-z0-9+/=\s]+)$#', $dataUrl, $match)) {
            throw ValidationException::withMessages(['signature' => 'Draw your signature first.']);
        }

        $raw = base64_decode(preg_replace('/\s+/', '', $match[1]), true);

        if ($raw === false) {
            throw ValidationException::withMessages(['signature' => 'That drawing could not be read. Try again.']);
        }

        return $this->store($user, $raw, UserSignature::METHOD_DRAWN);
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function saveUploaded(User $user, UploadedFile $file, string $password): UserSignature
    {
        $this->authorizeOwner($user);
        $this->confirmPassword($user, $password);

        $raw = (string) @file_get_contents($file->getRealPath());

        return $this->store($user, $raw, UserSignature::METHOD_UPLOADED);
    }

    /** Delete (revoke) the user's own signature. */
    public function deleteOwn(User $user): void
    {
        $this->authorizeOwner($user);
        $this->revokeAll($user, $user);
    }

    /**
     * Revoke every signature of $target: by themselves, or by Global Admin / super_admin. Revoked signatures are never
     * rendered on any print or reprint again.
     *
     * @throws AuthorizationException
     */
    public function revoke(User $actor, User $target): void
    {
        if ($actor->id !== $target->id && ! $this->canRevokeOthers($actor)) {
            throw new AuthorizationException('You can only revoke your own signature.');
        }

        $this->revokeAll($actor, $target);
    }

    // ------------------------------------------------------------------ internals

    protected function authorizeOwner(User $user): void
    {
        if (! $this->canSign($user)) {
            throw new AuthorizationException('You are not allowed to keep a signature.');
        }
    }

    protected function confirmPassword(User $user, string $password): void
    {
        if ($password === '' || ! Hash::check($password, (string) $user->password)) {
            throw ValidationException::withMessages(['password' => 'Your password is not correct.']);
        }
    }

    /** @throws ValidationException */
    protected function store(User $user, string $raw, string $method): UserSignature
    {
        [$png, $width, $height] = $this->process($raw);

        $path = $user->id.'/'.Str::uuid()->toString().'.sig';
        $hash = hash('sha256', $png);

        $previous = null;

        $signature = DB::transaction(function () use ($user, $path, $hash, $width, $height, $method, $png, &$previous) {
            $previous = UserSignature::query()->where('user_id', $user->id)->where('is_active', true)->get();
            UserSignature::query()->where('user_id', $user->id)->where('is_active', true)->update(['is_active' => false]);

            $this->disk()->put($path, Crypt::encryptString($png));

            return UserSignature::query()->create([
                'user_id' => $user->id,
                'storage_path' => $path,
                'sha256' => $hash,
                'width' => $width,
                'height' => $height,
                'method' => $method,
                'is_active' => true,
            ]);
        });

        foreach ($previous as $old) {
            $this->discardIfUnreferenced($old);
        }

        // The event only: never the image, its path or its hash.
        AuditLog::record(
            $previous->isNotEmpty() ? 'leave_signature_replaced' : 'leave_signature_set',
            'leave',
            'user_signatures',
            $signature->id,
            null,
            null,
            ['target_user_id' => $user->id, 'method' => $method]
        );

        return $signature;
    }

    protected function revokeAll(User $actor, User $target): void
    {
        $signatures = UserSignature::query()->where('user_id', $target->id)->whereNull('revoked_at')->get();

        if ($signatures->isEmpty()) {
            return;
        }

        UserSignature::query()->whereIn('id', $signatures->pluck('id'))->update([
            'is_active' => false,
            'revoked_at' => now(),
            'revoked_by' => $actor->id,
        ]);

        foreach ($signatures as $signature) {
            $this->discardIfUnreferenced($signature->fresh(), true);
        }

        AuditLog::record(
            'leave_signature_revoked',
            'leave',
            'user_signatures',
            $signatures->last()->id,
            null,
            null,
            ['target_user_id' => $target->id, 'actor_user_id' => $actor->id, 'self' => $actor->id === $target->id]
        );
    }

    /**
     * Remove a signature's file (and row) when no letter points at it. A letter that does keeps its row so the earlier version
     * is still what it prints (unless revoked: a revoked signature is never rendered, and its file goes whether or not a letter
     * still references the row).
     */
    protected function discardIfUnreferenced(UserSignature $signature, bool $revoked = false): void
    {
        $referenced = LeaveLetter::query()->where('signature_id', $signature->id)->exists();

        if ($referenced && ! $revoked) {
            return;
        }

        $this->disk()->delete($signature->storage_path);

        // A revoked row stays as the record of who revoked it and when; a merely replaced one nothing uses goes entirely.
        if (! $referenced && ! $revoked) {
            $signature->delete();
        }
    }

    /**
     * Validate $raw as a real PNG/JPEG, scale it down, make its near-white background transparent and re-encode it as PNG.
     *
     * @return array{0: string, 1: int, 2: int} the PNG bytes, width, height
     *
     * @throws ValidationException
     */
    protected function process(string $raw): array
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['signature' => $message]);

        $maxBytes = (int) config('gwl.signature_max_upload_kb', 1024) * 1024;

        if ($raw === '') {
            $fail('The file is empty.');
        }

        if (strlen($raw) > $maxBytes) {
            $fail('The signature must be '.(int) config('gwl.signature_max_upload_kb', 1024).' KB or smaller.');
        }

        // The content decides, not the extension or the browser's claim.
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($raw);
        $info = @getimagesizefromstring($raw);

        if (! in_array($mime, ['image/png', 'image/jpeg'], true) || $info === false || ! in_array($info[2], self::ALLOWED_TYPES, true)) {
            $fail('Upload a PNG or JPG image of your signature.');
        }

        [$sourceWidth, $sourceHeight] = $info;

        if ($sourceWidth < 20 || $sourceHeight < 10 || $sourceWidth > 6000 || $sourceHeight > 6000) {
            $fail('That image is too small or too large to be a signature.');
        }

        $source = @imagecreatefromstring($raw);

        if (! $source) {
            $fail('That image could not be read.');
        }

        $maxWidth = (int) config('gwl.signature_max_width', 600);
        $maxHeight = (int) config('gwl.signature_max_height', 200);
        $scale = min(1, $maxWidth / $sourceWidth, $maxHeight / $sourceHeight);
        $width = max(1, (int) round($sourceWidth * $scale));
        $height = max(1, (int) round($sourceHeight * $scale));

        $target = imagecreatetruecolor($width, $height);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, imagecolorallocatealpha($target, 255, 255, 255, 127));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight);
        imagedestroy($source);

        $ink = 0;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $color = imagecolorat($target, $x, $y);
                $alpha = ($color >> 24) & 0x7F;
                $red = ($color >> 16) & 0xFF;
                $green = ($color >> 8) & 0xFF;
                $blue = $color & 0xFF;

                // Near-white paper becomes transparent (softly, so the edge of a stroke doesn't get a halo).
                $lightest = min($red, $green, $blue);

                if ($alpha < 127 && $lightest >= 200) {
                    $coverage = $lightest >= 235 ? 0.0 : (235 - $lightest) / 35;
                    $alpha = (int) round(127 - (127 - $alpha) * $coverage);
                    imagesetpixel($target, $x, $y, imagecolorallocatealpha($target, $red, $green, $blue, min(127, $alpha)));
                }

                if (((imagecolorat($target, $x, $y) >> 24) & 0x7F) < 100) {
                    $ink++;
                }
            }
        }

        if ($ink < 30) {
            imagedestroy($target);
            $fail('The signature looks empty. Sign in the box, or upload a clearer picture.');
        }

        ob_start();
        imagepng($target, null, 9);
        $png = (string) ob_get_clean();
        imagedestroy($target);

        return [$png, $width, $height];
    }

    protected function disk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk((string) config('gwl.signature_disk', 'leave_signatures'));
    }
}
