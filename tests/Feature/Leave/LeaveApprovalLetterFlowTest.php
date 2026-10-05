<?php

namespace Tests\Feature\Leave;

use App\Livewire\Leave\AllRequests;
use App\Livewire\Leave\Approvals;
use App\Livewire\Leave\ApprovalLetter;
use App\Livewire\Leave\MyHistory;
use App\Models\LeaveLetter;
use App\Models\LeaveRequest;
use App\Notifications\GeneralDatabaseNotification;
use App\Services\Leave\LeaveLetterService;
use App\Services\Leave\SignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use RuntimeException;
use Tests\Feature\Leave\Concerns\BuildsLeaveLetters;
use Tests\TestCase;

/**
 * The letter in the screens around it: the final-approval checkbox, the Print Letter buttons, the notification links, and the
 * letter screen's own states.
 */
class LeaveApprovalLetterFlowTest extends TestCase
{
    use BuildsLeaveLetters;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLetterWorld();
    }

    protected function waitingForFinalApproval(): array
    {
        $chain = $this->districtChain();
        $request = $this->workflow()->submit($chain['applicant'], $this->leaveData(['start_date' => '2026-05-11', 'end_date' => '2026-05-12']));
        $this->workflow()->recommend($chain['manager'], $request->fresh(), null, true);

        return [$request->fresh(), $chain];
    }

    public function test_the_final_approval_offers_the_saved_signature_ticked_and_applies_it(): void
    {
        [$request, $chain] = $this->waitingForFinalApproval();
        $chief = $this->userOf($chain['chief']);
        $signature = app(SignatureService::class)->saveDrawn($chief, $this->signatureDataUrl(), 'abc12');

        Livewire::actingAs($chief)->test(Approvals::class)
            ->call('viewRequest', $request->id)
            ->assertSee('Apply my saved signature to the approval letter')
            ->assertSet('applySignature', true)
            ->call('approveRequest', $request->id);

        $letter = $request->fresh()->letter;
        $this->assertTrue($letter->signature_authorized);
        $this->assertSame($signature->id, $letter->signature_id);
    }

    public function test_unticking_the_box_leaves_the_signing_space_blank(): void
    {
        [$request, $chain] = $this->waitingForFinalApproval();
        $chief = $this->userOf($chain['chief']);
        app(SignatureService::class)->saveDrawn($chief, $this->signatureDataUrl(), 'abc12');

        Livewire::actingAs($chief)->test(Approvals::class)
            ->call('viewRequest', $request->id)
            ->set('applySignature', false)
            ->call('approveRequest', $request->id);

        $this->assertFalse($request->fresh()->letter->signature_authorized);
    }

    public function test_an_approver_with_no_signature_is_pointed_to_my_signature_and_the_approval_still_goes_through(): void
    {
        [$request, $chain] = $this->waitingForFinalApproval();

        Livewire::actingAs($this->userOf($chain['chief']))->test(Approvals::class)
            ->call('viewRequest', $request->id)
            ->assertDontSee('Apply my saved signature to the approval letter')
            ->assertSee('Add one under My Signature')
            ->assertSeeHtml(route('leave.signature'))
            ->call('approveRequest', $request->id);

        $request = $request->fresh(['letter']);
        $this->assertSame('Approved', $request->leave_status);
        $this->assertFalse($request->letter->signature_authorized);
    }

    public function test_the_manager_stage_has_no_signature_option(): void
    {
        $chain = $this->districtChain();
        $request = $this->workflow()->submit($chain['applicant'], $this->leaveData(['start_date' => '2026-05-11', 'end_date' => '2026-05-12']));
        app(SignatureService::class)->saveDrawn($this->userOf($chain['chief']), $this->signatureDataUrl(), 'abc12');

        Livewire::actingAs($this->userOf($chain['manager']))->test(Approvals::class)
            ->call('viewRequest', $request->id)
            ->assertDontSee('Apply my saved signature');
    }

    public function test_print_letter_buttons_show_for_approved_requests_only(): void
    {
        [$request, $chain] = $this->waitingForFinalApproval();
        $planned = $this->workflow()->savePlanned($chain['applicant'], $this->leaveData(['start_date' => '2026-06-01', 'end_date' => '2026-06-02']));

        Livewire::actingAs($this->userOf($chain['applicant']))->test(MyHistory::class)->assertDontSee('Print Letter');

        $this->workflow()->finalDecision($chain['chief'], $request->fresh(), null, true);

        $letterUrl = route('leave.letters.show', $request);
        Livewire::actingAs($this->userOf($chain['applicant']))->test(MyHistory::class)
            ->assertSee('Print Letter')
            ->assertSeeHtml($letterUrl)
            ->assertDontSeeHtml(route('leave.letters.show', $planned));

        // HR's list: a button for the approved request they may open a letter for.
        $hr = $this->userOf($this->staff('HRH001', $this->headOffice, $this->finance, ['hr_headoffice']));
        Livewire::actingAs($hr)->test(AllRequests::class)->call('setTab', 'approved')->assertSee('Print Letter')->assertSeeHtml($letterUrl);
    }

    public function test_the_approval_notifications_link_to_the_letter(): void
    {
        [$request, $chain] = $this->waitingForFinalApproval();
        $hr = $this->staff('HRA001', $this->accraOffice, $this->finance, ['hr_region']);

        $this->workflow()->finalDecision($chain['chief'], $request->fresh(), null, true);

        $url = route('leave.letters.show', $request);
        $applicantUser = $this->userOf($chain['applicant']);

        Notification::assertSentTo($applicantUser, GeneralDatabaseNotification::class, fn ($n) => $n->toArray($applicantUser)['url'] === $url);
        Notification::assertSentTo($this->userOf($hr), GeneralDatabaseNotification::class, fn ($n) => $n->toArray($this->userOf($hr))['url'] === $url);
    }

    public function test_the_pdf_can_be_downloaded_and_each_download_counts_as_a_print(): void
    {
        [$request, $chain] = $this->waitingForFinalApproval();
        $this->workflow()->finalDecision($chain['chief'], $request->fresh(), null, true);

        $response = $this->actingAs($this->userOf($chain['applicant']))->get(route('leave.letters.pdf', ['leaveRequest' => $request, 'download' => 1]));

        $response->assertOk();
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        // An ordinary letter, with its letterhead and the board footer, fits on one A4 page.
        $this->assertSame(1, preg_match_all('#/Type\s*/Page\b(?!s)#', $response->getContent()));
        $this->assertSame(1, $request->fresh()->letter->printed_count);
    }

    public function test_the_screen_offers_hr_to_generate_a_missing_letter_and_nobody_else(): void
    {
        [$request, $chain] = $this->waitingForFinalApproval();
        $this->workflow()->finalDecision($chain['chief'], $request->fresh(), null, true);
        LeaveLetter::query()->delete();

        $hr = $this->userOf($this->staff('HRH001', $this->headOffice, $this->finance, ['hr_headoffice']));

        Livewire::actingAs($this->userOf($chain['applicant']))->test(ApprovalLetter::class, ['leaveRequestId' => $request->id])
            ->assertSee('No letter yet')
            ->assertDontSee('Generate the letter');

        Livewire::actingAs($hr)->test(ApprovalLetter::class, ['leaveRequestId' => $request->id])
            ->assertSee('Generate the letter')
            ->call('regenerate')
            ->assertHasNoErrors()
            ->assertSee('Yours faithfully,');

        $this->assertNotNull($request->fresh()->letter);
    }

    public function test_the_screen_shows_the_letter_text_without_the_signature_image(): void
    {
        [$request, $chain] = $this->waitingForFinalApproval();
        $this->workflow()->finalDecision($chain['chief'], $request->fresh(), null, true);

        Livewire::actingAs($this->userOf($chain['applicant']))->test(ApprovalLetter::class, ['leaveRequestId' => $request->id])
            ->assertSee('RE: REQUEST FOR PART LEAVE')
            ->assertSee('Approval is given to enable you spend two (2) working days')
            ->assertSee('REGIONAL CHIEF MANAGER')
            ->assertSee('Print Letter')
            ->assertSee('Download PDF')
            ->assertSee('Not printed yet.');
    }

    public function test_the_for_mode_needs_an_hr_signatory_for_the_location(): void
    {
        [$request, $chain] = $this->waitingForFinalApproval();
        $this->workflow()->finalDecision($chain['chief'], $request->fresh(), null, true);
        $hr = $this->userOf($this->staff('HRH001', $this->headOffice, $this->finance, ['hr_headoffice']));

        // No HR signatory is set for Greater Accra: "for" isn't on offer, and asking for it is refused.
        $this->assertSame(['self'], app(LeaveLetterService::class)->modesFor($request->fresh()));

        try {
            app(LeaveLetterService::class)->update($hr, $request->fresh()->letter, ['signatory_mode' => 'for']);
            $this->fail('"for" needs an HR signatory.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('No HR signatory', $e->getMessage());
        }

        // "acting" can't be chosen for a letter the post-holder signed.
        $this->expectException(ValidationException::class);
        app(LeaveLetterService::class)->update($hr, $request->fresh()->letter, ['signatory_mode' => 'acting']);
    }

    public function test_one_letter_per_request(): void
    {
        [$request, $chain] = $this->waitingForFinalApproval();
        $this->workflow()->finalDecision($chain['chief'], $request->fresh(), null, true);

        $again = app(LeaveLetterService::class)->generate($request->fresh(), $this->userOf($chain['chief']));

        $this->assertSame($request->fresh()->letter->id, $again->id);
        $this->assertSame(1, LeaveLetter::query()->where('leave_request_id', $request->id)->count());
        $this->assertInstanceOf(LeaveRequest::class, $request);
    }
}
