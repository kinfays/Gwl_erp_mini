<?php

namespace Tests\Feature\Commercial\Sms;

use App\Models\CommercialSmsCampaign;
use App\Models\District;
use App\Models\Region;
use App\Services\Commercial\Sms\SmsAccess;
use App\Services\Commercial\Sms\SmsAudienceException;
use App\Services\Commercial\Sms\SmsAudienceResolver;
use Illuminate\Support\Facades\DB;
use Tests\Support\Commercial\ReportWorkbooks;

/**
 * Audiences: every group's count and members, the region lock, one SMS per number, unreachable and missing accounts, selected lists,
 * "most valuable". Synthetic customers only. Customer numbers: 1-20 Sowutuom, 31-40 Odorkor (both Accra West), 101-103 Kumasi
 * (Ashanti), 201-203 Accra Central (a region that is NOT locked in).
 */
class SmsAudienceTest extends SmsTestCase
{
    protected function resolver(): SmsAudienceResolver
    {
        return app(SmsAudienceResolver::class);
    }

    /** @return list<int> */
    protected function west(): array
    {
        return SmsAccess::regionIds();
    }

    protected function c(int $n, array $over = []): array
    {
        return ReportWorkbooks::customer($n, $over);
    }

    protected function file(array $customers, string $district = 'SOWUTUOM', string $region = 'ACCRA WEST', string $route = '1001', string $asOf = '2026-10-05'): void
    {
        $this->loadCustomers(['region' => $region, 'district' => $district, 'routes' => [['name' => $route, 'customers' => $customers]]], $asOf);
    }

    /** @return list<int> customer numbers behind the audience's recipients */
    protected function members(array $spec): array
    {
        $campaign = $this->campaign($spec);
        $this->resolver()->materialise($campaign);

        return DB::table('commercial_sms_recipients')->where('campaign_id', $campaign->id)->orderBy('customer_id')->pluck('customer_id')
            ->map(fn ($id) => (int) (DB::table('commercial_customers')->where('id', $id)->value('account_no') - 100000000000))->all();
    }

    protected function campaign(array $spec): CommercialSmsCampaign
    {
        return CommercialSmsCampaign::query()->create([
            'region_id' => $this->accraWest->id, 'created_by' => $this->westOfficer()->id, 'status' => 'queued', 'audience_type' => $spec['type'], 'audience' => $spec,
            'include_second_number' => $spec['include_second'] ?? false, 'include_missing' => $spec['include_missing'] ?? false,
            'sender_id' => 'GWCL', 'body' => 'Hello', 'segments' => 1, 'encoding' => 'gsm7',
        ]);
    }

    protected function spec(array $input = []): array
    {
        return $this->resolver()->normalise($input + ['type' => 'all']);
    }

    protected function group(string $group, array $input = []): array
    {
        return $this->resolver()->normalise($input + ['type' => 'group', 'group' => $group]);
    }

    protected function counts(array $spec): array
    {
        return $this->resolver()->counts($spec, $this->west());
    }

    // ---------------------------------------------------------------- the lock

    public function test_only_accra_west_customers_are_ever_in_an_audience(): void
    {
        $this->file([$this->c(1), $this->c(2), $this->c(3)]);
        $this->file([$this->c(101), $this->c(102)], 'KUMASI', 'ASHANTI');

        $central = Region::query()->create(['region_name' => 'Accra Central']);
        District::query()->create(['region_id' => $central->id, 'district_name' => 'Central District']);
        $this->file([$this->c(201), $this->c(202)], 'CENTRAL DISTRICT', 'ACCRA CENTRAL');

        $this->assertSame(7, DB::table('commercial_customers')->count(), 'sanity: seven customers in three regions');
        $this->assertSame([1, 2, 3], $this->members($this->spec()));

        // even a filter that asks for another region's district gets nothing
        $this->assertSame(0, $this->counts($this->spec(['district_ids' => [$this->kumasi->id]]))['matched']);
        $this->assertSame(0, $this->counts($this->spec(['district_ids' => [(int) District::query()->where('district_name', 'Central District')->value('id')]]))['matched']);

        // ticking another region's customers adds nothing, and is only counted
        $foreign = [(int) DB::table('commercial_customers')->where('account_no', '100000000101')->value('id'), (int) DB::table('commercial_customers')->where('account_no', '100000000201')->value('id')];
        $own = (int) DB::table('commercial_customers')->where('account_no', '100000000001')->value('id');
        $selected = $this->resolver()->normalise(['type' => 'selected', 'customer_ids' => [...$foreign, $own]]);

        $this->assertSame(1, $this->counts($selected)['matched']);
        $this->assertSame(['inside' => 1, 'outside' => 2], $this->resolver()->checkSelected([...$foreign, $own], $this->west()));

        // a campaign filed under a region that is not locked in draws no audience at all
        $bad = CommercialSmsCampaign::query()->create(['region_id' => $this->ashanti->id] + $this->campaign($this->spec())->only(['audience_type', 'audience', 'sender_id', 'body', 'segments', 'encoding', 'status']));
        $this->expectException(SmsAudienceException::class);
        $this->resolver()->materialise($bad);
    }

    public function test_the_audience_never_includes_an_unreachable_or_missing_account_and_a_switch_brings_missing_ones_back(): void
    {
        $this->file([$this->c(1), $this->c(2, ['mobile' => null]), $this->c(3, ['mobile' => '12345']), $this->c(4, ['mobile' => '0000000000']), $this->c(5), $this->c(6)]);

        $counts = $this->counts($this->spec());
        $this->assertSame(6, $counts['matched']);
        $this->assertSame(3, $counts['reachable'], 'no number, a short number and a placeholder are not reachable');
        $this->assertSame(3, $counts['without_number']);
        $this->assertSame([1, 5, 6], $this->members($this->spec()));

        // the next file no longer lists 5 and 6
        $this->file([$this->c(1), $this->c(2, ['mobile' => null]), $this->c(3, ['mobile' => '12345']), $this->c(4, ['mobile' => '0000000000'])], 'SOWUTUOM', 'ACCRA WEST', '1001', '2026-11-05');
        $this->assertSame([1], $this->members($this->spec()), 'accounts missing from the latest file are left out');
        $this->assertSame([1, 5, 6], $this->members($this->spec(['include_missing' => true])), 'unless the audience is told to include them');
    }

    // ---------------------------------------------------------------- numbers

    public function test_accounts_sharing_a_number_get_one_sms_and_the_preview_says_how_many_share(): void
    {
        $this->file([$this->c(1, ['mobile' => '0241234567']), $this->c(2, ['mobile' => '0241234567']), $this->c(3, ['mobile' => '233241234567']), $this->c(4), $this->c(5, ['mobile' => '0207654321 / 0241234567'])]);

        $counts = $this->counts($this->spec());
        $this->assertSame(5, $counts['matched']);
        $this->assertSame(5, $counts['reachable']);
        $this->assertSame(3, $counts['numbers'], '0241234567 (x4 first numbers), customer 4\'s own, and customer 5\'s first 0207654321 are 3 numbers... ');
    }

    public function test_one_number_is_one_recipient_and_the_lowest_account_is_the_one_kept(): void
    {
        $this->file([$this->c(1, ['mobile' => '0241234567']), $this->c(2, ['mobile' => '0241234567']), $this->c(3, ['mobile' => '0241234567']), $this->c(4)]);

        $counts = $this->counts($this->spec());
        $this->assertSame([4, 4, 2, 2], [$counts['matched'], $counts['reachable'], $counts['numbers'], $counts['sharing']]);
        $this->assertSame([1, 4], $this->members($this->spec()));
        $this->assertSame(2, DB::table('commercial_sms_recipients')->count());
    }

    public function test_materialising_twice_adds_nothing_and_the_recipient_count_equals_the_preview(): void
    {
        $this->file(array_map(fn (int $n) => $this->c($n, ['mobile' => $n % 4 === 0 ? '0241234567' : null]), range(1, 12)));
        $this->file(array_map(fn (int $n) => $this->c($n), range(31, 40)), 'ODORKOR');

        $spec = $this->spec();
        $campaign = $this->campaign($spec);
        $first = $this->resolver()->materialise($campaign);
        $rows = DB::table('commercial_sms_recipients')->orderBy('id')->get()->toJson();
        $second = $this->resolver()->materialise($campaign->fresh());

        $this->assertSame($first, $second);
        $this->assertSame($rows, DB::table('commercial_sms_recipients')->orderBy('id')->get()->toJson(), 'a repeat or a resume writes nothing new');
        $this->assertSame($this->counts($spec)['numbers'], $first['recipients']);
        $this->assertSame(DB::table('commercial_sms_recipients')->where('campaign_id', $campaign->id)->count(), $first['recipients']);
        $this->assertSame(['pending'], DB::table('commercial_sms_recipients')->distinct()->pluck('status')->all());
    }

    public function test_the_second_number_is_used_only_when_asked_and_a_number_is_never_sent_twice_across_slots(): void
    {
        $this->file([
            $this->c(1, ['mobile' => '0241234567 / 0207654321']),
            $this->c(2, ['mobile' => '0551234567 / 0207654321']),   // its second number is customer 1's second number
            $this->c(3, ['mobile' => '0207654321']),                // and customer 3's first
            $this->c(4, ['mobile' => '0501234567']),
            $this->c(5, ['mobile' => '0261234567 / 0262345678']),   // a second number nobody else has
        ]);

        $one = $this->counts($this->spec());
        $this->assertSame([5, 5], [$one['reachable'], $one['numbers']], 'first numbers only: five different ones');

        $both = $this->counts($this->spec(['include_second' => true]));
        $this->assertSame(6, $both['numbers'], 'A, B, C, D, E and F: 0207654321 is on three accounts but is one number');

        $campaign = $this->campaign($this->spec(['include_second' => true]));
        $this->resolver()->materialise($campaign);

        // read the numbers the recipients would be sent to (in the test only) and check none is repeated
        $numbers = DB::table('commercial_sms_recipients as r')->join('commercial_customer_contacts as ct', 'ct.customer_id', '=', 'r.customer_id')
            ->where('r.campaign_id', $campaign->id)
            ->selectRaw('CASE WHEN r.slot = 1 THEN ct.phone_primary ELSE ct.phone_secondary END AS number')->pluck('number')->all();

        $this->assertCount(6, $numbers);
        $this->assertCount(6, array_unique($numbers), 'one SMS per number, across both slots');
        $this->assertContains('0262345678', $numbers, 'the second number of customer 5 is a recipient');
        $this->assertSame(1, DB::table('commercial_sms_recipients')->where('campaign_id', $campaign->id)->where('slot', 2)->whereIn('customer_id', [
            (int) DB::table('commercial_customers')->where('account_no', '100000000005')->value('id'),
        ])->count());
    }

    // ---------------------------------------------------------------- groups

    protected function seedGroups(): void
    {
        $this->file([
            $this->c(1, ['status' => 'ACTB', 'balance' => 250.0, 'last_bill_amount' => 100.0, 'last_paid_date' => '2026-09-28']),
            $this->c(2, ['status' => 'ACTB', 'balance' => 50.0, 'last_bill_amount' => 90.0, 'last_paid_date' => '2026-07-01']),
            $this->c(3, ['status' => 'ACTB', 'balance' => -20.0, 'last_bill_amount' => 80.0, 'last_paid_date' => '2026-03-01']),
            $this->c(4, ['status' => 'ACTN', 'balance' => 0.0, 'last_bill_amount' => 0.0, 'last_paid_date' => null]),
            $this->c(5, ['status' => 'DISC', 'balance' => 900.0, 'last_bill_amount' => 60.0, 'last_paid_date' => '2025-01-01', 'meter_status' => 'F']),
            $this->c(6, ['status' => 'SUSP', 'balance' => 10.0, 'last_bill_amount' => 70.0, 'last_paid_date' => '2026-10-01', 'meter_status' => 'N']),
            $this->c(7, ['status' => 'TRFR', 'balance' => 300.0, 'last_bill_amount' => 300.0, 'last_paid_date' => '2026-10-01']),
            $this->c(8, ['status' => 'ACTB', 'balance' => 400.0, 'last_bill_amount' => 500.0, 'last_paid_date' => '2026-09-30', 'category' => '612']),
        ]);
        $this->file([$this->c(31, ['status' => 'ACTB', 'balance' => 1000.0, 'last_bill_amount' => 700.0, 'last_paid_date' => '2026-01-01'])], 'ODORKOR');
    }

    public function test_the_status_groups(): void
    {
        $this->seedGroups();

        $this->assertSame([1, 2, 3, 8, 31], $this->members($this->group('actb')));
        $this->assertSame([4], $this->members($this->group('actn')));
        $this->assertSame([5], $this->members($this->group('disc')));
        $this->assertSame([6], $this->members($this->group('susp')));
        $this->assertSame(5, $this->counts($this->group('actb'))['matched']);
    }

    public function test_unconfirmed_status_codes_are_not_groups_and_cannot_be_used_as_a_filter(): void
    {
        foreach (['trfr', 'vacn', 'diso', 'nflo'] as $code) {
            $this->assertArrayNotHasKey($code, SmsAudienceResolver::GROUPS);
        }

        $trfr = (int) DB::table('commercial_customer_statuses')->where('code', 'TRFR')->value('id');
        $actb = (int) DB::table('commercial_customer_statuses')->where('code', 'ACTB')->value('id');

        $this->assertSame($actb, $this->spec(['status_id' => $actb])['status_id']);
        $this->expectException(SmsAudienceException::class);
        $this->spec(['status_id' => $trfr]);
    }

    public function test_debtors_credit_and_the_arrears_bucket(): void
    {
        $this->seedGroups();

        $this->assertSame([1, 5, 7, 8, 31], $this->members($this->group('debtors', ['min_debt' => 100])), 'owing more than GH¢ 100');
        $this->assertSame([1, 2, 5, 6, 7, 8, 31], $this->members($this->group('debtors', ['min_debt' => 0])), 'any balance owed');
        $this->assertSame([5, 31], $this->members($this->group('debtors', ['min_debt' => 500])));
        $this->assertSame([3], $this->members($this->group('credit')));

        // the arrears bucket says how many bills are owed: up to 1 bill (2), 1 to 3 bills (3), more than 12 (6)
        $this->assertSame([2, 6, 7, 8], $this->members($this->group('debtors', ['min_debt' => 0, 'bucket' => 2])));
        $this->assertSame([1, 31], $this->members($this->group('debtors', ['min_debt' => 0, 'bucket' => 3])));
        $this->assertSame([5], $this->members($this->group('debtors', ['min_debt' => 0, 'bucket' => 6])));
        $this->assertSame([31], $this->members($this->group('debtors', ['min_debt' => 500, 'bucket' => 3])));
    }

    public function test_no_payment_is_counted_from_each_districts_file_date(): void
    {
        $this->seedGroups();

        // file date 2026-10-05: 90 days back is 2026-07-07
        $this->assertSame([2, 3, 4, 5, 31], $this->members($this->group('no_payment', ['days' => 90])), 'older than 90 days, or never paid');
        $this->assertSame([3, 4, 5, 31], $this->members($this->group('no_payment', ['days' => 180])));
        $this->assertSame([2, 3, 4, 5, 31], $this->members($this->group('no_payment', ['days' => 60])));

        $this->expectException(SmsAudienceException::class);
        $this->group('no_payment', ['days' => 45]);
    }

    public function test_faulty_or_no_meter(): void
    {
        $this->seedGroups();

        $this->assertSame([5, 6], $this->members($this->group('faulty_meter')));
    }

    public function test_most_valuable_is_the_top_n_or_top_percent_of_billing_accounts_by_last_bill_with_ties_kept(): void
    {
        $this->seedGroups();

        // billing accounts with a bill: 1 (100), 2 (90), 3 (80), 8 (500), 31 (700). 7 (TRFR, 300) and the others are not billing accounts.
        $this->assertSame([8, 31], $this->members($this->group('valuable', ['top_n' => 2])));
        $this->assertSame([1, 8, 31], $this->members($this->group('valuable', ['top_n' => 3])));
        $this->assertSame([31], $this->members($this->group('valuable', ['top_n' => 1])));

        // 40% of 5 billing accounts = 2
        $this->assertSame([8, 31], $this->members($this->group('valuable', ['top_pct' => 40])));

        // narrowed to one district the ranking is inside it
        $odorkor = (int) District::query()->where('district_name', 'Odorkor')->value('id');
        $this->assertSame([31], $this->members($this->group('valuable', ['top_n' => 3, 'district_ids' => [$odorkor]])));

        // the default is the configured N
        config(['gwl.commercial_sms_most_valuable_top_n' => 2]);
        $this->assertSame(2, $this->group('valuable')['top_n']);

        // ties at the cut-off are all in: Odorkor now has 31 (700) and two accounts with a last bill of 80
        $this->file([$this->c(31, ['status' => 'ACTB', 'balance' => 1000.0, 'last_bill_amount' => 700.0, 'last_paid_date' => '2026-01-01']), $this->c(51, ['status' => 'ACTB', 'last_bill_amount' => 80.0]), $this->c(52, ['status' => 'ACTB', 'last_bill_amount' => 80.0])], 'ODORKOR', 'ACCRA WEST', '2002', '2026-11-05');
        $this->assertSame([31, 51, 52], $this->members($this->group('valuable', ['top_n' => 2, 'district_ids' => [$odorkor]])), 'the 2nd highest is 80, and both 80s are in');
    }

    public function test_a_group_combines_with_the_filters_by_and(): void
    {
        $this->seedGroups();
        $odorkor = (int) District::query()->where('district_name', 'Odorkor')->value('id');
        $sowutuom = $this->sowutuom->id;
        $commercial = DB::table('commercial_customer_categories')->where('code', '612')->value('category_group');
        $actb = (int) DB::table('commercial_customer_statuses')->where('code', 'ACTB')->value('id');

        $this->assertSame([31], $this->members($this->group('debtors', ['min_debt' => 100, 'district_ids' => [$odorkor]])));
        $this->assertSame([1, 5, 7, 8], $this->members($this->group('debtors', ['min_debt' => 100, 'district_ids' => [$sowutuom]])));
        $this->assertSame([8], $this->members($this->group('debtors', ['min_debt' => 100, 'category_group' => $commercial])));
        $this->assertSame([1, 8], $this->members($this->group('debtors', ['min_debt' => 100, 'district_ids' => [$sowutuom], 'status_id' => $actb])));
        $route = (int) DB::table('commercial_routes')->where('district_id', $sowutuom)->value('id');
        $this->assertSame([1, 5, 7, 8], $this->members($this->group('debtors', ['min_debt' => 100, 'route_id' => $route])));
        $this->assertSame([], $this->members($this->group('actn', ['status_id' => $actb])), 'contradicting rules give nobody');
    }

    // ---------------------------------------------------------------- selected lists

    public function test_a_pasted_list_reports_what_was_not_found_or_is_outside_and_never_names_them(): void
    {
        $this->file([$this->c(1), $this->c(2)]);
        $this->file([$this->c(101)], 'KUMASI', 'ASHANTI');

        $report = $this->resolver()->parseAccountList("100000000001, 100000000002\n100000000001 ; 100000000101 999 abc 1000000000099999 100000000404", $this->west());

        $this->assertSame(2, count($report['ids']));
        $this->assertSame(1, $report['duplicates']);
        $this->assertSame(1, $report['outside'], 'Kumasi is not Accra West');
        $this->assertSame(2, $report['not_found'], 'the short "999" pads to 000000000999 (not found) and ...0404 is not found');
        $this->assertSame(2, $report['invalid'], '"abc" and the 16-digit one');

        // short numbers are padded with zeros on the left, like in the customer search: '1' is 000000000001, which is not an account
        $this->assertSame(1, $this->resolver()->parseAccountList('1', $this->west())['not_found']);
    }

    public function test_a_pasted_list_and_a_selection_have_a_limit_and_must_not_be_empty(): void
    {
        config(['gwl.commercial_sms_max_selected' => 3]);

        try {
            $this->resolver()->parseAccountList('1 2 3 4', $this->west());
            $this->fail('over the limit');
        } catch (SmsAudienceException $e) {
            $this->assertStringContainsString('3', $e->getMessage());
        }

        $this->expectException(SmsAudienceException::class);
        $this->resolver()->normalise(['type' => 'selected', 'customer_ids' => []]);
    }

    // ---------------------------------------------------------------- samples and wording

    public function test_the_sample_shows_masked_numbers_and_names_only_to_those_who_may_see_them(): void
    {
        $this->file([$this->c(1), $this->c(2), $this->c(3), $this->c(4)]);

        $plain = $this->resolver()->sample($this->spec(), $this->west(), false);
        $this->assertCount(3, $plain);
        $this->assertSame('024****001', $plain[0]['mobile']);
        $this->assertNull($plain[0]['name']);
        $this->assertStringNotContainsString('0240000001', json_encode($plain));

        $detailed = $this->resolver()->sample($this->spec(), $this->west(), true);
        $this->assertSame('Customer 000001', $detailed[0]['name']);
        $this->assertStringNotContainsString('0240000001', json_encode($detailed));
    }

    public function test_the_wording_states_every_definition_so_staff_know_what_they_are_sending_to(): void
    {
        $text = fn (array $spec) => $this->resolver()->describe($spec);

        $this->assertStringContainsString('All customers', $text($this->spec()));
        $this->assertStringContainsString('first number', $text($this->spec()));
        $this->assertStringContainsString('both numbers', $text($this->spec(['include_second' => true])));
        $this->assertStringContainsString('to be confirmed', $text($this->group('debtors', ['min_debt' => 100])));
        $this->assertStringContainsString('file date', $text($this->group('no_payment', ['days' => 60])));
        $this->assertStringContainsString('top 5%', $text($this->group('valuable', ['top_pct' => 5])));
        $this->assertStringContainsString('top 500', $text($this->group('valuable', ['top_n' => 500])));
        $this->assertStringContainsString('assumption', $text($this->group('valuable', ['top_n' => 500])));
    }
}
