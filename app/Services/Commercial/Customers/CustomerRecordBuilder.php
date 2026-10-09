<?php

namespace App\Services\Commercial\Customers;

use Illuminate\Support\Carbon;

/**
 * Turns one parsed sheet row into the staging row: lookup ids, pesewas, normalised dates and phones, the arrears bucket, the
 * data-quality bits and the two hashes (hot attributes / contact details) used to skip rows that did not change.
 */
class CustomerRecordBuilder
{
    /** Data-quality bits stored in staging.quality_flags. */
    public const MISSING_MOBILE = 1;

    public const MISSING_EMAIL = 2;

    public const MISSING_ADDRESS = 4;

    public const INVALID_PHONE = 8;

    public const FUTURE_DATE = 16;

    public const IMPLAUSIBLE_DATE = 32;

    public const MISSING_NAME = 64;

    /** The quality bits that describe the contact details (they are stored with the contact row). */
    public const CONTACT_BITS = self::MISSING_MOBILE | self::MISSING_EMAIL | self::MISSING_ADDRESS | self::INVALID_PHONE | self::MISSING_NAME;

    /** The columns the "attributes_hash" covers, in order. */
    public const HASHED = [
        'region_id', 'district_id', 'route_id', 'category_id', 'status_id', 'meter_status_id', 'meter_no', 'connect_date', 'balance',
        'last_read_date', 'last_reading', 'last_bill_date', 'last_bill_amount', 'last_paid_date', 'last_paid_amount',
        'estimated_consume', 'average_consume', 'meter_factor', 'arrears_bucket',
    ];

    /** Bills-outstanding limits that separate bucket 2..5 (a balance of up to 1 bill, 3, 6, 12 bills). */
    protected array $limits;

    /** The latest date (Y-m-d) taken as plausible: the as-of date plus a day of slack. */
    protected string $latestPlausible;

    /** @var array<string, int> route name => id, so a route is resolved once per file, not once per row */
    protected array $routes = [];

    public function __construct(
        protected CustomerLookups $lookups,
        protected int $regionId,
        protected int $districtId,
        Carbon $asOf,
    ) {
        $limits = array_values(array_map('floatval', (array) config('gwl.commercial_customer_arrears_bills', [1, 3, 6, 12])));
        sort($limits);

        $this->limits = count($limits) === 4 ? $limits : [1.0, 3.0, 6.0, 12.0];
        $this->latestPlausible = $asOf->copy()->addDay()->format('Y-m-d');
    }

    /**
     * Arrears bucket from the balance and the last bill (the file has no age of debt, so the bucket says how many bills'
     * worth is owed): 0 credit, 1 nil, 2 up to the first limit ... 6 above the last limit, 7 owes with no usable last bill.
     */
    public function bucket(int $balance, ?int $lastBill): int
    {
        if ($balance < 0) {
            return 0;
        }

        if ($balance === 0) {
            return 1;
        }

        if ($lastBill === null || $lastBill <= 0) {
            return 7;
        }

        $bills = $balance / $lastBill;

        foreach ($this->limits as $index => $limit) {
            if ($bills <= $limit) {
                return 2 + $index;
            }
        }

        return 6;
    }

    /**
     * @param  array<string, mixed>  $values  field => raw cell (CustomerListParser)
     * @return array<string, mixed> a commercial_customer_staging row without batch_id / row_no
     */
    public function build(array $values, string $routeName): array
    {
        $flags = 0;
        $dates = [];

        foreach (['connect_date', 'last_read_date', 'last_bill_date', 'last_paid_date'] as $field) {
            [$date, $problem] = CustomerValues::date($values[$field] ?? null);

            if ($problem !== null) {
                $flags |= self::IMPLAUSIBLE_DATE;
            } elseif ($date !== null && $date > $this->latestPlausible) {
                $flags |= self::FUTURE_DATE;
            }

            $dates[$field] = $date;
        }

        $balance = CustomerValues::pesewas($values['balance'] ?? null) ?? 0;
        $lastBill = CustomerValues::pesewas($values['last_bill_amount'] ?? null);

        [$phones, $invalidPhone] = CustomerValues::phones($values['mobile'] ?? null);
        $email = CustomerValues::email($values['email'] ?? null);
        $name = CustomerValues::text($values['name'] ?? null, 191);
        $address = CustomerValues::text($values['address'] ?? null, 255);

        if ($phones === []) {
            $flags |= self::MISSING_MOBILE;
        }

        if ($invalidPhone) {
            $flags |= self::INVALID_PHONE;
        }

        if ($email === null) {
            $flags |= self::MISSING_EMAIL;
        }

        if ($address === null) {
            $flags |= self::MISSING_ADDRESS;
        }

        if ($name === null) {
            $flags |= self::MISSING_NAME;
        }

        $row = [
            'account_no' => $values['account'],
            'region_id' => $this->regionId,
            'district_id' => $this->districtId,
            'route_id' => $this->routes[$routeName] ??= $this->lookups->route($this->districtId, $routeName),
            'category_id' => $this->lookups->category(CustomerValues::categoryCode($values['category'] ?? null)),
            'status_id' => $this->lookups->status(CustomerValues::code($values['status'] ?? null)),
            'meter_status_id' => $this->lookups->meterStatus(CustomerValues::code($values['meter_status'] ?? null)),
            'meter_no' => CustomerValues::meterNo($values['meter_no'] ?? null),
            'connect_date' => $dates['connect_date'],
            'balance' => $balance,
            'last_read_date' => $dates['last_read_date'],
            'last_reading' => CustomerValues::decimal($values['last_reading'] ?? null),
            'last_bill_date' => $dates['last_bill_date'],
            'last_bill_amount' => $lastBill,
            'last_paid_date' => $dates['last_paid_date'],
            'last_paid_amount' => CustomerValues::pesewas($values['last_paid_amount'] ?? null),
            'estimated_consume' => CustomerValues::decimal($values['estimated_consume'] ?? null),
            'average_consume' => CustomerValues::decimal($values['average_consume'] ?? null),
            'meter_factor' => CustomerValues::decimal($values['meter_factor'] ?? null),
        ];

        $row['arrears_bucket'] = $this->bucket($balance, $lastBill);
        $row['attributes_hash'] = self::hash(array_map(fn (string $column) => $row[$column], self::HASHED));

        // The region and district are constant for a batch: they are in the hash (so a move is a change) but not staged.
        unset($row['region_id'], $row['district_id']);

        $mobiles = $phones === [] ? null : mb_substr(implode(',', $phones), 0, 255);

        $row['account_name'] = $name;
        $row['address'] = $address;
        $row['mobiles'] = $mobiles;
        $row['phone_primary'] = $phones[0] ?? null;
        $row['email'] = $email;
        $row['email_lower'] = $email === null ? null : mb_strtolower($email);
        $row['name_search'] = $name === null ? null : mb_substr(mb_strtolower($name), 0, 191);
        $row['contact_hash'] = self::hash([$name, $address, $mobiles, $email, $flags & self::CONTACT_BITS]);
        $row['quality_flags'] = $flags;

        return $row;
    }

    /** @param  list<mixed>  $parts */
    public static function hash(array $parts): string
    {
        return substr(hash('xxh3', implode("\x1f", array_map(fn ($part) => $part === null ? "\x00" : (string) $part, $parts))), 0, 16);
    }
}
