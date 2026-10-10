<?php

namespace Tests\Feature\Commercial\Sms;

use App\Models\District;
use App\Models\Region;
use App\Models\User;
use Tests\Feature\Commercial\Customers\CustomerTestCase;
use Tests\Support\Commercial\FakesSms;

/**
 * Shared builders for the SMS tests. Synthetic data only; the Arkesel gateway is always the fake (no test may reach Arkesel).
 * Accra West is the locked region; Ashanti is "another region"; Head Office is a DISTRICT that shares Accra West's region_id.
 */
abstract class SmsTestCase extends CustomerTestCase
{
    use FakesSms;

    protected District $odorkorWest;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeSms();
    }

    /** The Commercial Officer of Accra West: the person the feature is for. */
    protected function westOfficer(string $staffId = '910001'): User
    {
        return $this->officer($staffId, $this->accraWest, $this->sowutuom);
    }

    /** A Commercial Officer of another region: holds the same permissions, must still be refused. */
    protected function eastOfficer(string $staffId = '910002'): User
    {
        return $this->officer($staffId, $this->ashanti, $this->kumasi);
    }

    /** A Commercial Officer at Head Office (a district that shares Accra West's region id). */
    protected function headOfficeOfficer(string $staffId = '910003'): User
    {
        return $this->officer($staffId, $this->accraWest, $this->headOffice);
    }

    /** Accra West staff with only the given SMS permissions. */
    protected function westWith(string $staffId, array $permissions): User
    {
        return $this->userWith($staffId, $permissions, $this->accraWest, $this->sowutuom);
    }

    protected function regionOf(string $name): Region
    {
        return Region::query()->where('region_name', $name)->firstOrFail();
    }
}
