<?php

namespace Tests\Feature\Letters;

use App\Livewire\Letters\ActiveLetters;
use App\Models\Employee;
use App\Models\ModuleAccess;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoutingHistory;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Letters\Concerns\BuildsLettersOrg;
use Tests\TestCase;

/**
 * The Managing Director does NOT hold letters (decided 2026-09-30): no Letters module, no letters.* permission. Letters
 * for the MD are held by the MD's office secretary, and "Deliver to addressee" records the paper hand-over. This guard
 * runs against the real seeders (and the migrations RefreshDatabase already ran) so a later change that grants it by
 * accident fails here.
 */
class ManagingDirectorGuardTest extends TestCase
{
    use BuildsLettersOrg;
    use RefreshDatabase;

    protected Employee $md;

    protected Employee $hrSec;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class); // every seeder, in the real order
        $this->buildLettersOrg();

        $this->hrSec = $this->letterStaff('HR001', $this->headOffice);
        $this->md = $this->letterStaff('MD001', $this->headOffice, ['managing_director']);
    }

    private function mdRole(): Role
    {
        return Role::query()->where('name', 'managing_director')->firstOrFail();
    }

    public function test_the_seeded_managing_director_role_has_no_letters_module_access(): void
    {
        $role = $this->mdRole();

        $this->assertFalse(
            ModuleAccess::query()->where('role_id', $role->id)->where('module', Permission::MODULE_LETTERS)->where('can_access', true)->exists(),
            'managing_director must not be given the Letters module'
        );

        $this->assertNotContains(Permission::MODULE_LETTERS, $this->letterUserOf($this->md)->getAccessibleModules());
        $this->assertContains(Permission::MODULE_LEAVE, $this->letterUserOf($this->md)->getAccessibleModules(), 'the MD keeps Leave');
    }

    public function test_the_seeded_managing_director_role_holds_no_letters_permission(): void
    {
        $permissions = $this->mdRole()->permissions()->pluck('name')->all();

        $this->assertSame([], array_values(array_filter($permissions, fn (string $name) => str_starts_with($name, 'letters.'))), 'no letters.* permission');
        $this->assertFalse($this->letterUserOf($this->md)->hasPermission('letters.view'));
    }

    public function test_recipients_query_never_returns_the_managing_director(): void
    {
        $others = [
            $this->letterStaff('SEC02', $this->headOffice),
            $this->letterStaff('MGR01', $this->headOffice, ['manager']),
        ];

        foreach ([null, 'mine', 'head_office', 'any'] as $scope) {
            $ids = $this->lettersWorkflow()->recipientsQuery(null, $this->hrSec, $scope)->pluck('id')->all();
            $this->assertNotContains($this->md->id, $ids, "scope {$scope}");
            $this->assertEqualsCanonicalizing(collect($others)->pluck('id')->all(), $ids, "everyone else is still listed ({$scope})");
        }

        $this->assertSame([], $this->lettersWorkflow()->recipientsQuery('MD001', $this->hrSec)->pluck('id')->all(), 'not even when searched for');
        $this->assertSame([], $this->lettersWorkflow()->recipientsQuery('Employee MD001', $this->hrSec, 'any')->pluck('id')->all());

        $letter = $this->createLetter($this->hrSec);
        $picker = $this->lettersWorkflow()->recipientPicker($this->hrSec, collect([$letter]), null, 'any');
        $listed = collect([$picker['previous']])->merge($picker['recent'])->merge($picker['secretaries'])->merge($picker['managers'])->filter()->pluck('id');
        $this->assertNotContains($this->md->id, $listed->all());
    }

    public function test_dispatch_rejects_the_managing_director_even_with_a_crafted_id(): void
    {
        $letter = $this->createLetter($this->hrSec);
        $letters = $this->createLetters($this->hrSec, 2);

        try {
            $this->lettersWorkflow()->dispatch($letter, $this->hrSec, $this->md);
            $this->fail('dispatch() must refuse the Managing Director.');
        } catch (\RuntimeException $e) {
            $this->assertSame('The selected recipient cannot receive letters.', $e->getMessage());
        }

        try {
            $this->lettersWorkflow()->dispatchBatch($this->hrSec, $this->md, $letters->pluck('id')->all());
            $this->fail('dispatchBatch() must refuse the Managing Director.');
        } catch (\RuntimeException $e) {
            $this->assertSame('The selected recipient cannot receive letters.', $e->getMessage());
        }

        $this->assertSame(0, RoutingHistory::query()->count());
    }

    public function test_a_crafted_id_in_the_ui_does_not_reach_the_managing_director_either(): void
    {
        $letter = $this->createLetter($this->hrSec);
        $other = $this->createLetter($this->hrSec, ['subject' => 'Other']);
        $this->actingAs($this->letterUserOf($this->hrSec));

        Livewire::test(ActiveLetters::class)
            ->call('openLetter', $letter->id)
            ->set('dispatchToId', $this->md->id)
            ->call('dispatchLetter')
            ->assertDispatched('toast', type: 'error', message: 'The selected recipient cannot receive letters.');

        Livewire::test(ActiveLetters::class)
            ->set('selected', [$other->id])
            ->call('openBulkDispatch')
            ->set('bulkDispatchToId', $this->md->id)
            ->call('dispatchSelected')
            ->assertDispatched('toast', type: 'error', message: 'The selected recipient cannot receive letters.');

        $this->assertSame(0, RoutingHistory::query()->count());
        $this->assertFalse($this->lettersWorkflow()->visibleLettersQuery($this->md)->exists());
    }

    public function test_the_managing_director_cannot_open_the_letters_module(): void
    {
        $this->actingAs($this->letterUserOf($this->md))
            ->get(route('letters.active'))
            ->assertRedirect('/dashboard');

        $this->actingAs($this->letterUserOf($this->md));
        Livewire::test(ActiveLetters::class)->assertForbidden();
    }
}
