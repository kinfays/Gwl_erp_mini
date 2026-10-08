<?php

namespace Tests\Feature\HealthSafety;

use App\Livewire\HealthSafety\IncidentShow;
use App\Livewire\HealthSafety\Sites;
use App\Models\AuditLog;
use App\Models\HsIncident;
use App\Models\HsIncidentAttachment;
use App\Models\HsSite;
use App\Services\HealthSafety\IncidentReferenceGenerator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

class SitesReferencesAndPhotosTest extends HealthSafetyTestCase
{
    // ---------------------------------------------------------------- references

    public function test_reference_numbers_do_not_collide_under_two_quick_submissions(): void
    {
        $reporter = $this->reporter();

        $first = $this->incident($reporter);
        $second = $this->incident($reporter);

        $this->assertNotSame($first->reference, $second->reference);
        $this->assertSame(['0001', '0002'], [substr($first->reference, -4), substr($second->reference, -4)]);
    }

    public function test_a_number_taken_by_someone_else_in_the_meantime_is_skipped_not_reused(): void
    {
        $reporter = $this->reporter();
        $first = $this->incident($reporter);

        // A report that slipped in with the next number (an import, a hand edit).
        $prefix = substr($first->reference, 0, -4);
        HsIncident::query()->whereKey($first->id)->update(['reference' => $prefix.'0007']);

        $next = $this->incident($reporter);

        $this->assertSame($prefix.'0008', $next->reference);
    }

    public function test_numbers_continue_past_four_digits_and_count_per_region_and_year(): void
    {
        $generator = app(IncidentReferenceGenerator::class);
        $reporter = $this->reporter();
        $incident = $this->incident($reporter);
        $prefix = substr($incident->reference, 0, -4);

        HsIncident::query()->whereKey($incident->id)->update(['reference' => $prefix.'9999']);
        $this->assertSame($prefix.'10000', $generator->next($this->accraWest));

        // Another region and another year start again at one.
        $this->assertStringEndsWith('-0001', $generator->next($this->ashanti));
        $this->assertSame('HS-'.$this->accraWest->fresh()->letter_prefix.'-2031-0001', $generator->next($this->accraWest, 2031));
    }

    public function test_a_collision_between_two_submissions_is_retried_with_a_fresh_number(): void
    {
        $reporter = $this->reporter();

        // A generator that hands out a number already in use the first time, as two concurrent requests could.
        $first = $this->incident($reporter);
        $taken = $first->reference;

        $generator = new class($taken) extends IncidentReferenceGenerator
        {
            public int $calls = 0;

            public function __construct(private string $taken) {}

            public function next(\App\Models\Region $region, ?int $year = null): string
            {
                $this->calls++;

                return $this->calls === 1 ? $this->taken : parent::next($region, $year);
            }
        };

        $this->app->instance(IncidentReferenceGenerator::class, $generator);
        $service = new \App\Services\HealthSafety\IncidentWorkflowService(app(\App\Services\HealthSafety\IncidentVisibility::class), $generator);

        $second = $service->submit($reporter, $this->reportData());

        $this->assertNotSame($taken, $second->reference);
        $this->assertSame(2, $generator->calls);
        $this->assertSame(2, HsIncident::query()->count());
    }

    // ---------------------------------------------------------------- sites

    public function test_an_officer_adds_edits_and_deactivates_sites_in_their_own_region(): void
    {
        $officer = $this->officer();

        Livewire::actingAs($officer)->test(Sites::class)
            ->call('create')
            ->set(['name' => 'Kaneshie Market PP', 'kind' => 'pay_point', 'districtId' => $this->sowutuom->id, 'address' => 'Opposite the market'])
            ->call('save')
            ->assertHasNoErrors();

        $site = HsSite::query()->firstOrFail();
        $this->assertSame($this->accraWest->id, $site->region_id);
        $this->assertSame($officer->id, $site->created_by);
        $this->assertTrue(AuditLog::query()->where('action', 'health_safety.site_saved')->where('target_id', $site->id)->exists());

        Livewire::actingAs($officer)->test(Sites::class)
            ->call('edit', $site->id)
            ->set('name', 'Kaneshie Market Pay Point')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame('Kaneshie Market Pay Point', $site->fresh()->name);

        Livewire::actingAs($officer)->test(Sites::class)->call('toggleActive', $site->id);
        $this->assertFalse($site->fresh()->is_active);
        $this->assertSame(1, HsSite::query()->count(), 'sites are deactivated, never deleted');
    }

    public function test_site_names_are_unique_within_a_region_and_kind(): void
    {
        $officer = $this->officer();
        HsSite::query()->create(['name' => 'Kaneshie Market PP', 'kind' => 'pay_point', 'region_id' => $this->accraWest->id]);

        Livewire::actingAs($officer)->test(Sites::class)
            ->call('create')
            ->set(['name' => 'Kaneshie Market PP', 'kind' => 'pay_point'])
            ->call('save')
            ->assertHasErrors(['name']);

        // The same name is fine as another kind of site.
        Livewire::actingAs($officer)->test(Sites::class)
            ->call('create')
            ->set(['name' => 'Kaneshie Market PP', 'kind' => 'depot'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(2, HsSite::query()->count());
    }

    public function test_a_regional_officer_cannot_see_or_change_another_regions_sites(): void
    {
        $officer = $this->officer();
        $theirs = HsSite::query()->create(['name' => 'Kumasi Central PP', 'kind' => 'pay_point', 'region_id' => $this->ashanti->id]);
        HsSite::query()->create(['name' => 'Odorkor PP', 'kind' => 'pay_point', 'region_id' => $this->accraWest->id]);

        Livewire::actingAs($officer)->test(Sites::class)
            ->assertSee('Odorkor PP')
            ->assertDontSee('Kumasi Central PP')
            ->call('toggleActive', $theirs->id)
            ->assertForbidden();

        Livewire::actingAs($officer)->test(Sites::class)->call('edit', $theirs->id)->assertForbidden();
        $this->assertTrue($theirs->fresh()->is_active);

        // A save cannot be aimed at another region by tampering with the form.
        Livewire::actingAs($officer)->test(Sites::class)
            ->call('create')
            ->set(['name' => 'Sneaky PP', 'kind' => 'pay_point', 'regionId' => $this->ashanti->id])
            ->call('save');
        $this->assertSame($this->accraWest->id, HsSite::query()->where('name', 'Sneaky PP')->value('region_id'));
    }

    public function test_the_sites_screen_needs_the_master_data_permission(): void
    {
        $this->actingAs($this->reporter())->get(route('health_safety.sites'))->assertForbidden();
        $this->actingAs($this->districtManager('200003', $this->sowutuom))->get(route('health_safety.sites'))->assertForbidden();
        $this->actingAs($this->officer())->get(route('health_safety.sites'))->assertOk();
        Livewire::actingAs($this->reporter())->test(Sites::class)->assertForbidden();
    }

    public function test_only_active_pay_points_of_the_chosen_district_are_offered_on_the_form(): void
    {
        HsSite::query()->create(['name' => 'Sowutuom PP', 'kind' => 'pay_point', 'region_id' => $this->accraWest->id, 'district_id' => $this->sowutuom->id]);
        HsSite::query()->create(['name' => 'Odorkor PP', 'kind' => 'pay_point', 'region_id' => $this->accraWest->id, 'district_id' => $this->odorkor->id]);
        HsSite::query()->create(['name' => 'Closed PP', 'kind' => 'pay_point', 'region_id' => $this->accraWest->id, 'district_id' => $this->sowutuom->id, 'is_active' => false]);

        Livewire::actingAs($this->reporter())->test(\App\Livewire\HealthSafety\ReportIncident::class)
            ->set('context', 'pay_point')
            ->set('districtId', $this->sowutuom->id)
            ->assertSee('Sowutuom PP')
            ->assertDontSee('Odorkor PP')
            ->assertDontSee('Closed PP');
    }

    // ---------------------------------------------------------------- photos

    public function test_photos_are_served_only_to_those_who_may_see_the_incident(): void
    {
        $reporter = $this->reporter('100001');
        $incident = $this->workflow()->submit($reporter, $this->reportData(), [UploadedFile::fake()->image('wet-floor.jpg')]);
        $photo = $incident->attachments()->firstOrFail();

        $this->actingAs($reporter)->get(route('health_safety.attachments.show', $photo))->assertOk();
        $this->actingAs($this->officer())->get(route('health_safety.attachments.show', $photo))->assertOk();
        $this->actingAs($this->districtManager('200003', $this->sowutuom))->get(route('health_safety.attachments.show', $photo))->assertOk();

        $this->actingAs($this->reporter('100002'))->get(route('health_safety.attachments.show', $photo))->assertForbidden();
        $this->actingAs($this->officer('200050', $this->ashanti, $this->kumasi))->get(route('health_safety.attachments.show', $photo))->assertForbidden();

        // There is no public URL to it.
        $this->assertStringNotContainsString('storage', route('health_safety.attachments.show', $photo));
        Storage::disk('public')->assertMissing($photo->path);
    }

    public function test_a_missing_file_is_a_404_not_an_error(): void
    {
        $reporter = $this->reporter('100001');
        $incident = $this->workflow()->submit($reporter, $this->reportData(), [UploadedFile::fake()->image('a.jpg')]);
        $photo = $incident->attachments()->firstOrFail();

        Storage::disk('local')->delete($photo->path);

        $this->actingAs($reporter)->get(route('health_safety.attachments.show', $photo))->assertNotFound();
    }

    public function test_an_officer_can_add_photos_but_not_beyond_the_limit(): void
    {
        config(['gwl.hs_attachments_per_incident' => 2]);
        $officer = $this->officer();
        $incident = $this->incident($this->reporter(), ['description' => 'No photos yet.']);

        Livewire::actingAs($officer)->test(IncidentShow::class, ['incident' => $incident])
            ->set('newPhotos', [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')])
            ->call('uploadPhotos')
            ->assertHasNoErrors();

        $this->assertSame(2, $incident->attachments()->count());

        Livewire::actingAs($officer)->test(IncidentShow::class, ['incident' => $incident])
            ->set('newPhotos', [UploadedFile::fake()->image('c.jpg')])
            ->call('uploadPhotos')
            ->assertHasErrors(['photos']);

        $this->assertSame(2, HsIncidentAttachment::query()->where('incident_id', $incident->id)->count());
    }

    public function test_the_reporter_cannot_add_photos_afterwards(): void
    {
        $reporter = $this->reporter();
        $incident = $this->incident($reporter);

        Livewire::actingAs($reporter)->test(IncidentShow::class, ['incident' => $incident])
            ->set('newPhotos', [UploadedFile::fake()->image('late.jpg')])
            ->call('uploadPhotos')
            ->assertForbidden();

        $this->assertSame(0, $incident->attachments()->count());
    }
}
