<?php

namespace Tests\Feature\HealthSafety;

use App\Livewire\HealthSafety\IncidentIndex;
use App\Livewire\HealthSafety\IncidentShow;
use App\Livewire\HealthSafety\MyReports;
use App\Livewire\HealthSafety\ReportIncident;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\HsIncident;
use App\Models\User;
use App\Notifications\GeneralDatabaseNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * Design section 10, question 1: a fully anonymous report. Nothing stored may link it to the person who filed it.
 */
class AnonymousReportTest extends HealthSafetyTestCase
{
    private const SECRET = 'SECRET-GPS-6.6N-1.6W';

    /** A real JPEG with an Exif block (as a phone writes) carrying a marker, to prove it is gone afterwards. */
    private function photoWithMetadata(string $name = 'IMG_KWAME_PHONE.jpg'): UploadedFile
    {
        $image = imagecreatetruecolor(24, 16);
        imagefilledrectangle($image, 0, 0, 24, 16, imagecolorallocate($image, 200, 30, 30));
        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = (string) ob_get_clean();
        imagedestroy($image);

        $payload = "Exif\0\0".self::SECRET;
        $segment = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        return UploadedFile::fake()->createWithContent($name, substr($jpeg, 0, 2).$segment.substr($jpeg, 2));
    }

    private function anonymous(User $reporter, array $overrides = [], array $photos = []): HsIncident
    {
        return $this->workflow()->submit($reporter, $this->reportData(['anonymous' => true, ...$overrides]), $photos);
    }

    // ---------------------------------------------------------------- nothing links it to the reporter

    public function test_an_anonymous_report_stores_nothing_that_identifies_the_reporter(): void
    {
        $reporter = $this->reporter('100001');
        $reporter->forceFill(['full_name' => 'Efua Mensah'])->save();

        $incident = $this->anonymous($reporter, ['is_confidential' => true, 'description' => 'A cable was left across the walkway.']);

        $this->assertTrue($incident->is_anonymous);
        $this->assertNull($incident->reported_by_user_id);
        $this->assertNull($incident->reported_by_employee_id);
        $this->assertNull($incident->reporter_name_raw);
        $this->assertNull($incident->recorded_by_user_id);
        $this->assertFalse($incident->is_confidential, 'nothing left to keep confidential');

        // The timeline row and the audit entry carry no one either.
        $this->assertNull($incident->statusLogs()->firstOrFail()->user_id);

        $audit = AuditLog::query()->where('target_type', 'hs_incidents')->where('target_id', $incident->id)->get();
        $this->assertNotEmpty($audit);

        foreach ($audit as $row) {
            $this->assertNull($row->user_id);
            $this->assertNull($row->ip_address);
            $this->assertSame('Anonymous', $row->user_name);
            $this->assertStringNotContainsString('Efua', json_encode($row->toArray()));
            $this->assertStringNotContainsString('100001', json_encode($row->toArray()));
        }

        $this->assertSame(0, AuditLog::query()->where('user_id', $reporter->id)->count());
    }

    public function test_the_person_who_filed_it_cannot_find_or_open_it_afterwards(): void
    {
        $reporter = $this->reporter('100001');
        $incident = $this->anonymous($reporter);

        Livewire::actingAs($reporter)->test(MyReports::class)->assertDontSee($incident->reference);
        $this->actingAs($reporter)->get(route('health_safety.incidents.show', $incident))->assertForbidden();
        $this->actingAs($reporter)->get(route('health_safety.incidents.print', $incident))->assertForbidden();
        Livewire::actingAs($reporter)->test(IncidentShow::class, ['incident' => $incident])->assertForbidden();
    }

    public function test_everyone_who_may_see_it_is_told_it_is_anonymous_officers_included(): void
    {
        $incident = $this->anonymous($this->reporter('100001'));

        foreach ([$this->officer(), $this->hsManager(), $this->districtManager('200003', $this->sowutuom), $this->superAdmin()] as $viewer) {
            Livewire::actingAs($viewer)->test(IncidentIndex::class)->assertSee($incident->reference)->assertSee('Anonymous');
            Livewire::actingAs($viewer)->test(IncidentShow::class, ['incident' => $incident])->assertSee('Anonymous report')->assertSee('Anonymous');
        }

        $this->actingAs($this->officer())->get(route('health_safety.incidents.print', $incident))->assertOk()->assertSee('Anonymous');
    }

    public function test_anonymous_cannot_be_combined_with_reporting_for_someone_else(): void
    {
        $manager = $this->districtManager('200003', $this->sowutuom);

        $incident = $this->anonymous($manager, ['on_behalf' => true, 'behalf_name' => 'Ama Casual']);

        $this->assertNull($incident->recorded_by_user_id, 'the person who filed it is not recorded either');
        $this->assertNull($incident->reporter_name_raw);
        $this->assertNull($incident->reported_by_user_id);
    }

    public function test_the_region_still_comes_from_the_place_or_the_reporters_office_without_storing_them(): void
    {
        $reporter = $this->reporter('100001', $this->accraWest, $this->headOffice);

        $regional = $this->anonymous($reporter, ['context' => 'regional_office', 'department_id' => Department::query()->firstOrCreate(['department_name' => 'ICT'])->id, 'district_id' => null]);
        $this->assertSame($this->accraWest->id, $regional->region_id);
        $this->assertNull($regional->reported_by_employee_id);

        $field = $this->anonymous($reporter, ['context' => 'field_work', 'district_id' => $this->odorkor->id, 'location_detail' => 'Main road']);
        $this->assertSame($this->odorkor->id, $field->district_id);
    }

    // ---------------------------------------------------------------- the workflow copes with nobody to tell

    public function test_the_officer_can_work_an_anonymous_report_through_to_closure_and_nobody_is_told_the_outcome(): void
    {
        $reporter = $this->reporter('100001');
        $officer = $this->officer();
        $incident = $this->anonymous($reporter);
        Notification::fake();

        $this->workflow()->triage($incident, $officer, ['severity' => 'low']);
        $closed = $this->workflow()->close($incident, $officer, 'Cable tidied away and staff reminded.');

        $this->assertSame(HsIncident::STATUS_CLOSED, $closed->status);
        Notification::assertNotSentTo($reporter, GeneralDatabaseNotification::class);

        $cancelled = $this->anonymous($reporter);
        $this->workflow()->cancel($cancelled, $officer, 'Duplicate.');
        Notification::assertNotSentTo($reporter, GeneralDatabaseNotification::class);
    }

    public function test_the_officers_are_still_told_of_a_new_anonymous_report_and_the_notice_names_no_one(): void
    {
        $reporter = $this->reporter('100001');
        $reporter->forceFill(['full_name' => 'Efua Mensah'])->save();
        $officer = $this->officer('200001', $this->accraWest, $this->odorkor);
        $this->districtManager('200003', $this->sowutuom);

        Notification::fake();
        $this->anonymous($reporter, ['incident_type' => 'injury']);

        Notification::assertSentTo($officer, GeneralDatabaseNotification::class, function ($notice) use ($officer) {
            $text = json_encode($notice->toArray($officer));

            return str_contains($text, 'hs_incident_reported') && ! str_contains($text, 'Efua');
        });
        Notification::assertNotSentTo($reporter, GeneralDatabaseNotification::class);
    }

    // ---------------------------------------------------------------- photos

    public function test_photos_on_an_anonymous_report_lose_their_hidden_details_and_their_name(): void
    {
        $reporter = $this->reporter('100001');

        $incident = $this->anonymous($reporter, [], [$this->photoWithMetadata('IMG_KWAME_PHONE.jpg')]);

        $photo = $incident->attachments()->firstOrFail();
        $stored = Storage::disk('local')->get($photo->path);

        $this->assertStringNotContainsString(self::SECRET, $stored, 'the metadata did not survive');
        $this->assertStringNotContainsString('Exif', $stored);
        $this->assertSame('photo-1.jpg', $photo->original_name, 'the phone file name can name its owner');
        $this->assertStringNotContainsString('KWAME', $photo->path);
        $this->assertNull($photo->uploaded_by);
        $this->assertSame('image/jpeg', $photo->mime);
        $this->assertSame(strlen($stored), $photo->size);

        // Still a picture, same size.
        $info = getimagesizefromstring($stored);
        $this->assertSame([24, 16], [$info[0], $info[1]]);

        // The same photo on an ordinary report is kept as sent (proving the test above would have caught it).
        $named = $this->workflow()->submit($reporter, $this->reportData(), [$this->photoWithMetadata()]);
        $this->assertStringContainsString(self::SECRET, Storage::disk('local')->get($named->attachments()->firstOrFail()->path));
        $this->assertSame($reporter->id, $named->attachments()->firstOrFail()->uploaded_by);
    }

    public function test_png_and_webp_photos_are_kept_as_pictures_and_something_else_is_refused(): void
    {
        $reporter = $this->reporter('100001');

        $incident = $this->anonymous($reporter, [], [UploadedFile::fake()->image('a.png', 20, 10), UploadedFile::fake()->image('b.webp', 20, 10)]);

        $this->assertSame(['image/png', 'image/webp'], $incident->attachments()->orderBy('id')->pluck('mime')->all());
        $this->assertSame(['photo-1.png', 'photo-2.webp'], $incident->attachments()->orderBy('id')->pluck('original_name')->all());

        foreach ($incident->attachments as $photo) {
            $this->assertNotFalse(getimagesizefromstring(Storage::disk('local')->get($photo->path)));
        }

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $this->anonymous($reporter, [], [UploadedFile::fake()->createWithContent('notes.jpg', 'this is not an image')]);
    }

    // ---------------------------------------------------------------- the form

    public function test_the_form_offers_the_option_and_clears_the_ticks_that_do_not_mix_with_it(): void
    {
        $manager = $this->districtManager('200003', $this->sowutuom);

        Livewire::actingAs($manager)->test(ReportIncident::class)
            ->assertSee('Report anonymously')
            ->assertSee('Keep my name confidential')
            ->set('confidential', true)
            ->set('onBehalf', true)
            ->set('behalfName', 'Ama Casual')
            ->set('anonymous', true)
            ->assertSet('confidential', false)
            ->assertSet('onBehalf', false)
            ->assertSet('behalfName', '')
            ->assertDontSee('Keep my name confidential')
            ->assertDontSee('reporting for someone else');
    }

    public function test_submitting_anonymously_through_the_form_shows_the_right_confirmation(): void
    {
        $user = $this->reporter('100001');

        $component = Livewire::actingAs($user)->test(ReportIncident::class)
            ->set(['context' => 'district_office', 'districtId' => $this->sowutuom->id, 'incidentType' => 'near_miss', 'firstAid' => 'no_need', 'description' => 'A near miss.'])
            ->set('anonymous', true)
            ->call('submit')
            ->assertHasNoErrors();

        $incident = HsIncident::query()->findOrFail($component->get('submittedId'));

        $this->assertTrue($incident->is_anonymous);
        $this->assertNull($incident->reported_by_user_id);
        $component->assertSee($incident->reference)
            ->assertSee('Your report is anonymous')
            ->assertSee('will not appear under My reports')
            ->assertDontSee(route('health_safety.incidents.print', $incident), false)
            ->assertDontSee(route('health_safety.incidents.show', $incident), false);
        $this->assertTrue($component->get('submittedAnonymous'));

        // An ordinary report still gets its links.
        $named = Livewire::actingAs($user)->test(ReportIncident::class)
            ->set(['context' => 'district_office', 'districtId' => $this->sowutuom->id, 'incidentType' => 'near_miss', 'firstAid' => 'no_need', 'description' => 'Another.'])
            ->call('submit');
        $named->assertSee('Print a copy');
        $this->assertFalse($named->get('submittedAnonymous'));
    }

    public function test_an_anonymous_report_never_appears_under_my_reports(): void
    {
        $user = $this->reporter('100001');

        Livewire::actingAs($user)->test(ReportIncident::class)
            ->set(['context' => 'district_office', 'districtId' => $this->sowutuom->id, 'incidentType' => 'near_miss', 'firstAid' => 'no_need', 'description' => 'A near miss.'])
            ->set('anonymous', true)
            ->call('submit');

        $this->assertSame(0, HsIncident::query()->where('reported_by_user_id', $user->id)->orWhere('recorded_by_user_id', $user->id)->count());
        $this->actingAs($user)->get(route('health_safety.mine'))->assertOk()->assertSee('You have not reported anything yet');
    }

    // ---------------------------------------------------------------- the dropped external-reporting fields (question 5)

    public function test_the_triage_screen_no_longer_asks_about_outside_authorities(): void
    {
        $officer = $this->officer();
        $incident = $this->incident($this->reporter());

        Livewire::actingAs($officer)->test(IncidentShow::class, ['incident' => $incident])
            ->assertSee('Triage')
            ->assertDontSee('outside authority')
            ->assertDontSee('Their reference');

        $this->workflow()->triage($incident, $officer, ['severity' => 'low', 'reportable_externally' => true, 'external_ref' => 'X1']);
        $this->assertFalse($incident->fresh()->reportable_externally, 'the service ignores them too');
    }
}
