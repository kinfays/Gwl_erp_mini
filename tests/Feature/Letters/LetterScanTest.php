<?php

namespace Tests\Feature\Letters;

use App\Exports\Letters\LetterRegisterExport;
use App\Livewire\Letters\ActiveLetters;
use App\Livewire\Letters\NewLetter;
use App\Livewire\Letters\Transmittals;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LetterScan;
use App\Models\MailLetter;
use App\Services\Letters\LetterRegisterService;
use App\Services\Letters\LetterScanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Letters\Concerns\BuildsLettersOrg;
use Tests\TestCase;

/**
 * Optional scans of the hardcopy (gwl.letters_scans_enabled). Files go on a PRIVATE disk and are only served through
 * letters.scans.show, resolved through the letters the viewer can see.
 */
class LetterScanTest extends TestCase
{
    use BuildsLettersOrg;
    use RefreshDatabase;

    protected Employee $hrSec;

    protected Employee $cmSec;

    protected Employee $matSec;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        $this->buildLettersOrg();
        $this->hrSec = $this->letterStaff('HR001', $this->accraOffice);
        $this->cmSec = $this->letterStaff('CM001', $this->accraOffice);
        $this->matSec = $this->letterStaff('MAT01', $this->accraOffice);
    }

    private function enable(bool $previewBeforeConfirm = false): void
    {
        config([
            'gwl.letters_scans_enabled' => true,
            'gwl.letters_scan_disk' => 'local',
            'gwl.letters_scan_preview_before_confirm' => $previewBeforeConfirm,
        ]);
    }

    private function scans(): LetterScanService
    {
        return app(LetterScanService::class);
    }

    /** A PDF with distinct content each time (so each has its own sha256). */
    private function pdf(string $name = 'scan.pdf', ?string $content = null): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content ?? "%PDF-1.4\n% ".Str::random(40)."\n%%EOF");
    }

    /**
     * A real temp file with the client's own name and no claimed type, so the mime type is sniffed from the content as it
     * is for a browser upload (UploadedFile::fake() takes it from the file name, which would hide a disguised file).
     */
    private function realUpload(string $clientName, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'scan');
        file_put_contents($path, $content);

        return new UploadedFile($path, $clientName, null, null, true);
    }

    /** hr's letter, dispatched to cm and confirmed: cm is the current confirmed holder. */
    private function heldByCm(string $subject = 'Held by cm'): MailLetter
    {
        $letter = $this->createLetter($this->hrSec, ['subject' => $subject]);
        $this->lettersWorkflow()->dispatch($letter, $this->hrSec, $this->cmSec);
        $this->lettersWorkflow()->confirmHardcopy($letter, $this->cmSec);

        return $letter;
    }

    private function as(Employee $employee, string $component = ActiveLetters::class)
    {
        $this->actingAs($this->letterUserOf($employee));

        return Livewire::test($component);
    }

    // ---- the flag ----------------------------------------------------------------------------------------

    public function test_the_defaults_keep_scans_off_on_the_local_disk(): void
    {
        $this->assertFalse(config('gwl.letters_scans_enabled'));
        $this->assertSame('local', config('gwl.letters_scan_disk'));
        $this->assertSame(10240, config('gwl.letters_scan_max_kb'));
        $this->assertSame(10, config('gwl.letters_scan_max_files'));
        $this->assertFalse(config('gwl.letters_scan_preview_before_confirm'));
    }

    public function test_with_the_flag_off_the_service_refuses_the_route_is_404_and_the_ui_is_hidden(): void
    {
        $letter = $this->createLetter($this->hrSec, ['subject' => 'Flag off']);

        try {
            $this->scans()->add($letter, $this->hrSec, $this->pdf());
            $this->fail('add() must refuse when scans are off.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Letter scans are not enabled.', $e->getMessage());
        }

        $this->enable();
        $scan = $this->scans()->add($letter, $this->hrSec, $this->pdf());
        config(['gwl.letters_scans_enabled' => false]);

        try {
            $this->scans()->void($scan, $this->hrSec, 'because');
            $this->fail('void() must refuse when scans are off.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Letter scans are not enabled.', $e->getMessage());
        }

        $this->actingAs($this->letterUserOf($this->hrSec))->get(route('letters.scans.show', $scan))->assertNotFound();

        $this->as($this->hrSec)
            ->call('openLetter', $letter->id)
            ->assertDontSee('Scans of the hardcopy')
            ->assertDontSeeHtml("'scans')")
            ->assertDontSeeHtml('title="1 scan attached"')
            ->call('uploadScans')
            ->assertNotFound();

        $this->as($this->hrSec, NewLetter::class)->assertDontSee('Scans of the hardcopy (optional)');
    }

    // ---- adding ------------------------------------------------------------------------------------------

    public function test_the_creator_can_attach_a_scan_at_intake_and_it_lands_on_the_private_disk_only(): void
    {
        $this->enable();
        $letter = $this->createLetter($this->hrSec);
        $file = $this->realUpload('../../Letter from Ministry.pdf', "%PDF-1.4\n% fixed content\n%%EOF");

        $scan = $this->scans()->add($letter, $this->hrSec, $file, 'original', '  Front page  ');

        $this->assertSame($letter->id, $scan->letter_id);
        $this->assertSame('original', $scan->kind);
        $this->assertSame('local', $scan->disk);
        $this->assertSame('application/pdf', $scan->mime);
        $this->assertSame(hash('sha256', "%PDF-1.4\n% fixed content\n%%EOF"), $scan->sha256);
        $this->assertSame(strlen("%PDF-1.4\n% fixed content\n%%EOF"), $scan->size_bytes);
        $this->assertSame('Front page', $scan->note);
        $this->assertSame($this->hrSec->id, $scan->uploaded_by_id);
        $this->assertSame('Letter from Ministry.pdf', $scan->original_name, 'directories are stripped from the client name');
        $this->assertNull($scan->voided_at);

        $this->assertMatchesRegularExpression('#^letters/scans/'.now()->format('Y/m').'/'.$letter->id.'/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.pdf$#', $scan->path);
        $this->assertStringNotContainsString('Ministry', $scan->path, "the client's file name is never part of the path");
        Storage::disk('local')->assertExists($scan->path);
        $this->assertSame([], Storage::disk('public')->allFiles(), 'nothing on the public disk');
        $this->assertSame([$scan->path], Storage::disk('local')->allFiles());
    }

    public function test_an_image_is_stored_with_a_safe_extension(): void
    {
        $this->enable();
        $letter = $this->createLetter($this->hrSec);

        $scan = $this->scans()->add($letter, $this->hrSec, UploadedFile::fake()->image('phone-photo.JPEG', 40, 40), 'commented');

        $this->assertSame('image/jpeg', $scan->mime);
        $this->assertStringEndsWith('.jpg', $scan->path);
        $this->assertSame('commented', $scan->kind);

        $png = $this->scans()->add($letter, $this->hrSec, UploadedFile::fake()->image('shot.png', 30, 30), 'enclosure');
        $this->assertStringEndsWith('.png', $png->path);
    }

    public function test_the_current_confirmed_holder_who_is_not_the_creator_can_attach_a_scan(): void
    {
        $this->enable();
        $letter = $this->heldByCm();

        $scan = $this->scans()->add($letter, $this->cmSec, $this->pdf(), 'commented');

        $this->assertSame($this->cmSec->id, $scan->uploaded_by_id);
    }

    public function test_only_a_holder_of_an_open_letter_can_attach_a_scan(): void
    {
        $this->enable();
        $letter = $this->heldByCm();
        $incoming = $this->createLetter($this->hrSec, ['subject' => 'Unconfirmed']);
        $this->lettersWorkflow()->dispatch($incoming, $this->hrSec, $this->cmSec);
        $closed = $this->createLetter($this->hrSec, ['subject' => 'Closed']);
        $this->lettersWorkflow()->close($closed, $this->hrSec);

        $cases = [
            'a past holder' => [$letter, $this->hrSec, 'Only the current holder of this letter can attach a scan.'],
            'someone who never held it' => [$letter, $this->matSec, 'Only the current holder of this letter can attach a scan.'],
            'a recipient who has not confirmed' => [$incoming, $this->cmSec, 'Confirm hardcopy receipt before you attach a scan.'],
            'the holder of a closed letter' => [$closed, $this->hrSec, 'This letter is closed. Re-open it before you attach a scan.'],
        ];

        foreach ($cases as $label => [$target, $actor, $message]) {
            try {
                $this->scans()->add($target, $actor, $this->pdf());
                $this->fail("{$label} must not attach a scan.");
            } catch (\RuntimeException $e) {
                $this->assertSame($message, $e->getMessage(), $label);
            }
        }

        $this->assertSame(0, LetterScan::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(0, AuditLog::query()->where('action', 'add_letter_scan')->count());
    }

    public function test_only_pdf_jpg_and_png_up_to_the_size_limit_are_accepted(): void
    {
        $this->enable();
        $letter = $this->createLetter($this->hrSec);

        $bad = [
            'plain text' => $this->realUpload('notes.txt', 'just some text'),
            'an executable renamed to .pdf' => $this->realUpload('invoice.pdf', "MZ\x90\x00\x03\x00\x00\x00 not a pdf"),
            'html renamed to .png' => $this->realUpload('pic.png', '<html><script>alert(1)</script></html>'),
            'an svg' => $this->realUpload('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
        ];

        foreach ($bad as $label => $file) {
            try {
                $this->scans()->add($letter, $this->hrSec, $file);
                $this->fail("{$label} must be rejected.");
            } catch (\RuntimeException $e) {
                $this->assertSame('Scans must be PDF, JPG or PNG files.', $e->getMessage(), $label);
            }
        }

        config(['gwl.letters_scan_max_kb' => 1]);
        try {
            $this->scans()->add($letter, $this->hrSec, UploadedFile::fake()->createWithContent('big.pdf', "%PDF-1.4\n".str_repeat('x', 3000)));
            $this->fail('An oversize file must be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('too large', $e->getMessage());
        }

        $this->assertSame(0, LetterScan::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_the_same_file_cannot_be_attached_twice_to_a_letter_but_can_after_it_is_voided_or_on_another_letter(): void
    {
        $this->enable();
        $letter = $this->createLetter($this->hrSec);
        $other = $this->createLetter($this->hrSec, ['subject' => 'Another letter']);
        $content = "%PDF-1.4\n% same bytes\n%%EOF";

        $first = $this->scans()->add($letter, $this->hrSec, $this->pdf('a.pdf', $content));

        try {
            $this->scans()->add($letter, $this->hrSec, $this->pdf('renamed.pdf', $content));
            $this->fail('The same content on the same letter must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertSame('This file is already attached to this letter.', $e->getMessage());
        }

        $this->scans()->add($other, $this->hrSec, $this->pdf('a.pdf', $content)); // another letter: fine

        $this->scans()->void($first, $this->hrSec, 'Wrong page');
        $this->scans()->add($letter, $this->hrSec, $this->pdf('again.pdf', $content)); // the voided one no longer counts

        $this->assertSame(1, $letter->scans()->active()->count());
        $this->assertSame(2, $letter->scans()->count());
    }

    public function test_a_letter_can_hold_only_so_many_scans(): void
    {
        $this->enable();
        config(['gwl.letters_scan_max_files' => 2]);
        $letter = $this->createLetter($this->hrSec);

        $first = $this->scans()->add($letter, $this->hrSec, $this->pdf());
        $this->scans()->add($letter, $this->hrSec, $this->pdf());

        try {
            $this->scans()->add($letter, $this->hrSec, $this->pdf());
            $this->fail('The third scan must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('at most 2 scans', $e->getMessage());
        }

        $this->scans()->void($first, $this->hrSec, 'Free a slot');
        $this->scans()->add($letter, $this->hrSec, $this->pdf());
        $this->assertSame(2, $letter->scans()->active()->count());
    }

    public function test_an_unknown_kind_is_refused(): void
    {
        $this->enable();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Choose what kind of scan this is.');
        $this->scans()->add($this->createLetter($this->hrSec), $this->hrSec, $this->pdf(), 'secret');
    }

    public function test_scans_never_go_on_a_public_disk(): void
    {
        $this->enable();
        $letter = $this->createLetter($this->hrSec);

        config(['gwl.letters_scan_disk' => 'public']);
        try {
            $this->scans()->add($letter, $this->hrSec, $this->pdf());
            $this->fail('The public disk must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Letter scans must be stored on a private disk, not a public one.', $e->getMessage());
        }

        config(['gwl.letters_scan_disk' => 'shared', 'filesystems.disks.shared' => ['driver' => 'local', 'root' => storage_path('app/shared'), 'visibility' => 'public']]);
        try {
            $this->scans()->add($letter, $this->hrSec, $this->pdf());
            $this->fail('A disk whose visibility is public must be refused.');
        } catch (\RuntimeException) {
        }

        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame(0, LetterScan::query()->count());
    }

    public function test_adding_a_scan_is_audited_and_an_unsaved_scan_leaves_no_file(): void
    {
        $this->enable();
        $letter = $this->createLetter($this->hrSec);

        $scan = $this->scans()->add($letter, $this->hrSec, $this->pdf('audited.pdf'), 'commented');

        $audit = AuditLog::query()->where('action', 'add_letter_scan')->sole();
        $this->assertSame('letters', $audit->module);
        $this->assertSame('mail_letters', $audit->target_type);
        $this->assertSame($letter->id, $audit->target_id);
        $this->assertSame(['scan_id' => $scan->id, 'kind' => 'commented', 'name' => 'audited.pdf', 'size_bytes' => $scan->size_bytes, 'sha256' => $scan->sha256], $audit->new_values);
    }

    // ---- viewing -----------------------------------------------------------------------------------------

    private function open(Employee $viewer, LetterScan $scan)
    {
        return $this->actingAs($this->letterUserOf($viewer))->get(route('letters.scans.show', $scan));
    }

    public function test_the_creator_and_the_holder_can_open_the_scan_with_the_right_headers(): void
    {
        $this->enable();
        $letter = $this->createLetter($this->hrSec);
        $content = "%PDF-1.4\n% visible\n%%EOF";
        $scan = $this->scans()->add($letter, $this->hrSec, $this->pdf('Ministry letter.pdf', $content));

        $response = $this->open($this->hrSec, $scan);

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('Ministry letter.pdf', $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertSame($content, $response->streamedContent());

        // A holder later in the chain, once they have confirmed.
        $this->lettersWorkflow()->dispatch($letter, $this->hrSec, $this->cmSec);
        $this->lettersWorkflow()->confirmHardcopy($letter, $this->cmSec);
        $this->open($this->cmSec, $scan)->assertOk();

        // The sender who dispatched it still sees the letter, so still sees the scan.
        $this->open($this->hrSec, $scan)->assertOk();
    }

    public function test_an_image_is_served_with_its_own_type(): void
    {
        $this->enable();
        $scan = $this->scans()->add($this->createLetter($this->hrSec), $this->hrSec, UploadedFile::fake()->image('p.jpg', 20, 20));

        $this->assertSame('image/jpeg', $this->open($this->hrSec, $scan)->assertOk()->headers->get('Content-Type'));
    }

    public function test_someone_who_cannot_see_the_letter_gets_a_404_and_guests_are_sent_to_login(): void
    {
        $this->enable();
        $scan = $this->scans()->add($this->createLetter($this->hrSec), $this->hrSec, $this->pdf());

        $this->open($this->matSec, $scan)->assertNotFound();

        $noModule = $this->letterStaff('EMP01', $this->accraOffice, ['leave_applicant']);
        $this->open($noModule, $scan)->assertRedirect('/dashboard');
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->enable();
        $scan = $this->scans()->add($this->createLetter($this->hrSec), $this->hrSec, $this->pdf());

        $this->get(route('letters.scans.show', $scan))->assertRedirect(route('login'));
    }

    public function test_a_recipient_must_confirm_the_hardcopy_first_unless_preview_is_switched_on(): void
    {
        $this->enable();
        $letter = $this->createLetter($this->hrSec);
        $scan = $this->scans()->add($letter, $this->hrSec, $this->pdf());
        $this->lettersWorkflow()->dispatch($letter, $this->hrSec, $this->cmSec);

        $this->open($this->cmSec, $scan)->assertForbidden();

        config(['gwl.letters_scan_preview_before_confirm' => true]);
        $this->open($this->cmSec, $scan)->assertOk();

        config(['gwl.letters_scan_preview_before_confirm' => false]);
        $this->lettersWorkflow()->confirmHardcopy($letter, $this->cmSec);
        $this->open($this->cmSec, $scan)->assertOk();
    }

    public function test_a_recalled_hand_over_takes_the_recipients_access_with_it(): void
    {
        $this->enable(previewBeforeConfirm: true);
        $letter = $this->createLetter($this->hrSec);
        $scan = $this->scans()->add($letter, $this->hrSec, $this->pdf());
        $this->lettersWorkflow()->dispatch($letter, $this->hrSec, $this->cmSec);
        $this->open($this->cmSec, $scan)->assertOk();

        $this->lettersWorkflow()->recall(\App\Models\RoutingHistory::query()->where('letter_id', $letter->id)->sole(), $this->hrSec);

        $this->open($this->cmSec, $scan)->assertNotFound();
    }

    public function test_a_missing_file_or_a_public_disk_row_is_a_404(): void
    {
        $this->enable();
        $scan = $this->scans()->add($this->createLetter($this->hrSec), $this->hrSec, $this->pdf());

        Storage::disk('local')->delete($scan->path);
        $this->open($this->hrSec, $scan)->assertNotFound();

        $public = LetterScan::query()->create([
            'letter_id' => $scan->letter_id, 'kind' => 'original', 'disk' => 'public', 'path' => 'x/y.pdf', 'original_name' => 'y.pdf',
            'mime' => 'application/pdf', 'size_bytes' => 10, 'sha256' => str_repeat('a', 64), 'uploaded_by_id' => $this->hrSec->id,
        ]);
        Storage::disk('public')->put('x/y.pdf', 'data');
        $this->open($this->hrSec, $public)->assertNotFound();
    }

    // ---- voiding -----------------------------------------------------------------------------------------

    public function test_voiding_hides_the_scan_but_keeps_the_file_and_the_row(): void
    {
        $this->enable();
        $letter = $this->createLetter($this->hrSec);
        $scan = $this->scans()->add($letter, $this->hrSec, $this->pdf());

        $this->scans()->void($scan, $this->hrSec, '  Wrong letter  ');

        $scan->refresh();
        $this->assertTrue($scan->isVoided());
        $this->assertSame($this->hrSec->id, $scan->voided_by_id);
        $this->assertSame('Wrong letter', $scan->void_reason);
        Storage::disk('local')->assertExists($scan->path);
        $this->assertSame(1, LetterScan::query()->count(), 'nothing is hard-deleted');
        $this->assertSame(0, $letter->scans()->active()->count());
        $this->open($this->hrSec, $scan)->assertNotFound();

        $audit = AuditLog::query()->where('action', 'void_letter_scan')->sole();
        $this->assertSame($letter->id, $audit->target_id);
        $this->assertSame(['scan_id' => $scan->id, 'name' => $scan->original_name, 'sha256' => $scan->sha256, 'reason' => 'Wrong letter'], $audit->new_values);
    }

    public function test_who_may_void(): void
    {
        $this->enable();
        $letter = $this->heldByCm();
        $byCm = $this->scans()->add($letter, $this->cmSec, $this->pdf('cm.pdf'));
        $byCmToo = $this->scans()->add($letter, $this->cmSec, $this->pdf('cm2.pdf'));
        $byCmThree = $this->scans()->add($letter, $this->cmSec, $this->pdf('cm3.pdf'));
        $admin = $this->letterStaff('ADM01', $this->accraOffice, ['super_admin']);

        // The uploader while they still hold the letter.
        $this->scans()->void($byCm, $this->cmSec, 'My mistake');
        $this->assertTrue($byCm->fresh()->isVoided());

        // Not someone who never held it.
        try {
            $this->scans()->void($byCmToo, $this->matSec, 'Not mine');
            $this->fail('A stranger must not void.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Only the person who attached this scan', $e->getMessage());
        }

        // The letter's creator, even though the scan is someone else's.
        $this->scans()->void($byCmToo, $this->hrSec, 'Creator clean-up');
        $this->assertTrue($byCmToo->fresh()->isVoided());

        // A super_admin.
        $this->scans()->void($byCmThree, $admin, 'Administrative');
        $this->assertTrue($byCmThree->fresh()->isVoided());
    }

    public function test_an_uploader_who_no_longer_holds_the_letter_cannot_void(): void
    {
        $this->enable();
        $letter = $this->heldByCm();
        $scan = $this->scans()->add($letter, $this->cmSec, $this->pdf());
        $this->lettersWorkflow()->dispatch($letter, $this->cmSec, $this->matSec);

        $this->expectException(\RuntimeException::class);
        $this->scans()->void($scan, $this->cmSec, 'Too late');
    }

    public function test_a_void_needs_a_reason_and_only_happens_once(): void
    {
        $this->enable();
        $scan = $this->scans()->add($this->createLetter($this->hrSec), $this->hrSec, $this->pdf());

        try {
            $this->scans()->void($scan, $this->hrSec, '   ');
            $this->fail('A reason is required.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Give a reason for voiding this scan.', $e->getMessage());
        }

        $this->scans()->void($scan, $this->hrSec, 'Once');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('This scan was already voided.');
        $this->scans()->void($scan, $this->hrSec, 'Twice');
    }

    // ---- the drawer --------------------------------------------------------------------------------------

    public function test_the_holder_uploads_several_scans_from_the_drawer(): void
    {
        $this->enable();
        $letter = $this->createLetter($this->hrSec, ['subject' => 'Drawer scans']);

        $this->as($this->hrSec)
            ->call('openLetter', $letter->id)
            ->set('detailTab', 'scans')
            ->assertSee('Scans of the hardcopy')
            ->assertSee('Attach scans')
            ->assertSeeHtml('accept="image/*,application/pdf"')
            ->assertSeeHtml('multiple')
            ->assertSeeHtml("camera ? 'environment' : false")
            ->set('scans', [$this->pdf('one.pdf'), $this->pdf('two.pdf')])
            ->set('scanKind', 'commented')
            ->set('scanNote', 'After the CM wrote on it')
            ->call('uploadScans')
            ->assertHasNoErrors()
            ->assertDispatched('toast', type: 'success', message: '2 scans attached.')
            ->assertSet('scans', [])
            ->assertSet('scanNote', '')
            ->assertSee('one.pdf')
            ->assertSee('two.pdf')
            ->assertSee('With comments')
            ->assertSee('After the CM wrote on it')
            ->assertSeeHtml(e(route('letters.scans.show', LetterScan::query()->orderBy('id')->first())));

        $this->assertSame(2, $letter->scans()->active()->count());
        $this->assertSame(['commented', 'commented'], $letter->scans()->pluck('kind')->all());
    }

    public function test_the_drawer_validates_type_size_and_count_per_file(): void
    {
        $this->enable();
        config(['gwl.letters_scan_max_files' => 2, 'gwl.letters_scan_max_kb' => 2]);
        $letter = $this->createLetter($this->hrSec);

        $this->as($this->hrSec)
            ->call('openLetter', $letter->id)
            ->set('detailTab', 'scans')
            ->set('scans', [UploadedFile::fake()->create('notes.txt', 1, 'text/plain')])
            ->call('uploadScans')
            ->assertHasErrors(['scans.0'])
            ->set('scans', [UploadedFile::fake()->createWithContent('big.pdf', "%PDF-1.4\n".str_repeat('x', 5000))])
            ->call('uploadScans')
            ->assertHasErrors(['scans.0'])
            ->set('scans', [$this->pdf('a.pdf'), $this->pdf('b.pdf'), $this->pdf('c.pdf')])
            ->call('uploadScans')
            ->assertHasErrors(['scans'])
            ->set('scans', [])
            ->call('uploadScans')
            ->assertHasErrors(['scans'])
            ->set('scanKind', 'nonsense')
            ->set('scans', [$this->pdf('d.pdf')])
            ->call('uploadScans')
            ->assertHasErrors(['scanKind']);

        $this->assertSame(0, LetterScan::query()->count());
    }

    public function test_one_bad_file_does_not_lose_the_others(): void
    {
        $this->enable();
        $letter = $this->createLetter($this->hrSec);
        $content = "%PDF-1.4\n% duplicate\n%%EOF";
        $this->scans()->add($letter, $this->hrSec, $this->pdf('first.pdf', $content));

        $component = $this->as($this->hrSec)
            ->call('openLetter', $letter->id)
            ->set('detailTab', 'scans')
            ->set('scans', [$this->pdf('dup.pdf', $content), $this->pdf('fresh.pdf')])
            ->call('uploadScans')
            ->assertDispatched('toast', type: 'success', message: '1 scan attached.')
            ->assertDispatched('toast', type: 'error', message: 'dup.pdf: This file is already attached to this letter.');

        $this->assertSame(2, $letter->scans()->count());
    }

    public function test_the_upload_form_is_only_offered_to_the_holder_and_the_action_refuses_anyone_else(): void
    {
        $this->enable();
        $letter = $this->heldByCm();

        $this->as($this->cmSec)->call('openLetter', $letter->id)->set('detailTab', 'scans')->assertSee('Attach scans');

        $this->as($this->hrSec)
            ->call('openLetter', $letter->id)
            ->set('detailTab', 'scans')
            ->assertDontSee('Attach scans')
            ->assertSee('Scans can be added by whoever currently holds this open letter.')
            ->set('scans', [$this->pdf()])
            ->call('uploadScans')
            ->assertDispatched('toast', type: 'error', message: 'scan.pdf: Only the current holder of this letter can attach a scan.');

        $this->assertSame(0, LetterScan::query()->count());
    }

    public function test_the_drawer_voids_with_a_reason_and_only_for_those_who_may(): void
    {
        $this->enable();
        $letter = $this->heldByCm();
        $scan = $this->scans()->add($letter, $this->cmSec, $this->pdf('cm.pdf'));

        // hr is the creator: may void. mat cannot even see the letter.
        $component = $this->as($this->hrSec)
            ->call('openLetter', $letter->id)
            ->set('detailTab', 'scans')
            ->assertSee('cm.pdf')
            ->assertSee('Void')
            ->call('startVoidScan', $scan->id)
            ->assertSee('Why is this scan being voided?')
            ->call('voidScan')
            ->assertHasErrors(['voidReason'])
            ->set('voidReason', 'Not the right letter')
            ->call('voidScan')
            ->assertHasNoErrors()
            ->assertDispatched('toast', type: 'success', message: 'Scan voided. It is hidden; the file is kept.')
            ->assertSet('voidingScanId', null)
            ->assertDontSee('cm.pdf');

        $this->assertTrue($scan->fresh()->isVoided());
        Storage::disk('local')->assertExists($scan->path);
    }

    public function test_a_holder_who_did_not_upload_the_scan_gets_no_void_button(): void
    {
        $this->enable();
        $letter = $this->createLetter($this->hrSec);
        $scan = $this->scans()->add($letter, $this->hrSec, $this->pdf('hr.pdf'));
        $this->lettersWorkflow()->dispatch($letter, $this->hrSec, $this->cmSec);
        $this->lettersWorkflow()->confirmHardcopy($letter, $this->cmSec);

        $this->as($this->cmSec)
            ->call('openLetter', $letter->id)
            ->set('detailTab', 'scans')
            ->assertSee('hr.pdf')
            ->assertDontSeeHtml('startVoidScan('.$scan->id.')')
            ->call('startVoidScan', $scan->id)
            ->set('voidReason', 'I will try anyway')
            ->call('voidScan')
            ->assertDispatched('toast', type: 'error', message: "Only the person who attached this scan (while they still hold the letter), the letter's creator or an administrator can void it.");

        $this->assertFalse($scan->fresh()->isVoided());
    }

    public function test_the_list_shows_a_paperclip_count_of_active_scans(): void
    {
        $this->enable();
        $with = $this->createLetter($this->hrSec, ['subject' => 'Has scans']);
        $without = $this->createLetter($this->hrSec, ['subject' => 'No scans']);
        $this->scans()->add($with, $this->hrSec, $this->pdf());
        $voided = $this->scans()->add($with, $this->hrSec, $this->pdf());
        $this->scans()->add($with, $this->hrSec, $this->pdf());
        $this->scans()->void($voided, $this->hrSec, 'Out');

        $html = $this->as($this->hrSec)->html();

        $this->assertStringContainsString('title="2 scans attached"', $html);
        $this->assertSame(1, substr_count($html, 'scans attached"'), 'only the letter that has scans');
    }

    public function test_a_recipient_reads_the_scan_before_confirming_only_with_the_preview_switch(): void
    {
        $this->enable();
        $letter = $this->createLetter($this->hrSec, ['subject' => 'In transit']);
        $this->scans()->add($letter, $this->hrSec, $this->pdf('transit.pdf'));
        $this->lettersWorkflow()->dispatch($letter, $this->hrSec, $this->cmSec);

        $this->as($this->cmSec)
            ->call('openLetter', $letter->id, true)
            ->assertSet('confirmPrompt', true)
            ->assertDontSee('transit.pdf');

        config(['gwl.letters_scan_preview_before_confirm' => true]);

        $this->as($this->cmSec)
            ->call('openLetter', $letter->id, true)
            ->assertSee('transit.pdf')
            ->assertSee('You can read the scan while the hardcopy is on its way.')
            ->assertSee('Confirm Hardcopy Received');
    }

    // ---- New Letter --------------------------------------------------------------------------------------

    private function fillNewLetter($component, array $scans = [])
    {
        return $component
            ->set('subject', 'With a scan')
            ->set('type', 'External')
            ->set('company_sender', 'Acme Supplies Ltd')
            ->set('date_on_letter', '2026-09-01')
            ->set('region_id', $this->accra->id)
            ->set('scans', $scans);
    }

    public function test_new_letter_offers_a_picker_and_attaches_the_files_after_saving(): void
    {
        $this->enable();

        $component = $this->as($this->hrSec, NewLetter::class)
            ->assertSee('Scans of the hardcopy (optional)')
            ->assertSeeHtml('accept="image/*,application/pdf"');

        $this->fillNewLetter($component, [$this->pdf('intake-1.pdf'), $this->pdf('intake-2.pdf')])
            ->call('save')
            ->assertHasNoErrors();

        $letter = MailLetter::query()->sole();
        $this->assertSame(['intake-1.pdf', 'intake-2.pdf'], $letter->scans()->orderBy('id')->pluck('original_name')->all());
        $this->assertSame(['original', 'original'], $letter->scans()->pluck('kind')->all());
        $this->assertSame($this->hrSec->id, $letter->scans()->first()->uploaded_by_id);
    }

    public function test_new_letter_rejects_a_bad_file_before_anything_is_saved(): void
    {
        $this->enable();

        $this->fillNewLetter($this->as($this->hrSec, NewLetter::class), [UploadedFile::fake()->create('notes.txt', 1, 'text/plain')])
            ->call('save')
            ->assertHasErrors(['scans.0']);

        $this->assertSame(0, MailLetter::query()->count());
    }

    public function test_a_scan_that_cannot_be_attached_does_not_lose_the_new_letter(): void
    {
        $this->enable();
        $content = "%PDF-1.4\n% same\n%%EOF";

        $this->fillNewLetter($this->as($this->hrSec, NewLetter::class), [$this->pdf('a.pdf', $content), $this->pdf('b.pdf', $content)])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, MailLetter::query()->count());
        $this->assertSame(1, LetterScan::query()->count());
        $this->assertStringContainsString('b.pdf: This file is already attached to this letter.', session('success'));
    }

    public function test_new_letter_ignores_files_when_scans_are_off(): void
    {
        $this->fillNewLetter($this->as($this->hrSec, NewLetter::class), [$this->pdf('ignored.pdf')])->call('save')->assertHasNoErrors();

        $this->assertSame(1, MailLetter::query()->count());
        $this->assertSame(0, LetterScan::query()->count());
    }

    // ---- touchpoints in other phases ----------------------------------------------------------------------

    public function test_the_transmittal_sheet_has_a_scan_column_only_with_scans_on(): void
    {
        $with = $this->createLetter($this->hrSec, ['subject' => 'Scanned letter']);
        $without = $this->createLetter($this->hrSec, ['subject' => 'Plain letter']);
        $this->enable();
        $this->scans()->add($with, $this->hrSec, $this->pdf()); // attached before it leaves hr's desk
        config(['gwl.letters_scans_enabled' => false]);
        $batch = $this->lettersWorkflow()->dispatchBatch($this->hrSec, $this->cmSec, [$with->id, $without->id]);
        $render = fn () => view('letters.exports.transmittal-sheet', ['batch' => $batch->fresh()->load(['fromSecretariat.department', 'toSecretariat.department', 'routingHistories.letter' => fn ($l) => $l->with('memoSender')->withCount(['scans as active_scans_count' => fn ($s) => $s->whereNull('voided_at')])])])->render();

        $this->assertStringNotContainsString('<th>Scan</th>', $render());

        $this->enable();
        $html = $render();

        $this->assertStringContainsString('<th>Scan</th>', $html);
        $this->assertSame(1, substr_count($html, '<td>Yes</td>'));

        $this->actingAs($this->letterUserOf($this->hrSec))->get(route('letters.transmittals.sheet', $batch))->assertOk();
    }

    public function test_the_incoming_checklist_links_to_the_scan_only_when_reading_before_confirming_is_allowed(): void
    {
        $this->enable();
        $one = $this->createLetter($this->hrSec, ['subject' => 'One scan']);
        $two = $this->createLetter($this->hrSec, ['subject' => 'Two scans']);
        $none = $this->createLetter($this->hrSec, ['subject' => 'No scan']);
        $scan = $this->scans()->add($one, $this->hrSec, $this->pdf());
        $this->scans()->add($two, $this->hrSec, $this->pdf());
        $this->scans()->add($two, $this->hrSec, $this->pdf());
        $this->lettersWorkflow()->dispatchBatch($this->hrSec, $this->cmSec, [$one->id, $two->id, $none->id]);

        $this->as($this->cmSec, Transmittals::class)
            ->assertDontSee('View scan')
            ->assertDontSeeHtml('<th>Scan</th>');

        config(['gwl.letters_scan_preview_before_confirm' => true]);

        $this->as($this->cmSec, Transmittals::class)
            ->assertSeeHtml('<th>Scan</th>')
            ->assertSee('View scan')
            ->assertSeeHtml(e(route('letters.scans.show', $scan)))
            ->assertSee('View scans (2)')
            ->assertSeeHtml(e(route('letters.active', ['letter' => $two->id, 'prompt' => 1])));
    }

    public function test_the_register_gets_a_scanned_column_only_with_scans_on(): void
    {
        Carbon::setTestNow('2026-09-10 09:00');
        $scanned = $this->createLetter($this->hrSec, ['subject' => 'Scanned one']);
        $this->createLetter($this->hrSec, ['subject' => 'Plain one']);
        $register = app(LetterRegisterService::class);
        $rows = fn () => $register->rows($this->hrSec, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));

        $this->assertArrayNotHasKey('scanned', $register->columns());
        $this->assertSame(array_values(LetterRegisterService::COLUMNS), (new LetterRegisterExport($rows()))->headings());

        $this->enable();
        $this->scans()->add($scanned, $this->hrSec, $this->pdf());
        $voided = $this->scans()->add($this->createLetter($this->hrSec, ['subject' => 'Voided scan']), $this->hrSec, $this->pdf());
        $this->scans()->void($voided, $this->hrSec, 'Out');

        $byName = $rows()->keyBy('subject');
        $this->assertTrue($byName['Scanned one']['scanned']);
        $this->assertFalse($byName['Plain one']['scanned']);
        $this->assertFalse($byName['Voided scan']['scanned'], 'a voided scan does not count');

        $export = new LetterRegisterExport($rows());
        $this->assertSame('Scanned', last($export->headings()));
        $this->assertCount(count($export->headings()), $export->collection()->first());
        $this->assertSame(['Yes'], $export->collection()->filter(fn ($cells) => $cells[8] === 'Scanned one')->map(fn ($cells) => last($cells))->values()->all());

        $this->actingAs($this->letterUserOf($this->hrSec))
            ->get(route('letters.register.pdf', ['from' => '2026-09-01', 'to' => '2026-09-30']))
            ->assertOk();
    }

    // ---- data model ---------------------------------------------------------------------------------------

    public function test_the_table_and_relations(): void
    {
        $this->assertTrue(Schema::hasColumns('letter_scans', [
            'letter_id', 'kind', 'disk', 'path', 'original_name', 'mime', 'size_bytes', 'sha256', 'note',
            'uploaded_by_id', 'voided_at', 'voided_by_id', 'void_reason',
        ]));

        $this->enable();
        $letter = $this->createLetter($this->hrSec);
        $scan = $this->scans()->add($letter, $this->hrSec, $this->pdf());

        $this->assertSame($scan->id, $letter->scans->sole()->id);
        $this->assertSame($letter->id, $scan->letter->id);
        $this->assertSame($this->hrSec->id, $scan->uploadedBy->id);
        $this->assertSame('Original', $scan->kindLabel());
        $this->assertNotEmpty($scan->humanSize());

        // Voiding records who; deleting that person later keeps the row (nullOnDelete).
        $this->scans()->void($scan, $this->hrSec, 'Out');
        $this->assertSame($this->hrSec->id, $scan->voidedBy->id);

        // The scans go with their letter (cascade).
        $letter->delete();
        $this->assertSame(0, LetterScan::query()->count());
    }
}
