<?php

namespace Tests\Feature\Finance;

use App\Livewire\Finance\Settings;
use App\Livewire\Finance\VoucherForm;
use App\Livewire\Finance\Vouchers;
use App\Livewire\Finance\VoucherShow;
use App\Models\FinPvType;
use App\Services\Finance\FinanceExportService;
use App\Services\Finance\FinanceScope;
use App\Services\Finance\PvAnalyticsService;
use Livewire\Livewire;

/**
 * Regression for "renaming a category or type seems not to work". Drives the rename exactly as a person does (the Settings screen)
 * and then reads the new name everywhere it is shown: the voucher form's dropdowns, the list, the detail page, the Reports
 * figures and the exports.
 */
class PvCatalogRenameTest extends FinanceTestCase
{
    private function west(): array
    {
        return ['region_id' => $this->sowutuom->region_id, 'district_id' => $this->sowutuom->id];
    }

    public function test_a_renamed_type_shows_its_new_name_on_every_screen_report_and_export(): void
    {
        $this->withoutVite();
        $officer = $this->finance('F1', $this->sowutuom);
        $manager = $this->financeManager('FM1', $this->sowutuom);
        $pv = $this->sent($officer, ['pv_no' => 'REN-1']);
        $medical = FinPvType::query()->where('name', 'Medical')->firstOrFail();

        // Warm the cached figures BEFORE the rename: a stale cache is the first thing to rule out.
        $analytics = app(PvAnalyticsService::class);
        $before = $analytics->report($officer, FinanceScope::AS_FINANCE, $this->west(), '2026-10-01', '2026-11-30');
        $this->assertStringContainsString('Medical', json_encode($before['types']));

        // The person's steps on the Settings screen. Root cause 1 of "rename does nothing": the Rename button's handler was the literal
        // text `startRenameType(1, @js($type->name))` (Blade does not compile @js inside a component's attribute), so the click was
        // never a valid call. Calling the method directly hid it, so the rendered button is checked as well.
        Livewire::actingAs($manager)->test(Settings::class)
            ->assertDontSee('@js', false)->assertSeeHtml('wire:click="startRenameType('.$medical->id.')"')->call('startRenameType', $medical->id)
            ->assertSet('renameValue', 'Medical')
            ->set('renameValue', 'Medical Claims')
            ->call('saveRename')
            ->assertHasNoErrors()
            ->assertSee('Medical Claims');

        // A rename by a location is that location's override of the display name; the standard row is untouched.
        $this->assertSame('Medical', $medical->fresh()->name);
        $this->assertSame(['Medical Claims'], \App\Models\FinCatalogOverride::query()->pluck('display_name')->all());

        // The voucher form's dropdown.
        Livewire::actingAs($officer)->test(VoucherForm::class)->set('form.category_id', (string) $medical->category_id)->assertSee('Medical Claims');
        // The list and the detail page.
        Livewire::actingAs($officer)->test(Vouchers::class)->assertSee('Medical Claims');
        Livewire::actingAs($officer)->test(VoucherShow::class, ['pv' => $pv->id])->assertSee('Medical Claims');

        // Reports and exports (these are cached: the rename must not leave them showing the old name).
        $after = $analytics->report($officer, FinanceScope::AS_FINANCE, $this->west(), '2026-10-01', '2026-11-30');
        $this->assertStringContainsString('Medical Claims', json_encode($after['types']), 'the Reports figures');
        $this->assertStringNotContainsString('"Staff Claims > Medical"', json_encode($after['types']));

        $summary = app(FinanceExportService::class)->build('summary', $officer, FinanceScope::AS_FINANCE, $this->west(), '2026-10-01', '2026-11-30', 'Accra West');
        $this->assertStringContainsString('Medical Claims', json_encode($summary['tables']), 'the summary export');
        $register = app(FinanceExportService::class)->build('register', $officer, FinanceScope::AS_FINANCE, $this->west(), '2026-10-01', '2026-11-30', 'Accra West');
        $this->assertStringContainsString('Medical Claims', json_encode($register['tables']), 'the register export');
    }

    public function test_a_renamed_category_shows_its_new_name_in_the_cached_figures_too(): void
    {
        $officer = $this->finance('F1', $this->sowutuom);
        $manager = $this->financeManager('FM1', $this->sowutuom);
        $this->sent($officer, ['pv_no' => 'REN-2']);
        $type = FinPvType::query()->where('name', 'Medical')->firstOrFail();
        $analytics = app(PvAnalyticsService::class);

        $this->assertSame('Staff Claims', $analytics->report($officer, FinanceScope::AS_FINANCE, $this->west(), '2026-10-01', '2026-11-30')['categories'][0]['name']);

        Livewire::actingAs($manager)->test(Settings::class)
            ->call('startRenameCategory', $type->category_id)->assertSet('renameValue', 'Staff Claims')
            ->set('renameValue', 'Staff Benefits')
            ->call('saveRename')
            ->assertHasNoErrors();

        $this->assertSame('Staff Benefits', $analytics->report($officer, FinanceScope::AS_FINANCE, $this->west(), '2026-10-01', '2026-11-30')['categories'][0]['name']);
    }
}
