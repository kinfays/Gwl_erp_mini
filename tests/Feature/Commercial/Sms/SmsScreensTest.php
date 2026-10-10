<?php

namespace Tests\Feature\Commercial\Sms;

use App\Livewire\Commercial\Customers\Lists;
use App\Livewire\Commercial\Settings as GeneralSettings;
use App\Livewire\Commercial\Sms\Campaigns;
use App\Livewire\Commercial\Sms\Send;
use App\Livewire\Commercial\Sms\Settings;
use App\Livewire\Commercial\Sms\Templates;
use App\Models\AuditLog;
use App\Models\CommercialSmsCampaign;
use App\Models\CommercialSmsTemplate;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Commercial\ReportWorkbooks;

/** The SMS screens: the four-step send flow, ticking customers on the lists, templates, settings and the campaigns list. */
class SmsScreensTest extends SmsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['gwl.commercial_sms_chunk_size' => 10]);
    }

    protected function load(int $count, array $over = [], int $from = 1): void
    {
        $this->loadCustomers(['routes' => [['name' => '1001', 'customers' => array_map(fn (int $n) => ReportWorkbooks::customer($n, $over), range($from, $from + $count - 1))]]]);
    }

    // ---------------------------------------------------------------- the send flow

    public function test_the_four_steps_end_in_a_filed_and_sent_campaign(): void
    {
        $this->load(30);
        $officer = $this->westOfficer();

        $page = Livewire::actingAs($officer)->test(Send::class)
            ->assertSet('step', 1)
            ->call('next')                                          // counts first
            ->assertSet('step', 2)
            ->assertSet('counts.numbers', 30)
            ->set('body', 'Your water bill is ready. Pay at any GWCL office.')
            ->assertSee('1 part')
            ->call('next')
            ->assertSet('step', 3)
            ->assertSee('024****001')->assertDontSee('0240000001')
            ->call('next')
            ->assertSet('step', 4);

        $page->set('confirmCount', '29')->call('send')->assertHasErrors('confirm')->assertSee('Type the number of recipients (30)');
        $this->assertSame(0, CommercialSmsCampaign::query()->count());

        $page->set('confirmCount', '30')->call('send');

        $campaign = CommercialSmsCampaign::query()->firstOrFail();
        $page->assertRedirect(route('commercial.sms.campaigns.show', $campaign->id));
        $this->assertSame('completed', $campaign->fresh()->status);
        $this->assertCount(3, $this->sms->sent);

        // pressing Send again with the same form (a double click) files nothing more
        $page->call('send');
        $this->assertSame(1, CommercialSmsCampaign::query()->count());
    }

    public function test_the_steps_will_not_move_on_with_nothing_to_send_or_nothing_to_say(): void
    {
        $this->load(5, ['mobile' => null]);

        $page = Livewire::actingAs($this->westOfficer())->test(Send::class)->call('next');
        $page->assertSet('step', 1)->assertHasErrors('audience');

        $this->load(5);
        $page = Livewire::actingAs($this->westOfficer('910009'))->test(Send::class)->call('next')->assertSet('step', 2);
        $page->call('next')->assertSet('step', 2)->assertHasErrors('body');
        $page->set('body', 'Hi {nope}')->call('next')->assertSet('step', 2)->assertSee('Unknown merge field');
        $page->set('body', 'Hi {name}')->call('next')->assertSet('step', 2)->assertSee('permission to see customer names');
        $page->set('body', 'Hello')->set('senderId', 'EVIL')->call('next')->assertSet('step', 2)->assertHasErrors('senderId');
    }

    public function test_changing_who_clears_the_count_and_the_defaults_come_from_the_settings(): void
    {
        $this->load(10);
        config(['gwl.commercial_sms_debtors_min' => 250, 'gwl.commercial_sms_no_payment_default_days' => 60]);

        $page = Livewire::actingAs($this->westOfficer())->test(Send::class)
            ->assertSet('minDebt', '250')->assertSet('days', '60')
            ->call('countAudience')->assertSet('counts.numbers', 10)
            ->set('type', 'group')->assertSet('counts', null)
            ->set('group', 'debtors')->set('minDebt', '1000')->call('countAudience');

        $this->assertSame(0, $page->get('counts')['numbers'], 'nobody owes GH¢ 1,000 here');
        $page->set('minDebt', '100')->call('countAudience');
        $this->assertSame(10, $page->get('counts')['numbers']);

        $page->set('group', 'no_payment')->set('days', '30')->call('countAudience');
        $this->assertSame(0, $page->get('counts')['numbers'], 'everyone paid within 30 days of the file date in this fixture');
    }

    public function test_a_pasted_list_becomes_the_selection_and_reports_what_it_left_out(): void
    {
        $this->load(5);
        $this->loadCustomers($this->simpleSpec(101, 102, ['region' => 'ASHANTI', 'district' => 'KUMASI'], '2001'));

        $page = Livewire::actingAs($this->westOfficer())->test(Send::class)
            ->set('type', 'selected')
            ->set('pasted', "100000000001\n100000000002, 100000000101 100000000999 abc")
            ->call('usePasted');

        $this->assertCount(2, $page->get('selectedIds'));
        $this->assertSame(['added' => 2, 'invalid' => 1, 'not_found' => 1, 'outside' => 1, 'duplicates' => 0], $page->get('pasteReport'));
        $page->call('countAudience')->assertSet('counts.numbers', 2)->assertSee('2 selected customers');
    }

    public function test_customers_ticked_on_the_lists_arrive_selected_and_other_regions_are_left_out(): void
    {
        $this->load(5);
        $this->loadCustomers($this->simpleSpec(101, 102, ['region' => 'ASHANTI', 'district' => 'KUMASI'], '2001'));
        $sender = $this->userWith('910300', ['commercial.view_customer_analytics', 'commercial.view_customer_details', 'commercial.send_sms'], $this->accraWest, $this->sowutuom);

        $lists = Livewire::actingAs($sender)->test(Lists::class)->set('district', (string) $this->sowutuom->id)->assertSee('Send SMS to ticked');
        $ids = [$this->customerId(1), $this->customerId(2), $this->customerId(101)];
        $lists->set('ticked', $ids)->call('sendSms')->assertRedirect(route('commercial.sms.send'));

        $this->assertSame($ids, session('sms.selected'));

        Livewire::actingAs($sender)->test(Send::class)->assertSet('type', 'selected')->assertSet('tickedOutside', 1)->assertSee('belong to another region')
            ->call('countAudience')->assertSet('counts.numbers', 2);

        $this->assertNull(session('sms.selected'), 'taken once');

        // people who may not send see no tick boxes, and cannot hand ids over
        $analyst = $this->analyst('910301', $this->accraWest, $this->sowutuom);
        Livewire::actingAs($analyst)->test(Lists::class)->set('district', (string) $this->sowutuom->id)->assertDontSee('Send SMS to ticked')->set('ticked', $ids)->call('sendSms')->assertForbidden();
    }

    // ---------------------------------------------------------------- templates

    public function test_templates_are_saved_edited_deleted_and_scoped_to_the_region(): void
    {
        $this->load(3);
        $officer = $this->westOfficer();
        $foreign = CommercialSmsTemplate::query()->create(['region_id' => $this->ashanti->id, 'name' => 'Ashanti only', 'body' => 'Hello']);

        $page = Livewire::actingAs($officer)->test(Templates::class)->assertDontSee('Ashanti only')
            ->set('name', 'Reminder')->set('body', 'Please pay {balance} on {account_no}.')->call('save')->assertHasNoErrors()->assertSee('Reminder');

        $template = CommercialSmsTemplate::query()->where('name', 'Reminder')->firstOrFail();
        $this->assertSame($this->accraWest->id, $template->region_id);
        $this->assertSame(1, AuditLog::query()->where('action', 'commercial.sms_template_saved')->count());

        $page->call('edit', $template->id)->assertSet('body', 'Please pay {balance} on {account_no}.')->set('body', 'Pay {balance} please.')->call('save');
        $this->assertSame('Pay {balance} please.', $template->fresh()->body);

        $page->set('name', 'Bad')->set('body', 'Hi {name}')->call('save')->assertHasErrors('body');
        $page->set('name', 'Reminder')->set('body', 'x')->call('save')->assertHasErrors('body');   // the name is taken

        $page->call('delete', $template->id);
        $this->assertNull(CommercialSmsTemplate::query()->find($template->id));

        // another region's template cannot be reached by id
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        app(\App\Services\Commercial\Sms\SmsCampaignService::class)->deleteTemplate($officer, $foreign->id);
    }

    public function test_a_picked_template_fills_the_message(): void
    {
        $this->load(3);
        $officer = $this->westOfficer();
        $template = CommercialSmsTemplate::query()->create(['region_id' => $this->accraWest->id, 'name' => 'Notice', 'body' => 'Supply is off tomorrow.']);

        Livewire::actingAs($officer)->test(Send::class)->set('templateId', (string) $template->id)->assertSet('body', 'Supply is off tomorrow.');
    }

    // ---------------------------------------------------------------- settings

    public function test_only_super_admin_edits_the_sms_settings_and_the_general_screen_leaves_them_alone(): void
    {
        $this->actingAs($this->westOfficer())->get(route('commercial.sms.settings'))->assertForbidden();

        $admin = $this->superAdmin();
        Livewire::actingAs($admin)->test(Settings::class)
            ->set('values.commercial_sms_low_balance', 350)->set('values.commercial_sms_debtors_min', 75.5)->set('values.commercial_sms_chunk_size', 25)
            ->call('save')->assertHasNoErrors();

        $this->assertSame(350, config('gwl.commercial_sms_low_balance'));
        $this->assertSame(75.5, config('gwl.commercial_sms_debtors_min'));
        $this->assertSame(25, config('gwl.commercial_sms_chunk_size'));
        $this->assertSame(1, AuditLog::query()->where('action', 'commercial.sms_settings_changed')->count());
        $this->assertStringNotContainsString((string) config('gwl.arkesel.api_key'), json_encode(AuditLog::query()->get()->toArray()));

        Livewire::actingAs($admin)->test(Settings::class)->set('values.commercial_sms_low_balance', -5)->call('save')->assertHasErrors('values.commercial_sms_low_balance');
        Livewire::actingAs($admin)->test(Settings::class)->assertSee('set (never shown)')->assertDontSee('test-key-never-real');

        // the general settings screen does not show or touch them, and still saves its own
        Livewire::actingAs($admin)->test(GeneralSettings::class)->assertDontSee('SMS to customers')->call('save')->assertHasNoErrors();
        $this->assertSame(350, config('gwl.commercial_sms_low_balance'));

        Livewire::actingAs($admin)->test(Settings::class)->call('resetAll');
        $this->assertSame(200, config('gwl.commercial_sms_low_balance'));
    }

    // ---------------------------------------------------------------- campaigns list

    public function test_the_campaigns_list_shows_only_the_regions_own_and_cancels_open_ones(): void
    {
        $this->load(12);
        $officer = $this->westOfficer();
        \Illuminate\Support\Facades\Queue::fake();
        $mine = app(\App\Services\Commercial\Sms\SmsCampaignService::class)->create($officer, [
            'audience' => ['type' => 'all'], 'sender_id' => 'GWCL', 'body' => 'Hello', 'confirm_checked' => true, 'submission_token' => 'x1', 'scheduled_at' => now()->addHour()->toDateTimeString(),
        ]);
        $theirs = CommercialSmsCampaign::query()->create([
            'region_id' => $this->ashanti->id, 'created_by' => null, 'status' => 'completed', 'audience_type' => 'all', 'audience' => ['type' => 'all'], 'sender_id' => 'OTHER', 'body' => 'x', 'segments' => 1, 'encoding' => 'gsm7', 'name' => 'Not ours',
        ]);

        $page = Livewire::actingAs($officer)->test(Campaigns::class)->assertSee('#'.$mine->id)->assertDontSee('Not ours')->assertDontSee('OTHER');
        $page->set('status', 'completed')->assertDontSee('#'.$mine->id)->set('status', '')->call('cancel', $mine->id);
        $this->assertSame('cancelled', $mine->fresh()->status);

        // another region's campaign cannot be cancelled by id
        $page->call('cancel', $theirs->id)->assertNotFound();
        $this->assertSame('completed', $theirs->fresh()->status);

        $this->actingAs($this->eastOfficer())->get(route('commercial.sms.campaigns'))->assertForbidden();
        $this->assertSame(1, DB::table('commercial_sms_campaigns')->where('status', 'cancelled')->count());
    }
}
