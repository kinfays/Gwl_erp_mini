<?php

namespace Tests\Feature\Letters;

use App\Livewire\Staff\RegionsManager;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LetterSnCounter;
use App\Models\MailLetter;
use App\Models\ModuleAccess;
use App\Models\Permission;
use App\Models\Region;
use App\Models\Role;
use App\Services\Letters\LetterWorkflowService;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Letters\Concerns\BuildsLettersOrg;
use Tests\TestCase;

/**
 * D3: serial numbers. Prefix per region (regions.letter_prefix), a locked counter per prefix and year
 * (letter_sn_counters), a numeric sequence that goes on past 999.
 */
class SerialNumberTest extends TestCase
{
    use BuildsLettersOrg;
    use RefreshDatabase;

    protected Employee $hrSec;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildLettersOrg();
        $this->hrSec = $this->letterStaff('HR001', $this->accraOffice);
    }

    private function region(string $name, ?string $prefix = null): Region
    {
        return Region::query()->create(['region_name' => $name, 'letter_prefix' => $prefix]);
    }

    private function letterIn(Region $region, array $overrides = []): MailLetter
    {
        return $this->createLetter($this->hrSec, ['region_id' => $region->id, ...$overrides]);
    }

    /** A letter as the old code would have left it: a serial number written directly, no counter involved. */
    private function legacyLetter(Region $region, string $sn): MailLetter
    {
        return MailLetter::query()->create([
            'sn_number' => $sn,
            'subject' => "Legacy {$sn}",
            'type' => 'External',
            'company_sender' => 'Old Co',
            'date_on_letter' => '2025-01-01',
            'region_id' => $region->id,
            'created_by_id' => $this->hrSec->id,
        ]);
    }

    private function migration(string $file)
    {
        return require database_path("migrations/{$file}.php");
    }

    private function year(): int
    {
        return now()->year;
    }

    // ---- prefix backfill ----------------------------------------------------------------------------------

    public function test_one_word_regions_get_different_prefixes_and_both_can_issue_their_first_letter(): void
    {
        $ashanti = $this->region('Ashanti');
        $ahafo = $this->region('Ahafo');

        $this->migration('2026_10_02_000001_add_letter_prefix_to_regions_table')->up();

        $this->assertSame('A', $ashanti->fresh()->letter_prefix, 'the first region by id keeps the plain initials');
        $this->assertSame('A'.$ahafo->id, $ahafo->fresh()->letter_prefix, 'the clash is resolved by appending the region id');

        $first = $this->letterIn($ashanti);
        $second = $this->letterIn($ahafo);

        $this->assertSame('A-'.$this->year().'-001', $first->sn_number);
        $this->assertSame('A'.$ahafo->id.'-'.$this->year().'-001', $second->sn_number);
    }

    public function test_the_backfill_is_deterministic_keeps_existing_prefixes_and_never_rewrites_issued_numbers(): void
    {
        $chosen = $this->region('Volta', 'VR');
        $first = $this->region('Western North');
        $second = $this->region('Wa North');
        $third = $this->region('Western Nile');
        $legacy = $this->legacyLetter($first, 'WN-2025-003');

        $migration = $this->migration('2026_10_02_000001_add_letter_prefix_to_regions_table');
        $migration->up();
        $migration->up();

        $this->assertSame('VR', $chosen->fresh()->letter_prefix, 'an existing prefix is left alone');
        $this->assertSame('WN', $first->fresh()->letter_prefix);
        $this->assertSame('WN'.$second->id, $second->fresh()->letter_prefix);
        $this->assertSame('WN'.$third->id, $third->fresh()->letter_prefix);
        $this->assertSame('WN-2025-003', $legacy->fresh()->sn_number);
        $this->assertSame(Region::query()->count(), Region::query()->distinct()->count('letter_prefix'), 'every region has its own prefix');
    }

    public function test_a_region_name_with_many_words_is_cut_to_the_column_width(): void
    {
        $long = $this->region('A B C D E F G H I J K L');

        $this->migration('2026_10_02_000001_add_letter_prefix_to_regions_table')->up();

        $this->assertSame('ABCDEFGHIJ', $long->fresh()->letter_prefix);
    }

    public function test_the_prefix_is_unique_in_the_database(): void
    {
        $this->region('Volta', 'VR');

        $this->expectException(QueryException::class);
        $this->region('Volta Two', 'VR');
    }

    // ---- counter seeding ----------------------------------------------------------------------------------

    public function test_the_counter_migration_is_seeded_from_issued_letters_by_parsing_the_suffix(): void
    {
        $aw = $this->region('Accra West', 'AW');
        LetterSnCounter::query()->delete();

        foreach (['AW-2026-005', 'AW-2026-012', 'AW-2026-1000', 'AW-2025-777', 'A-B-2026-003', 'junk', 'AW-26-001', 'AW-2026-'] as $sn) {
            $this->legacyLetter($aw, $sn);
        }

        $migration = $this->migration('2026_10_02_000002_create_letter_sn_counters_table');
        $migration->up();
        $migration->up(); // safe to re-run

        $counters = LetterSnCounter::query()->get()->mapWithKeys(fn ($c) => ["{$c->prefix}|{$c->year}" => $c->last_number])->all();

        $this->assertSame(['AW|2026' => 1000, 'AW|2025' => 777, 'A-B|2026' => 3], $counters, 'numeric maximum per prefix and year; malformed numbers are ignored');
    }

    public function test_seeding_never_lowers_an_existing_counter_but_raises_a_stale_one(): void
    {
        $aw = $this->region('Accra West', 'AW');
        LetterSnCounter::query()->delete();
        LetterSnCounter::query()->create(['prefix' => 'AW', 'year' => 2026, 'last_number' => 40]);
        LetterSnCounter::query()->create(['prefix' => 'AX', 'year' => 2026, 'last_number' => 1]);
        $this->legacyLetter($aw, 'AW-2026-012');
        $this->legacyLetter($aw, 'AX-2026-009');

        $this->migration('2026_10_02_000002_create_letter_sn_counters_table')->up();

        $this->assertSame(40, LetterSnCounter::query()->where('prefix', 'AW')->value('last_number'));
        $this->assertSame(9, LetterSnCounter::query()->where('prefix', 'AX')->value('last_number'));
    }

    public function test_legacy_letters_carry_on_with_the_next_number(): void
    {
        $aw = $this->region('Accra West'); // derives AW
        $this->legacyLetter($aw, 'AW-'.$this->year().'-005');
        $this->legacyLetter($aw, 'AW-'.($this->year() - 1).'-900');

        $this->assertSame('AW-'.$this->year().'-006', $this->letterIn($aw)->sn_number);
        $this->assertSame('AW-'.$this->year().'-007', $this->letterIn($aw)->sn_number);
    }

    public function test_a_missing_counter_row_is_created_from_the_highest_issued_number_not_the_highest_string(): void
    {
        $aw = $this->region('Accra West');
        $y = $this->year();
        $this->legacyLetter($aw, "AW-{$y}-999");
        $this->legacyLetter($aw, "AW-{$y}-1000"); // sorts below ...-999 as a string
        $this->legacyLetter($aw, "AW-{$y}-0042");

        $this->assertSame("AW-{$y}-1001", $this->letterIn($aw)->sn_number);
        $this->assertSame(1001, LetterSnCounter::query()->where('prefix', 'AW')->where('year', $y)->value('last_number'));
    }

    // ---- allocation ---------------------------------------------------------------------------------------

    public function test_the_sequence_goes_on_past_999(): void
    {
        $y = $this->year();
        $ga = $this->accra;
        $ga->assignLetterPrefix();
        LetterSnCounter::query()->create(['prefix' => 'GA', 'year' => $y, 'last_number' => 998]);

        $numbers = collect(range(1, 4))->map(fn () => $this->letterIn($ga)->sn_number)->all();

        $this->assertSame(["GA-{$y}-999", "GA-{$y}-1000", "GA-{$y}-1001", "GA-{$y}-1002"], $numbers);
    }

    public function test_creating_letters_in_a_loop_never_duplicates_and_stays_sequential(): void
    {
        $numbers = collect(range(1, 60))->map(fn () => $this->letterIn($this->accra)->sn_number);

        $this->assertCount(60, $numbers->unique());
        $this->assertSame(
            range(1, 60),
            $numbers->map(fn (string $sn) => (int) substr($sn, strrpos($sn, '-') + 1))->all()
        );
    }

    public function test_each_prefix_and_each_year_has_its_own_counter(): void
    {
        $volta = $this->region('Volta', 'VR');

        $this->assertSame('GA-'.$this->year().'-001', $this->letterIn($this->accra)->sn_number);
        $this->assertSame('GA-'.$this->year().'-002', $this->letterIn($this->accra)->sn_number);
        $this->assertSame('VR-'.$this->year().'-001', $this->letterIn($volta)->sn_number);

        $this->travelTo(now()->addYear());

        $this->assertSame('GA-'.$this->year().'-001', $this->letterIn($this->accra)->sn_number, 'a new year starts again at 001');
        $this->assertSame(3, LetterSnCounter::query()->count());
    }

    public function test_a_number_already_taken_outside_the_counter_is_skipped_not_a_collision(): void
    {
        $y = $this->year();
        $this->accra->assignLetterPrefix();
        LetterSnCounter::query()->create(['prefix' => 'GA', 'year' => $y, 'last_number' => 5]);
        $this->legacyLetter($this->accra, "GA-{$y}-006"); // hand-entered behind the counter's back

        $this->assertSame("GA-{$y}-007", $this->letterIn($this->accra)->sn_number);
    }

    /**
     * A second connection cannot be simulated on in-memory SQLite, so the race is staged at the seam: the insert "loses"
     * to another request, whose committed row (last_number 4) is there when we look again.
     */
    public function test_losing_the_race_to_create_the_counter_row_reads_the_winners_row_once(): void
    {
        $y = $this->year();
        $this->accra->assignLetterPrefix();

        $this->app->bind(LetterWorkflowService::class, fn () => new class extends LetterWorkflowService
        {
            protected function createSnCounter(string $prefix, int $year): void
            {
                DB::table('letter_sn_counters')->insert(['prefix' => $prefix, 'year' => $year, 'last_number' => 4, 'created_at' => now(), 'updated_at' => now()]);

                throw new UniqueConstraintViolationException('sqlite', 'insert into letter_sn_counters', [], new \Exception('UNIQUE constraint failed'));
            }
        });

        $this->assertSame("GA-{$y}-005", $this->letterIn($this->accra)->sn_number);
        $this->assertSame(1, LetterSnCounter::query()->count());
        $this->assertSame(5, LetterSnCounter::query()->value('last_number'));
    }

    public function test_a_unique_violation_with_no_row_to_read_back_is_not_swallowed(): void
    {
        $this->accra->assignLetterPrefix();

        $this->app->bind(LetterWorkflowService::class, fn () => new class extends LetterWorkflowService
        {
            protected function createSnCounter(string $prefix, int $year): void
            {
                throw new UniqueConstraintViolationException('sqlite', 'insert into letter_sn_counters', [], new \Exception('UNIQUE constraint failed'));
            }
        });

        $this->expectException(UniqueConstraintViolationException::class);
        $this->letterIn($this->accra);
    }

    public function test_the_number_is_rolled_back_with_a_letter_that_fails_to_save(): void
    {
        $y = $this->year();
        $this->accra->assignLetterPrefix();

        try {
            $this->lettersWorkflow()->create($this->hrSec, $this->letterData(['date_on_letter' => null]));
            $this->fail('Creating a letter without a date should fail.');
        } catch (QueryException) {
        }

        $this->assertSame(0, MailLetter::query()->count());
        $this->assertSame(0, LetterSnCounter::query()->where('prefix', 'GA')->value('last_number') ?? 0, 'no number is burned by a failed create');
        $this->assertSame("GA-{$y}-001", $this->letterIn($this->accra)->sn_number);
    }

    // ---- prefix on first use ------------------------------------------------------------------------------

    public function test_a_region_without_a_prefix_is_given_a_derived_unique_one_on_first_use_and_keeps_it(): void
    {
        $gold = $this->region('Gold Adventure'); // initials GA, like Greater Accra
        $this->assertNull($this->accra->fresh()->letter_prefix);

        $this->assertSame('GA-'.$this->year().'-001', $this->letterIn($this->accra)->sn_number);
        $this->assertSame('GA'.$gold->id.'-'.$this->year().'-001', $this->letterIn($gold)->sn_number);

        $this->assertSame('GA', $this->accra->fresh()->letter_prefix);
        $this->assertSame('GA'.$gold->id, $gold->fresh()->letter_prefix);
        $this->assertSame('GA-'.$this->year().'-002', $this->letterIn($this->accra)->sn_number);
    }

    public function test_a_name_without_words_falls_back_to_reg_with_its_own_counter(): void
    {
        $blank = $this->region(' ');
        $otherBlank = $this->region('  ');

        $this->assertSame('REG-'.$this->year().'-001', $this->letterIn($blank)->sn_number);
        $this->assertSame('REG-'.$this->year().'-002', $this->letterIn($blank)->sn_number);
        $this->assertSame('REG'.$otherBlank->id.'-'.$this->year().'-001', $this->letterIn($otherBlank)->sn_number);
    }

    public function test_renaming_a_region_does_not_change_its_prefix_or_its_numbers(): void
    {
        $this->assertSame('GA-'.$this->year().'-001', $this->letterIn($this->accra)->sn_number);

        $this->accra->update(['region_name' => 'Accra Metropolitan']);

        $this->assertSame('GA-'.$this->year().'-002', $this->letterIn($this->accra)->sn_number);
    }

    public function test_changing_a_prefix_starts_the_new_prefixs_own_counter_and_leaves_old_numbers_alone(): void
    {
        $y = $this->year();
        $first = $this->letterIn($this->accra);
        $second = $this->letterIn($this->accra);
        $this->assertSame(["GA-{$y}-001", "GA-{$y}-002"], [$first->sn_number, $second->sn_number]);

        $this->accra->update(['letter_prefix' => 'GAR']);

        $this->assertSame("GAR-{$y}-001", $this->letterIn($this->accra)->sn_number);
        $this->assertSame(["GA-{$y}-001", "GA-{$y}-002"], [$first->fresh()->sn_number, $second->fresh()->sn_number]);

        // Going back picks the old sequence up where it stopped.
        $this->accra->update(['letter_prefix' => 'GA']);
        $this->assertSame("GA-{$y}-003", $this->letterIn($this->accra)->sn_number);
    }

    // ---- Regions manager ----------------------------------------------------------------------------------

    private function regionsManager(?Employee $as = null)
    {
        $this->actingAs($this->letterUserOf($as ?? $this->letterStaff('ADM01', $this->accraOffice, ['super_admin'])));

        return Livewire::test(RegionsManager::class);
    }

    public function test_the_manager_creates_a_region_with_a_chosen_prefix_and_audits_it(): void
    {
        $this->regionsManager()
            ->set('region_name', 'Volta')
            ->set('letter_prefix', 'VR')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('letter_prefix', '');

        $region = Region::query()->where('region_name', 'Volta')->sole();
        $this->assertSame('VR', $region->letter_prefix);

        $audit = AuditLog::query()->where('action', 'create_region')->latest('id')->first();
        $this->assertSame('VR', $audit->new_values['letter_prefix']);
    }

    public function test_a_blank_prefix_on_create_is_derived_and_made_unique(): void
    {
        $manager = $this->regionsManager();

        $manager->set('region_name', 'Gold Adventure')->call('save')->assertHasNoErrors();
        $this->assertSame('GA', Region::query()->where('region_name', 'Gold Adventure')->value('letter_prefix'));

        $manager->set('region_name', 'Grand Atlantic')->call('save')->assertHasNoErrors();
        $grand = Region::query()->where('region_name', 'Grand Atlantic')->sole();
        $this->assertSame('GA'.$grand->id, $grand->letter_prefix);

        $audit = AuditLog::query()->where('action', 'create_region')->latest('id')->first();
        $this->assertSame($grand->letter_prefix, $audit->new_values['letter_prefix'], 'the audit row carries the derived prefix');
    }

    public function test_malformed_and_duplicate_prefixes_are_rejected_on_create(): void
    {
        $this->region('Volta', 'VR');
        $manager = $this->regionsManager();

        foreach (['A', 'ABCDEFG', 'ab', 'Vr', 'V-R', 'V R', 'V_R', 'VR'] as $bad) { // 'VR' is taken
            $manager->set('region_name', 'Bono')->set('letter_prefix', $bad)->call('save')->assertHasErrors(['letter_prefix']);
        }

        $this->assertDatabaseMissing('regions', ['region_name' => 'Bono']);

        foreach (['AB', 'ASH2', 'A1B2C3', '99'] as $good) {
            $manager->set('region_name', "Region {$good}")->set('letter_prefix', $good)->call('save')->assertHasNoErrors();
            $this->assertDatabaseHas('regions', ['region_name' => "Region {$good}", 'letter_prefix' => $good]);
        }
    }

    public function test_the_manager_edits_a_prefix_and_audits_old_and_new(): void
    {
        $manager = $this->regionsManager()
            ->call('edit', $this->accra->id)
            ->assertSet('editingPrefix', '')
            ->set('editingPrefix', 'GAR')
            ->call('update')
            ->assertHasNoErrors();

        $this->assertSame('GAR', $this->accra->fresh()->letter_prefix);

        $audit = AuditLog::query()->where('action', 'update_region')->latest('id')->first();
        $this->assertNull($audit->old_values['letter_prefix']);
        $this->assertSame('GAR', $audit->new_values['letter_prefix']);

        $manager->call('edit', $this->accra->id)->assertSet('editingPrefix', 'GAR');
    }

    public function test_an_edit_cannot_take_another_regions_prefix_but_may_keep_its_own(): void
    {
        $this->region('Volta', 'VR');
        $this->accra->update(['letter_prefix' => 'GA']);

        $this->regionsManager()
            ->call('edit', $this->accra->id)
            ->set('editingPrefix', 'VR')
            ->call('update')
            ->assertHasErrors(['editingPrefix'])
            ->set('editingPrefix', 'ga')
            ->call('update')
            ->assertHasErrors(['editingPrefix'])
            ->set('editingPrefix', 'GA')
            ->set('editingName', 'Greater Accra Region')
            ->call('update')
            ->assertHasNoErrors();

        $this->assertSame('Greater Accra Region', $this->accra->fresh()->region_name);
        $this->assertSame('GA', $this->accra->fresh()->letter_prefix);
    }

    public function test_renaming_a_region_with_a_short_derived_prefix_is_not_blocked_by_the_prefix_rule(): void
    {
        $ashanti = $this->region('Ashanti', 'A'); // what the backfill gives a one-word region: below the 2-character rule for typed prefixes

        $this->regionsManager()
            ->call('edit', $ashanti->id)
            ->set('editingName', 'Ashanti Region')
            ->call('update')
            ->assertHasNoErrors();

        $this->assertSame('Ashanti Region', $ashanti->fresh()->region_name);
        $this->assertSame('A', $ashanti->fresh()->letter_prefix);
    }

    public function test_a_prefix_cannot_be_cleared_but_a_region_without_one_may_stay_without(): void
    {
        $volta = $this->region('Volta', 'VR');

        $manager = $this->regionsManager()
            ->call('edit', $volta->id)
            ->set('editingPrefix', '')
            ->call('update')
            ->assertHasErrors(['editingPrefix']);

        $manager
            ->call('edit', $this->accra->id) // no prefix yet
            ->set('editingName', 'Greater Accra Region')
            ->call('update')
            ->assertHasNoErrors();

        $this->assertSame('VR', $volta->fresh()->letter_prefix);
        $this->assertNull($this->accra->fresh()->letter_prefix, 'still derived on its first letter');
    }

    public function test_the_screen_shows_each_prefix_and_explains_that_changes_only_affect_future_letters(): void
    {
        $this->region('Volta', 'VR');

        $this->regionsManager()
            ->assertSee('Letter prefix')
            ->assertSee('VR')
            ->assertSee('Set on first letter')
            ->assertSee('only affects future letters')
            ->call('edit', $this->accra->id)
            ->assertSee('never rewritten');
    }

    public function test_only_people_who_may_manage_regions_can_open_the_manager(): void
    {
        $clerkRole = Role::query()->create(['name' => 'regions_clerk', 'display_name' => 'Regions clerk', 'is_system' => false]);
        $clerkRole->permissions()->attach(Permission::query()->firstOrCreate(['name' => 'staff.manage_regions'], ['display_name' => 'Manage regions', 'module' => 'staff'])->id);
        ModuleAccess::query()->create(['role_id' => $clerkRole->id, 'module' => 'staff', 'can_access' => true]);
        $clerk = $this->letterStaff('CLK01', $this->accraOffice, ['regions_clerk']);

        $this->regionsManager($clerk)
            ->set('region_name', 'Bono')
            ->set('letter_prefix', 'BO')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertDatabaseHas('regions', ['region_name' => 'Bono', 'letter_prefix' => 'BO']);

        // A secretary has no Staff module access at all.
        $this->actingAs($this->letterUserOf($this->hrSec));
        Livewire::test(RegionsManager::class)->assertForbidden();
    }
}
