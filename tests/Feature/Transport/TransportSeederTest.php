<?php

namespace Tests\Feature\Transport;

use App\Models\MaintenanceRecord;
use App\Models\MileageLog;
use App\Models\Vehicle;
use App\Models\VehicleAssignmentHistory;
use App\Models\VehicleExpense;
use App\Models\VehicleIssue;
use Database\Seeders\TransportSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\NullDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransportSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_seed_fills_event_derived_transport_columns(): void
    {
        // DatabaseSeeder runs every seeder with model events muted.
        $this->seed();

        $this->assertSame(6, Vehicle::query()->count());

        foreach ([Vehicle::class, MileageLog::class, VehicleIssue::class, MaintenanceRecord::class, VehicleExpense::class, VehicleAssignmentHistory::class] as $model) {
            $this->assertTrue($model::query()->exists(), "{$model} was not seeded.");
            $this->assertFalse($model::query()->whereNull('uuid')->exists(), "{$model} rows are missing a uuid.");
        }

        MileageLog::query()->get()->each(
            fn (MileageLog $log) => $this->assertSame($log->mileage_after - $log->mileage_before, $log->distance_driven)
        );
    }

    public function test_transport_seeder_restores_muted_events_for_later_seeders(): void
    {
        Model::withoutEvents(function (): void {
            $this->seed(TransportSeeder::class);

            $this->assertInstanceOf(NullDispatcher::class, Model::getEventDispatcher());
        });
    }
}
