<?php

namespace Tests\Feature\Leave;

use App\Livewire\Leave\ApprovalLetter;
use App\Livewire\Leave\MySignature;
use App\Mail\LeaveApprovedMail;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveActingAssignment;
use App\Models\LeaveLetter;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Models\UserSignature;
use App\Services\Leave\LeaveLetterService;
use App\Services\Leave\LeaveLetterSettingsService;
use App\Services\Leave\SignatureService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use RuntimeException;
use Tests\Feature\Leave\Concerns\BuildsLeaveLetters;
use Tests\TestCase;

/**
 * Saved signatures: drawn or uploaded, validated, re-encoded, encrypted on a private disk; who may keep, apply and revoke
 * one; and that a signature only ever goes on a letter its owner approved or signs, only in the print, never after a
 * revocation, and never into an audit row, an email or a list.
 */
class LeaveLetterSignatureTest extends TestCase
{
    use BuildsLeaveLetters;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLetterWorld();
        Storage::fake('local'); // Livewire's temporary uploads
    }

    protected function signatures(): SignatureService
    {
        return app(SignatureService::class);
    }

    protected function letters(): LeaveLetterService
    {
        return app(LeaveLetterService::class);
    }

    protected function disk()
    {
        return Storage::disk(config('gwl.signature_disk'));
    }

    protected function saveSignatureFor(Employee $employee): UserSignature
    {
        return $this->signatures()->saveDrawn($this->userOf($employee), $this->signatureDataUrl(), 'abc12');
    }

    // ================================================================== saving

    public function test_a_drawn_signature_is_saved_re_encoded_and_stored_encrypted_on_the_private_disk(): void
    {
        ['chief' => $chief] = $this->districtChain();

        Livewire::actingAs($this->userOf($chief))->test(MySignature::class)
            ->set('drawn', $this->signatureDataUrl())
            ->set('password', 'abc12')
            ->call('saveDrawn')
            ->assertHasNoErrors();

        $signature = $this->signatures()->activeFor($this->userOf($chief));
        $this->assertSame('drawn', $signature->method);
        $this->assertTrue($signature->is_active);

        // On disk it is ciphertext, not an image...
        $stored = $this->disk()->get($signature->storage_path);
        $this->assertStringStartsNotWith("\x89PNG", $stored);
        $this->assertStringNotContainsString('PNG', substr($stored, 0, 20));

        // ...which decrypts to a PNG at the size it was stored.
        $png = Crypt::decryptString($stored);
        $this->assertStringStartsWith("\x89PNG", $png);
        $this->assertSame(hash('sha256', $png), $signature->sha256);
        [$width, $height] = getimagesizefromstring($png);
        $this->assertSame([$signature->width, $signature->height], [$width, $height]);
    }

    public function test_an_upload_is_re_encoded_to_png_scaled_down_and_its_white_background_made_transparent(): void
    {
        ['chief' => $chief] = $this->districtChain();

        // A big JPG, 1200x400 on white paper.
        $image = imagecreatetruecolor(1200, 400);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagesetthickness($image, 8);
        imageline($image, 50, 300, 600, 60, imagecolorallocate($image, 0, 0, 80));
        ob_start();
        imagejpeg($image, null, 90);
        $jpg = (string) ob_get_clean();

        Livewire::actingAs($this->userOf($chief))->test(MySignature::class)
            ->set('upload', $this->signatureUpload('scan.jpg', $jpg))
            ->set('password', 'abc12')
            ->call('saveUpload')
            ->assertHasNoErrors();

        $signature = $this->signatures()->activeFor($this->userOf($chief));
        $this->assertSame('uploaded', $signature->method);
        $this->assertSame([600, 200], [$signature->width, $signature->height]);

        $decoded = imagecreatefromstring(Crypt::decryptString($this->disk()->get($signature->storage_path)));
        $this->assertSame(IMAGETYPE_PNG, getimagesizefromstring(Crypt::decryptString($this->disk()->get($signature->storage_path)))[2]);
        // The corner is paper: fully transparent now. The middle of the stroke is still ink.
        $this->assertSame(127, (imagecolorat($decoded, 2, 2) >> 24) & 0x7F);
        $inked = 0;

        for ($x = 0; $x < 600; $x += 5) {
            for ($y = 0; $y < 200; $y += 5) {
                $inked += ((imagecolorat($decoded, $x, $y) >> 24) & 0x7F) < 60 ? 1 : 0;
            }
        }

        $this->assertGreaterThan(5, $inked);
    }

    public function test_the_stored_file_is_on_a_private_disk_with_no_public_url(): void
    {
        ['chief' => $chief] = $this->districtChain();
        $signature = $this->saveSignatureFor($chief);

        $disks = (require config_path('filesystems.php'))['disks'];

        $this->assertArrayNotHasKey('url', $disks['leave_signatures']);
        $this->assertStringContainsString('private', $disks['leave_signatures']['root']);
        $this->assertSame('private', $disks['leave_signatures']['visibility']);
        $this->assertFalse($disks['leave_signatures']['serve'] ?? false);
        $this->assertSame('leave_signatures', config('gwl.signature_disk'));
        $this->assertFalse(Storage::disk('public')->exists($signature->storage_path));
        $this->assertNotSame(200, $this->get('/storage/'.$signature->storage_path)->getStatusCode());
        // The model never serialises where the file is or its hash.
        $this->assertArrayNotHasKey('storage_path', $signature->toArray());
        $this->assertArrayNotHasKey('sha256', $signature->toArray());
    }

    public function test_things_that_are_not_a_usable_signature_are_rejected(): void
    {
        ['chief' => $chief] = $this->districtChain();
        $user = $this->userOf($chief);

        $cases = [
            'not an image at all' => fn () => $this->signatures()->saveUploaded($user, $this->signatureUpload('sig.png', 'this is just text, not a picture'), 'abc12'),
            'a gif' => function () use ($user) {
                $image = imagecreatetruecolor(300, 100);
                imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
                imageline($image, 0, 0, 300, 100, imagecolorallocate($image, 0, 0, 0));
                ob_start();
                imagegif($image);

                return $this->signatures()->saveUploaded($user, $this->signatureUpload('sig.png', (string) ob_get_clean()), 'abc12');
            },
            'too big' => fn () => $this->signatures()->saveUploaded($user, $this->signatureUpload('big.png', "\x89PNG\r\n\x1a\n".str_repeat('x', 1100 * 1024)), 'abc12'),
            'a blank page' => fn () => $this->signatures()->saveUploaded($user, $this->signatureUpload('blank.png', $this->signaturePng(blank: true)), 'abc12'),
            'a tiny image' => fn () => $this->signatures()->saveUploaded($user, $this->signatureUpload('tiny.png', $this->signaturePng(8, 4)), 'abc12'),
            'a drawing that is not a png data url' => fn () => $this->signatures()->saveDrawn($user, 'data:text/html;base64,PGh0bWw+', 'abc12'),
            'an empty drawing' => fn () => $this->signatures()->saveDrawn($user, '', 'abc12'),
        ];

        foreach ($cases as $name => $attempt) {
            try {
                $attempt();
                $this->fail("{$name} should have been rejected.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('signature', $e->errors(), $name);
            }
        }

        $this->assertSame(0, UserSignature::query()->count());
        $this->assertSame([], $this->disk()->allFiles());
    }

    public function test_the_upload_form_rejects_a_text_file_and_an_oversized_file(): void
    {
        ['chief' => $chief] = $this->districtChain();

        Livewire::actingAs($this->userOf($chief))->test(MySignature::class)
            ->set('upload', UploadedFile::fake()->createWithContent('sig.png', 'plain text'))
            ->set('password', 'abc12')
            ->call('saveUpload')
            ->assertHasErrors('signature'); // the content is checked, not the file name

        Livewire::actingAs($this->userOf($chief))->test(MySignature::class)
            ->set('upload', UploadedFile::fake()->create('big.png', 2048, 'image/png'))
            ->set('password', 'abc12')
            ->call('saveUpload')
            ->assertHasErrors('upload');

        $this->assertSame(0, UserSignature::query()->count());
    }

    public function test_saving_or_replacing_needs_the_users_password(): void
    {
        ['chief' => $chief] = $this->districtChain();

        Livewire::actingAs($this->userOf($chief))->test(MySignature::class)
            ->set('drawn', $this->signatureDataUrl())
            ->set('password', 'wrong')
            ->call('saveDrawn')
            ->assertHasErrors('password');

        $this->assertSame(0, UserSignature::query()->count());

        $this->saveSignatureFor($chief);

        try {
            $this->signatures()->saveDrawn($this->userOf($chief), $this->signatureDataUrl(), '');
            $this->fail('A replacement needs the password too.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('password', $e->errors());
        }

        $this->assertSame(1, UserSignature::query()->count());
    }

    // ================================================================== who keeps one

    public function test_the_signing_roles_and_current_acting_assignees_have_the_page_and_nobody_else(): void
    {
        ['applicant' => $applicant, 'chief' => $chief] = $this->districtChain();
        $hrRegion = $this->staff('HRR001', $this->accraOffice, $this->finance, ['hr_region']);
        $hrHeadOffice = $this->staff('HRH001', $this->headOffice, $this->finance, ['hr_headoffice']);
        $headOfficeChief = $this->staff('HOC001', $this->headOffice, $this->finance, ['chief_manager']);
        $md = $this->staff('MD001', $this->headOffice, $this->finance, ['managing_director']);
        $acting = $this->staff('ACT001', $this->accraOffice, $this->operations, ['departmental_manager']);
        $admin = $this->staff('ADM001', $this->headOffice, $this->finance, ['admin']);
        $superAdmin = $this->staff('SA001', $this->headOffice, $this->finance, ['super_admin']);

        foreach ([$chief, $hrRegion, $hrHeadOffice, $headOfficeChief, $md] as $signer) {
            $this->actingAs($this->userOf($signer))->get(route('leave.signature'))->assertOk()->assertSee('My Signature');
        }

        // Not yet acting: no page. During the assignment: the page. After it: none again.
        $this->actingAs($this->userOf($acting))->get(route('leave.signature'))->assertForbidden();
        $assignment = LeaveActingAssignment::query()->create([
            'user_id' => $this->userOf($acting)->id, 'acting_for_role' => 'regional_chief_manager', 'region_id' => $this->accra->id,
            'starts_on' => today(), 'ends_on' => today()->addDays(7), 'is_active' => true,
        ]);
        $this->get(route('leave.signature'))->assertOk();
        $assignment->update(['ends_on' => today()->subDay(), 'starts_on' => today()->subDays(5)]);
        $this->get(route('leave.signature'))->assertForbidden();

        foreach ([$applicant, $admin, $superAdmin] as $other) {
            $this->actingAs($this->userOf($other))->get(route('leave.signature'))->assertForbidden();
            Livewire::actingAs($this->userOf($other))->test(MySignature::class)->assertForbidden();
        }
    }

    public function test_global_admin_and_super_admin_can_revoke_but_never_add_view_or_apply(): void
    {
        ['chief' => $chief] = $this->districtChain();
        $signature = $this->saveSignatureFor($chief);

        foreach (['ADM001' => 'admin', 'SA001' => 'super_admin'] as $id => $role) {
            $user = $this->userOf($this->staff($id, $this->headOffice, $this->finance, [$role]));

            $this->assertFalse($this->signatures()->canSign($user));
            $this->assertTrue($this->signatures()->canRevokeOthers($user));

            try {
                $this->signatures()->saveDrawn($user, $this->signatureDataUrl(), 'abc12');
                $this->fail("{$role} must not be able to add a signature.");
            } catch (AuthorizationException) {
                $this->assertSame(0, UserSignature::query()->where('user_id', $user->id)->count());
            }
        }

        $admin = User::query()->where('staff_id', 'ADM001')->firstOrFail();
        $this->signatures()->revoke($admin, $this->userOf($chief));

        $this->assertNull($this->signatures()->activeFor($this->userOf($chief)));
        $this->assertNotNull($signature->fresh()->revoked_at);
        $this->assertSame($admin->id, $signature->fresh()->revoked_by);
    }

    public function test_nobody_can_revoke_view_or_apply_another_persons_signature(): void
    {
        ['applicant' => $applicant, 'manager' => $manager, 'chief' => $chief] = $this->districtChain();
        $hr = $this->staff('HRR001', $this->accraOffice, $this->finance, ['hr_region']);
        $this->saveSignatureFor($chief);
        $this->saveSignatureFor($hr);

        // A peer cannot revoke it.
        $this->expectException(AuthorizationException::class);
        $this->signatures()->revoke($this->userOf($hr), $this->userOf($chief));
    }

    public function test_another_signer_cannot_apply_a_signature_to_someone_elses_letter(): void
    {
        ['applicant' => $applicant, 'manager' => $manager, 'chief' => $chief] = $this->districtChain();
        $hr = $this->staff('HRR001', $this->accraOffice, $this->finance, ['hr_region']);
        $this->saveSignatureFor($hr);

        $request = $this->approveLeave($applicant, ['start_date' => '2026-05-11', 'end_date' => '2026-05-12'], $manager, $chief);

        // The signer is the regional chief manager, who has no signature yet; the HR user (who has one) can't use theirs here.
        foreach ([$this->userOf($hr), $this->userOf($manager), $this->userOf($applicant)] as $other) {
            try {
                $this->letters()->applySignature($other, $request->letter);
                $this->fail('Only the signer can apply a signature.');
            } catch (AuthorizationException) {
                $this->assertFalse($request->letter->fresh()->signature_authorized);
            }
        }
    }

    // ================================================================== authorised at approval

    public function test_the_approvers_signature_goes_on_the_print_only_when_authorised_at_final_approval(): void
    {
        ['applicant' => $applicant, 'manager' => $manager, 'chief' => $chief] = $this->districtChain();
        $signature = $this->saveSignatureFor($chief);
        $uri = $this->signatures()->dataUri($signature);

        $with = $this->approveLeave($applicant, ['start_date' => '2026-05-11', 'end_date' => '2026-05-12'], $manager, $chief, applySignature: true);
        $without = $this->approveLeave($applicant, ['start_date' => '2026-05-13', 'end_date' => '2026-05-14'], $manager, $chief, applySignature: false);

        $this->assertTrue($with->letter->signature_authorized);
        $this->assertSame($signature->id, $with->letter->signature_id);
        $this->assertStringContainsString($uri, $this->letters()->html($with->letter));

        $this->assertFalse($without->letter->signature_authorized);
        $this->assertNull($without->letter->signature_id);
        $this->assertStringNotContainsString($uri, $this->letters()->html($without->letter));
    }

    public function test_approving_without_a_saved_signature_leaves_a_blank_space_and_the_signer_can_add_one_before_the_first_print(): void
    {
        ['applicant' => $applicant, 'manager' => $manager, 'chief' => $chief] = $this->districtChain();

        $request = $this->approveLeave($applicant, ['start_date' => '2026-05-11', 'end_date' => '2026-05-12'], $manager, $chief, applySignature: true);
        $this->assertFalse($request->letter->signature_authorized);

        $signature = $this->saveSignatureFor($chief);

        // The letter screen offers it to the signer, and only to them.
        Livewire::actingAs($this->userOf($chief))->test(ApprovalLetter::class, ['leaveRequestId' => $request->id])
            ->assertSee('Apply my signature')
            ->call('applySignature')
            ->assertHasNoErrors();

        $letter = $request->letter->fresh();
        $this->assertTrue($letter->signature_authorized);
        $this->assertSame($signature->id, $letter->signature_id);
        $this->assertStringContainsString($this->signatures()->dataUri($signature), $this->letters()->html($letter));

        Livewire::actingAs($this->userOf($applicant))->test(ApprovalLetter::class, ['leaveRequestId' => $request->id])->assertDontSee('Apply my signature');
    }

    public function test_a_signature_cannot_be_applied_after_the_first_print(): void
    {
        ['applicant' => $applicant, 'manager' => $manager, 'chief' => $chief] = $this->districtChain();
        $request = $this->approveLeave($applicant, ['start_date' => '2026-05-11', 'end_date' => '2026-05-12'], $manager, $chief);
        $this->saveSignatureFor($chief);

        $this->actingAs($this->userOf($applicant))->get(route('leave.letters.pdf', $request))->assertOk();

        $this->expectException(RuntimeException::class);
        $this->letters()->applySignature($this->userOf($chief), $request->letter->fresh());
    }

    // ================================================================== "for" mode

    public function test_in_for_mode_the_hr_signatorys_own_signature_is_used_and_only_they_can_authorise_it(): void
    {
        ['applicant' => $applicant, 'manager' => $manager, 'chief' => $chief] = $this->districtChain();
        $hr = $this->staff('HRR001', $this->accraOffice, $this->finance, ['hr_region']);
        $otherHr = $this->staff('HRR002', $this->accraOffice, $this->finance, ['hr_region']);
        $admin = $this->staff('ADM001', $this->headOffice, $this->finance, ['admin']);
        // Three different drawings, so a print can tell whose is on it.
        $chiefSignature = $this->saveSignatureFor($chief);
        $hrSignature = $this->signatures()->saveUploaded($this->userOf($hr), $this->signatureUpload('hr.png', $this->signaturePng(500, 160)), 'abc12');
        $otherSignature = $this->signatures()->saveUploaded($this->userOf($otherHr), $this->signatureUpload('other.png', $this->signaturePng(330, 120)), 'abc12');

        app(LeaveLetterSettingsService::class)->saveLetterhead($this->userOf($admin), $this->accra->id, ['hr_signatory_user_id' => $this->userOf($hr)->id]);

        // The approver chose to apply their signature, but HR then switches the letter to "for".
        $request = $this->approveLeave($applicant, ['start_date' => '2026-05-11', 'end_date' => '2026-05-12'], $manager, $chief, applySignature: true);
        $this->assertSame($chiefSignature->id, $request->letter->signature_id);

        $letter = $this->letters()->update($this->userOf($otherHr), $request->letter, ['signatory_mode' => 'for']);

        $this->assertSame('for', $letter->signatory_mode);
        $this->assertSame($this->userOf($hr)->id, $letter->signer_user_id);
        $this->assertSame('EMPLOYEE HRR001', $letter->snapshot['signatory']['name']);
        $this->assertSame('OFFICER', $letter->snapshot['signatory']['title']);
        $this->assertSame('For: REGIONAL CHIEF MANAGER', $letter->snapshot['signatory']['for_line']);
        // The approver's authorisation does not carry over to someone else signing on their behalf.
        $this->assertFalse($letter->signature_authorized);
        $this->assertNull($letter->signature_id);

        // Not the other HR user, the chief manager, nor the admin: only the HR signatory.
        foreach ([$otherHr, $chief, $admin] as $other) {
            try {
                $this->letters()->applySignature($this->userOf($other), $letter);
                $this->fail('Only the HR signatory can apply their signature in "for" mode.');
            } catch (AuthorizationException) {
                $this->assertFalse($letter->fresh()->signature_authorized);
            }
        }

        $letter = $this->letters()->applySignature($this->userOf($hr), $letter);
        $html = $this->letters()->html($letter);

        $this->assertSame($hrSignature->id, $letter->signature_id);
        $this->assertStringContainsString($this->signatures()->dataUri($hrSignature), $html);
        $this->assertStringNotContainsString($this->signatures()->dataUri($chiefSignature), $html);
        $this->assertStringNotContainsString($this->signatures()->dataUri($otherSignature), $html);
        $this->assertStringContainsString('For: REGIONAL CHIEF MANAGER', $html);
    }

    // ================================================================== never anywhere else

    public function test_the_signature_is_absent_from_emails_lists_the_letter_screen_audit_and_the_snapshot(): void
    {
        ['applicant' => $applicant, 'manager' => $manager, 'chief' => $chief] = $this->districtChain();
        $signature = $this->saveSignatureFor($chief);
        $uri = $this->signatures()->dataUri($signature);
        $raw = base64_encode(Crypt::decryptString($this->disk()->get($signature->storage_path)));

        $request = $this->approveLeave($applicant, ['start_date' => '2026-05-11', 'end_date' => '2026-05-12'], $manager, $chief, applySignature: true);
        $this->assertTrue($request->letter->signature_authorized);

        // The approval email: sent, with a link to the letter, and no image.
        Mail::assertSent(LeaveApprovedMail::class, function (LeaveApprovedMail $mail) use ($uri, $raw, $request) {
            $html = $mail->render();
            $this->assertStringContainsString(route('leave.letters.show', $request), $html);
            $this->assertStringNotContainsString($raw, $html);
            $this->assertStringNotContainsString('data:image', $html);

            return true;
        });

        // The lists the applicant and HR use, and the letter screen.
        $this->actingAs($this->userOf($applicant));
        foreach ([route('leave.my-history'), route('leave.requests')] as $url) {
            $this->get($url)->assertOk();
        }
        Livewire::test(\App\Livewire\Leave\MyHistory::class)->assertDontSee($raw, false)->assertDontSee('data:image', false);
        Livewire::actingAs($this->userOf($chief))->test(ApprovalLetter::class, ['leaveRequestId' => $request->id])->assertDontSee($raw, false)->assertDontSee('data:image', false);

        // Not in the audit trail, and the snapshot holds the id, not the bytes.
        $audit = AuditLog::query()->get()->toJson();
        $this->assertStringNotContainsString($raw, $audit);
        $this->assertStringNotContainsString('base64', $audit);
        $this->assertStringNotContainsString($signature->storage_path, $audit);
        $this->assertStringNotContainsString($signature->sha256, $audit);
        $this->assertStringNotContainsString($raw, json_encode($request->letter->fresh()->snapshot));
        $this->assertSame($signature->id, $request->letter->fresh()->signature_id);

        $events = AuditLog::query()->whereIn('action', ['leave_signature_set', 'leave_signature_replaced', 'leave_signature_revoked'])->get();
        $this->assertSame(['leave_signature_set'], $events->pluck('action')->all());
        $this->assertSame(['target_user_id' => $this->userOf($chief)->id, 'method' => 'drawn'], $events->first()->metadata);
    }

    public function test_a_revoked_signature_is_never_printed_again_and_hr_is_told(): void
    {
        ['applicant' => $applicant, 'manager' => $manager, 'chief' => $chief] = $this->districtChain();
        $signature = $this->saveSignatureFor($chief);
        $uri = $this->signatures()->dataUri($signature);
        $request = $this->approveLeave($applicant, ['start_date' => '2026-05-11', 'end_date' => '2026-05-12'], $manager, $chief, applySignature: true);

        $this->actingAs($this->userOf($applicant))->get(route('leave.letters.pdf', $request))->assertOk();
        $this->assertStringContainsString($uri, $this->letters()->html($request->letter->fresh()));

        // A compromised account: Global Admin revokes it.
        $admin = $this->userOf($this->staff('ADM001', $this->headOffice, $this->finance, ['admin']));
        $this->signatures()->revoke($admin, $this->userOf($chief));

        $letter = $request->letter->fresh();
        $this->assertSame(2 - 1, $letter->printed_count);
        $rendered = $this->letters()->render($letter);
        $this->assertNull($rendered['signature']);
        $this->assertTrue($rendered['signature_revoked']);
        $this->assertStringNotContainsString($uri, $this->letters()->html($letter));

        // A reprint succeeds, with a blank signing space; HR sees the notice on the screen.
        $this->actingAs($this->userOf($applicant))->get(route('leave.letters.pdf', $request))->assertOk();
        Livewire::actingAs($admin)->test(ApprovalLetter::class, ['leaveRequestId' => $request->id])->assertSee('A signature was revoked');
        $this->assertFalse($this->disk()->exists($signature->storage_path));
        $this->assertTrue(AuditLog::query()->where('action', 'leave_signature_revoked')->exists());
    }

    public function test_deleting_your_own_signature_revokes_it_too(): void
    {
        ['chief' => $chief] = $this->districtChain();
        $this->saveSignatureFor($chief);

        Livewire::actingAs($this->userOf($chief))->test(MySignature::class)->call('deleteSignature');

        $this->assertNull($this->signatures()->activeFor($this->userOf($chief)));
        $this->assertNotNull(UserSignature::query()->sole()->revoked_at);
    }

    public function test_replacing_a_signature_leaves_letters_already_printed_on_the_earlier_version(): void
    {
        ['applicant' => $applicant, 'manager' => $manager, 'chief' => $chief] = $this->districtChain();
        $first = $this->saveSignatureFor($chief);
        $firstUri = $this->signatures()->dataUri($first);

        $request = $this->approveLeave($applicant, ['start_date' => '2026-05-11', 'end_date' => '2026-05-12'], $manager, $chief, applySignature: true);
        $this->actingAs($this->userOf($applicant))->get(route('leave.letters.pdf', $request))->assertOk();

        // A different drawing replaces it.
        $second = $this->signatures()->saveUploaded($this->userOf($chief), $this->signatureUpload('new.png', $this->signaturePng(500, 160)), 'abc12');
        $secondUri = $this->signatures()->dataUri($second);

        $this->assertNotSame($firstUri, $secondUri);
        $this->assertSame($second->id, $this->signatures()->activeFor($this->userOf($chief))->id);
        $this->assertFalse($first->fresh()->is_active);

        // The printed letter still points at, and prints, the earlier version.
        $letter = $request->letter->fresh();
        $this->assertSame($first->id, $letter->signature_id);
        $rendered = $this->letters()->render($letter);
        $this->assertSame($firstUri, $rendered['signature']);
        $this->assertFalse($rendered['signature_revoked']);
        $this->assertTrue($this->disk()->exists($first->storage_path));

        // A letter approved now uses the new one.
        $next = $this->approveLeave($applicant, ['start_date' => '2026-05-13', 'end_date' => '2026-05-14'], $manager, $chief, applySignature: true);
        $this->assertSame($second->id, $next->letter->signature_id);

        // An earlier version nothing refers to is cleaned up: replace again and the second goes, the first stays (a letter uses it).
        $third = $this->signatures()->saveDrawn($this->userOf($chief), $this->signatureDataUrl(), 'abc12');
        $this->assertNotNull(UserSignature::query()->find($first->id));
        $this->assertNotNull(UserSignature::query()->find($second->id)); // the second letter uses it
        $this->assertTrue($third->is_active);
        $this->assertSame(['leave_signature_set', 'leave_signature_replaced', 'leave_signature_replaced'], AuditLog::query()->whereIn('action', ['leave_signature_set', 'leave_signature_replaced'])->orderBy('id')->pluck('action')->all());
    }

    public function test_a_replaced_signature_no_letter_uses_is_removed_from_disk_and_database(): void
    {
        ['chief' => $chief] = $this->districtChain();
        $first = $this->saveSignatureFor($chief);
        $path = $first->storage_path;

        $this->signatures()->saveDrawn($this->userOf($chief), $this->signatureDataUrl(), 'abc12');

        $this->assertNull(UserSignature::query()->find($first->id));
        $this->assertFalse($this->disk()->exists($path));
        $this->assertSame(1, UserSignature::query()->where('user_id', $this->userOf($chief)->id)->count());
    }

    public function test_every_user_keeps_at_most_one_active_signature(): void
    {
        ['chief' => $chief] = $this->districtChain();

        foreach (range(1, 3) as $ignored) {
            $this->saveSignatureFor($chief);
        }

        $this->assertSame(1, UserSignature::query()->where('user_id', $this->userOf($chief)->id)->where('is_active', true)->count());
    }
}
