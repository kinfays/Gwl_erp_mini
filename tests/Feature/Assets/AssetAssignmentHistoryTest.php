<?php

namespace Tests\Feature\Assets;

use App\Livewire\Assets\AssetDetail;
use App\Livewire\Assets\AssetsList;
use App\Livewire\Assets\PhonesList;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\IctAsset;
use App\Models\IctAssetAssignment;
use App\Models\IctAssetTransfer;
use App\Services\Assets\AssetAssignmentService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Assets\Concerns\BuildsAssetFixtures;
use Tests\TestCase;

/** The assignment history: handover vs correction, "previous assigned to" derived from it, scope and audit rules. */
class AssetAssignmentHistoryTest extends TestCase
{
    use BuildsAssetFixtures;
    use RefreshDatabase;

    protected District $accra;

    protected Employee $ama;

    protected Employee $kofi;

    protected Employee $yaw;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedCoreAssetsAccess();
        $this->accra = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $this->ama = $this->makeEmployee('H001', 'Ama Boateng', $this->accra);
        $this->kofi = $this->makeEmployee('H002', 'Kofi Asare', $this->accra);
        $this->yaw = $this->makeEmployee('H003', 'Yaw Owusu', $this->accra);
    }

    protected function service(): AssetAssignmentService
    {
        return app(AssetAssignmentService::class);
    }

    protected function asAdmin()
    {
        $admin = $this->superAdmin($this->accra);
        $this->actingAs($admin);

        return $admin;
    }

    protected function assetHeldBy(?Employee $holder, array $overrides = []): IctAsset
    {
        return $this->makeAsset($this->accra, [
            'asset_name' => 'Laptop '.uniqid(),
            'asset_type' => 'Laptop',
            'ict_asset_model_id' => $this->makeModel('ThinkPad', 'Laptop')->id,
            'department_id' => Department::query()->firstOrCreate(['department_name' => 'ICT'])->id,
            'assigned_to_employee_id' => $holder?->id,
            ...$overrides,
        ]);
    }

    // ---- new asset -------------------------------------------------------------------------------------------

    public function test_a_new_asset_opens_one_row_and_has_no_previous_holder_or_question(): void
    {
        $this->asAdmin();
        $department = Department::query()->firstOrCreate(['department_name' => 'ICT']);

        Livewire::test(AssetsList::class)->call('openCreate')
            ->set('form.asset_name', 'Front Desk PC')
            ->set('form.serial_number', 'PC-1')
            ->set('form.asset_type', 'PC')
            ->set('form.ict_asset_model_id', $this->makeModel()->id)
            ->set('form.assigned_to_employee_id', $this->ama->id)
            ->set('form.department_id', $department->id)
            ->set('form.district_id', $this->accra->id)
            ->assertViewHas('askAssignmentChange', false)
            ->assertDontSee('What kind of change is this?')
            ->call('save')
            ->assertHasNoErrors();

        $asset = IctAsset::query()->where('serial_number', 'PC-1')->firstOrFail();
        $rows = IctAssetAssignment::query()->where('ict_asset_id', $asset->id)->get();

        $this->assertCount(1, $rows);
        $this->assertTrue($rows->first()->is_current);
        $this->assertSame($this->ama->id, $rows->first()->employee_id);
        $this->assertNull($rows->first()->end_reason);
        $this->assertNull($asset->previousHolder());
    }

    // ---- editing: the required question ------------------------------------------------------------------------

    public function test_changing_the_holder_of_an_existing_asset_requires_a_choice_and_blocks_the_save(): void
    {
        $this->asAdmin();
        $asset = $this->assetHeldBy($this->ama);

        Livewire::test(AssetsList::class)->call('openEdit', $asset->id)
            ->assertViewHas('askAssignmentChange', false)
            ->assertDontSee('What kind of change is this?')
            ->set('form.assigned_to_employee_id', $this->kofi->id)
            ->assertViewHas('askAssignmentChange', true)
            ->assertSee('Reassigned to a new person')
            ->assertSee('Correction: the previous selection was a mistake')
            ->assertSet('assignmentChange', '')
            ->call('save')
            ->assertHasErrors(['assignmentChange' => 'required']);

        $this->assertSame($this->ama->id, $asset->fresh()->assigned_to_employee_id);
        $this->assertSame(0, IctAssetAssignment::query()->where('ict_asset_id', $asset->id)->where('employee_id', $this->kofi->id)->count());
    }

    public function test_a_handover_keeps_the_old_holder_as_previous(): void
    {
        $this->asAdmin();
        $asset = $this->assetHeldBy($this->ama);

        Livewire::test(AssetsList::class)->call('openEdit', $asset->id)
            ->set('form.assigned_to_employee_id', $this->kofi->id)
            ->set('assignmentChange', 'reassigned')
            ->call('save')
            ->assertHasNoErrors();

        $asset->refresh();
        $this->assertSame($this->kofi->id, $asset->assigned_to_employee_id);
        $this->assertSame($this->ama->id, $asset->previousHolder()?->id);

        $closed = IctAssetAssignment::query()->where('ict_asset_id', $asset->id)->where('employee_id', $this->ama->id)->sole();
        $this->assertSame('reassigned', $closed->end_reason);
        $this->assertNull($closed->is_current);
        $this->assertSame(1, IctAssetAssignment::query()->where('ict_asset_id', $asset->id)->current()->count());
    }

    public function test_a_correction_never_becomes_the_previous_holder_and_keeps_the_original_start_date(): void
    {
        $this->asAdmin();
        $asset = $this->assetHeldBy(null);
        $this->service()->assign($asset, $this->ama->id);
        $startedAt = IctAssetAssignment::query()->where('ict_asset_id', $asset->id)->current()->value('assigned_at');
        $this->travel(2)->days();

        Livewire::test(AssetsList::class)->call('openEdit', $asset->id)
            ->set('form.assigned_to_employee_id', $this->kofi->id)
            ->set('assignmentChange', 'correction')
            ->call('save')
            ->assertHasNoErrors();

        $asset->refresh();
        $this->assertSame($this->kofi->id, $asset->assigned_to_employee_id);
        $this->assertNull($asset->previousHolder(), 'The mistaken person must not show as the previous holder.');

        $wrong = IctAssetAssignment::query()->where('ict_asset_id', $asset->id)->where('employee_id', $this->ama->id)->sole();
        $this->assertSame('corrected', $wrong->end_reason);

        $right = IctAssetAssignment::query()->where('ict_asset_id', $asset->id)->current()->sole();
        $this->assertSame($this->kofi->id, $right->employee_id);
        $this->assertSame((string) $startedAt, (string) $right->assigned_at);

        $transfer = IctAssetTransfer::query()->where('ict_asset_id', $asset->id)->where('to_employee_id', $this->kofi->id)->sole();
        $this->assertSame('Correction', $transfer->reason);
    }

    public function test_a_correction_after_a_handover_leaves_the_real_previous_holder_alone(): void
    {
        $this->asAdmin();
        $asset = $this->assetHeldBy($this->ama);

        $this->service()->reassign($asset, $this->kofi->id);      // Ama -> Kofi (real handover)
        $this->service()->correct($asset, $this->yaw->id);        // Kofi was the wrong pick; Yaw is right

        $this->assertSame($this->yaw->id, $asset->fresh()->assigned_to_employee_id);
        $this->assertSame($this->ama->id, $asset->fresh()->previousHolder()?->id);
    }

    public function test_clearing_the_holder_is_an_unassign_without_a_question_and_the_person_becomes_previous(): void
    {
        $this->asAdmin();
        $asset = $this->assetHeldBy($this->ama);

        Livewire::test(AssetsList::class)->call('openEdit', $asset->id)
            ->set('form.assigned_to_employee_id', null)
            ->assertViewHas('askAssignmentChange', false)
            ->set('form.is_general_use', true)
            ->call('save')
            ->assertHasNoErrors();

        $asset->refresh();
        $this->assertNull($asset->assigned_to_employee_id);
        $this->assertSame($this->ama->id, $asset->previousHolder()?->id);
        $this->assertSame('unassigned', IctAssetAssignment::query()->where('ict_asset_id', $asset->id)->sole()->end_reason);
        $this->assertSame(0, IctAssetAssignment::query()->where('ict_asset_id', $asset->id)->current()->count());
    }

    public function test_giving_an_unassigned_asset_a_holder_needs_no_question_and_adds_no_previous(): void
    {
        $this->asAdmin();
        $asset = $this->assetHeldBy(null);

        $this->service()->change($asset, $this->kofi->id, null);

        $this->assertSame($this->kofi->id, $asset->fresh()->assigned_to_employee_id);
        $this->assertNull($asset->fresh()->previousHolder());
    }

    public function test_the_phones_form_asks_the_same_question(): void
    {
        $this->asAdmin();
        $phone = $this->makeAsset($this->accra, [
            'asset_name' => 'Field phone', 'asset_type' => 'Ph', 'device_category' => IctAsset::DEVICE_CATEGORY_PHONE,
            'assigned_to_employee_id' => $this->ama->id,
        ]);

        Livewire::test(PhonesList::class)->call('openEdit', $phone->id)
            ->set('form.assigned_to_employee_id', $this->kofi->id)
            ->call('save')
            ->assertHasErrors(['assignmentChange' => 'required'])
            ->set('assignmentChange', 'correction')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($this->kofi->id, $phone->fresh()->assigned_to_employee_id);
        $this->assertNull($phone->fresh()->previousHolder());
    }

    public function test_the_service_refuses_a_swap_without_a_kind_even_if_the_form_is_bypassed(): void
    {
        $this->asAdmin();
        $asset = $this->assetHeldBy($this->ama);

        try {
            $this->service()->change($asset, $this->kofi->id, null);
            $this->fail('A holder swap without a kind must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('assignmentChange', $e->errors());
        }

        $this->assertSame($this->ama->id, $asset->fresh()->assigned_to_employee_id);
    }

    // ---- refusals ---------------------------------------------------------------------------------------------

    public function test_the_same_person_an_inactive_person_and_a_general_use_asset_are_refused(): void
    {
        $this->asAdmin();
        $asset = $this->assetHeldBy($this->ama);
        $this->kofi->forceFill(['is_active' => false])->save();

        foreach ([
            fn () => $this->service()->reassign($asset, $this->ama->id),
            fn () => $this->service()->correct($asset, $this->ama->id),
            fn () => $this->service()->reassign($asset, $this->kofi->id),
            fn () => $this->service()->assign($asset, $this->yaw->id),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Expected a validation refusal.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('form.assigned_to_employee_id', $e->errors());
            }
        }

        $shared = $this->assetHeldBy(null, ['is_general_use' => true]);
        $this->expectException(ValidationException::class);
        $this->service()->assign($shared, $this->yaw->id);
    }

    public function test_a_regional_ict_user_cannot_hand_over_to_a_person_or_touch_an_asset_outside_their_region(): void
    {
        $kumasi = $this->makeDistrict('Kumasi Central District', 'Ashanti');
        $kumasiStaff = $this->makeEmployee('K001', 'Kwabena Kumasi', $kumasi);
        $this->actingAs($this->ictUser($this->accra));
        $asset = $this->assetHeldBy($this->ama);

        try {
            $this->service()->reassign($asset, $kumasiStaff->id);
            $this->fail('A person outside the actor region must be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('outside your region', $e->errors()['form.assigned_to_employee_id'][0]);
        }

        $elsewhere = $this->makeAsset($kumasi, ['assigned_to_employee_id' => $kumasiStaff->id]);

        try {
            $this->service()->unassign($elsewhere);
            $this->fail('Another region\'s asset must not be reachable.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        $this->assertSame($kumasiStaff->id, $elsewhere->fresh()->assigned_to_employee_id);
    }

    public function test_nobody_acting_writes_nothing(): void
    {
        $asset = $this->assetHeldBy($this->ama);

        try {
            $this->service()->unassign($asset);
            $this->fail('No signed-in user must mean no write.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame($this->ama->id, $asset->fresh()->assigned_to_employee_id);
        $this->assertSame(0, IctAssetAssignment::query()->count());
    }

    public function test_the_database_allows_only_one_current_row_per_asset(): void
    {
        $asset = $this->assetHeldBy(null);
        IctAssetAssignment::query()->create(['ict_asset_id' => $asset->id, 'employee_id' => $this->ama->id, 'is_current' => true]);

        // Any number of closed rows is fine...
        IctAssetAssignment::query()->create(['ict_asset_id' => $asset->id, 'employee_id' => $this->kofi->id, 'end_reason' => 'reassigned']);
        IctAssetAssignment::query()->create(['ict_asset_id' => $asset->id, 'employee_id' => $this->yaw->id, 'end_reason' => 'corrected']);

        // ...but a second open one is not.
        $this->expectException(UniqueConstraintViolationException::class);
        IctAssetAssignment::query()->create(['ict_asset_id' => $asset->id, 'employee_id' => $this->kofi->id, 'is_current' => true]);
    }

    public function test_an_asset_whose_holder_was_stored_without_history_is_reconciled_before_a_change(): void
    {
        $this->asAdmin();
        $asset = $this->assetHeldBy($this->ama); // written straight to the column: no history row yet
        $this->assertSame(0, IctAssetAssignment::query()->count());

        $this->service()->reassign($asset, $this->kofi->id);

        $this->assertSame(1, IctAssetAssignment::query()->where('ict_asset_id', $asset->id)->current()->count());
        $this->assertSame($this->ama->id, $asset->fresh()->previousHolder()?->id);
    }

    // ---- audit trail ------------------------------------------------------------------------------------------

    public function test_every_action_is_audited_with_ids_only(): void
    {
        $admin = $this->asAdmin();
        $asset = $this->assetHeldBy(null);

        $this->service()->assign($asset, $this->ama->id);
        $this->service()->reassign($asset, $this->kofi->id);
        $this->service()->correct($asset, $this->yaw->id);
        $this->service()->unassign($asset);

        $logs = AuditLog::query()->where('target_id', $asset->id)->where('action', 'like', 'asset_assignment_%')->orderBy('id')->get();

        $this->assertSame(
            ['asset_assignment_assign', 'asset_assignment_reassign', 'asset_assignment_correct', 'asset_assignment_unassign'],
            $logs->pluck('action')->all()
        );

        $correct = $logs[2]->metadata;
        $this->assertSame($this->kofi->id, $correct['from_employee_id']);
        $this->assertSame($this->yaw->id, $correct['to_employee_id']);
        $this->assertSame($admin->id, $correct['actor_user_id']);

        $dump = json_encode($logs->map->toArray()->all());
        foreach (['Ama', 'Kofi', 'Yaw', 'Boateng', 'Asare', 'Owusu'] as $name) {
            $this->assertStringNotContainsString($name, $dump, 'No employee name may reach the audit log.');
        }
    }

    // ---- the admin action on old rows --------------------------------------------------------------------------

    public function test_marking_a_past_row_as_a_correction_moves_the_previous_holder_back(): void
    {
        $admin = $this->asAdmin();
        $asset = $this->assetHeldBy($this->ama);
        $this->service()->reassign($asset, $this->kofi->id);   // Ama closed (reassigned)
        $this->service()->reassign($asset, $this->yaw->id);    // Kofi closed (reassigned)
        $this->assertSame($this->kofi->id, $asset->fresh()->previousHolder()?->id);

        $kofiRow = IctAssetAssignment::query()->where('ict_asset_id', $asset->id)->where('employee_id', $this->kofi->id)->sole();
        $this->service()->markRowCorrected($kofiRow, 'wrong_person_picked');

        $this->assertSame($this->ama->id, $asset->fresh()->previousHolder()?->id);
        $kofiRow->refresh();
        $this->assertSame('corrected', $kofiRow->end_reason);
        $this->assertSame('wrong_person_picked', $kofiRow->correction_code);
        $this->assertSame($admin->id, $kofiRow->ended_by_user_id);

        $log = AuditLog::query()->where('action', 'asset_assignment_correct_history_row')->sole();
        $this->assertSame('wrong_person_picked', $log->metadata['reason_code']);
        $this->assertSame($kofiRow->id, $log->metadata['assignment_id']);
    }

    public function test_the_admin_correction_needs_the_permission_a_valid_code_and_a_past_row(): void
    {
        $this->actingAs($this->ictUser($this->accra));
        $asset = $this->assetHeldBy($this->ama);
        $this->service()->reassign($asset, $this->kofi->id);
        $closed = IctAssetAssignment::query()->where('ict_asset_id', $asset->id)->where('employee_id', $this->ama->id)->sole();

        try {
            $this->service()->markRowCorrected($closed, 'data_entry_error');
            $this->fail('ICT without assets.correct_assignments must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->actingAs($this->superAdmin($this->accra));

        try {
            $this->service()->markRowCorrected($closed, 'because I said so');
            $this->fail('An unknown reason code must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('correctionCode', $e->errors());
        }

        $open = IctAssetAssignment::query()->where('ict_asset_id', $asset->id)->current()->sole();
        try {
            $this->service()->markRowCorrected($open, 'data_entry_error');
            $this->fail('The current holder is fixed with a correction, not this action.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('correctionCode', $e->errors());
        }

        $this->assertSame('reassigned', $closed->fresh()->end_reason);
    }

    public function test_the_detail_page_lists_the_history_and_only_super_admin_sees_the_fix_button(): void
    {
        $this->asAdmin();
        $asset = $this->assetHeldBy($this->ama);
        $this->service()->reassign($asset, $this->kofi->id);
        $this->service()->correct($asset, $this->yaw->id);

        $component = Livewire::test(AssetDetail::class, ['asset' => $asset->id])
            ->assertSee('Assignment history')
            ->assertSee('Ama Boateng')
            ->assertSee('Kofi Asare')
            ->assertSee('Yaw Owusu')
            ->assertSee('Corrected')
            ->assertSee('Picked by mistake')
            ->assertSee('Current')
            ->assertSee('Mark as correction');

        $amaRow = IctAssetAssignment::query()->where('employee_id', $this->ama->id)->sole();
        $component->call('startCorrecting', $amaRow->id)
            ->call('confirmCorrecting')
            ->assertHasErrors(['correctionCode' => 'required'])
            ->set('correctionCode', 'never_held')
            ->call('confirmCorrecting')
            ->assertHasNoErrors();

        $this->assertSame('corrected', $amaRow->fresh()->end_reason);

        // An ICT user may read the page but not mark rows.
        $this->actingAs($this->ictUser($this->accra, 'ICT009'));
        Livewire::test(AssetDetail::class, ['asset' => $asset->id])
            ->assertSee('Assignment history')
            ->assertDontSee('Mark as correction');
    }

    public function test_the_detail_page_does_not_leak_another_regions_history(): void
    {
        $kumasi = $this->makeDistrict('Kumasi Central District', 'Ashanti');
        $elsewhere = $this->makeAsset($kumasi);
        $this->actingAs($this->ictUser($this->accra));

        Livewire::test(AssetDetail::class, ['asset' => $elsewhere->id])->assertNotFound();
    }

    // ---- backfill ---------------------------------------------------------------------------------------------

    public function test_the_backfill_rebuilds_history_from_the_old_columns_and_is_safe_to_rerun(): void
    {
        $withPrevious = $this->assetHeldBy($this->kofi);
        $unassignedAfter = $this->assetHeldBy(null);
        $onlyCurrent = $this->assetHeldBy($this->yaw);
        $nothing = $this->assetHeldBy(null);

        // The old column is still there; write it the way the old code did.
        \Illuminate\Support\Facades\DB::table('ict_assets')->where('id', $withPrevious->id)->update(['previous_assigned_to_employee_id' => $this->ama->id]);
        \Illuminate\Support\Facades\DB::table('ict_assets')->where('id', $unassignedAfter->id)->update(['previous_assigned_to_employee_id' => $this->ama->id]);
        IctAssetTransfer::query()->create([
            'ict_asset_id' => $withPrevious->id, 'transfer_type' => 'assignment_change',
            'from_employee_id' => $this->ama->id, 'to_employee_id' => $this->kofi->id, 'occurred_at' => '2026-03-10 09:00:00',
        ]);
        IctAssetAssignment::query()->delete();

        $migration = require base_path('database/migrations/2026_11_07_000002_backfill_ict_asset_assignments.php');
        $migration->up();
        $migration->up(); // re-running adds nothing

        $this->assertSame(4, IctAssetAssignment::query()->count()); // 2 open (kofi, yaw) + 2 closed (ama x2)
        $this->assertSame($this->ama->id, $withPrevious->fresh()->previousHolder()?->id);
        $this->assertSame($this->ama->id, $unassignedAfter->fresh()->previousHolder()?->id);

        $closed = IctAssetAssignment::query()->where('ict_asset_id', $withPrevious->id)->whereNotNull('end_reason')->sole();
        $this->assertSame('reassigned', $closed->end_reason);
        $this->assertTrue($closed->is_backfilled);
        $this->assertSame('2026-03-10 09:00:00', $closed->ended_at->format('Y-m-d H:i:s'));

        $this->assertSame('unassigned', IctAssetAssignment::query()->where('ict_asset_id', $unassignedAfter->id)->sole()->end_reason);
        $this->assertSame(1, IctAssetAssignment::query()->where('ict_asset_id', $withPrevious->id)->current()->count());
        $this->assertSame(1, IctAssetAssignment::query()->where('ict_asset_id', $onlyCurrent->id)->count());
        $this->assertSame(0, IctAssetAssignment::query()->where('ict_asset_id', $nothing->id)->count());

        // Nothing wrote the old column, and it is untouched.
        $this->assertSame($this->ama->id, (int) \Illuminate\Support\Facades\DB::table('ict_assets')->where('id', $withPrevious->id)->value('previous_assigned_to_employee_id'));
    }
}
