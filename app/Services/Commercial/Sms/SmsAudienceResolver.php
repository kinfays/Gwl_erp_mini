<?php

namespace App\Services\Commercial\Sms;

use App\Models\CommercialSmsCampaign;
use App\Services\Commercial\Customers\CustomerSnapshots;
use App\Services\Commercial\Customers\CustomerValues;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * THE one place audiences ("all customers", "selected customers", and the named groups) are defined. Never in Blade or Livewire.
 *
 * Every audience, whatever the screen asked for, starts with:
 *   - the region lock: customers whose region is one SmsAccess allows for the campaign (the audience queries add this themselves;
 *     nothing relies on the UI), reached through the district-first indexes of commercial_customers;
 *   - not missing from the latest file (missing_since_batch_id IS NULL), unless the audience is told to include them;
 *   - a reachable valid number (commercial_customer_contacts.phone_primary; phone_secondary too when asked), and then
 *   - one SMS per NUMBER per campaign: accounts that share a number are collapsed to the first account, and the preview says how many.
 *
 * Counts are exact COUNTs. Candidate customers come from a SINGLE-table query on commercial_customers (keyset by id, one district at
 * a time, no join to the lookups); contacts are read by id list, or joined only for the counts. Recipients are written as customer
 * ids, set-based, and the whole thing is idempotent (a re-run adds nothing already there), so a crashed or repeated start cannot
 * double up. A phone number is read here only to de-duplicate inside SQL and to mask three sample lines; it is never stored.
 *
 * Sign of balance: positive = owed (the Phase 6 assumption, to be confirmed). Money is integer pesewas.
 */
class SmsAudienceResolver
{
    /** Candidate customers read per step and written per INSERT. */
    public const CHUNK = 2000;

    public const NO_PAYMENT_DAYS = [30, 60, 90, 180];

    /** Group key => label. Unconfirmed status codes (TRFR, VACN, DISO, NFLO) are deliberately not offered as groups. */
    public const GROUPS = [
        'actb' => 'Billing accounts (ACTB)',
        'actn' => 'Active, not billing (ACTN)',
        'disc' => 'Disconnected (DISC)',
        'susp' => 'Suspended (SUSP)',
        'debtors' => 'Debtors (balance owed above a minimum)',
        'credit' => 'Customers in credit',
        'no_payment' => 'No payment in 30 / 60 / 90 / 180 days',
        'faulty_meter' => 'Faulty meter or no meter',
        'valuable' => 'Most valuable customers (highest last bill)',
    ];

    private const STATUS_GROUPS = ['actb' => 'ACTB', 'actn' => 'ACTN', 'disc' => 'DISC', 'susp' => 'SUSP'];

    public function __construct(protected CustomerSnapshots $snapshots)
    {
    }

    // ---------------------------------------------------------------- input

    /**
     * Cleans what a screen sends into a spec the rest of this class understands, or says what is wrong.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function normalise(array $input): array
    {
        $type = (string) ($input['type'] ?? 'all');

        if (! in_array($type, ['all', 'selected', 'group'], true)) {
            throw new SmsAudienceException('Choose who to message: all customers, selected customers or a group.');
        }

        $spec = [
            'type' => $type,
            'group' => null,
            'district_ids' => array_values(array_unique(array_filter(array_map('intval', (array) ($input['district_ids'] ?? [])), fn (int $id) => $id > 0))),
            'route_id' => ! empty($input['route_id']) ? (int) $input['route_id'] : null,
            'category_group' => ! empty($input['category_group']) ? (string) $input['category_group'] : null,
            'status_id' => ! empty($input['status_id']) ? (int) $input['status_id'] : null,
            'include_missing' => (bool) ($input['include_missing'] ?? false),
            'include_second' => (bool) ($input['include_second'] ?? false),
        ];

        if ($spec['category_group'] !== null && ! DB::table('commercial_customer_categories')->where('category_group', $spec['category_group'])->exists()) {
            throw new SmsAudienceException('That category group does not exist.');
        }

        if ($spec['status_id'] !== null && ! DB::table('commercial_customer_statuses')->where('id', $spec['status_id'])->where('meaning_confirmed', true)->exists()) {
            throw new SmsAudienceException('That account status cannot be used: its meaning has not been confirmed.');
        }

        if ($type === 'selected') {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($input['customer_ids'] ?? [])), fn (int $id) => $id > 0)));
            $max = (int) config('gwl.commercial_sms_max_selected', 2000);

            if ($ids === []) {
                throw new SmsAudienceException('No customers are selected.');
            }

            if (count($ids) > $max) {
                throw new SmsAudienceException('At most '.number_format($max).' customers can be selected for one campaign.');
            }

            $spec['customer_ids'] = $ids;
        }

        if ($type === 'group') {
            $group = (string) ($input['group'] ?? '');

            if (! isset(self::GROUPS[$group])) {
                throw new SmsAudienceException('Choose one of the groups.');
            }

            $spec['group'] = $group;

            if ($group === 'debtors') {
                $spec['min_debt'] = max(0.0, round((float) ($input['min_debt'] ?? 0), 2));
                $spec['bucket'] = isset($input['bucket']) && $input['bucket'] !== '' && $input['bucket'] !== null ? max(0, min(7, (int) $input['bucket'])) : null;
            }

            if ($group === 'no_payment') {
                $days = (int) ($input['days'] ?? 90);
                $spec['days'] = in_array($days, self::NO_PAYMENT_DAYS, true) ? $days : throw new SmsAudienceException('Choose 30, 60, 90 or 180 days.');
            }

            if ($group === 'valuable') {
                $n = (int) ($input['top_n'] ?? 0);
                $pct = (float) ($input['top_pct'] ?? 0);

                if ($n <= 0 && $pct <= 0) {
                    $n = (int) config('gwl.commercial_sms_most_valuable_top_n', 500);
                }

                $spec['top_n'] = $n > 0 ? $n : null;
                $spec['top_pct'] = $n > 0 ? null : min(100.0, round($pct, 2));
            }
        }

        return $spec;
    }

    // ---------------------------------------------------------------- the one customer query

    /** @return list<int> the districts of the allowed regions (the district-first indexes need them), narrowed by the filter */
    protected function districtIds(array $regionIds, array $spec): array
    {
        $ids = DB::table('districts')->whereIn('region_id', $regionIds)->pluck('id')->map(fn ($id) => (int) $id)->all();

        return $spec['district_ids'] === [] ? $ids : array_values(array_intersect($ids, $spec['district_ids']));
    }

    /**
     * Resolves what a spec needs from the small lookup tables, ONCE, so the customers query itself is single-table: status and
     * category ids, the per-district "as of" dates, and the "most valuable" cut-off.
     *
     * @param  list<int>  $regionIds
     * @return array<string, mixed>
     */
    protected function prepare(array $spec, array $regionIds): array
    {
        $prepared = ['regions' => $regionIds, 'districts' => $this->districtIds($regionIds, $spec)];

        $prepared['category_ids'] = $spec['category_group'] !== null
            ? DB::table('commercial_customer_categories')->where('category_group', $spec['category_group'])->pluck('id')->map(fn ($id) => (int) $id)->all()
            : null;

        $group = $spec['group'] ?? null;

        if (isset(self::STATUS_GROUPS[$group])) {
            $prepared['group_status_ids'] = DB::table('commercial_customer_statuses')->where('code', self::STATUS_GROUPS[$group])->where('meaning_confirmed', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        if ($group === 'faulty_meter') {
            $prepared['group_meter_ids'] = DB::table('commercial_meter_statuses')->whereIn('code', ['F', 'N'])->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        if ($group === 'no_payment') {
            // "N days without a payment" is counted from each district's own file date, like the dormancy figures.
            $batches = $this->snapshots->current(null, null, null, null)->filter(fn ($batch) => in_array((int) $batch->region_id, $regionIds, true));
            $prepared['cutoffs'] = $batches->mapWithKeys(fn ($batch) => [(int) $batch->district_id => \Illuminate\Support\Carbon::parse($batch->as_of_date)->subDays((int) $spec['days'])->toDateString()])->all();
        }

        if ($group === 'valuable') {
            $prepared['valuable_cutoff'] = $this->valuableCutoff($spec, $prepared);
        }

        return $prepared;
    }

    /** The last-bill amount (pesewas) a customer must reach to be "most valuable": the Nth highest among the billing accounts in view. Ties at the cut-off are included. */
    protected function valuableCutoff(array $spec, array $prepared): ?int
    {
        $billing = DB::table('commercial_customer_statuses')->where('is_billing', true)->where('meaning_confirmed', true)->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($billing === [] || $prepared['districts'] === []) {
            return null;
        }

        $base = $this->customers(array_merge($spec, ['group' => null]), $prepared)->whereIn('c.status_id', $billing)->where('c.last_bill_amount', '>', 0);
        $total = (int) (clone $base)->count();

        if ($total === 0) {
            return null;
        }

        $n = ! empty($spec['top_n']) ? (int) $spec['top_n'] : (int) ceil($total * ((float) $spec['top_pct']) / 100);
        $n = max(1, min($n, $total));

        $value = (clone $base)->orderByDesc('c.last_bill_amount')->offset($n - 1)->limit(1)->value('c.last_bill_amount');

        return $value === null ? null : (int) $value;
    }

    /**
     * The candidate customers: ONE table, the region lock and every rule of the spec as indexed conditions. Optionally one
     * district (the keyset walk goes district by district so the (district, missing_since) index delivers id order).
     */
    protected function customers(array $spec, array $prepared, ?int $districtId = null): Builder
    {
        $query = DB::table('commercial_customers as c');

        // The region lock, written here as well as by SmsAccess: the district ids are those of the allowed regions only.
        $districts = $districtId !== null ? array_values(array_intersect($prepared['districts'], [$districtId])) : $prepared['districts'];

        if ($districts === [] || $prepared['regions'] === []) {
            return $query->whereRaw('1 = 0');
        }

        $query->whereIn('c.region_id', $prepared['regions'])->whereIn('c.district_id', $districts);

        empty($spec['include_missing']) ? $query->whereNull('c.missing_since_batch_id') : null;

        if (($spec['type'] ?? 'all') === 'selected') {
            $query->whereIn('c.id', $spec['customer_ids'] ?? [0]);
        }

        if (! empty($spec['route_id'])) {
            $query->where('c.route_id', (int) $spec['route_id']);
        }

        if ($prepared['category_ids'] !== null) {
            $prepared['category_ids'] === [] ? $query->whereRaw('1 = 0') : $query->whereIn('c.category_id', $prepared['category_ids']);
        }

        if (! empty($spec['status_id'])) {
            $query->where('c.status_id', (int) $spec['status_id']);
        }

        $group = $spec['group'] ?? null;

        if (isset(self::STATUS_GROUPS[$group])) {
            ($prepared['group_status_ids'] ?? []) === [] ? $query->whereRaw('1 = 0') : $query->whereIn('c.status_id', $prepared['group_status_ids']);
        }

        match ($group) {
            'debtors' => $this->debtors($query, $spec),
            'credit' => $query->where('c.balance', '<', 0),
            'faulty_meter' => ($prepared['group_meter_ids'] ?? []) === [] ? $query->whereRaw('1 = 0') : $query->whereIn('c.meter_status_id', $prepared['group_meter_ids']),
            'no_payment' => $this->noPayment($query, $prepared),
            'valuable' => $prepared['valuable_cutoff'] === null
                ? $query->whereRaw('1 = 0')
                : $query->where('c.last_bill_amount', '>=', $prepared['valuable_cutoff'])->where('c.last_bill_amount', '>', 0)->whereIn('c.status_id', $this->billingIds()),
            default => null,
        };

        return $query;
    }

    protected function debtors(Builder $query, array $spec): void
    {
        $query->where('c.balance', '>', (int) round(((float) ($spec['min_debt'] ?? 0)) * 100));

        if (isset($spec['bucket']) && $spec['bucket'] !== null) {
            $query->where('c.arrears_bucket', (int) $spec['bucket']);
        }
    }

    protected function noPayment(Builder $query, array $prepared): void
    {
        $cutoffs = $prepared['cutoffs'] ?? [];

        if ($cutoffs === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $any) use ($cutoffs) {
            foreach ($cutoffs as $district => $cutoff) {
                $any->orWhere(fn (Builder $one) => $one->where('c.district_id', (int) $district)->where(fn (Builder $paid) => $paid->whereNull('c.last_paid_date')->orWhere('c.last_paid_date', '<', $cutoff)));
            }
        });
    }

    /** @return list<int> */
    protected function billingIds(): array
    {
        return DB::table('commercial_customer_statuses')->where('is_billing', true)->where('meaning_confirmed', true)->pluck('id')->map(fn ($id) => (int) $id)->all() ?: [0];
    }

    // ---------------------------------------------------------------- counts

    /**
     * Exact counts for the preview, from one query on the customers (indexed conditions) joined to their contacts.
     *
     * @param  array<string, mixed>  $spec  as returned by normalise()
     * @param  list<int>  $regionIds
     * @return array{matched: int, reachable: int, numbers: int, sharing: int, without_number: int}
     *         matched = accounts the rules pick; reachable = of them, with a valid number; numbers = unique numbers (= recipients);
     *         sharing = reachable accounts that share their first number with another; without_number = matched - reachable
     */
    public function counts(array $spec, array $regionIds): array
    {
        $prepared = $this->prepare($spec, $regionIds);
        $matched = (int) $this->customers($spec, $prepared)->count();

        $joined = fn () => $this->customers($spec, $prepared)->join('commercial_customer_contacts as ct', 'ct.customer_id', '=', 'c.id')->whereNotNull('ct.phone_primary');
        $reachable = (int) $joined()->count();
        $firstNumbers = (int) $joined()->distinct()->count('ct.phone_primary');

        $numbers = $firstNumbers;

        if (! empty($spec['include_second'])) {
            $first = $joined()->select('ct.phone_primary as v');
            $second = $this->customers($spec, $prepared)->join('commercial_customer_contacts as ct', 'ct.customer_id', '=', 'c.id')->whereNotNull('ct.phone_secondary')->select('ct.phone_secondary as v');
            $numbers = (int) DB::query()->fromSub($first->union($second), 'u')->count();
        }

        return ['matched' => $matched, 'reachable' => $reachable, 'numbers' => $numbers, 'sharing' => max(0, $reachable - $firstNumbers), 'without_number' => max(0, $matched - $reachable)];
    }

    /** Counts for a campaign (its own region, only while that region is locked-in). */
    public function countsFor(CommercialSmsCampaign $campaign): array
    {
        return $this->counts($this->specOf($campaign), SmsAccess::audienceRegionIds($campaign));
    }

    /** The spec a campaign was made from. @return array<string, mixed> */
    public function specOf(CommercialSmsCampaign $campaign): array
    {
        return ($campaign->audience ?? []) + ['type' => $campaign->audience_type, 'include_missing' => (bool) $campaign->include_missing, 'include_second' => (bool) $campaign->include_second_number];
    }

    // ---------------------------------------------------------------- recipients

    /**
     * Writes the campaign's recipients (customer ids + slot, status pending) and sets its totals. Idempotent: a customer whose
     * number is already among the campaign's recipients is skipped, so a repeat or a resume adds nothing twice. Walks one district at
     * a time, in id order, by keyset.
     *
     * @return array{accounts: int, recipients: int}
     */
    public function materialise(CommercialSmsCampaign $campaign): array
    {
        $regionIds = SmsAccess::audienceRegionIds($campaign);

        if ($regionIds === []) {
            throw new SmsAudienceException('This campaign cannot run: its region is not one SMS is switched on for.');
        }

        $spec = $this->specOf($campaign);
        $prepared = $this->prepare($spec, $regionIds);

        foreach ($prepared['districts'] as $districtId) {
            $cursor = 0;

            do {
                $ids = $this->customers($spec, $prepared, (int) $districtId)->where('c.id', '>', $cursor)->orderBy('c.id')->limit(self::CHUNK)->pluck('c.id')->map(fn ($id) => (int) $id)->all();

                if ($ids === []) {
                    break;
                }

                $this->insertChunk($campaign, $ids, ! empty($spec['include_second']));
                $cursor = max($ids);
            } while (count($ids) === self::CHUNK);
        }

        $recipients = (int) DB::table('commercial_sms_recipients')->where('campaign_id', $campaign->id)->count();
        $accounts = (int) $this->customers($spec, $prepared)->join('commercial_customer_contacts as ct', 'ct.customer_id', '=', 'c.id')->whereNotNull('ct.phone_primary')->count();

        return ['accounts' => $accounts, 'recipients' => $recipients];
    }

    /**
     * One INSERT ... SELECT for a chunk of candidate ids: the chunk's numbers collapsed to one customer per number, minus the numbers
     * the campaign already has. Numbers are compared inside the database and never leave it.
     *
     * @param  list<int>  $ids
     */
    protected function insertChunk(CommercialSmsCampaign $campaign, array $ids, bool $second): void
    {
        $camp = (int) $campaign->id;
        $list = implode(',', $ids);

        // "The campaign already has this number" is asked as one NOT EXISTS per slot, each driven by the index on the number
        // (phone_primary / phone_secondary) and finished by the unique key (campaign_id, customer_id, slot): an OR across the two
        // columns would defeat both indexes.
        $hasFirst = "SELECT 1 FROM commercial_customer_contacts o INNER JOIN commercial_sms_recipients r ON r.campaign_id = {$camp} AND r.customer_id = o.customer_id AND r.slot = 1 WHERE o.phone_primary = ";
        $hasSecond = "SELECT 1 FROM commercial_customer_contacts o INNER JOIN commercial_sms_recipients r ON r.campaign_id = {$camp} AND r.customer_id = o.customer_id AND r.slot = 2 WHERE o.phone_secondary = ";

        if (! $second) {
            // Only first numbers: a recipient of this campaign can only be in slot 1.
            DB::insert(
                "INSERT INTO commercial_sms_recipients (campaign_id, customer_id, slot, status) "
                ."SELECT {$camp}, MIN(ct.customer_id), 1, 'pending' FROM commercial_customer_contacts ct "
                ."WHERE ct.customer_id IN ({$list}) AND ct.phone_primary IS NOT NULL "
                ."AND NOT EXISTS ({$hasFirst}ct.phone_primary) "
                .'GROUP BY ct.phone_primary'
            );

            return;
        }

        // Both numbers: every number of the chunk with the customer and slot it came from; one row per number (the lowest
        // customer id, first number before second), minus the numbers the campaign already has in either slot.
        DB::insert(
            "INSERT INTO commercial_sms_recipients (campaign_id, customer_id, slot, status) "
            ."SELECT {$camp}, (MIN(u.k) - (MIN(u.k) % 2)) / 2, (MIN(u.k) % 2) + 1, 'pending' FROM ("
            ."SELECT ct.customer_id * 2 AS k, ct.phone_primary AS v FROM commercial_customer_contacts ct WHERE ct.customer_id IN ({$list}) AND ct.phone_primary IS NOT NULL "
            ."UNION ALL SELECT ct.customer_id * 2 + 1 AS k, ct.phone_secondary AS v FROM commercial_customer_contacts ct WHERE ct.customer_id IN ({$list}) AND ct.phone_secondary IS NOT NULL"
            .') u '
            ."WHERE NOT EXISTS ({$hasFirst}u.v) AND NOT EXISTS ({$hasSecond}u.v) "
            .'GROUP BY u.v'
        );
    }

    // ---------------------------------------------------------------- lists, samples, wording

    /**
     * A pasted list of account numbers: which customers they are (inside the region lock), and counts of the rest. Numbers that
     * are not found or are in another region are only COUNTED: which is which is never revealed.
     *
     * @param  list<int>  $regionIds
     * @return array{ids: list<int>, invalid: int, not_found: int, outside: int, duplicates: int}
     */
    public function parseAccountList(string $text, array $regionIds): array
    {
        $tokens = preg_split('/[\s,;]+/', trim($text)) ?: [];
        $tokens = array_values(array_filter($tokens, fn (string $token) => $token !== ''));
        $max = (int) config('gwl.commercial_sms_max_selected', 2000);

        if (count($tokens) > $max * 2) {
            throw new SmsAudienceException('That list is too long: at most '.number_format($max).' account numbers can be pasted.');
        }

        $accounts = [];
        $invalid = 0;
        $duplicates = 0;

        foreach ($tokens as $token) {
            $digits = preg_replace('/\D/', '', $token) ?? '';

            if ($digits === '' || strlen($digits) > 12) {
                $invalid++;

                continue;
            }

            $account = str_pad($digits, 12, '0', STR_PAD_LEFT);

            if (isset($accounts[$account])) {
                $duplicates++;

                continue;
            }

            $accounts[$account] = true;
        }

        if (count($accounts) > $max) {
            throw new SmsAudienceException('That list holds '.number_format(count($accounts)).' account numbers; at most '.number_format($max).' can be selected for one campaign.');
        }

        $ids = [];
        $found = 0;

        foreach (array_chunk(array_keys($accounts), 500) as $part) {
            foreach (DB::table('commercial_customers')->whereIn('account_no', $part)->get(['id', 'region_id']) as $row) {
                $found++;

                if (in_array((int) $row->region_id, $regionIds, true)) {
                    $ids[] = (int) $row->id;
                }
            }
        }

        return ['ids' => $ids, 'invalid' => $invalid, 'not_found' => count($accounts) - $found, 'outside' => $found - count($ids), 'duplicates' => $duplicates];
    }

    /**
     * Ticked customers (ids from a list): how many are inside the region lock. The rest are only counted, never named.
     *
     * @param  list<int>  $ids
     * @param  list<int>  $regionIds
     * @return array{inside: int, outside: int}
     */
    public function checkSelected(array $ids, array $regionIds): array
    {
        $inside = 0;

        foreach (array_chunk(array_values(array_unique(array_map('intval', $ids))), 1000) as $part) {
            $inside += (int) DB::table('commercial_customers')->whereIn('id', $part)->whereIn('region_id', $regionIds ?: [0])->count();
        }

        return ['inside' => $inside, 'outside' => max(0, count(array_unique($ids)) - $inside)];
    }

    /**
     * Three example recipients for the preview, masked exactly like the customer lists (names only for holders of the details permission).
     *
     * @param  list<int>  $regionIds
     * @return list<array{account_no: string, name: string|null, mobile: string}>
     */
    public function sample(array $spec, array $regionIds, bool $details, int $limit = 3): array
    {
        $prepared = $this->prepare($spec, $regionIds);
        $rows = $this->customers($spec, $prepared)->join('commercial_customer_contacts as ct', 'ct.customer_id', '=', 'c.id')
            ->whereNotNull('ct.phone_primary')->orderBy('c.id')->limit($limit)->get(['c.account_no', 'ct.phone_primary', 'ct.account_name']);

        return $rows->map(fn ($row) => [
            'account_no' => (string) $row->account_no,
            'name' => $details ? $row->account_name : null,
            'mobile' => (string) CustomerValues::maskPhone($row->phone_primary),
        ])->all();
    }

    /** @param  array<string, mixed>  $spec */
    public function describe(array $spec): string
    {
        $type = $spec['type'] ?? 'all';
        $text = match (true) {
            $type === 'selected' => number_format(count($spec['customer_ids'] ?? [])).' selected customers',
            $type === 'all' => 'All customers',
            default => $this->describeGroup($spec),
        };

        $parts = [];

        if (! empty($spec['district_ids'])) {
            $parts[] = count($spec['district_ids']).' district(s)';
        }

        if (! empty($spec['route_id'])) {
            $parts[] = 'one route';
        }

        if (! empty($spec['category_group'])) {
            $parts[] = 'category group '.$spec['category_group'];
        }

        if (! empty($spec['status_id'])) {
            $parts[] = 'one account status';
        }

        $text .= $parts === [] ? '' : ', only '.implode(', ', $parts);
        $text .= ! empty($spec['include_missing']) ? '; including accounts no longer in the latest file' : '';

        return $text.(! empty($spec['include_second']) ? '; both numbers of a customer' : '; first number of a customer');
    }

    protected function describeGroup(array $spec): string
    {
        $group = (string) ($spec['group'] ?? '');

        return match ($group) {
            'debtors' => 'Debtors: balance owed above GH¢ '.number_format((float) ($spec['min_debt'] ?? 0), 2).(isset($spec['bucket']) && $spec['bucket'] !== null ? ', arrears bucket '.(int) $spec['bucket'] : '').' (a positive balance is taken as owed: to be confirmed)',
            'no_payment' => 'No payment in '.(int) ($spec['days'] ?? 90).' days (counted from each district\'s file date)',
            'valuable' => 'Most valuable customers: the '.(! empty($spec['top_n']) ? 'top '.number_format((int) $spec['top_n']) : 'top '.rtrim(rtrim(number_format((float) $spec['top_pct'], 2), '0'), '.').'%').' of billing accounts by last bill amount (ties at the cut-off included; an assumption to be confirmed)',
            default => self::GROUPS[$group] ?? 'Group',
        };
    }
}
