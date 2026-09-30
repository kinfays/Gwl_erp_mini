<?php

namespace App\Services\Letters;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LetterScan;
use App\Models\MailLetter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Optional scanned copies of a letter's hardcopy. Everything here is behind config('gwl.letters_scans_enabled').
 *
 * A scan never replaces custody: only someone who holds the letter (confirmed, not closed, the same rule as remarks)
 * can attach one, and the files are stored on a PRIVATE disk under a path the client cannot influence. They are served
 * only by LetterScanController. Voiding hides a scan and keeps the file and the row.
 */
class LetterScanService
{
    /** Content types we accept, and the extension each is stored under. The client's file name is never used in a path. */
    private const EXTENSIONS = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    public function __construct(protected LetterWorkflowService $workflow) {}

    public function enabled(): bool
    {
        return (bool) config('gwl.letters_scans_enabled');
    }

    /** Whether a recipient may read a scan before confirming the hardcopy. */
    public function previewBeforeConfirm(): bool
    {
        return (bool) config('gwl.letters_scan_preview_before_confirm');
    }

    /** The disk scans are written to; refuses the public one (Transport uses it, letters must not). */
    public function disk(): string
    {
        $disk = (string) config('gwl.letters_scan_disk', 'local');

        if ($disk === 'public' || config("filesystems.disks.{$disk}.visibility") === 'public') {
            throw new \RuntimeException('Letter scans must be stored on a private disk, not a public one.');
        }

        return $disk;
    }

    /**
     * Attach $file to $letter. The actor must hold the letter (confirmed, open); the file must be a PDF, JPG or PNG
     * within the size limit; the letter may not already have the maximum number of scans or this exact file.
     *
     * @throws \RuntimeException with a message fit to show the user
     */
    public function add(MailLetter $letter, Employee $actor, UploadedFile $file, string $kind = 'original', ?string $note = null): LetterScan
    {
        $this->assertEnabled();

        if (! array_key_exists($kind, LetterScan::KINDS)) {
            throw new \RuntimeException('Choose what kind of scan this is.');
        }

        $this->assertAcceptable($file);

        $disk = $this->disk();
        $mime = $this->normalizedMime($file);
        $sha256 = hash_file('sha256', $file->getRealPath());
        $max = max(1, (int) config('gwl.letters_scan_max_files', 10));

        return DB::transaction(function () use ($letter, $actor, $file, $kind, $note, $disk, $mime, $sha256, $max) {
            $locked = MailLetter::query()->lockForUpdate()->findOrFail($letter->id);

            $this->assertHolds($locked, $actor, 'attach a scan');

            if ($locked->scans()->active()->count() >= $max) {
                throw new \RuntimeException("A letter can have at most {$max} scans. Void one before adding another.");
            }

            if ($locked->scans()->active()->where('sha256', $sha256)->exists()) {
                throw new \RuntimeException('This file is already attached to this letter.');
            }

            $extension = self::EXTENSIONS[$mime];
            $path = 'letters/scans/'.now()->format('Y/m').'/'.$locked->id.'/'.Str::uuid()->toString().'.'.$extension;

            if (! Storage::disk($disk)->putFileAs(dirname($path), $file, basename($path))) {
                throw new \RuntimeException('The scan could not be saved. Try again.');
            }

            try {
                $scan = LetterScan::create([
                    'letter_id' => $locked->id,
                    'kind' => $kind,
                    'disk' => $disk,
                    'path' => $path,
                    'original_name' => $this->safeName($file, $extension),
                    'mime' => $mime,
                    'size_bytes' => (int) $file->getSize(),
                    'sha256' => $sha256,
                    'note' => filled($note) ? Str::limit(trim($note), 255, '') : null,
                    'uploaded_by_id' => $actor->id,
                ]);

                AuditLog::record('add_letter_scan', 'letters', 'mail_letters', $locked->id, null, [
                    'scan_id' => $scan->id,
                    'kind' => $kind,
                    'name' => $scan->original_name,
                    'size_bytes' => $scan->size_bytes,
                    'sha256' => $sha256,
                ]);
            } catch (\Throwable $e) {
                Storage::disk($disk)->delete($path); // no orphaned file if the record could not be written

                throw $e;
            }

            return $scan;
        });
    }

    /**
     * Hide a scan (the file and the record stay, for the audit trail). The person who attached it while they still hold
     * the letter, the letter's creator, or a super_admin.
     */
    public function void(LetterScan $scan, Employee $actor, string $reason): void
    {
        $this->assertEnabled();

        $reason = trim($reason);

        if ($reason === '') {
            throw new \RuntimeException('Give a reason for voiding this scan.');
        }

        DB::transaction(function () use ($scan, $actor, $reason) {
            $locked = LetterScan::query()->lockForUpdate()->findOrFail($scan->id);

            if ($locked->isVoided()) {
                throw new \RuntimeException('This scan was already voided.');
            }

            if (! $this->canVoid($locked, $actor)) {
                throw new \RuntimeException("Only the person who attached this scan (while they still hold the letter), the letter's creator or an administrator can void it.");
            }

            $locked->update([
                'voided_at' => now(),
                'voided_by_id' => $actor->id,
                'void_reason' => Str::limit($reason, 255, ''),
            ]);

            AuditLog::record('void_letter_scan', 'letters', 'mail_letters', $locked->letter_id, null, [
                'scan_id' => $locked->id,
                'name' => $locked->original_name,
                'sha256' => $locked->sha256,
                'reason' => $locked->void_reason,
            ]);

            $scan->refresh();
        });
    }

    public function canVoid(LetterScan $scan, Employee $actor): bool
    {
        if ($scan->isVoided()) {
            return false;
        }

        $letter = $scan->letter;

        return $letter->created_by_id === $actor->id
            || $this->isSuperAdmin($actor)
            || ($scan->uploaded_by_id === $actor->id && $this->workflow->holdsLetter($letter, $actor));
    }

    /** Whether $actor may attach scans to $letter right now (the holder rule), for showing the upload form. */
    public function canAdd(MailLetter $letter, Employee $actor): bool
    {
        return $this->enabled() && $this->workflow->holdsLetter($letter, $actor);
    }

    protected function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw new \RuntimeException('Letter scans are not enabled.');
        }
    }

    /** The holder rule shared with remarks: confirmed custody of an open letter, nothing pending. */
    protected function assertHolds(MailLetter $letter, Employee $actor, string $what): void
    {
        if ($letter->isClosed()) {
            throw new \RuntimeException("This letter is closed. Re-open it before you {$what}.");
        }

        if ($this->workflow->pendingIncomingRoute($letter, $actor)) {
            throw new \RuntimeException("Confirm hardcopy receipt before you {$what}.");
        }

        if (! $this->workflow->holdsLetter($letter, $actor)) {
            throw new \RuntimeException("Only the current holder of this letter can {$what}.");
        }
    }

    /** Laravel's `mimes` rule checks the file's content, not just its extension; the size limit is in KB. */
    protected function assertAcceptable(UploadedFile $file): void
    {
        $validator = Validator::make(['file' => $file], [
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:'.max(1, (int) config('gwl.letters_scan_max_kb', 10240))],
        ], [
            'file.mimes' => 'Scans must be PDF, JPG or PNG files.',
            'file.max' => 'That file is too large (the limit is '.round(max(1, (int) config('gwl.letters_scan_max_kb', 10240)) / 1024, 1).' MB).',
            'file.file' => 'The file could not be read.',
            'file.uploaded' => 'The file could not be uploaded.',
        ]);

        if ($validator->fails()) {
            throw new \RuntimeException($validator->errors()->first('file'));
        }

        if (! isset(self::EXTENSIONS[$this->normalizedMime($file)])) {
            throw new \RuntimeException('Scans must be PDF, JPG or PNG files.');
        }
    }

    protected function normalizedMime(UploadedFile $file): string
    {
        $mime = strtolower((string) $file->getMimeType());

        return $mime === 'image/jpg' || $mime === 'image/pjpeg' ? 'image/jpeg' : $mime;
    }

    /** The client's name, for display only: no directories, no control characters. */
    protected function safeName(UploadedFile $file, string $extension): string
    {
        $name = basename(str_replace('\\', '/', (string) $file->getClientOriginalName()));
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name));

        return Str::limit($name !== '' ? $name : 'scan.'.$extension, 255, '');
    }

    protected function isSuperAdmin(Employee $actor): bool
    {
        return (bool) (($actor->user ?? $actor->userByStaffId)?->hasRoles('super_admin'));
    }
}
