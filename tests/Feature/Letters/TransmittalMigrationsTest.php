<?php

namespace Tests\Feature\Letters;

use App\Models\Employee;
use App\Models\LetterNotification;
use App\Models\RoutingHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Letters\Concerns\BuildsLettersOrg;
use Tests\TestCase;

class TransmittalMigrationsTest extends TestCase
{
    use BuildsLettersOrg;
    use RefreshDatabase;

    protected Employee $hrSec;

    protected Employee $cmSec;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildLettersOrg();
        $this->hrSec = $this->letterStaff('HR001', $this->accraOffice);
        $this->cmSec = $this->letterStaff('CM001', $this->accraOffice);
    }

    private function migration(string $file)
    {
        return require database_path("migrations/{$file}.php");
    }

    public function test_the_schema_has_the_new_tables_and_columns(): void
    {
        $this->assertTrue(Schema::hasTable('letter_dispatch_batches'));
        $this->assertTrue(Schema::hasColumns('letter_dispatch_batches', [
            'batch_no', 'from_secretariat_id', 'to_secretariat_id', 'note', 'letters_count', 'confirmed_count', 'dispatched_at', 'completed_at',
        ]));
        $this->assertTrue(Schema::hasColumns('routing_histories', [
            'batch_id', 'confirmed_at', 'confirmed_by_id', 'resolution', 'resolved_at', 'resolution_note',
        ]));
        $this->assertTrue(Schema::hasColumn('letter_notifications', 'batch_id'));
    }

    public function test_letter_notifications_letter_id_is_nullable_and_keeps_its_cascade(): void
    {
        $column = collect(Schema::getColumns('letter_notifications'))->firstWhere('name', 'letter_id');
        $this->assertTrue($column['nullable']);

        // A batch notification has no letter.
        LetterNotification::query()->create(['title' => 'T', 'message' => 'M', 'secretariat_id' => $this->cmSec->id, 'letter_id' => null]);
        $this->assertSame(1, LetterNotification::query()->whereNull('letter_id')->count());

        // The foreign key to mail_letters is still there, still cascading.
        $letter = $this->createLetter($this->hrSec);
        $this->lettersWorkflow()->dispatch($letter, $this->hrSec, $this->cmSec);
        $this->assertSame(1, LetterNotification::query()->where('letter_id', $letter->id)->count());

        $letter->delete();

        $this->assertSame(0, LetterNotification::query()->where('letter_id', $letter->id)->count());
        $this->assertSame(1, LetterNotification::query()->whereNull('letter_id')->count(), 'notifications without a letter are untouched');
        $this->assertNotEmpty(collect(Schema::getForeignKeys('letter_notifications'))->firstWhere('columns', ['letter_id']));
    }

    public function test_deleting_a_batch_cascades_its_notification_and_detaches_its_hops(): void
    {
        $letters = $this->createLetters($this->hrSec, 2);
        $batch = $this->lettersWorkflow()->dispatchBatch($this->hrSec, $this->cmSec, $letters->pluck('id')->all());

        $batch->delete();

        $this->assertSame(0, LetterNotification::query()->count());
        $this->assertSame(2, RoutingHistory::query()->whereNull('batch_id')->count(), 'nullOnDelete keeps the hops and their history');
    }

    public function test_the_routing_history_backfill_dates_old_confirmations_and_is_safe_to_rerun(): void
    {
        $confirmed = $this->createLetter($this->hrSec, ['subject' => 'Confirmed long ago']);
        $pending = $this->createLetter($this->hrSec, ['subject' => 'Still pending']);
        $this->lettersWorkflow()->dispatch($confirmed, $this->hrSec, $this->cmSec);
        $this->lettersWorkflow()->dispatch($pending, $this->hrSec, $this->cmSec);

        // Make the first hop look like a row confirmed before these columns existed.
        DB::table('routing_histories')->where('letter_id', $confirmed->id)->update([
            'received_confirm' => true,
            'confirmed_at' => null,
            'confirmed_by_id' => null,
            'updated_at' => '2026-03-04 09:30:00',
        ]);

        $migration = $this->migration('2026_10_01_000002_add_batch_and_confirmation_columns_to_routing_histories_table');
        $migration->up();
        $migration->up();

        $hop = RoutingHistory::query()->where('letter_id', $confirmed->id)->sole();
        $this->assertSame('2026-03-04 09:30:00', $hop->confirmed_at->format('Y-m-d H:i:s'));
        $this->assertSame($this->cmSec->id, $hop->confirmed_by_id, 'only the recipient could confirm');

        $open = RoutingHistory::query()->where('letter_id', $pending->id)->sole();
        $this->assertNull($open->confirmed_at);
        $this->assertNull($open->confirmed_by_id);
    }

    public function test_every_new_migration_can_be_run_again_without_error(): void
    {
        foreach ([
            '2026_10_01_000001_create_letter_dispatch_batches_table',
            '2026_10_01_000002_add_batch_and_confirmation_columns_to_routing_histories_table',
            '2026_10_01_000003_add_batch_id_to_letter_notifications_table',
        ] as $file) {
            $this->migration($file)->up();
        }

        $this->assertTrue(Schema::hasTable('letter_dispatch_batches'));
        $this->assertTrue(collect(Schema::getColumns('letter_notifications'))->firstWhere('name', 'letter_id')['nullable']);
    }

    public function test_the_batch_number_is_unique_but_may_be_empty_until_it_is_assigned(): void
    {
        $now = now();
        $row = fn (?string $number) => [
            'batch_no' => $number,
            'from_secretariat_id' => $this->hrSec->id,
            'to_secretariat_id' => $this->cmSec->id,
            'letters_count' => 1,
            'dispatched_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        DB::table('letter_dispatch_batches')->insert($row(null));
        DB::table('letter_dispatch_batches')->insert($row(null));
        DB::table('letter_dispatch_batches')->insert($row('TR-2026-000001'));

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('letter_dispatch_batches')->insert($row('TR-2026-000001'));
    }
}
