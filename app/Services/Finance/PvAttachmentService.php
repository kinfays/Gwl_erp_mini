<?php

namespace App\Services\Finance;

use App\Models\FinPaymentVoucher;
use App\Models\FinPvAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Optional scans and supporting documents on a voucher. Checked by CONTENT, not by the name or the type the browser claims:
 * a PDF by its signature, a picture by decoding its header (JPG, PNG, WebP; no SVG). Size and count limits are config. Files
 * live on the private `local` disk under a random name and have no URL: they are served only by the attachment routes, after
 * the voucher is resolved through FinanceScope (404 outside the location). A file name is never put in an audit row.
 */
class PvAttachmentService
{
    public const DISK = 'local';

    /**
     * Checks a batch of new files (count against what is already attached) and returns what each one is.
     *
     * @param  array<int, UploadedFile>  $files
     * @return array<int, array{0: string, 1: string}> mime, extension per file
     */
    public function inspectAll(array $files, int $alreadyAttached = 0): array
    {
        $max = max(1, (int) config('gwl.fin_attachment_max_per_pv'));

        if ($alreadyAttached + count($files) > $max) {
            throw ValidationException::withMessages(['attachments' => "A voucher can have at most {$max} files."]);
        }

        return array_map(fn (UploadedFile $file) => $this->inspect($file), array_values($files));
    }

    /** @return array{0: string, 1: string} mime, extension */
    public function inspect(UploadedFile $file): array
    {
        $limitBytes = max(1, (int) config('gwl.fin_attachment_max_mb')) * 1024 * 1024;

        if (! $file->isValid() || ($file->getSize() ?: 0) > $limitBytes || ($file->getSize() ?: 0) < 1) {
            throw ValidationException::withMessages(['attachments' => 'Each file must be at most '.(int) config('gwl.fin_attachment_max_mb').' MB.']);
        }

        $handle = @fopen($file->getRealPath(), 'rb');
        $head = $handle ? (string) fread($handle, 8) : '';

        if ($handle) {
            fclose($handle);
        }

        if (str_starts_with($head, '%PDF-')) {
            return ['application/pdf', 'pdf'];
        }

        $info = @getimagesize($file->getRealPath());
        $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

        if (! $info || ! isset($types[$info['mime'] ?? ''])) {
            throw ValidationException::withMessages(['attachments' => 'Attach a PDF, or a JPG, PNG or WebP picture.']);
        }

        return [$info['mime'], $types[$info['mime']]];
    }

    /** Stores one inspected file for the voucher. Returns the row; the caller deletes the file if its transaction fails. */
    public function store(FinPaymentVoucher $pv, UploadedFile $file, array $type, User $actor): FinPvAttachment
    {
        $path = Storage::disk(self::DISK)->putFileAs('finance/pvs/'.$pv->id, $file, Str::random(40).'.'.$type[1]);

        if ($path === false) {
            throw ValidationException::withMessages(['attachments' => 'A file could not be saved. Try again.']);
        }

        return FinPvAttachment::query()->create([
            'pv_id' => $pv->id,
            'path' => $path,
            'display_name' => $this->displayName($file, $type[1]),
            'mime' => $type[0],
            'size' => (int) $file->getSize(),
            'uploaded_by' => $actor->id,
        ]);
    }

    public function deleteFile(string $path): void
    {
        Storage::disk(self::DISK)->delete($path);
    }

    private function displayName(UploadedFile $file, string $extension): string
    {
        $name = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $name = trim((string) preg_replace('/[^\p{L}\p{N} ._()-]+/u', '', $name));

        return Str::limit($name !== '' ? $name : 'Document', 100, '').'.'.$extension;
    }
}
