<?php

namespace Tests\Feature\Commercial\Sms;

use App\Models\CommercialSmsCampaign;
use App\Services\Commercial\Customers\CustomerAnalyticsService;
use App\Services\Commercial\Customers\CustomerLookups;
use App\Services\Commercial\Customers\CustomerSnapshots;
use App\Services\Commercial\Sms\SmsAccess;
use App\Services\Commercial\Sms\SmsAudienceResolver;
use Illuminate\Support\Facades\DB;
use Tests\Support\Commercial\ReportWorkbooks;

/**
 * Phase 8b, step 0.3: the Commercial team confirmed that NSC (new service connection) is a BILLING status. Everything that counts
 * "billing accounts" reads the is_billing flag, so NSC now counts in the customer base mix, in the billing coverage's eligible
 * accounts and in the SMS billing group; HOLD (billing paused) and DISN (same group as DISC) stay out. Synthetic customers only.
 */
class NscIsBillingTest extends SmsTestCase
{
    protected function resolver(): SmsAudienceResolver
    {
        return app(SmsAudienceResolver::class);
    }

    protected function c(int $n, array $over = []): array
    {
        return ReportWorkbooks::customer($n, $over);
    }

    protected function file(array $customers): void
    {
        $this->loadCustomers(['region' => 'ACCRA WEST', 'district' => 'SOWUTUOM', 'routes' => [['name' => '1001', 'customers' => $customers]]], '2026-10-05');
    }

    /** @return list<int> customer numbers behind the audience's recipients */
    protected function members(array $spec): array
    {
        $campaign = CommercialSmsCampaign::query()->create([
            'region_id' => $this->accraWest->id, 'created_by' => $this->westOfficer()->id, 'status' => 'queued', 'audience_type' => $spec['type'], 'audience' => $spec,
            'include_second_number' => false, 'include_missing' => false, 'sender_id' => 'GWCL', 'body' => 'Hello', 'segments' => 1, 'encoding' => 'gsm7',
        ]);
        $this->resolver()->materialise($campaign);

        return DB::table('commercial_sms_recipients')->where('campaign_id', $campaign->id)->orderBy('customer_id')->pluck('customer_id')
            ->map(fn ($id) => (int) (DB::table('commercial_customers')->where('id', $id)->value('account_no') - 100000000000))->all();
    }

    protected function group(string $group, array $input = []): array
    {
        return $this->resolver()->normalise($input + ['type' => 'group', 'group' => $group]);
    }

    protected function counts(array $spec): array
    {
        return $this->resolver()->counts($spec, SmsAccess::regionIds());
    }

    protected function seedMix(): void
    {
        $this->file([
            $this->c(1, ['status' => 'ACTB', 'balance' => 100.0, 'last_bill_date' => '2026-09-25', 'last_bill_amount' => 80.0, 'last_read_date' => '2026-09-20']),
            $this->c(2, ['status' => 'NSC', 'balance' => 40.0, 'last_bill_date' => '2026-09-26', 'last_bill_amount' => 30.0, 'last_read_date' => '2026-09-21']),
            $this->c(3, ['status' => 'HOLD', 'balance' => 60.0, 'last_bill_date' => '2026-09-26', 'last_bill_amount' => 30.0, 'last_read_date' => '2026-09-21']),
            $this->c(4, ['status' => 'DISN', 'balance' => 70.0, 'last_bill_date' => '2026-01-01', 'last_bill_amount' => 30.0, 'last_read_date' => '2026-01-01']),
            $this->c(5, ['status' => 'DISC', 'balance' => 90.0, 'last_bill_date' => '2026-01-01', 'last_bill_amount' => 30.0, 'last_read_date' => '2026-01-01']),
            $this->c(6, ['status' => 'ACTN', 'balance' => 10.0, 'last_bill_date' => '2026-09-26', 'last_bill_amount' => 30.0, 'last_read_date' => '2026-09-21']),
        ]);
    }

    protected function filtersOf(): \App\Services\Commercial\Customers\CustomerFilters
    {
        $snapshots = app(CustomerSnapshots::class);

        return $snapshots->filters($snapshots->current(null), null);
    }

    public function test_the_flags_are_what_the_team_confirmed_and_the_lookup_reads_them(): void
    {
        $statuses = DB::table('commercial_customer_statuses')->get()->keyBy('code');

        $this->assertEquals([true, true, true], [(bool) $statuses['NSC']->is_active, (bool) $statuses['NSC']->is_billing, (bool) $statuses['NSC']->meaning_confirmed]);
        $this->assertEquals([true, false], [(bool) $statuses['HOLD']->is_active, (bool) $statuses['HOLD']->is_billing], 'HOLD is unchanged: connected, billing paused');
        $this->assertEquals([false, false], [(bool) $statuses['DISN']->is_active, (bool) $statuses['DISN']->is_billing], 'DISN is unchanged: with DISC');

        $billing = (new CustomerLookups)->billingStatusIds();
        $this->assertEqualsCanonicalizing([(int) $statuses['ACTB']->id, (int) $statuses['NSC']->id], $billing, 'the import hands the rollups exactly the billing flag');
    }

    public function test_nsc_counts_in_the_customer_base_mix_and_hold_and_disn_do_not(): void
    {
        $this->seedMix();

        $overview = app(CustomerAnalyticsService::class)->overview($this->filtersOf());

        $this->assertSame(6, $overview['total']);
        $this->assertSame(2, $overview['billing'], 'ACTB and NSC');
        $this->assertSame(2, $overview['active_non_billing'], 'ACTN and HOLD are active but not billing');
        $this->assertSame(1, $overview['disconnected'], 'DISC; DISN is a separate code of the same group and stays in "other", as before');
        $this->assertSame(1, $overview['other_status'], 'DISN');
        $statuses = collect($overview['by_status'])->keyBy('label');
        $this->assertSame(1, $statuses['New service connection (NSC)']['customers'] ?? null);
    }

    public function test_nsc_is_an_eligible_account_in_the_billing_coverage(): void
    {
        $this->seedMix();

        $total = app(CustomerAnalyticsService::class)->coverage($this->filtersOf())['total'];

        $this->assertSame(2, $total['eligible'], 'ACTB and NSC are billed accounts; HOLD, DISN, DISC and ACTN are not');
        $this->assertSame(2, $total['billed_valid'], 'both have a valid bill in the last 30 days');
        $this->assertSame(100.0, $total['billing_coverage']);
    }

    public function test_the_sms_billing_group_includes_nsc_and_leaves_hold_and_disn_out(): void
    {
        $this->seedMix();

        $this->assertSame([1, 2], $this->members($this->group('actb')), 'ACTB and NSC');
        $this->assertSame([6], $this->members($this->group('actn')), 'ACTN is its own group');
        $this->assertSame([5], $this->members($this->group('disc')));
        $this->assertSame(2, $this->counts($this->group('actb'))['matched']);
    }

    public function test_the_most_valuable_group_and_the_debtor_lists_already_read_the_flag(): void
    {
        $this->seedMix();

        // the valuable cut-off is taken among billing accounts: customer 1 (bill 80) then customer 2 (bill 30); HOLD (30) is not billing
        $top = $this->members($this->group('valuable', ['top_n' => 1]));
        $this->assertSame([1], $top);

        $two = $this->members($this->group('valuable', ['top_n' => 2]));
        $this->assertContains(2, $two, 'NSC is among the billing accounts');
        $this->assertNotContains(3, $two, 'HOLD is not');
    }
}
