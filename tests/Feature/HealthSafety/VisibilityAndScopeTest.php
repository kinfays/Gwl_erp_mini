<?php

namespace Tests\Feature\HealthSafety;

use App\Livewire\HealthSafety\IncidentIndex;
use App\Livewire\HealthSafety\IncidentShow;
use App\Livewire\HealthSafety\MyReports;
use App\Models\Role;
use App\Services\HealthSafety\IncidentVisibility;
use Livewire\Livewire;

class VisibilityAndScopeTest extends HealthSafetyTestCase
{
    public function test_an_employee_sees_only_their_own_reports_and_is_turned_away_from_the_register(): void
    {
        $alice = $this->reporter('100001');
        $bob = $this->reporter('100002');
        $mine = $this->incident($alice, ['description' => 'Alice slipped on the stairs.']);
        $theirs = $this->incident($bob, ['description' => 'Bob found a loose cable.']);

        Livewire::actingAs($alice)->test(MyReports::class)
            ->assertSee($mine->reference)
            ->assertDontSee($theirs->reference);

        $this->actingAs($alice)->get(route('health_safety.incidents'))->assertForbidden();
        Livewire::actingAs($alice)->test(IncidentIndex::class)->assertForbidden();

        $this->actingAs($alice)->get(route('health_safety.incidents.show', $mine))->assertOk();
        $this->actingAs($alice)->get(route('health_safety.incidents.show', $theirs))->assertForbidden();
        Livewire::actingAs($alice)->test(IncidentShow::class, ['incident' => $theirs])->assertForbidden();
    }

    public function test_a_regional_officer_sees_their_own_region_only(): void
    {
        $officer = $this->officer('200001', $this->accraWest, $this->odorkor);
        $accra = $this->incident($this->reporter('100001'), ['description' => 'In Accra West.']);
        $kumasi = $this->incident($this->reporter('100002', $this->ashanti, $this->kumasi), ['district_id' => $this->kumasi->id, 'description' => 'In Ashanti.']);

        Livewire::actingAs($officer)->test(IncidentIndex::class)
            ->assertSee($accra->reference)
            ->assertDontSee($kumasi->reference);

        $this->actingAs($officer)->get(route('health_safety.incidents.show', $accra))->assertOk();
        $this->actingAs($officer)->get(route('health_safety.incidents.show', $kumasi))->assertForbidden();
    }

    public function test_a_district_manager_sees_their_district_only(): void
    {
        $manager = $this->districtManager('200003', $this->sowutuom);
        $inDistrict = $this->incident($this->reporter('100001'), ['description' => 'In Sowutuom.']);
        $elsewhere = $this->incident($this->reporter('100002', $this->accraWest, $this->odorkor), ['district_id' => $this->odorkor->id, 'description' => 'In Odorkor.']);

        Livewire::actingAs($manager)->test(IncidentIndex::class)
            ->assertSee($inDistrict->reference)
            ->assertDontSee($elsewhere->reference);

        $this->actingAs($manager)->get(route('health_safety.incidents.show', $elsewhere))->assertForbidden();
    }

    public function test_head_office_staff_with_a_view_permission_the_manager_and_super_admin_see_every_region(): void
    {
        $accra = $this->incident($this->reporter('100001'));
        $kumasi = $this->incident($this->reporter('100002', $this->ashanti, $this->kumasi), ['district_id' => $this->kumasi->id]);

        $headOfficeOfficer = $this->officer('200020', $this->accraWest, $this->headOffice);
        $this->assertSame('HeadOffice', $headOfficeOfficer->employee->location_type);

        foreach ([$headOfficeOfficer, $this->hsManager(), $this->superAdmin()] as $viewer) {
            Livewire::actingAs($viewer)->test(IncidentIndex::class)
                ->assertSee($accra->reference)
                ->assertSee($kumasi->reference);
        }
    }

    public function test_a_view_permission_without_a_region_shows_nothing(): void
    {
        $this->incident($this->reporter('100001'));
        $user = $this->userWithoutEmployee('200030', ['hs_officer']);

        $this->assertSame(IncidentVisibility::SCOPE_NONE, app(IncidentVisibility::class)->scopeOf($user)['level']);
        Livewire::actingAs($user)->test(IncidentIndex::class)->assertSee('No incidents match');
    }

    public function test_a_confidential_reporter_is_hidden_from_a_district_manager_but_not_from_the_officer(): void
    {
        $reporter = $this->reporter('100001');
        $reporter->forceFill(['full_name' => 'Efua Mensah'])->save();
        $incident = $this->incident($reporter, ['is_confidential' => true]);

        $manager = $this->districtManager('200003', $this->sowutuom);
        $officer = $this->officer();

        Livewire::actingAs($manager)->test(IncidentIndex::class)->assertSee($incident->reference)->assertSee('Confidential')->assertDontSee('Efua Mensah');
        Livewire::actingAs($manager)->test(IncidentShow::class, ['incident' => $incident])->assertSee('Confidential')->assertDontSee('Efua Mensah');

        Livewire::actingAs($officer)->test(IncidentIndex::class)->assertSee('Efua Mensah');
        Livewire::actingAs($officer)->test(IncidentShow::class, ['incident' => $incident])->assertSee('Efua Mensah');

        // The reporter is never hidden from themselves.
        Livewire::actingAs($reporter)->test(IncidentShow::class, ['incident' => $incident])->assertSee('Efua Mensah');

        // And an unconfidential report names its reporter to the district manager.
        $open = $this->incident($reporter);
        Livewire::actingAs($manager)->test(IncidentShow::class, ['incident' => $open])->assertSee('Efua Mensah');
    }

    public function test_injury_details_are_hidden_without_the_permission_on_screen_and_in_the_print_copy(): void
    {
        $reporter = $this->reporter('100001');
        $officer = $this->officer();
        $manager = $this->districtManager('200003', $this->sowutuom);
        $incident = $this->triaged($reporter, $officer, 'medium', ['incident_type' => 'injury']);

        $this->workflow()->addPerson($incident, $officer, [
            'name_raw' => 'Kwame Contractor', 'person_type' => 'contractor', 'injury_type' => 'Deep laceration', 'body_part' => 'Left forearm',
            'treatment' => 'clinic', 'first_aider_name' => 'Nurse Adjoa', 'lost_time_days' => 3,
        ]);

        // The officer holds view_injury_details.
        Livewire::actingAs($officer)->test(IncidentShow::class, ['incident' => $incident])
            ->assertSee('Kwame Contractor')->assertSee('Deep laceration')->assertSee('Nurse Adjoa');
        $this->actingAs($officer)->get(route('health_safety.incidents.print', $incident))
            ->assertOk()->assertSee('Kwame Contractor')->assertSee('Deep laceration');

        // The district manager sees the incident, not the health information, on screen or on paper.
        Livewire::actingAs($manager)->test(IncidentShow::class, ['incident' => $incident])
            ->assertSee($incident->reference)
            ->assertDontSee('Kwame Contractor')->assertDontSee('Deep laceration')->assertDontSee('Nurse Adjoa');
        $this->actingAs($manager)->get(route('health_safety.incidents.print', $incident))
            ->assertOk()->assertSee($incident->reference)
            ->assertDontSee('Kwame Contractor')->assertDontSee('Deep laceration')->assertDontSee('Nurse Adjoa')->assertDontSee('People affected');

        // The reporter, who also may not see it, gets the same.
        $this->actingAs($reporter)->get(route('health_safety.incidents.print', $incident))
            ->assertOk()->assertDontSee('Kwame Contractor');

        // Only the view_injury_details holder can record or remove them.
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->workflow()->addPerson($incident, $manager, ['name_raw' => 'Nobody']);
    }

    public function test_the_print_copy_and_pdf_are_refused_to_someone_who_may_not_see_the_incident(): void
    {
        $incident = $this->incident($this->reporter('100001'));
        $stranger = $this->reporter('100002');

        $this->actingAs($stranger)->get(route('health_safety.incidents.print', $incident))->assertForbidden();
        $this->actingAs($stranger)->get(route('health_safety.incidents.pdf', $incident))->assertForbidden();
    }

    public function test_the_pdf_is_a_pdf_for_someone_who_may_see_the_incident(): void
    {
        $reporter = $this->reporter('100001');
        $incident = $this->incident($reporter);

        $response = $this->actingAs($reporter)->get(route('health_safety.incidents.pdf', $incident));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_the_reporter_sees_the_closure_note_but_never_the_findings(): void
    {
        $reporter = $this->reporter('100001');
        $officer = $this->officer();
        $incident = $this->triaged($reporter, $officer, 'low', ['incident_type' => 'injury']);

        $this->workflow()->saveInvestigation($incident, $officer, 'equipment', 'The mop bucket had no wet-floor sign: INTERNAL-FINDINGS.');
        $this->workflow()->close($incident, $officer, 'We have added signage and retrained the cleaners.');

        Livewire::actingAs($reporter)->test(IncidentShow::class, ['incident' => $incident])
            ->assertSee('We have added signage and retrained the cleaners.')
            ->assertDontSee('INTERNAL-FINDINGS')
            ->assertDontSee('Investigation');

        Livewire::actingAs($reporter)->test(MyReports::class)->assertSee('We have added signage');

        $print = $this->actingAs($reporter)->get(route('health_safety.incidents.print', $incident));
        $print->assertOk()->assertSee('We have added signage')->assertDontSee('INTERNAL-FINDINGS');

        Livewire::actingAs($officer)->test(IncidentShow::class, ['incident' => $incident])->assertSee('INTERNAL-FINDINGS');
    }

    public function test_the_reporter_does_not_see_the_closure_note_before_it_is_closed_nor_internal_timeline_notes(): void
    {
        $reporter = $this->reporter('100001');
        $officer = $this->officer();
        $incident = $this->triaged($reporter, $officer, 'high', ['incident_type' => 'near_miss']);

        $this->workflow()->sendForApproval($incident, $officer, 'DRAFT-OUTCOME not final yet.');
        $this->workflow()->returnForRework($incident, $this->chiefManager('200004'), 'SECRET-REASON: wording too soft.');

        Livewire::actingAs($reporter)->test(IncidentShow::class, ['incident' => $incident])
            ->assertDontSee('DRAFT-OUTCOME')
            ->assertDontSee('SECRET-REASON');

        Livewire::actingAs($officer)->test(IncidentShow::class, ['incident' => $incident])->assertSee('SECRET-REASON');
    }

    public function test_a_custom_role_with_the_view_permission_is_confined_to_its_region(): void
    {
        $role = Role::query()->create(['name' => 'auditor_test', 'display_name' => 'Auditor', 'is_system' => false]);
        $role->permissions()->attach(\App\Models\Permission::query()->where('name', 'health_safety.view_incidents')->value('id'));
        \App\Models\ModuleAccess::query()->create(['role_id' => $role->id, 'module' => 'health_safety', 'can_access' => true]);

        $auditor = $this->userWithRoles('200040', ['employee']);
        $auditor->roles()->attach($role);
        $auditor = $auditor->fresh();

        $inRegion = $this->incident($this->reporter('100001'));
        $other = $this->incident($this->reporter('100002', $this->ashanti, $this->kumasi), ['district_id' => $this->kumasi->id]);

        Livewire::actingAs($auditor)->test(IncidentIndex::class)->assertSee($inRegion->reference)->assertDontSee($other->reference);
    }
}
