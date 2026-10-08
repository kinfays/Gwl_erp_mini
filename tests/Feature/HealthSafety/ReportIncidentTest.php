<?php

namespace Tests\Feature\HealthSafety;

use App\Livewire\HealthSafety\ReportIncident;
use App\Models\Department;
use App\Models\HsIncident;
use App\Models\HsIncidentAttachment;
use App\Models\HsSite;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

class ReportIncidentTest extends HealthSafetyTestCase
{
    public function test_an_employee_can_report_for_each_of_the_four_contexts_and_gets_a_unique_reference(): void
    {
        $user = $this->reporter();
        $department = Department::query()->firstOrCreate(['department_name' => 'ICT']);
        $payPoint = HsSite::query()->create(['name' => 'Kaneshie Market PP', 'kind' => 'pay_point', 'region_id' => $this->accraWest->id, 'district_id' => $this->sowutuom->id]);

        $forms = [
            'regional' => ['context' => 'regional_office', 'departmentId' => $department->id],
            'district' => ['context' => 'district_office', 'districtId' => $this->sowutuom->id],
            'pay point' => ['context' => 'pay_point', 'districtId' => $this->sowutuom->id, 'siteId' => (string) $payPoint->id],
            'field work' => ['context' => 'field_work', 'districtId' => $this->odorkor->id, 'locationDetail' => 'Junction near the Odorkor market'],
        ];

        $references = [];

        foreach ($forms as $label => $place) {
            $component = Livewire::actingAs($user)->test(ReportIncident::class)
                ->set($place)
                ->set('incidentType', 'near_miss')
                ->set('description', "A near miss at the {$label}.")
                ->set('firstAid', 'no_need')
                ->call('submit')
                ->assertHasNoErrors();

            $this->assertNotNull($component->get('submittedId'), "{$label} should have been filed");
            $references[] = $component->get('submittedReference');
        }

        $this->assertCount(4, array_unique($references));
        $this->assertMatchesRegularExpression('/^HS-[A-Z0-9]+-'.now()->year.'-\d{4}$/', $references[0]);
        $this->assertSame(4, HsIncident::query()->where('reported_by_user_id', $user->id)->count());
        $this->assertSame(['regional_office', 'district_office', 'pay_point', 'field_work'], HsIncident::query()->orderBy('id')->pluck('context')->all());
        $this->assertSame($payPoint->id, HsIncident::query()->where('context', 'pay_point')->value('site_id'));
    }

    public function test_a_description_is_required_and_a_future_date_is_rejected(): void
    {
        $user = $this->reporter();

        Livewire::actingAs($user)->test(ReportIncident::class)
            ->set(['context' => 'district_office', 'districtId' => $this->sowutuom->id, 'incidentType' => 'injury', 'firstAid' => 'yes', 'description' => ''])
            ->call('submit')
            ->assertHasErrors(['description' => 'required']);

        Livewire::actingAs($user)->test(ReportIncident::class)
            ->set(['context' => 'district_office', 'districtId' => $this->sowutuom->id, 'incidentType' => 'injury', 'firstAid' => 'yes', 'description' => 'Cut a hand.'])
            ->set('occurredOn', today()->addDay()->toDateString())
            ->call('submit')
            ->assertHasErrors(['occurredOn']);

        $this->assertSame(0, HsIncident::query()->count());
    }

    public function test_a_time_still_to_come_today_is_rejected(): void
    {
        $user = $this->reporter();

        $this->travelTo(today()->setTime(10, 0));

        Livewire::actingAs($user)->test(ReportIncident::class)
            ->set(['context' => 'district_office', 'districtId' => $this->sowutuom->id, 'incidentType' => 'near_miss', 'firstAid' => 'no_need', 'description' => 'Something.'])
            ->set('occurredTime', '15:30')
            ->call('submit')
            ->assertHasErrors(['occurredTime']);

        $this->assertSame(0, HsIncident::query()->count());
    }

    public function test_no_witness_lets_the_witness_fields_be_empty_and_clears_them(): void
    {
        $user = $this->reporter();

        $component = Livewire::actingAs($user)->test(ReportIncident::class)
            ->set(['context' => 'district_office', 'districtId' => $this->sowutuom->id, 'incidentType' => 'near_miss', 'firstAid' => 'no_need', 'description' => 'Nobody saw it.'])
            ->set('witnessName', 'Typed by mistake')
            ->set('noWitness', true)
            ->call('submit')
            ->assertHasNoErrors();

        $incident = HsIncident::query()->findOrFail($component->get('submittedId'));

        $this->assertTrue($incident->no_witness);
        $this->assertNull($incident->witness_name);
        $this->assertNull($incident->witness_contact);

        // Without the tick the witness is optional too: a report can say nothing about one.
        $second = Livewire::actingAs($user)->test(ReportIncident::class)
            ->set(['context' => 'district_office', 'districtId' => $this->sowutuom->id, 'incidentType' => 'near_miss', 'firstAid' => 'no_need', 'description' => 'Not sure who saw it.'])
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertNotNull($second->get('submittedId'));
    }

    public function test_each_context_asks_for_its_own_place(): void
    {
        $user = $this->reporter();
        $base = ['incidentType' => 'near_miss', 'firstAid' => 'no_need', 'description' => 'Something happened.'];

        Livewire::actingAs($user)->test(ReportIncident::class)->set($base)->set('context', 'field_work')->set('districtId', $this->sowutuom->id)
            ->call('submit')->assertHasErrors(['locationDetail']);

        Livewire::actingAs($user)->test(ReportIncident::class)->set($base)->set('context', 'pay_point')->set('districtId', $this->sowutuom->id)
            ->call('submit')->assertHasErrors(['siteNameRaw']);

        Livewire::actingAs($user)->test(ReportIncident::class)->set($base)->set('context', 'district_office')->set('districtId', null)
            ->call('submit')->assertHasErrors(['districtId']);

        $this->assertSame(0, HsIncident::query()->count());
    }

    public function test_a_pay_point_that_is_not_in_the_list_is_kept_as_typed(): void
    {
        $user = $this->reporter();

        $component = Livewire::actingAs($user)->test(ReportIncident::class)
            ->set(['context' => 'pay_point', 'districtId' => $this->sowutuom->id, 'siteId' => 'other', 'siteNameRaw' => 'Kaneshie pay point'])
            ->set(['incidentType' => 'near_miss', 'firstAid' => 'no_need', 'description' => 'Slippery steps.'])
            ->call('submit')
            ->assertHasNoErrors();

        $incident = HsIncident::query()->findOrFail($component->get('submittedId'));

        $this->assertNull($incident->site_id);
        $this->assertSame('Kaneshie pay point', $incident->site_name_raw);
    }

    public function test_a_district_from_another_region_cannot_be_chosen(): void
    {
        $user = $this->reporter();

        Livewire::actingAs($user)->test(ReportIncident::class)
            ->set(['context' => 'district_office', 'districtId' => $this->kumasi->id, 'incidentType' => 'near_miss', 'firstAid' => 'no_need', 'description' => 'Elsewhere.'])
            ->call('submit')
            ->assertHasErrors(['districtId']);

        $this->assertSame(0, HsIncident::query()->count());
    }

    public function test_the_region_comes_from_the_place_and_the_reporter(): void
    {
        $reporter = $this->reporter('100005', $this->accraWest, $this->headOffice);

        $regional = $this->incident($reporter, ['context' => 'regional_office', 'department_id' => Department::query()->first()->id, 'district_id' => null]);
        $this->assertSame($this->accraWest->id, $regional->region_id);

        $field = $this->incident($reporter, ['context' => 'field_work', 'district_id' => $this->sowutuom->id, 'location_detail' => 'Main road']);
        $this->assertSame($this->accraWest->id, $field->region_id);
        $this->assertSame($this->sowutuom->id, $field->district_id);
    }

    public function test_a_user_with_no_region_cannot_file_a_regional_office_report(): void
    {
        $user = $this->userWithoutEmployee('100090', ['employee']);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $this->incident($user, ['context' => 'regional_office', 'department_id' => Department::query()->firstOrCreate(['department_name' => 'HR'])->id, 'district_id' => null]);
    }

    public function test_photos_are_kept_on_the_private_disk_and_limited_in_number(): void
    {
        $user = $this->reporter();
        config(['gwl.hs_attachments_per_incident' => 2]);

        $component = Livewire::actingAs($user)->test(ReportIncident::class)
            ->set(['context' => 'district_office', 'districtId' => $this->sowutuom->id, 'incidentType' => 'property_damage', 'firstAid' => 'no_need', 'description' => 'Cracked window.'])
            ->set('photos', [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.png')])
            ->call('submit')
            ->assertHasNoErrors();

        $incident = HsIncident::query()->findOrFail($component->get('submittedId'));
        $this->assertSame(2, $incident->attachments()->count());

        foreach ($incident->attachments as $photo) {
            $this->assertStringStartsWith('health_safety/incidents/'.$incident->id.'/', $photo->path);
            Storage::disk('local')->assertExists($photo->path);
        }

        Livewire::actingAs($user)->test(ReportIncident::class)
            ->set(['context' => 'district_office', 'districtId' => $this->sowutuom->id, 'incidentType' => 'property_damage', 'firstAid' => 'no_need', 'description' => 'Too many photos.'])
            ->set('photos', [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg'), UploadedFile::fake()->image('c.jpg')])
            ->call('submit')
            ->assertHasErrors(['photos']);

        $this->assertSame(1, HsIncident::query()->count());
    }

    public function test_a_file_that_is_not_a_picture_is_rejected(): void
    {
        $user = $this->reporter();

        Livewire::actingAs($user)->test(ReportIncident::class)
            ->set(['context' => 'district_office', 'districtId' => $this->sowutuom->id, 'incidentType' => 'near_miss', 'firstAid' => 'no_need', 'description' => 'With a file.'])
            ->set('photos', [UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])
            ->call('submit')
            ->assertHasErrors(['photos.0']);

        $this->assertSame(0, HsIncidentAttachment::query()->count());
    }

    public function test_only_someone_with_the_permission_can_report_on_behalf_of_another(): void
    {
        $plain = $this->reporter();
        $manager = $this->districtManager('200010', $this->sowutuom);

        $form = ['context' => 'district_office', 'districtId' => $this->sowutuom->id, 'incidentType' => 'injury', 'firstAid' => 'yes', 'description' => 'A pay point attendant fell.'];

        // The tick is silently refused for someone without record_on_behalf, and the report is filed as their own.
        $own = Livewire::actingAs($plain)->test(ReportIncident::class)->set($form)->set('onBehalf', true)->set('behalfName', 'Someone')->call('submit');
        $incident = HsIncident::query()->findOrFail($own->get('submittedId'));
        $this->assertSame($plain->id, $incident->reported_by_user_id);
        $this->assertNull($incident->recorded_by_user_id);

        $behalf = Livewire::actingAs($manager)->test(ReportIncident::class)->set($form)->set('onBehalf', true)->set('behalfName', 'Ama Casual')->call('submit')->assertHasNoErrors();
        $recorded = HsIncident::query()->findOrFail($behalf->get('submittedId'));
        $this->assertNull($recorded->reported_by_user_id);
        $this->assertSame('Ama Casual', $recorded->reporter_name_raw);
        $this->assertSame($manager->id, $recorded->recorded_by_user_id);

        // The service refuses it too, whatever the form did.
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->incident($plain, ['on_behalf' => true, 'behalf_name' => 'Someone Else']);
    }
}
