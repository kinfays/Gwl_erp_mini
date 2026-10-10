<?php

namespace Tests\Feature\Commercial\Sms;

use App\Livewire\Commercial\Sms\Home;
use App\Models\CommercialSmsCampaign;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Commercial\Sms\SmsAccess;
use App\Support\ErpNavigation;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Who may use the SMS feature: the flag, the region lock (Accra West only), Head Office, and the four permissions. */
class SmsAccessTest extends SmsTestCase
{
    public function test_the_four_permissions_are_seeded_and_only_the_officer_and_super_admin_hold_them(): void
    {
        $slugs = ['commercial.send_sms', 'commercial.view_sms_balance', 'commercial.view_sms_reports', 'commercial.manage_sms_settings'];
        $this->assertSame(4, Permission::query()->where('module', 'commercial')->whereIn('name', $slugs)->count());

        $holds = fn (string $role) => collect($slugs)->filter(
            fn ($slug) => Role::query()->where('name', $role)->firstOrFail()->permissions()->where('name', $slug)->exists()
        )->values()->all();

        $this->assertSame($slugs, $holds('super_admin'));
        $this->assertSame(['commercial.send_sms', 'commercial.view_sms_balance', 'commercial.view_sms_reports'], $holds('commercial_officer'));

        foreach (['commercial_manager', 'admin', 'chief_manager', 'regional_chief_manager', 'district_manager', 'employee', 'hr_headoffice'] as $role) {
            $this->assertSame([], $holds($role), "{$role} must hold no SMS permission");
        }
    }

    public function test_an_accra_west_officer_is_let_in_and_sees_the_balance(): void
    {
        $officer = $this->westOfficer();

        $this->assertTrue(SmsAccess::allows($officer, SmsAccess::SEND));
        $this->assertTrue(SmsAccess::allows($officer, SmsAccess::BALANCE));
        $this->assertTrue(SmsAccess::allows($officer, SmsAccess::REPORTS));
        $this->assertFalse(SmsAccess::allows($officer, SmsAccess::SETTINGS), 'settings are super_admin only');

        $this->actingAs($officer)->get(route('commercial.sms'))->assertOk()->assertSee('SMS to customers')->assertSee('100,000');
    }

    public function test_an_officer_of_another_region_is_refused_everywhere(): void
    {
        $other = $this->eastOfficer();

        $this->assertFalse(SmsAccess::userMay($other));
        $this->assertFalse(SmsAccess::allows($other, SmsAccess::SEND));
        $this->assertSame([], SmsAccess::allowedRegionIds($other));

        $this->actingAs($other)->get(route('commercial.sms'))->assertForbidden();
        Livewire::actingAs($other)->test(Home::class)->assertForbidden();
    }

    public function test_head_office_staff_are_not_let_in_even_though_they_share_the_regions_id(): void
    {
        $headOffice = $this->headOfficeOfficer();

        $this->assertSame($this->accraWest->id, (int) $headOffice->employee->region_id, 'sanity: Head Office staff carry the Accra West region id');
        $this->assertSame('HeadOffice', $headOffice->employee->location_type);
        $this->assertFalse(SmsAccess::userMay($headOffice));

        $this->actingAs($headOffice)->get(route('commercial.sms'))->assertForbidden();
    }

    public function test_super_admin_is_let_in_and_global_admin_is_not_unless_they_work_in_the_region(): void
    {
        $this->assertTrue(SmsAccess::allows($this->superAdmin(), SmsAccess::SETTINGS));
        $this->actingAs($this->superAdmin())->get(route('commercial.sms'))->assertOk();

        $admin = $this->userWithRoles('910010', ['admin'], $this->ashanti, $this->kumasi);
        $this->assertFalse(SmsAccess::userMay($admin), 'Global Admin sees every region in the customer list, but is not Accra West staff');
        $this->assertContains($this->actingAs($admin)->get(route('commercial.sms'))->status(), [302, 403], 'turned away by the module/permission middleware or by SmsAccess');

        // and a Global Admin who does work in Accra West and holds the permission is like any other Accra West user
        $westAdmin = $this->userWith('910011', ['commercial.send_sms'], $this->accraWest, $this->sowutuom);
        $westAdmin->roles()->syncWithoutDetaching(Role::query()->where('name', 'admin')->first());
        $this->assertTrue(SmsAccess::allows($westAdmin->fresh(), SmsAccess::SEND));
    }

    public function test_a_west_user_without_any_sms_permission_gets_403(): void
    {
        $viewer = $this->westWith('910020', ['commercial.view_customer_analytics']);

        $this->assertFalse(SmsAccess::allows($viewer, SmsAccess::SEND));
        $this->actingAs($viewer)->get(route('commercial.sms'))->assertForbidden();
    }

    public function test_a_user_with_no_employee_record_is_refused(): void
    {
        $user = $this->userWithoutEmployee('910030', ['commercial_officer']);

        $this->assertFalse(SmsAccess::userMay($user));
        $this->assertFalse(SmsAccess::userMay(null));
    }

    public function test_the_region_names_are_resolved_at_run_time_not_by_id(): void
    {
        $this->assertSame([$this->accraWest->id], SmsAccess::regionIds());

        config(['gwl.commercial_sms_region_names' => ['ashanti']]);
        $this->assertSame([$this->ashanti->id], SmsAccess::regionIds(), 'names are matched case-insensitively');
        $this->assertFalse(SmsAccess::userMay($this->westOfficer()), 'and Accra West is no longer locked-in');
        $this->assertTrue(SmsAccess::userMay($this->eastOfficer()));

        config(['gwl.commercial_sms_region_names' => ['No Such Region']]);
        $this->assertSame([], SmsAccess::regionIds());
        $this->assertFalse(SmsAccess::userMay($this->superAdmin()) && SmsAccess::allowedRegionIds($this->superAdmin()) !== [], 'nothing to message when no region matches');
    }

    public function test_a_campaign_of_another_region_answers_404_and_the_scope_hides_it(): void
    {
        $west = CommercialSmsCampaign::query()->create($this->campaignRow($this->accraWest->id));
        $east = CommercialSmsCampaign::query()->create($this->campaignRow($this->ashanti->id));

        $officer = $this->westOfficer();

        $this->assertSame($west->id, SmsAccess::findCampaign($west->id, $officer)->id);

        try {
            SmsAccess::findCampaign($east->id, $officer);
            $this->fail('a campaign of another region must not be found');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame([$west->id], SmsAccess::scopeCampaigns(CommercialSmsCampaign::query(), $officer)->pluck('id')->all());
        $this->assertSame([], SmsAccess::scopeCampaigns(CommercialSmsCampaign::query(), $this->eastOfficer())->pluck('id')->all(), 'an Ashanti officer is outside the lock, so sees nothing even of Ashanti');
    }

    public function test_a_queued_job_re_checks_the_creator_and_the_region(): void
    {
        $officer = $this->westOfficer();
        $campaign = CommercialSmsCampaign::query()->create($this->campaignRow($this->accraWest->id, $officer->id));

        $this->assertTrue(SmsAccess::campaignMayRun($campaign->fresh()));
        $this->assertSame([$this->accraWest->id], SmsAccess::audienceRegionIds($campaign));

        // the creator is moved to another region: the campaign no longer runs
        $officer->employee->forceFill(['region_id' => $this->ashanti->id, 'district_id' => $this->kumasi->id])->save();
        $this->assertFalse(SmsAccess::campaignMayRun($campaign->fresh()));

        // a campaign filed under a region that is not locked-in never draws an audience
        $this->assertSame([], SmsAccess::audienceRegionIds($this->ashanti->id));
        $this->assertFalse(SmsAccess::campaignMayRun(CommercialSmsCampaign::query()->create($this->campaignRow($this->ashanti->id, $officer->id))));
    }

    public function test_with_the_flag_off_nothing_is_reachable(): void
    {
        $officer = $this->westOfficer();
        $this->assertTrue(SmsAccess::enabled());

        config(['gwl.commercial_sms_enabled' => false]);
        $this->assertFalse(SmsAccess::enabled());
        $this->assertFalse(SmsAccess::allows($officer, SmsAccess::SEND));
        $this->assertFalse(SmsAccess::allows($this->superAdmin(), SmsAccess::SEND));

        try {
            SmsAccess::authorize($officer, SmsAccess::SEND);
            $this->fail('expected a 404');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        // the module flag and the customer-list flag are conditions too
        config(['gwl.commercial_sms_enabled' => true, 'gwl.commercial_customer_list_enabled' => false]);
        $this->assertFalse(SmsAccess::enabled());
        config(['gwl.commercial_customer_list_enabled' => true, 'gwl.commercial_module_enabled' => false]);
        $this->assertFalse(SmsAccess::enabled());
    }

    public function test_with_the_flag_off_the_routes_do_not_exist_and_the_sidebar_hides_the_item(): void
    {
        $navigation = app(ErpNavigation::class);
        $labels = fn ($user) => collect($navigation->build($user, 'commercial')['sidebar'])->pluck('label')->all();

        $this->assertTrue(Route::has('commercial.sms'));
        $this->assertContains('SMS', $labels($this->westOfficer()));
        $this->assertNotContains('SMS', $labels($this->eastOfficer()));
        $this->assertNotContains('SMS', $labels($this->headOfficeOfficer()));
        $this->assertNotContains('SMS', $labels($this->westWith('910040', ['commercial.view_customer_analytics'])));

        $_ENV['GWL_COMMERCIAL_SMS_ENABLED'] = 'false';
        $_SERVER['GWL_COMMERCIAL_SMS_ENABLED'] = 'false';
        putenv('GWL_COMMERCIAL_SMS_ENABLED=false');
        $this->refreshApplication();

        try {
            $this->assertFalse(Route::has('commercial.sms'), 'the SMS routes must not be registered when the flag is off');
        } finally {
            $_ENV['GWL_COMMERCIAL_SMS_ENABLED'] = 'true';
            $_SERVER['GWL_COMMERCIAL_SMS_ENABLED'] = 'true';
            putenv('GWL_COMMERCIAL_SMS_ENABLED=true');
            $this->refreshApplication();
        }
    }

    /** @return array<string, mixed> */
    private function campaignRow(int $regionId, ?int $creatorId = null): array
    {
        return [
            'region_id' => $regionId, 'created_by' => $creatorId, 'status' => 'queued', 'audience_type' => 'all', 'audience' => ['type' => 'all'],
            'sender_id' => 'GWCL', 'body' => 'Hello', 'segments' => 1, 'encoding' => 'gsm7',
        ];
    }
}
