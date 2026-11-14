<?php

namespace App\Services\Commercial\Customers;

use Illuminate\Support\Facades\DB;

/**
 * Category / status / meter-status / route lookups for ONE import, loaded into memory once so resolving a row costs an array
 * read, never a query. A code the tables do not know does not fail the import: it becomes a *pending* row (is_pending) that
 * is surfaced for review, and is remembered in $created so the import can warn.
 */
class CustomerLookups
{
    /** @var array<string, int> */
    protected array $categories = [];

    protected int $unknownCategoryId = 0;

    /** @var array<string, int> */
    protected array $statuses = [];

    /** @var array<string, int> */
    protected array $meterStatuses = [];

    /** @var array<string, int> district_id|name => id */
    protected array $routes = [];

    /** @var array<int, int|null> district id => its region id (for the route master) */
    protected array $districtRegions = [];

    /** @var array<int, bool> status id => is a billing status */
    protected array $billing = [];

    /** @var array{categories: list<string>, statuses: list<string>, meter_statuses: list<string>} codes created as pending during this import */
    public array $created = ['categories' => [], 'statuses' => [], 'meter_statuses' => []];

    public function __construct()
    {
        foreach (DB::table('commercial_customer_categories')->get(['id', 'code', 'is_unknown']) as $row) {
            if ($row->is_unknown) {
                $this->unknownCategoryId = (int) $row->id;
            } elseif ($row->code !== null) {
                $this->categories[(string) $row->code] = (int) $row->id;
            }
        }

        foreach (DB::table('commercial_customer_statuses')->get(['id', 'code', 'is_billing']) as $row) {
            $this->statuses[(string) $row->code] = (int) $row->id;
            $this->billing[(int) $row->id] = (bool) $row->is_billing;
        }

        foreach (DB::table('commercial_meter_statuses')->get(['id', 'code']) as $row) {
            $this->meterStatuses[(string) $row->code] = (int) $row->id;
        }

        if ($this->unknownCategoryId === 0) {
            $this->unknownCategoryId = (int) DB::table('commercial_customer_categories')->insertGetId([
                'code' => null, 'name' => 'UNKNOWN', 'category_group' => 'unknown', 'group_is_proposed' => true, 'is_unknown' => true,
                'is_pending' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function category(?string $code): int
    {
        if ($code === null || $code === '') {
            return $this->unknownCategoryId;
        }

        if (isset($this->categories[$code])) {
            return $this->categories[$code];
        }

        $id = (int) DB::table('commercial_customer_categories')->insertGetId([
            'code' => $code, 'name' => 'Category '.$code, 'category_group' => 'unknown', 'group_is_proposed' => true,
            'is_unknown' => false, 'is_pending' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->created['categories'][] = $code;

        return $this->categories[$code] = $id;
    }

    public function status(?string $code): int
    {
        // A blank status maps to UNKNOWN (Phase 8a): one pending row to classify, never a guess
        $code = $code === null || $code === '' ? 'UNKNOWN' : $code;

        if (isset($this->statuses[$code])) {
            return $this->statuses[$code];
        }

        $id = (int) DB::table('commercial_customer_statuses')->insertGetId([
            'code' => $code, 'label' => $code === 'UNKNOWN' ? 'Unknown (no status given)' : $code, 'is_active' => false, 'is_billing' => false,
            'meaning_confirmed' => false, 'is_pending' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->created['statuses'][] = $code;
        $this->billing[$id] = false;

        return $this->statuses[$code] = $id;
    }

    public function meterStatus(?string $code): int
    {
        $code = $code === null || $code === '' ? '?' : $code;

        if (isset($this->meterStatuses[$code])) {
            return $this->meterStatuses[$code];
        }

        $id = (int) DB::table('commercial_meter_statuses')->insertGetId([
            'code' => $code, 'label' => $code === '?' ? 'Not given' : $code, 'is_pending' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->created['meter_statuses'][] = $code;

        return $this->meterStatuses[$code] = $id;
    }

    public function route(int $districtId, string $name): int
    {
        $name = mb_substr(trim($name) === '' ? '(no route)' : trim($name), 0, 80);
        $key = $districtId.'|'.$name;

        if (isset($this->routes[$key])) {
            return $this->routes[$key];
        }

        // The route master (Phase 8a): a route the customer list names is filed with its region, its code (digits as printed) and its source.
        $this->districtRegions[$districtId] ??= DB::table('districts')->where('id', $districtId)->value('region_id');
        $code = preg_match('/^(\d{1,12})$/', $name, $m) || preg_match('/\s(\d{1,12})$/', $name, $m) ? $m[1] : null;

        DB::table('commercial_routes')->insertOrIgnore([
            'district_id' => $districtId, 'region_id' => $this->districtRegions[$districtId], 'name' => $name, 'code' => $code, 'source' => 'customer_list',
            'active' => true, 'is_pending' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $this->routes[$key] = (int) DB::table('commercial_routes')->where('district_id', $districtId)->where('name', $name)->value('id');
    }

    /** @return list<int> ids of the statuses that bill */
    public function billingStatusIds(): array
    {
        return array_keys(array_filter($this->billing));
    }
}
