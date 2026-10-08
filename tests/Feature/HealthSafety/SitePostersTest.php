<?php

namespace Tests\Feature\HealthSafety;

use App\Livewire\HealthSafety\ReportIncident;
use App\Livewire\HealthSafety\Sites;
use App\Models\AuditLog;
use App\Models\HsIncident;
use App\Models\HsSite;
use App\Services\HealthSafety\LabelBatch;
use App\Services\HealthSafety\SitePosterService;
use Livewire\Livewire;

/**
 * Phase 3b item 8: one A4 poster per site whose QR opens the report form with the site already chosen, and the form's
 * ?site= handling that does the choosing (pre-fills only; validation, permission and scope are untouched).
 */
class SitePostersTest extends HealthSafetyTestCase
{
    private function westOfficer(): \App\Models\User
    {
        return $this->officer('200001', $this->accraWest, $this->odorkor);
    }

    private function payPoint(string $name = 'Sowutuom Pay Point', ?\App\Models\District $district = null): HsSite
    {
        return $this->site($name, $district ?? $this->sowutuom, 'pay_point');
    }

    // ---------------------------------------------------------------- the poster PDF

    public function test_the_poster_pdf_is_produced_and_audited(): void
    {
        config(['gwl.hs_qr_base_url' => 'https://portal.gwcl.test']);
        $a = $this->payPoint('Pay Point A');
        $b = $this->payPoint('Pay Point B');
        $officer = $this->westOfficer();

        $result = app(SitePosterService::class)->print($officer, [$a->id, $b->id]);

        $this->assertSame(2, $result['count']);
        $this->assertStringStartsWith('%PDF', $result['pdf']);

        $entry = AuditLog::query()->where('action', 'health_safety.posters_printed')->firstOrFail();
        $this->assertSame(2, $entry->metadata['count']);
        $this->assertSame('https://portal.gwcl.test', $entry->metadata['base_url']);
        $this->assertStringNotContainsString('"ids"', json_encode($entry->metadata));
    }

    public function test_the_poster_link_opens_the_report_form_with_the_site(): void
    {
        config(['gwl.hs_qr_base_url' => 'https://portal.gwcl.test']);
        $site = $this->payPoint();

        $this->assertSame('https://portal.gwcl.test/health-safety/report?site='.$site->id, app(\App\Services\HealthSafety\QrLinks::class)->reportUrl($site->id));
    }

    public function test_the_poster_download_goes_through_a_one_use_batch_and_an_authorised_route(): void
    {
        $site = $this->payPoint();
        $officer = $this->westOfficer();

        $component = Livewire::actingAs($officer)->test(Sites::class)->set('selectedSites', [$site->id])->call('printPosters');
        $url = $component->effects['redirect'];
        $this->assertStringContainsString('/health-safety/sites/posters?batch=', $url);

        $response = $this->actingAs($officer)->get($url);
        $response->assertOk();
        $this->assertStringStartsWith('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());

        $this->actingAs($officer)->get($url)->assertStatus(410);
    }

    public function test_nothing_selected_is_a_message_not_a_download(): void
    {
        Livewire::actingAs($this->westOfficer())->test(Sites::class)->call('printPosters')->assertHasErrors(['posters'])->assertNoRedirect();
    }

    public function test_a_site_outside_the_scope_is_left_out_and_counted(): void
    {
        $mine = $this->payPoint('West Pay Point');
        $theirs = $this->payPoint('Kumasi Pay Point', $this->kumasi);

        $result = app(SitePosterService::class)->print($this->westOfficer(), [$mine->id, $theirs->id]);

        $this->assertSame(1, $result['count']);
        $this->assertSame(1, $result['excluded']);
    }

    public function test_an_inactive_site_gets_no_poster(): void
    {
        $site = $this->payPoint();
        $site->update(['is_active' => false]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(SitePosterService::class)->print($this->westOfficer(), [$site->id]);
    }

    public function test_without_manage_master_data_the_poster_route_and_action_are_forbidden(): void
    {
        $site = $this->payPoint();
        $manager = $this->districtManager('200003', $this->sowutuom);   // no manage_master_data

        $this->actingAs($manager)->get(route('health_safety.posters', ['id' => $site->id]))->assertForbidden();
        $this->actingAs($this->reporter())->get(route('health_safety.posters', ['id' => $site->id]))->assertForbidden();

        $token = app(LabelBatch::class)->stash($manager, [$site->id], 'standard');
        $this->actingAs($manager)->get(route('health_safety.posters', ['batch' => $token]))->assertForbidden();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(SitePosterService::class)->print($manager, [$site->id]);
    }

    // ---------------------------------------------------------------- ?site= on the report form

    public function test_a_pay_point_poster_fills_the_context_the_district_and_the_site(): void
    {
        $site = $this->payPoint();

        Livewire::withQueryParams(['site' => (string) $site->id])->actingAs($this->reporter('100001', $this->accraWest, $this->odorkor))->test(ReportIncident::class)
            ->assertSet('context', HsIncident::CONTEXT_PAY_POINT)
            ->assertSet('districtId', $this->sowutuom->id)
            ->assertSet('siteId', (string) $site->id);
    }

    public function test_a_district_office_poster_fills_the_context_and_the_district(): void
    {
        $site = $this->site('Odorkor District Office', $this->odorkor, 'district_office');

        Livewire::withQueryParams(['site' => (string) $site->id])->actingAs($this->reporter())->test(ReportIncident::class)
            ->assertSet('context', HsIncident::CONTEXT_DISTRICT_OFFICE)
            ->assertSet('districtId', $this->odorkor->id)
            ->assertSet('siteId', '');
    }

    public function test_a_regional_office_poster_fills_the_context(): void
    {
        $site = HsSite::query()->create(['name' => 'Accra West Regional Office', 'kind' => 'regional_office', 'region_id' => $this->accraWest->id, 'district_id' => null]);

        Livewire::withQueryParams(['site' => (string) $site->id])->actingAs($this->reporter())->test(ReportIncident::class)
            ->assertSet('context', HsIncident::CONTEXT_REGIONAL_OFFICE);
    }

    public function test_an_unknown_inactive_foreign_or_odd_site_is_ignored_without_error(): void
    {
        $inactive = $this->payPoint('Closed Pay Point');
        $inactive->update(['is_active' => false]);
        $foreign = $this->payPoint('Kumasi Pay Point', $this->kumasi);
        $depot = $this->site('Main Depot', $this->sowutuom, 'depot');
        $reporter = $this->reporter();

        foreach (['999999', (string) $inactive->id, (string) $foreign->id, (string) $depot->id, 'abc', '-3', '1.5', ''] as $value) {
            Livewire::withQueryParams(['site' => $value])->actingAs($reporter)->test(ReportIncident::class)
                ->assertOk()
                ->assertSet('context', '')
                ->assertSet('siteId', '')
                ->assertHasNoErrors();
        }
    }

    public function test_the_parameter_does_not_bypass_validation(): void
    {
        $site = $this->payPoint();
        $reporter = $this->reporter();

        Livewire::withQueryParams(['site' => (string) $site->id])->actingAs($reporter)->test(ReportIncident::class)
            ->assertSet('siteId', (string) $site->id)
            ->call('submit')
            ->assertHasErrors(['incidentType', 'description', 'firstAid']);

        $this->assertSame(0, HsIncident::query()->count());

        // Pre-filled, but the person can still change it: moving to another district clears the pay point, so the form asks again.
        Livewire::withQueryParams(['site' => (string) $site->id])->actingAs($reporter)->test(ReportIncident::class)
            ->set('districtId', $this->odorkor->id)
            ->set('incidentType', 'near_miss')->set('description', 'Wet floor')->set('firstAid', 'no_need')
            ->call('submit')
            ->assertHasErrors(['siteNameRaw']);
    }
}
