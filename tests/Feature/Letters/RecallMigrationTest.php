<?php

namespace Tests\Feature\Letters;

use App\Models\Employee;
use App\Models\LetterStatusLog;
use App\Models\RoutingHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Letters\Concerns\BuildsLettersOrg;
use Tests\TestCase;

class RecallMigrationTest extends TestCase
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

    private function migration()
    {
        return require database_path('migrations/2026_10_03_000001_add_recall_and_reminder_columns_to_letters_tables.php');
    }

    private function logOf($letter, Employee $employee): ?LetterStatusLog
    {
        return LetterStatusLog::query()->where('letter_id', $letter->id)->where('secretariat_id', $employee->id)->orderByDesc('id')->first();
    }

    public function test_the_schema_has_the_new_columns_and_an_index(): void
    {
        $this->assertTrue(Schema::hasColumn('letter_status_logs', 'routing_history_id'));
        $this->assertTrue(Schema::hasColumn('routing_histories', 'reminded_at'));
        $this->assertTrue(Schema::hasIndex('letter_status_logs', ['routing_history_id']));
    }

    public function test_new_hops_link_the_recipients_log_to_themselves_for_single_and_batch_dispatches(): void
    {
        $single = $this->createLetter($this->hrSec);
        $this->lettersWorkflow()->dispatch($single, $this->hrSec, $this->cmSec);
        $letters = $this->createLetters($this->hrSec, 2);
        $this->lettersWorkflow()->dispatchBatch($this->hrSec, $this->cmSec, $letters->pluck('id')->all());

        foreach (RoutingHistory::query()->get() as $hop) {
            $log = $this->logOf($hop->letter, $this->cmSec);
            $this->assertSame($hop->id, $log->routing_history_id);
            $this->assertSame($hop->id, $log->routingHistory->id);
        }

        $this->assertNull($this->logOf($single, $this->hrSec)->routing_history_id, "the creator's first log belongs to no hop");
    }

    public function test_the_backfill_links_only_awaiting_hops_to_the_recipients_latest_received_log(): void
    {
        $awaiting = $this->createLetter($this->hrSec, ['subject' => 'Awaiting']);
        $confirmed = $this->createLetter($this->hrSec, ['subject' => 'Confirmed']);
        $this->lettersWorkflow()->dispatch($awaiting, $this->hrSec, $this->cmSec);
        $this->lettersWorkflow()->dispatch($confirmed, $this->hrSec, $this->cmSec);
        $this->lettersWorkflow()->confirmHardcopy($confirmed, $this->cmSec);

        // Rows as they were before the link existed, with an older Received log for the same person that must not win.
        DB::table('letter_status_logs')->update(['routing_history_id' => null]);
        $awaitingHop = RoutingHistory::query()->where('letter_id', $awaiting->id)->sole();
        $hopLog = $this->logOf($awaiting, $this->cmSec);
        DB::table('routing_histories')->where('id', $awaitingHop->id)->update(['created_at' => '2026-05-01 10:00:00']);
        DB::table('letter_status_logs')->where('id', $hopLog->id)->update(['created_at' => '2026-05-01 10:00:00']);
        DB::table('letter_status_logs')->insert([
            'letter_id' => $awaiting->id, 'secretariat_id' => $this->cmSec->id, 'status' => 'Received',
            'created_at' => '2026-04-01 09:00:00', 'updated_at' => '2026-04-01 09:00:00',
        ]);

        $this->migration()->up();
        $this->migration()->up(); // safe to re-run

        $this->assertSame($awaitingHop->id, $hopLog->fresh()->routing_history_id);
        $this->assertSame(1, LetterStatusLog::query()->whereNotNull('routing_history_id')->count(), 'the older log and the confirmed hop are not linked');
        $this->assertNull($this->logOf($confirmed, $this->cmSec)->routing_history_id, 'a confirmed hop is left alone');
    }

    public function test_the_backfill_does_not_steal_a_log_another_hop_already_owns(): void
    {
        $letter = $this->createLetter($this->hrSec);
        $this->lettersWorkflow()->dispatch($letter, $this->hrSec, $this->cmSec);
        $hop = RoutingHistory::query()->sole();

        $this->migration()->up();

        $this->assertSame($hop->id, $this->logOf($letter, $this->cmSec)->routing_history_id, 'an existing link is kept');
    }

    public function test_deleting_a_hop_keeps_the_log_and_clears_its_link(): void
    {
        $letter = $this->createLetter($this->hrSec);
        $this->lettersWorkflow()->dispatch($letter, $this->hrSec, $this->cmSec);

        RoutingHistory::query()->delete();

        $this->assertNull($this->logOf($letter, $this->cmSec)->routing_history_id, 'nullOnDelete');
    }
}
