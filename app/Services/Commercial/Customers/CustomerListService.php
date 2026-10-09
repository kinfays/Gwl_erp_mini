<?php

namespace App\Services\Commercial\Customers;

use App\Models\CommercialCustomerCategory;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Drill-down lists over the CURRENT customers: the only code that reads commercial_customers for screens. Rules:
 *
 *   * keyset pagination only (no OFFSET, no COUNT over millions): the next page starts after the last row's sort key, written
 *     as a row-value comparison ((balance, id) < (?, ?)) so the database walks the index instead of testing every row;
 *   * the list query touches ONE table. Names of districts, routes, categories and statuses are resolved afterwards from the
 *     handful of rows on the page; joining those tiny lookup tables made MySQL choose a hash join and give up index order;
 *   * ORDER BY always follows an index: (route, id) via (district_id, route_id), or (balance, id) via (district_id, balance);
 *   * every query carries the region restriction, so another region's customers cannot be reached by changing an id or a
 *     filter; the caller passes the restriction (CustomerScope / the Livewire scoping trait), never the request;
 *   * personal data (names, addresses, phones, e-mails) is joined only when the caller holds commercial.view_customer_details,
 *     and phones / e-mails are MASKED in every list; unmasking is the single-customer view, which is audited by its caller.
 *
 * @phpstan-type Page array{rows: list<array<string, mixed>>, next: array<int, int>|null, size: int}
 */
class CustomerListService
{
    public const SORTS = ['route' => 'By route', 'balance_desc' => 'Highest balance first', 'balance_asc' => 'Lowest balance first'];

    /** Search kinds that need the contact table (and so the details permission). */
    public const PII_SEARCHES = ['phone', 'email', 'name'];

    /** @var array<string, array<int, mixed>>|null lookup tables, loaded once per instance */
    protected ?array $maps = null;

    /**
     * @param  array<string, mixed>  $q  restriction, district_id, region_id, route_id, category_id, group, status_id, meter_status_id,
     *                                   bucket, sort, after, size, max_size, details, search ['type', 'value'], issue, missing, as_of, batch_id
     * @return Page
     */
    public function page(array $q): array
    {
        $size = max(10, min((int) ($q['max_size'] ?? 200), (int) ($q['size'] ?? config('gwl.commercial_customer_page_size', 50))));
        $details = (bool) ($q['details'] ?? false);
        $sort = array_key_exists($q['sort'] ?? '', self::SORTS) ? $q['sort'] : 'route';
        $search = $q['search'] ?? null;

        if (($search['type'] ?? null) && in_array($search['type'], self::PII_SEARCHES, true) && ! $details) {
            throw new CustomerImportException('Searching by name, phone or e-mail needs the customer details permission.');
        }

        $needsContacts = $details || ($search['type'] ?? null) !== null;
        $query = $this->query($q, $needsContacts);
        $this->select($query, $details);

        if ($search && ($search['value'] ?? '') !== '') {
            $this->applySearch($query, $search['type'], (string) $search['value']);

            // A search is a lookup, not a listing: the first matches, no paging.
            return ['next' => null] + $this->shape($query->orderBy('c.id')->limit($size)->get(), $size, 'route', $details);
        }

        if (! empty($q['issue'])) {
            $this->applyIssue($query, (string) $q['issue'], (int) ($q['district_id'] ?? 0), (string) ($q['as_of'] ?? now()->toDateString()), (int) ($q['batch_id'] ?? 0));
        }

        $after = $q['after'] ?? null;

        match ($sort) {
            'balance_desc' => $query->orderByDesc('c.balance')->orderByDesc('c.id')
                ->when($after, fn (Builder $b) => $b->whereRaw('(c.balance, c.id) < (?, ?)', [(int) $after[0], (int) $after[1]])),
            'balance_asc' => $query->orderBy('c.balance')->orderBy('c.id')
                ->when($after, fn (Builder $b) => $b->whereRaw('(c.balance, c.id) > (?, ?)', [(int) $after[0], (int) $after[1]])),
            default => $query->orderBy('c.route_id')->orderBy('c.id')
                ->when($after, fn (Builder $b) => $b->whereRaw('(c.route_id, c.id) > (?, ?)', [(int) $after[0], (int) $after[1]])),
        };

        return $this->shape($query->limit($size + 1)->get(), $size, $sort, $details);
    }

    /** The base query: scope and filters on the customers table alone; contacts only when asked. */
    public function query(array $q, bool $withContacts = false): Builder
    {
        $restriction = $q['restriction'] ?? null;
        $query = DB::table('commercial_customers AS c');

        if ($restriction !== null) {
            $restriction === 0 ? $query->whereRaw('1 = 0') : $query->where('c.region_id', (int) $restriction);
        }

        if ($withContacts) {
            $query->leftJoin('commercial_customer_contacts AS ct', 'ct.customer_id', '=', 'c.id');
        }

        $query
            ->when($q['region_id'] ?? null, fn (Builder $b, $id) => $b->where('c.region_id', (int) $id))
            ->when($q['district_id'] ?? null, fn (Builder $b, $id) => $b->where('c.district_id', (int) $id))
            ->when($q['route_id'] ?? null, fn (Builder $b, $id) => $b->where('c.route_id', (int) $id))
            ->when($q['category_id'] ?? null, fn (Builder $b, $id) => $b->where('c.category_id', (int) $id))
            ->when($q['status_id'] ?? null, fn (Builder $b, $id) => $b->where('c.status_id', (int) $id))
            ->when($q['meter_status_id'] ?? null, fn (Builder $b, $id) => $b->where('c.meter_status_id', (int) $id))
            ->when(isset($q['bucket']) && $q['bucket'] !== '' && $q['bucket'] !== null, fn (Builder $b) => $b->where('c.arrears_bucket', (int) $q['bucket']));

        if (! empty($q['group'])) {
            $ids = DB::table('commercial_customer_categories')->where('category_group', (string) $q['group'])->pluck('id')->all();
            $ids === [] ? $query->whereRaw('1 = 0') : $query->whereIn('c.category_id', $ids);
        }

        empty($q['missing']) ? $query->whereNull('c.missing_since_batch_id') : $query->whereNotNull('c.missing_since_batch_id');

        return $query;
    }

    protected function applySearch(Builder $query, string $type, string $value): void
    {
        $value = trim($value);
        $number = CustomerValues::phones($value)[0][0] ?? '#';   // a number we cannot read, or a placeholder, matches nothing

        match ($type) {
            'account' => $query->where('c.account_no', str_pad(preg_replace('/\D/', '', $value) ?? '', 12, '0', STR_PAD_LEFT)),
            'meter' => $query->where('c.meter_no', $value),
            // Any of the customer's numbers, first or second.
            'phone' => $query->where(fn (Builder $w) => $w->where('ct.phone_primary', $number)->orWhere('ct.phone_secondary', $number)),
            'email' => $query->where('ct.email_lower', mb_strtolower($value)),
            'name' => $query->where('ct.name_search', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($value)).'%'),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * Restricts the list to one data-quality issue of one district (the drill-down behind a quality count). The issues that
     * need a scan or a GROUP BY to find (no mobile, shared meter, a date in the future ...) read the account lists the rollup
     * stored for the batch (up to CustomerRollupService::ISSUE_LIST_CAP per issue), so no screen ever scans a district to find
     * a handful of accounts. The category / status issues follow indexes and need no list.
     */
    protected function applyIssue(Builder $query, string $issue, int $districtId, string $asOf, int $batchId): void
    {
        $query->where('c.district_id', $districtId);

        switch ($issue) {
            case 'missing_mobile':
            case 'missing_email':
            case 'missing_address':
            case 'missing_name':
            case 'invalid_phone':
            case 'multiple_phones':
            case 'future_date':
            case 'implausible_date':
            case 'shared_meter':
            case 'shared_mobile':
            case 'shared_email':
                $query->whereIn('c.id', fn ($sub) => $sub->from('commercial_customer_issue_accounts')->select('customer_id')->where('batch_id', $batchId)->where('issue', $issue));

                break;
            case 'unknown_category':
                $query->whereIn('c.category_id', DB::table('commercial_customer_categories')->where('is_unknown', true)->pluck('id')->all() ?: [0]);

                break;
            case 'pending_category':
                $query->whereIn('c.category_id', DB::table('commercial_customer_categories')->where('is_pending', true)->pluck('id')->all() ?: [0]);

                break;
            case 'unconfirmed_status':
                $query->whereIn('c.status_id', DB::table('commercial_customer_statuses')->where('meaning_confirmed', false)->pluck('id')->all() ?: [0]);

                break;
            default:
                $query->whereRaw('1 = 0');
        }
    }

    /** The columns every list selects, all from the customers table (and the contacts for holders of the details permission). */
    public function select(Builder $query, bool $details): Builder
    {
        $columns = [
            'c.id', 'c.account_no', 'c.balance', 'c.arrears_bucket', 'c.meter_no', 'c.district_id', 'c.route_id', 'c.category_id', 'c.status_id', 'c.meter_status_id',
            'c.last_bill_date', 'c.last_bill_amount', 'c.last_paid_date', 'c.last_paid_amount', 'c.last_read_date', 'c.connect_date', 'c.missing_since_batch_id',
        ];

        if ($details) {
            array_push($columns, 'ct.account_name AS contact_name', 'ct.address AS contact_address', 'ct.mobiles AS contact_mobiles', 'ct.email AS contact_email',
                // How many numbers there are besides the first: counted in SQL, so a page needs no extra query.
                DB::raw("(CASE WHEN ct.mobiles IS NULL THEN 0 ELSE LENGTH(ct.mobiles) - LENGTH(REPLACE(ct.mobiles, ',', '')) END) AS contact_more_phones"));
        }

        return $query->select($columns);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     * @return Page
     */
    protected function shape($rows, int $size, string $sort, bool $details): array
    {
        $more = $rows->count() > $size;
        $rows = $rows->take($size)->values();
        $last = $rows->last();
        $maps = $this->lookups($rows);

        $selected = $rows->map(function ($r) use ($maps, $details) {
            $category = $maps['categories'][$r->category_id] ?? null;
            $status = $maps['statuses'][$r->status_id] ?? null;

            return [
                'id' => (int) $r->id,
                'account_no' => $r->account_no,
                'district' => $maps['districts'][$r->district_id] ?? '',
                'route' => $maps['routes'][$r->route_id] ?? '',
                'category' => $category ? ($category->is_unknown ? 'UNKNOWN' : trim($category->code.' '.$category->name)) : '',
                'status' => $status ? ($status->meaning_confirmed ? $status->label.' ('.$status->code.')' : $status->code) : '',
                'meter_status' => $maps['meters'][$r->meter_status_id] ?? '',
                'meter_no' => $r->meter_no,
                'balance' => round(((int) $r->balance) / 100, 2),
                'bucket' => CustomerAnalyticsService::BUCKET_LABELS[(int) $r->arrears_bucket] ?? '',
                'last_bill_date' => $r->last_bill_date,
                'last_bill_amount' => $r->last_bill_amount === null ? null : round(((int) $r->last_bill_amount) / 100, 2),
                'last_paid_date' => $r->last_paid_date,
                'last_paid_amount' => $r->last_paid_amount === null ? null : round(((int) $r->last_paid_amount) / 100, 2),
                'last_read_date' => $r->last_read_date,
                'connect_date' => $r->connect_date,
                'missing' => $r->missing_since_batch_id !== null,
                'name' => $details ? ($r->contact_name ?? null) : null,
                'address' => $details ? ($r->contact_address ?? null) : null,
                'mobile' => $details ? CustomerValues::maskPhone(explode(',', (string) ($r->contact_mobiles ?? ''))[0] ?: null) : null,
                'more_phones' => $details ? (int) ($r->contact_more_phones ?? 0) : 0,
                'mobiles' => $details && ($r->contact_mobiles ?? '') !== '' ? implode(' / ', array_map([CustomerValues::class, 'maskPhone'], explode(',', $r->contact_mobiles))) : null,
                'email' => $details ? CustomerValues::maskEmail($r->contact_email ?? null) : null,
            ];
        })->all();

        return [
            'rows' => $selected,
            'next' => $more && $last ? ($sort === 'route' ? [(int) $last->route_id, (int) $last->id] : [(int) $last->balance, (int) $last->id]) : null,
            'size' => $size,
        ];
    }

    /**
     * Labels for the rows on a page: the small lookup tables once, and only the districts and routes the page names.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     * @return array<string, array<int, mixed>>
     */
    protected function lookups($rows): array
    {
        $this->maps ??= [
            'categories' => DB::table('commercial_customer_categories')->get()->keyBy('id')->all(),
            'statuses' => DB::table('commercial_customer_statuses')->get()->keyBy('id')->all(),
            'meters' => DB::table('commercial_meter_statuses')->pluck('label', 'id')->all(),
        ];

        return $this->maps + [
            'districts' => DB::table('districts')->whereIn('id', $rows->pluck('district_id')->unique()->all() ?: [0])->pluck('district_name', 'id')->all(),
            'routes' => DB::table('commercial_routes')->whereIn('id', $rows->pluck('route_id')->unique()->all() ?: [0])->pluck('name', 'id')->all(),
        ];
    }

    /**
     * One customer, inside the viewer's region or not at all (null: the caller answers 404, which never reveals that the id
     * exists elsewhere). Contact details are NOT included: see contact().
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id, ?int $restriction): ?array
    {
        $query = DB::table('commercial_customers AS c')->where('c.id', $id);

        if ($restriction !== null) {
            $restriction === 0 ? $query->whereRaw('1 = 0') : $query->where('c.region_id', $restriction);
        }

        $this->select($query, false);
        $row = $query->first();

        if (! $row) {
            return null;
        }

        return $this->shape(collect([$row]), 1, 'route', false)['rows'][0] + ['district_id' => (int) $row->district_id];
    }

    /**
     * What changed for the customer upload by upload (status / category / meter / route / district / bucket, arrival and
     * disappearance), newest first, with the date of the file that changed it.
     *
     * @return list<array<string, mixed>>
     */
    public function history(int $id, int $limit = 40): array
    {
        $labels = [
            CustomerMergeService::FIELD_STATUS => ['Status', 'commercial_customer_statuses', 'code'],
            CustomerMergeService::FIELD_CATEGORY => ['Category', 'commercial_customer_categories', 'code'],
            CustomerMergeService::FIELD_METER_STATUS => ['Meter status', 'commercial_meter_statuses', 'code'],
            CustomerMergeService::FIELD_ROUTE => ['Route', 'commercial_routes', 'name'],
            CustomerMergeService::FIELD_DISTRICT => ['District', 'districts', 'district_name'],
            CustomerMergeService::FIELD_BUCKET => ['Arrears bucket', null, null],
        ];

        $rows = DB::table('commercial_customer_changes AS ch')->join('commercial_customer_batches AS b', 'b.id', '=', 'ch.batch_id')
            ->where('ch.customer_id', $id)->orderByDesc('b.as_of_date')->orderByDesc('ch.id')->limit($limit)
            ->get(['ch.field', 'ch.old_value', 'ch.new_value', 'b.as_of_date', 'b.id AS batch_id']);

        $lookup = function (int $field, $value) use ($labels): ?string {
            if ($value === null) {
                return null;
            }

            [, $table, $column] = $labels[$field];

            if ($field === CustomerMergeService::FIELD_BUCKET) {
                return CustomerAnalyticsService::BUCKET_LABELS[(int) $value] ?? (string) $value;
            }

            $text = DB::table($table)->where('id', $value)->value($column);

            return $text === null ? (string) $value : (string) $text;
        };

        $first = DB::table('commercial_customers AS c')->join('commercial_customer_batches AS b', 'b.id', '=', 'c.first_seen_batch_id')->where('c.id', $id)->first(['b.as_of_date', 'b.id AS batch_id']);

        return $rows->map(fn ($r) => [
            'date' => $r->as_of_date, 'batch_id' => (int) $r->batch_id,
            'what' => match ((int) $r->field) {
                CustomerMergeService::FIELD_NEW => 'First seen in the files',
                CustomerMergeService::FIELD_MISSING => 'Missing from the file (not deleted)',
                CustomerMergeService::FIELD_RETURNED => 'Back in the file',
                default => ($labels[(int) $r->field][0] ?? 'Field').': '.($lookup((int) $r->field, $r->old_value) ?? '–').' → '.($lookup((int) $r->field, $r->new_value) ?? '–'),
            },
        ])->when($first, fn ($list) => $list->push(['date' => $first->as_of_date, 'batch_id' => (int) $first->batch_id, 'what' => 'First seen in the files']))->all();
    }

    /**
     * The customer's contact details. MASKED unless $reveal; the caller must hold commercial.view_customer_details and must
     * audit a reveal (the audit row carries the customer id only, never a value).
     *
     * @return array<string, ?string>|null
     */
    public function contact(int $id, bool $reveal): ?array
    {
        $contact = DB::table('commercial_customer_contacts')->where('customer_id', $id)->first();

        if (! $contact) {
            return null;
        }

        $mobiles = $contact->mobiles ? explode(',', $contact->mobiles) : [];

        return [
            'name' => $contact->account_name,
            'address' => $contact->address,
            'mobiles' => implode(', ', $reveal ? $mobiles : array_map([CustomerValues::class, 'maskPhone'], $mobiles)),
            'email' => $reveal ? $contact->email : CustomerValues::maskEmail($contact->email),
        ];
    }

    public static function groupLabel(?string $group): string
    {
        return CommercialCustomerCategory::groupLabel($group);
    }
}
