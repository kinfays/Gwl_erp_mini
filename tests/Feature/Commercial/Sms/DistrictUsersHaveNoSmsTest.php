<?php

namespace Tests\Feature\Commercial\Sms;

use App\Livewire\Commercial\Customers\Lists;
use App\Livewire\Commercial\Sms\Campaigns;
use App\Livewire\Commercial\Sms\Home;
use App\Livewire\Commercial\Sms\Send;
use App\Livewire\Commercial\Sms\Settings;
use App\Livewire\Commercial\Sms\Templates;
use App\Models\CommercialSmsCampaign;
use App\Models\User;
use App\Services\Commercial\Sms\SmsAccess;
use App\Services\Commercial\Sms\SmsAudienceResolver;
use App\Services\Commercial\Sms\SmsBalanceService;
use App\Services\Commercial\Sms\SmsCampaignService;
use App\Support\ErpNavigation;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Commercial\ReportWorkbooks;

/**
 * Phase 8a: SMS is closed to ANY district-limited user (one who sees only their own district), whatever roles or permissions they hold:
 * the menu entry, every route, every Livewire action, the balance, the reports and exports, the "send SMS" actions on the customer
 * lists, and the audience resolver. The Accra West lock stays for everyone else.
 */
class DistrictUsersHaveNoSmsTest extends SmsTestCase
{
    /** A district user in Accra West holding EVERY SMS permission (and the others they need): the permissions must not open it. */
    protected function districtUserWithEverySms(string $staffId = '910901'): User
    {
        return $this->userWith($staffId, [
            'commercial.view_customer_analytics', 'commercial.view_customer_details', 'commercial.export_reports',
            'commercial.send_sms', 'commercial.view_sms_balance', 'commercial.view_sms_reports', 'commercial.manage_sms_settings',
        ], $this->accraWest, $this->sowutuom, districtLimited: true);
    }

    public function test_the_district_user_is_denied_by_the_access_class_for_every_permission(): void
    {
        $user = $this->districtUserWithEverySms();

        $this->assertTrue(SmsAccess::enabled());
        $this->assertFalse(SmsAccess::userMay($user));

        foreach ([SmsAccess::SEND, SmsAccess::BALANCE, SmsAccess::REPORTS, SmsAccess::SETTINGS] as $permission) {
            $this->assertTrue($user->hasPermission($permission), 'they DO hold '.$permission);
            $this->assertFalse(SmsAccess::allows($user, $permission), "but {$permission} opens nothing for a district user");
        }

        $this->assertSame([], SmsAccess::allowedRegionIds($user));
        $this->assertNull(SmsAccess::campaignRegionId($user));
        $this->assertNotContains($user->id, SmsAccess::balanceRecipientIds($this->accraWest->id), 'no low-balance notice either');

        // the same permissions without the district limit are let in (the lock is the district, not the permission)
        $regional = $this->westWith('910902', ['commercial.send_sms', 'commercial.view_sms_balance']);
        $this->assertTrue(SmsAccess::allows($regional, SmsAccess::SEND));
        $this->assertFalse(SmsAccess::allows($regional, SmsAccess::SETTINGS), 'and without the permission still refused');
    }

    public function test_the_menu_has_no_sms_entry_and_every_sms_route_is_forbidden(): void
    {
        $user = $this->districtUserWithEverySms();

        $labels = collect(app(ErpNavigation::class)->build($user, 'commercial')['sidebar'])->pluck('label')->all();
        $this->assertNotContains('SMS', $labels);
        $this->assertContains('SMS', collect(app(ErpNavigation::class)->build($this->westOfficer(), 'commercial')['sidebar'])->pluck('label')->all(), 'the regional officer keeps it');

        foreach (['commercial.sms', 'commercial.sms.send', 'commercial.sms.campaigns', 'commercial.sms.templates', 'commercial.sms.settings'] as $name) {
            $this->actingAs($user)->get(route($name))->assertForbidden();
        }

        // a campaign of the region: even its report and export are closed
        $campaign = CommercialSmsCampaign::query()->create(['region_id' => $this->accraWest->id, 'created_by' => $this->westOfficer()->id, 'status' => 'completed', 'audience_type' => 'all', 'audience' => ['type' => 'all'], 'sender_id' => 'GWCL', 'body' => 'x', 'segments' => 1, 'encoding' => 'gsm7', 'name' => 'Ours']);
        $this->actingAs($user)->get(route('commercial.sms.campaigns.show', $campaign->id))->assertForbidden();
        $this->actingAs($user)->get(route('commercial.sms.campaigns.export', $campaign->id))->assertForbidden();
    }

    public function test_every_livewire_screen_and_action_answers_403(): void
    {
        $user = $this->districtUserWithEverySms();

        foreach ([Home::class, Send::class, Campaigns::class, Templates::class, Settings::class] as $component) {
            Livewire::actingAs($user)->test($component)->assertForbidden();
        }
    }

    public function test_the_customer_list_has_no_send_sms_action_and_refuses_it_on_the_server(): void
    {
        $this->loadCustomers(['routes' => [['name' => '1001', 'customers' => array_map(fn (int $n) => ReportWorkbooks::customer($n), range(1, 5))]]]);
        $user = $this->districtUserWithEverySms();

        $page = Livewire::actingAs($user)->test(Lists::class)->set('district', (string) $this->sowutuom->id);
        $this->assertFalse($page->viewData('canSms'), 'the tick boxes and the button are not drawn');
        $page->assertDontSee('Send SMS to ticked');

        // a request that ticks customers and calls the action anyway is refused
        $page->set('ticked', [$this->customerId(1), $this->customerId(2)])->call('sendSms')->assertForbidden();
        $this->assertNull(session('sms.selected'));

        // an Accra West person with the same permissions but no district limit keeps the button
        $regional = $this->westWith('910903', ['commercial.view_customer_analytics', 'commercial.view_customer_details', 'commercial.send_sms']);
        $this->assertTrue(Livewire::actingAs($regional)->test(Lists::class)->set('district', (string) $this->sowutuom->id)->viewData('canSms'));
    }

    public function test_the_audience_resolver_and_the_campaign_service_refuse_a_district_user(): void
    {
        $this->loadCustomers(['routes' => [['name' => '1001', 'customers' => array_map(fn (int $n) => ReportWorkbooks::customer($n), range(1, 5))]]]);
        $user = $this->districtUserWithEverySms();
        $resolver = app(SmsAudienceResolver::class);
        $spec = $resolver->normalise(['group' => 'all']);

        $refused = fn (callable $call) => $this->assertSame(403, $this->statusOf($call));

        $refused(fn () => $resolver->counts($spec, [$this->accraWest->id], $user));
        $refused(fn () => $resolver->sample($spec, [$this->accraWest->id], false, 3, $user));
        $refused(fn () => $resolver->parseAccountList('100000000001', [$this->accraWest->id], $user));
        $refused(fn () => $resolver->checkSelected([$this->customerId(1)], [$this->accraWest->id], $user));

        // the same calls for the regional officer work
        $this->assertSame(5, $resolver->counts($spec, [$this->accraWest->id], $this->westOfficer())['matched']);

        // the services ask SmsAccess first
        $refused(fn () => app(SmsCampaignService::class)->preview($user, ['audience' => ['group' => 'all'], 'body' => 'Hello']));
        $refused(fn () => app(SmsCampaignService::class)->create($user, ['audience' => ['group' => 'all'], 'body' => 'Hello', 'sender_id' => 'GWCL']));
        $refused(fn () => app(SmsBalanceService::class)->current() && SmsAccess::authorize($user, SmsAccess::BALANCE));

        $this->assertSame(0, CommercialSmsCampaign::query()->count());
        $this->assertSame(0, DB::table('commercial_sms_recipients')->count());
        $this->assertCount(0, $this->sms->sent ?? [], 'nothing reached the provider');
    }

    /** The HTTP status an aborting call answers (0 when it did not abort). */
    protected function statusOf(callable $call): int
    {
        try {
            $call();
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            return $exception->getStatusCode();
        }

        return 0;
    }
}
