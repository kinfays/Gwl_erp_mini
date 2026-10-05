<?php

namespace Tests\Feature\Leave;

use App\Livewire\Leave\LetterSettings;
use App\Models\AuditLog;
use App\Models\LeaveLetterhead;
use App\Models\LeaveLetterSetting;
use App\Models\Region;
use App\Models\User;
use App\Services\Leave\LeaveLetterSettingsService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Feature\Leave\Concerns\BuildsLeaveLetters;
use Tests\TestCase;

/**
 * Letter Settings: regional HR edit their own region's letterhead and nothing else; Head Office HR, Global Admin and
 * super_admin edit every location and the company block (board, registered office, contacts).
 */
class LeaveLetterSettingsTest extends TestCase
{
    use BuildsLeaveLetters;
    use RefreshDatabase;

    protected User $accraHr;

    protected User $ashantiHr;

    protected User $headOfficeHr;

    protected User $admin;

    protected User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLetterWorld();

        $this->accraHr = $this->userOf($this->staff('HRA001', $this->accraOffice, $this->finance, ['hr_region']));
        $this->ashantiHr = $this->userOf($this->staff('HRK001', $this->kumasiOffice, $this->finance, ['hr_region']));
        $this->headOfficeHr = $this->userOf($this->staff('HRH001', $this->headOffice, $this->finance, ['hr_headoffice']));
        $this->admin = $this->userOf($this->staff('ADM001', $this->headOffice, $this->finance, ['admin']));
        $this->superAdmin = $this->userOf($this->staff('SA001', $this->headOffice, $this->finance, ['super_admin']));
    }

    protected function settings(): LeaveLetterSettingsService
    {
        return app(LeaveLetterSettingsService::class);
    }

    protected function address(string $box = 'Post Office Box 1'): array
    {
        return ['region_name' => 'Accra West Region', 'address_lines' => [$box, 'Accra, Ghana', 'West Africa']];
    }

    public function test_the_company_block_is_seeded_with_the_board_from_the_template(): void
    {
        $company = $this->settings()->company();

        $this->assertCount(11, $company['board']);
        $this->assertSame(['name' => 'Hon. Patrick Yaw Boamah', 'role' => 'Chairman'], $company['board'][0]);
        $this->assertSame(['name' => 'Ing. Dr. Clifford A. Braimah', 'role' => 'Managing Director'], $company['board'][1]);
        $this->assertSame('Ing. Dr. Hadisu Alhassan', $company['board'][10]['name']);
        $this->assertTrue($this->settings()->letterheadFor(null)->isHeadOffice());

        // And the rest of the template's company block.
        $this->assertSame(['GCB Bank Limited', 'Societe Generale Ghana', 'National Investment Bank'], $company['bankers']);
        $this->assertSame('28th February Road, (Near Independence Square)', $company['registered_office']);
        $this->assertSame('233-508-300-537', $company['telephone']);
        $this->assertSame('www.gwcl.com.gh', $company['website']);
        $this->assertSame('info@gwcl.com.gh', $company['email']);
    }

    public function test_regional_hr_edit_their_own_regions_letterhead_only(): void
    {
        $this->settings()->saveLetterhead($this->accraHr, $this->accra->id, $this->address() + ['default_cc' => ['File']]);

        $letterhead = $this->settings()->letterheadFor($this->accra->id);
        $this->assertSame(['Post Office Box 1', 'Accra, Ghana', 'West Africa'], $letterhead->address_lines);
        $this->assertSame(['File'], $letterhead->default_cc);
        $this->assertSame($this->accraHr->id, $letterhead->updated_by);

        foreach ([$this->ashanti->id, null] as $other) {
            try {
                $this->settings()->saveLetterhead($this->accraHr, $other, $this->address('Elsewhere'));
                $this->fail('Regional HR can only edit their own region.');
            } catch (AuthorizationException) {
                $this->assertNotContains('Elsewhere', (array) $this->settings()->letterheadFor($other)->address_lines);
            }
        }
    }

    public function test_regional_hr_cannot_edit_the_board_or_the_company_block(): void
    {
        $this->expectException(AuthorizationException::class);

        $this->settings()->saveCompany($this->accraHr, ['board_members' => [['name' => 'Mr. X', 'role' => 'Chairman']]]);
    }

    public function test_head_office_hr_global_admin_and_super_admin_edit_every_location_and_the_company_block(): void
    {
        foreach ([$this->headOfficeHr, $this->admin, $this->superAdmin] as $editor) {
            foreach ([null, $this->accra->id, $this->ashanti->id] as $scope) {
                $this->settings()->saveLetterhead($editor, $scope, $this->address('By '.$editor->staff_id));
                $this->assertContains('By '.$editor->staff_id, $this->settings()->letterheadFor($scope)->address_lines);
            }

            $this->settings()->saveCompany($editor, [
                'bankers' => ['GCB Bank'],
                'board_members' => [['name' => 'Mr. Chair '.$editor->staff_id, 'role' => 'Chairman'], ['name' => 'Ms. Member', 'role' => 'Member']],
                'registered_office' => 'Office of '.$editor->staff_id,
                'telephone' => '030 1', 'website' => 'gwl.example', 'email' => 'info@gwl.example',
            ]);
            $this->assertSame('Office of '.$editor->staff_id, $this->settings()->company()['registered_office']);
        }
    }

    public function test_every_change_is_audited_with_old_and_new_values(): void
    {
        $this->actingAs($this->headOfficeHr);
        $this->settings()->saveLetterhead($this->headOfficeHr, $this->accra->id, $this->address('One'));
        $this->actingAs($this->accraHr);
        $this->settings()->saveLetterhead($this->accraHr, $this->accra->id, $this->address('Two'));

        $audits = AuditLog::query()->where('action', 'leave_letterhead_updated')->orderBy('id')->get();

        $this->assertCount(2, $audits);
        $this->assertSame([], $audits[0]->old_values['address_lines']);
        $this->assertSame('One', $audits[0]->new_values['address_lines'][0]);
        $this->assertSame('One', $audits[1]->old_values['address_lines'][0]);
        $this->assertSame('Two', $audits[1]->new_values['address_lines'][0]);
        $this->assertSame($this->accraHr->id, $audits[1]->user_id);

        $this->settings()->saveCompany($this->admin, ['board_members' => [['name' => 'Mr. Only', 'role' => 'Chairman']], 'telephone' => '030 5']);
        $company = AuditLog::query()->where('action', 'leave_letter_company_updated')->sole();
        $this->assertCount(11, $company->old_values['board_members']);
        $this->assertSame([['name' => 'Mr. Only', 'role' => 'Chairman']], $company->new_values['board_members']);
        $this->assertSame('030 5', $company->new_values['telephone']);
    }

    public function test_the_board_must_have_valid_roles_and_at_most_one_chairman_and_managing_director(): void
    {
        foreach ([
            [['name' => 'A', 'role' => 'Chairman'], ['name' => 'B', 'role' => 'Chairman']],
            [['name' => 'A', 'role' => 'Managing Director'], ['name' => 'B', 'role' => 'Managing Director']],
            [['name' => 'A', 'role' => 'Treasurer']],
        ] as $board) {
            try {
                $this->settings()->saveCompany($this->admin, ['board_members' => $board]);
                $this->fail('That board should have been rejected.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('board_members', $e->errors());
            }
        }

        $this->assertCount(11, $this->settings()->company()['board']);
    }

    public function test_the_hr_signatory_must_be_hr_of_that_location(): void
    {
        $accraEmployee = $this->userOf($this->staff('EMP001', $this->accraOffice, $this->finance));

        foreach ([$accraEmployee->id, $this->ashantiHr->id] as $notAllowed) {
            try {
                $this->settings()->saveLetterhead($this->accraHr, $this->accra->id, $this->address() + ['hr_signatory_user_id' => $notAllowed]);
                $this->fail('Not a valid HR signatory for Accra.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('hr_signatory_user_id', $e->errors());
            }
        }

        $this->settings()->saveLetterhead($this->accraHr, $this->accra->id, $this->address() + ['hr_signatory_user_id' => $this->accraHr->id]);
        $this->assertSame($this->accraHr->id, $this->settings()->letterheadFor($this->accra->id)->hr_signatory_user_id);

        // Head Office HR can be the signatory anywhere.
        $this->settings()->saveLetterhead($this->admin, $this->ashanti->id, $this->address() + ['hr_signatory_user_id' => $this->headOfficeHr->id]);
        $this->assertSame($this->headOfficeHr->id, $this->settings()->letterheadFor($this->ashanti->id)->hr_signatory_user_id);
    }

    public function test_a_region_gets_a_letterhead_the_first_time_it_is_needed_and_is_flagged_until_it_has_an_address(): void
    {
        $new = Region::query()->create(['region_name' => 'Volta']);

        $letterhead = $this->settings()->letterheadFor($new->id);

        $this->assertSame('Volta', $letterhead->region_name);
        $this->assertTrue($letterhead->addressMissing());
        $this->assertSame(1, LeaveLetterhead::query()->where('region_id', $new->id)->count());

        Livewire::actingAs($this->admin)->test(LetterSettings::class)->assertSee('Volta (address not set)');
    }

    public function test_the_page_is_for_hr_and_admins_and_shows_regional_hr_only_their_own_region(): void
    {
        foreach ([$this->accraHr, $this->headOfficeHr, $this->admin, $this->superAdmin] as $allowed) {
            $this->actingAs($allowed)->get(route('leave.letter-settings'))->assertOk()->assertSee('Letter Settings');
        }

        $employee = $this->userOf($this->staff('EMP001', $this->temaDistrict, $this->operations));
        $manager = $this->userOf($this->staff('DM001', $this->temaDistrict, $this->operations, ['district_manager']));

        foreach ([$employee, $manager] as $denied) {
            $this->actingAs($denied)->get(route('leave.letter-settings'))->assertForbidden();
            Livewire::actingAs($denied)->test(LetterSettings::class)->assertForbidden();
        }

        // Regional HR: their region in the list, no Head Office, no other region, no company card.
        Livewire::actingAs($this->accraHr)->test(LetterSettings::class)
            ->assertSee('Greater Accra')
            ->assertDontSee('Ashanti')
            ->assertDontSee('Head Office')
            ->assertDontSee('Company details and Board of Directors')
            ->assertDontSee('Signatures on file');

        // Head Office HR: everything but the signature revocation list.
        Livewire::actingAs($this->headOfficeHr)->test(LetterSettings::class)
            ->assertSee('Head Office')->assertSee('Ashanti')->assertSee('Company details and Board of Directors')->assertDontSee('Signatures on file');
    }

    public function test_the_page_saves_a_letterhead_and_the_company_block_and_refuses_regional_hr_the_board(): void
    {
        $page = Livewire::actingAs($this->accraHr)->test(LetterSettings::class)
            ->set('addressLines', "P. O. Box 77\nAccra, Ghana\nWest Africa")
            ->set('defaultCc', "File\nRegional Chief Manager")
            ->call('saveLetterhead')
            ->assertHasNoErrors();

        $letterhead = $this->settings()->letterheadFor($this->accra->id);
        $this->assertSame(['P. O. Box 77', 'Accra, Ghana', 'West Africa'], $letterhead->address_lines);
        $this->assertSame(['File', 'Regional Chief Manager'], $letterhead->default_cc);

        // Even by calling the action directly, regional HR cannot change the company block.
        $page->call('saveCompany')->assertForbidden();
        $this->assertCount(11, LeaveLetterSetting::current()->board_members);

        Livewire::actingAs($this->headOfficeHr)->test(LetterSettings::class)
            ->set('registeredOffice', 'Ridge, Accra')
            ->call('saveCompany')
            ->assertHasNoErrors();

        $this->assertSame('Ridge, Accra', LeaveLetterSetting::current()->registered_office);
    }

    public function test_global_admin_and_super_admin_see_signatures_on_file_only_to_revoke_them(): void
    {
        $chief = $this->userOf($this->staff('RCM001', $this->accraOffice, $this->operations, ['regional_chief_manager']));
        $signature = app(\App\Services\Leave\SignatureService::class)->saveDrawn($chief, $this->signatureDataUrl(), 'abc12');

        foreach ([$this->admin, $this->superAdmin] as $viewer) {
            Livewire::actingAs($viewer)->test(LetterSettings::class)
                ->assertSee('Signatures on file')
                ->assertSee('Employee RCM001')
                ->assertDontSee('data:image', false);
        }

        Livewire::actingAs($this->admin)->test(LetterSettings::class)->call('revokeSignature', $chief->id);
        $this->assertNotNull($signature->fresh()->revoked_at);

        // Head Office HR can't revoke, even by calling the action.
        $other = $this->userOf($this->staff('RCM002', $this->kumasiOffice, $this->operations, ['regional_chief_manager']));
        app(\App\Services\Leave\SignatureService::class)->saveDrawn($other, $this->signatureDataUrl(), 'abc12');
        Livewire::actingAs($this->headOfficeHr)->test(LetterSettings::class)->call('revokeSignature', $other->id)->assertForbidden();
        $this->assertNotNull(app(\App\Services\Leave\SignatureService::class)->activeFor($other));
    }
}
