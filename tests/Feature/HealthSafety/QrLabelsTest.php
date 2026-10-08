<?php

namespace Tests\Feature\HealthSafety;

use App\Livewire\HealthSafety\ExtinguisherShow;
use App\Livewire\HealthSafety\Extinguishers;
use App\Livewire\HealthSafety\FirstAidKits;
use App\Livewire\HealthSafety\FirstAidKitShow;
use App\Models\AuditLog;
use App\Models\HsFireExtinguisher;
use App\Models\HsFirstAidKit;
use App\Services\HealthSafety\EquipmentExpiryService;
use App\Services\HealthSafety\EquipmentLabelService;
use App\Services\HealthSafety\LabelBatch;
use App\Services\HealthSafety\QrCodeGenerator;
use App\Services\HealthSafety\QrLinks;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * Phase 3b: QR label sheets for extinguishers and kits, and what a scanned code opens. The QR image itself is never
 * decoded here (that needs a camera): what is tested is the link the service builds, that a PDF comes out, what is stamped
 * as printed, and who may do what.
 */
class QrLabelsTest extends HealthSafetyTestCase
{
    private function labels(): EquipmentLabelService
    {
        return app(EquipmentLabelService::class);
    }

    private function westOfficer(): \App\Models\User
    {
        return $this->officer('200001', $this->accraWest, $this->odorkor);
    }

    private function assertPdf($response): void
    {
        $response->assertOk();
        $this->assertStringStartsWith('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    // ---------------------------------------------------------------- the link

    public function test_the_scan_url_uses_the_configured_base_and_falls_back_to_the_app_url(): void
    {
        config(['app.url' => 'https://erp.example.org', 'gwl.hs_qr_base_url' => null]);
        $this->assertSame('https://erp.example.org/health-safety/scan/extinguisher/7', app(QrLinks::class)->scanUrl('extinguisher', 7));

        config(['gwl.hs_qr_base_url' => 'https://portal.gwcl.test/']);
        $this->assertSame('https://portal.gwcl.test/health-safety/scan/kit/7', app(QrLinks::class)->scanUrl('kit', 7));

        config(['gwl.hs_qr_base_url' => '   ']);
        $this->assertSame('https://erp.example.org', app(QrLinks::class)->baseUrl(), 'blank means APP_URL');
    }

    public function test_the_link_holds_the_numeric_id_never_the_asset_code(): void
    {
        config(['gwl.hs_qr_base_url' => 'https://portal.gwcl.test']);
        $odd = $this->unit(['asset_code' => 'FE/12 B']);
        $plain = $this->unit(['asset_code' => 'FE-12']);

        $oddLabel = $this->labels()->labelFor('extinguisher', $odd->load('site'));
        $plainLabel = $this->labels()->labelFor('extinguisher', $plain->load('site'));

        $this->assertSame('https://portal.gwcl.test/health-safety/scan/extinguisher/'.$odd->id, $oddLabel['url']);
        $this->assertStringNotContainsString('FE', $oddLabel['url']);
        $this->assertStringNotContainsString(' ', $oddLabel['url']);
        $this->assertSame(strlen($oddLabel['url']) - strlen((string) $odd->id), strlen($plainLabel['url']) - strlen((string) $plain->id), 'same shape whatever the code');
    }

    public function test_a_label_carries_code_place_and_the_scan_line_but_no_dates(): void
    {
        $site = $this->site('Sowutuom District Office');
        $unit = $this->unit(['asset_code' => 'FE-001', 'location_detail' => 'By the front door', 'expiry_date' => '2031-05-17', 'capacity' => '9 kg'], $site);

        $label = $this->labels()->labelFor('extinguisher', $unit->load(['site', 'vehicle', 'region']));

        $this->assertSame('FE-001', $label['code']);
        $this->assertSame('Sowutuom District Office', $label['site']);
        $this->assertSame('By the front door', $label['where']);
        $this->assertSame('Scan to record monthly check', $label['line']);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $label['qr']);
        $this->assertStringNotContainsString('2031', json_encode(array_diff_key($label, ['qr' => 1])));
    }

    // ---------------------------------------------------------------- the PDF

    public function test_a_single_label_downloads_as_a_pdf_in_both_layouts_and_stamps_it_printed(): void
    {
        $unit = $this->unit(['asset_code' => 'FE-001'], $this->site('Sowutuom District Office', $this->sowutuom));
        $officer = $this->westOfficer();

        foreach (['standard', 'large'] as $layout) {
            $this->assertPdf($this->actingAs($officer)->get(route('health_safety.labels.extinguishers', ['id' => $unit->id, 'layout' => $layout])));
        }

        $this->assertNotNull($unit->fresh()->label_printed_at);
    }

    public function test_kit_labels_work_the_same_way(): void
    {
        $kit = $this->kit(['asset_code' => 'KIT-001'], [], $this->site('Sowutuom District Office', $this->sowutuom));

        $this->assertPdf($this->actingAs($this->westOfficer())->get(route('health_safety.labels.kits', ['id' => $kit->id])));
        $this->assertNotNull($kit->fresh()->label_printed_at);
    }

    public function test_the_maximum_number_of_labels_renders_within_memory(): void
    {
        $site = $this->site('Sowutuom District Office', $this->sowutuom);
        $ids = collect(range(1, EquipmentLabelService::max()))->map(fn ($n) => $this->unit(['asset_code' => sprintf('FE-%03d', $n)], $site)->id)->all();
        $officer = $this->westOfficer();

        foreach (['standard', 'large'] as $layout) {
            memory_reset_peak_usage();
            $before = memory_get_usage();
            $result = $this->labels()->print($officer, 'extinguisher', $ids, $layout);
            $this->assertLessThan(64 * 1024 * 1024, memory_get_peak_usage() - $before, 'a full sheet needs well under 64 MB on top of the application (measured: about 37 MB)');

            $this->assertSame(EquipmentLabelService::max(), $result['count']);
            $this->assertStringStartsWith('%PDF', $result['pdf']);
        }
    }

    public function test_more_than_the_maximum_is_refused_and_nothing_is_printed(): void
    {
        config(['gwl.hs_labels_per_pdf_max' => 3]);
        $site = $this->site('Sowutuom District Office', $this->sowutuom);
        $ids = collect(range(1, 4))->map(fn ($n) => $this->unit(['asset_code' => "FE-{$n}"], $site)->id)->all();

        try {
            $this->labels()->print($this->westOfficer(), 'extinguisher', $ids);
            $this->fail('A sheet over the maximum must be refused.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('at most 3', $exception->errors()['labels'][0]);
        }

        $this->assertSame(0, HsFireExtinguisher::query()->whereNotNull('label_printed_at')->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'health_safety.labels_printed')->count());
    }

    public function test_the_list_refuses_too_many_with_a_message_and_prints_nothing(): void
    {
        config(['gwl.hs_labels_per_pdf_max' => 2]);
        $site = $this->site('Sowutuom District Office', $this->sowutuom);
        foreach (range(1, 3) as $n) {
            $this->unit(['asset_code' => "FE-{$n}"], $site);
        }

        Livewire::actingAs($this->westOfficer())->test(Extinguishers::class)
            ->call('printAllInFilter')
            ->assertHasErrors(['labels'])
            ->assertNoRedirect();
    }

    // ---------------------------------------------------------------- what is stamped

    public function test_a_rendering_failure_leaves_the_items_unstamped(): void
    {
        $unit = $this->unit(['asset_code' => 'FE-001'], $this->site('Sowutuom District Office', $this->sowutuom));

        $failing = new class(app(\App\Services\HealthSafety\EquipmentScope::class), app(QrCodeGenerator::class), app(QrLinks::class)) extends EquipmentLabelService
        {
            protected function renderPdf(string $html): string
            {
                throw new \RuntimeException('the renderer broke');
            }
        };

        try {
            $failing->print($this->westOfficer(), 'extinguisher', [$unit->id]);
            $this->fail('The failure must surface.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertNull($unit->fresh()->label_printed_at);
        $this->assertSame(0, AuditLog::query()->where('action', 'health_safety.labels_printed')->count());
    }

    public function test_an_item_outside_the_scope_is_left_out_and_counted(): void
    {
        $mine = $this->unit(['asset_code' => 'FE-MINE'], $this->site('Sowutuom District Office', $this->sowutuom));
        $theirs = $this->unit(['asset_code' => 'FE-KUMASI'], $this->site('Kumasi Office', $this->kumasi));

        $result = $this->labels()->print($this->westOfficer(), 'extinguisher', [$mine->id, $theirs->id]);

        $this->assertSame(1, $result['count']);
        $this->assertSame(1, $result['excluded']);
        $this->assertNotNull($mine->fresh()->label_printed_at);
        $this->assertNull($theirs->fresh()->label_printed_at);
    }

    public function test_a_regional_officer_cannot_print_another_regions_labels(): void
    {
        $theirs = $this->unit(['asset_code' => 'FE-KUMASI'], $this->site('Kumasi Office', $this->kumasi));

        $this->actingAs($this->westOfficer())->get(route('health_safety.labels.extinguishers', ['id' => $theirs->id]))->assertStatus(422);

        $this->assertNull($theirs->fresh()->label_printed_at);
    }

    public function test_decommissioned_items_are_not_printed(): void
    {
        $gone = $this->unit(['asset_code' => 'FE-GONE', 'status' => 'decommissioned'], $this->site('Sowutuom District Office', $this->sowutuom));
        $live = $this->unit(['asset_code' => 'FE-LIVE'], $this->site('Odorkor Office', $this->odorkor));

        $result = $this->labels()->print($this->westOfficer(), 'extinguisher', [$gone->id, $live->id]);

        $this->assertSame(1, $result['count']);
        $this->assertNull($gone->fresh()->label_printed_at);
    }

    public function test_the_audit_entry_records_the_base_url_and_count_but_not_the_ids(): void
    {
        config(['gwl.hs_qr_base_url' => 'https://portal.gwcl.test']);
        $site = $this->site('Sowutuom District Office', $this->sowutuom);
        $a = $this->unit(['asset_code' => 'FE-AAA'], $site);
        $b = $this->unit(['asset_code' => 'FE-BBB'], $site);

        $this->labels()->print($this->westOfficer(), 'extinguisher', [$a->id, $b->id]);

        $entry = AuditLog::query()->where('action', 'health_safety.labels_printed')->firstOrFail();
        $metadata = $entry->metadata;

        $this->assertSame('https://portal.gwcl.test', $metadata['base_url']);
        $this->assertSame(2, $metadata['count']);
        $this->assertSame('FE-AAA', $metadata['first']);
        $this->assertSame('FE-BBB', $metadata['last']);
        $this->assertArrayNotHasKey('ids', $metadata);
        $this->assertStringNotContainsString('"ids"', json_encode($metadata));
    }

    // ---------------------------------------------------------------- who may print

    public function test_without_manage_equipment_the_label_routes_and_actions_are_forbidden(): void
    {
        $unit = $this->unit(['asset_code' => 'FE-001'], $this->site('Sowutuom District Office', $this->sowutuom));
        $manager = $this->districtManager('200003', $this->sowutuom);   // views and checks, but does not manage

        $this->actingAs($manager)->get(route('health_safety.labels.extinguishers', ['id' => $unit->id]))->assertForbidden();
        $this->actingAs($manager)->get(route('health_safety.labels.kits', ['id' => 1]))->assertForbidden();
        $this->actingAs($this->reporter())->get(route('health_safety.labels.extinguishers', ['id' => $unit->id]))->assertForbidden();

        Livewire::actingAs($manager)->test(Extinguishers::class)->set('selected', [$unit->id])->call('printSelected')->assertForbidden();
        Livewire::actingAs($manager)->test(FirstAidKits::class)->call('printAllInFilter')->assertForbidden();
        Livewire::actingAs($manager)->test(Extinguishers::class)->assertDontSee('Print labels for all');

        $this->assertNull($unit->fresh()->label_printed_at);
        $this->assertTrue(Cache::get('nothing') === null);
    }

    public function test_the_list_hands_ticked_rows_to_a_one_use_download(): void
    {
        $site = $this->site('Sowutuom District Office', $this->sowutuom);
        $a = $this->unit(['asset_code' => 'FE-AAA'], $site);
        $this->unit(['asset_code' => 'FE-BBB'], $site);
        $officer = $this->westOfficer();

        $component = Livewire::actingAs($officer)->test(Extinguishers::class)
            ->set('selected', [$a->id])
            ->set('labelLayout', 'large')
            ->call('printSelected');

        $url = $component->effects['redirect'];
        $this->assertStringContainsString('/health-safety/labels/extinguishers?batch=', $url);

        $this->assertPdf($this->actingAs($officer)->get($url));
        $this->actingAs($officer)->get($url)->assertStatus(410);   // used up
        $this->assertNotNull($a->fresh()->label_printed_at);
    }

    public function test_a_batch_is_useless_to_anyone_else(): void
    {
        $unit = $this->unit(['asset_code' => 'FE-001'], $this->site('Sowutuom District Office', $this->sowutuom));
        $owner = $this->westOfficer();
        $other = $this->hsManager('200002', $this->accraWest, $this->odorkor);

        $token = app(LabelBatch::class)->stash($owner, [$unit->id], 'standard');

        $this->actingAs($other)->get(route('health_safety.labels.extinguishers', ['batch' => $token]))->assertStatus(410);
        $this->assertNull($unit->fresh()->label_printed_at);
    }

    // ---------------------------------------------------------------- "no label yet"

    public function test_the_no_label_filter_returns_exactly_the_unlabelled_items(): void
    {
        $site = $this->site('Sowutuom District Office', $this->sowutuom);
        $this->unit(['asset_code' => 'FE-PRINTED'], $site)->forceFill(['label_printed_at' => now()])->save();
        $this->unit(['asset_code' => 'FE-NEW'], $site);
        $this->kit(['asset_code' => 'KIT-PRINTED'], [], $site)->forceFill(['label_printed_at' => now()])->save();
        $this->kit(['asset_code' => 'KIT-NEW'], [], $site);
        $officer = $this->westOfficer();
        $service = app(EquipmentExpiryService::class);

        $this->assertSame(['FE-NEW'], $service->extinguishers($officer, ['label' => 'none'])->pluck('asset_code')->all());
        $this->assertSame(['KIT-NEW'], $service->kits($officer, ['label' => 'none'])->pluck('asset_code')->all());
        $this->assertCount(2, $service->extinguishers($officer)->get());

        Livewire::withQueryParams(['label' => 'none'])->actingAs($officer)->test(Extinguishers::class)
            ->assertSee('FE-NEW')->assertDontSee('FE-PRINTED');
        Livewire::withQueryParams(['label' => 'none'])->actingAs($officer)->test(FirstAidKits::class)
            ->assertSee('KIT-NEW')->assertDontSee('KIT-PRINTED');
        Livewire::withQueryParams(['label' => 'junk'])->actingAs($officer)->test(Extinguishers::class)
            ->assertSet('label', '')->assertSee('FE-PRINTED');
    }

    public function test_the_list_shows_the_address_the_codes_will_open(): void
    {
        config(['gwl.hs_qr_base_url' => 'https://portal.gwcl.test']);
        $this->unit([], $this->site('Sowutuom District Office', $this->sowutuom));

        Livewire::actingAs($this->westOfficer())->test(Extinguishers::class)
            ->assertSee('Labels will open')
            ->assertSee('https://portal.gwcl.test')
            ->assertSee('production');
    }

    // ---------------------------------------------------------------- the scan page

    public function test_a_logged_out_scan_goes_to_login_and_returns_to_the_scan_afterwards(): void
    {
        $unit = $this->unit(['asset_code' => 'FE-001'], $this->site('Sowutuom District Office', $this->sowutuom));
        $officer = $this->westOfficer();
        $officer->forceFill(['password' => Hash::make('a-good-password-1')])->save();
        $scan = route('health_safety.scan', ['type' => 'extinguisher', 'id' => $unit->id]);

        $this->get($scan)->assertRedirect(route('login'));

        $this->post(route('login'), ['staff_id' => $officer->staff_id, 'password' => 'a-good-password-1'])->assertRedirect($scan);
    }

    public function test_an_officer_scanning_lands_on_the_unit_with_the_check_form_open(): void
    {
        $unit = $this->unit(['asset_code' => 'FE-001'], $this->site('Sowutuom District Office', $this->sowutuom));
        $officer = $this->westOfficer();

        $this->actingAs($officer)->get(route('health_safety.scan', ['type' => 'extinguisher', 'id' => $unit->id]))
            ->assertRedirect(route('health_safety.extinguishers.show', ['extinguisher' => $unit->id, 'check' => 1]));

        Livewire::actingAs($officer)->test(ExtinguisherShow::class, ['extinguisher' => $unit])->assertSet('panel', '');
        Livewire::withQueryParams(['check' => 1])->actingAs($officer)->test(ExtinguisherShow::class, ['extinguisher' => $unit])
            ->assertSet('panel', 'check');
    }

    public function test_the_same_goes_for_a_kit(): void
    {
        $kit = $this->kit(['asset_code' => 'KIT-001'], [['Plasters', 10, 10, null]], $this->site('Sowutuom District Office', $this->sowutuom));
        $officer = $this->westOfficer();

        $this->actingAs($officer)->get(route('health_safety.scan', ['type' => 'kit', 'id' => $kit->id]))
            ->assertRedirect(route('health_safety.kits.show', ['kit' => $kit->id, 'check' => 1]));

        Livewire::withQueryParams(['check' => 1])->actingAs($officer)->test(FirstAidKitShow::class, ['kit' => $kit])->assertSet('panel', 'check');
    }

    public function test_the_named_responsible_person_gets_the_form_on_their_item_only(): void
    {
        $site = $this->site('Sowutuom District Office', $this->sowutuom);
        $person = $this->reporter('100010', $this->accraWest, $this->sowutuom);
        $mine = $this->unit(['asset_code' => 'FE-MINE', 'responsible_employee_id' => $person->employee->id], $site);
        $other = $this->unit(['asset_code' => 'FE-OTHER'], $site);

        $this->actingAs($person)->get(route('health_safety.scan', ['type' => 'extinguisher', 'id' => $mine->id]))
            ->assertRedirect(route('health_safety.extinguishers.show', ['extinguisher' => $mine->id, 'check' => 1]));

        $this->actingAs($person)->get(route('health_safety.scan', ['type' => 'extinguisher', 'id' => $other->id]))
            ->assertForbidden()->assertSee('This item is not available to you');

        Livewire::withQueryParams(['check' => 1])->actingAs($person)->test(ExtinguisherShow::class, ['extinguisher' => $mine])->assertSet('panel', 'check');
    }

    public function test_a_view_only_user_lands_on_the_unit_without_the_form(): void
    {
        $unit = $this->unit(['asset_code' => 'FE-001'], $this->site('Sowutuom District Office', $this->sowutuom));
        $chief = $this->chiefManager('200004', $this->accraWest);   // view_equipment, no record_checks

        $this->actingAs($chief)->get(route('health_safety.scan', ['type' => 'extinguisher', 'id' => $unit->id]))
            ->assertRedirect(route('health_safety.extinguishers.show', ['extinguisher' => $unit->id]));

        Livewire::withQueryParams(['check' => 1])->actingAs($chief)->test(ExtinguisherShow::class, ['extinguisher' => $unit])
            ->assertSet('panel', '')
            ->assertDontSee('Record check');
    }

    public function test_an_unreachable_id_and_a_missing_id_give_the_identical_page(): void
    {
        $theirs = $this->unit(['asset_code' => 'FE-KUMASI'], $this->site('Kumasi Office', $this->kumasi));
        $officer = $this->westOfficer();

        $outOfScope = $this->actingAs($officer)->get(route('health_safety.scan', ['type' => 'extinguisher', 'id' => $theirs->id]));
        $missing = $this->actingAs($officer)->get(route('health_safety.scan', ['type' => 'extinguisher', 'id' => 987654]));
        $wrongKind = $this->actingAs($officer)->get(route('health_safety.scan', ['type' => 'kit', 'id' => $theirs->id]));

        $this->assertSame($outOfScope->getStatusCode(), $missing->getStatusCode());
        $this->assertSame($outOfScope->getStatusCode(), $wrongKind->getStatusCode());
        $this->assertSame(403, $missing->getStatusCode());
        foreach ([$outOfScope, $missing, $wrongKind] as $response) {
            $response->assertViewIs('health_safety.scan-unavailable');
        }
        $this->assertSame($this->bodyOf($outOfScope), $this->bodyOf($missing));
        $this->assertSame($this->bodyOf($outOfScope), $this->bodyOf($wrongKind));
        $this->assertStringNotContainsString('FE-KUMASI', $outOfScope->getContent());
    }

    public function test_a_decommissioned_unit_still_resolves_with_a_banner_and_no_check_form(): void
    {
        $unit = $this->unit(['asset_code' => 'FE-OLD', 'status' => 'decommissioned', 'decommissioned_on' => today()->toDateString(), 'decommission_reason' => 'Sold'], $this->site('Sowutuom District Office', $this->sowutuom));
        $kit = $this->kit(['asset_code' => 'KIT-OLD', 'status' => 'decommissioned', 'decommissioned_on' => today()->toDateString(), 'decommission_reason' => 'Lost'], [], $this->site('Odorkor Office', $this->odorkor));
        $officer = $this->westOfficer();

        $this->actingAs($officer)->get(route('health_safety.scan', ['type' => 'extinguisher', 'id' => $unit->id]))
            ->assertRedirect(route('health_safety.extinguishers.show', ['extinguisher' => $unit->id]));

        Livewire::withQueryParams(['check' => 1])->actingAs($officer)->test(ExtinguisherShow::class, ['extinguisher' => $unit])
            ->assertSet('panel', '')
            ->assertSee('This unit is decommissioned');
        Livewire::actingAs($officer)->test(FirstAidKitShow::class, ['kit' => $kit])->assertSee('This kit is decommissioned');
    }

    /** The card the person reads. The surrounding layout carries per-request Livewire ids and the request path, which differ. */
    private function bodyOf($response): string
    {
        return \Illuminate\Support\Str::of($response->getContent())->between('This item is not available to you', 'Go to Health')->squish()->toString();
    }
}
