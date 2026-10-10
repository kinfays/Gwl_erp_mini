<?php

namespace App\Services\Commercial\Sms;

use App\Models\CommercialSmsCampaign;
use App\Services\Commercial\Customers\CustomerValues;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * The delivery report of one campaign: totals (percentages worked out from counts), the timeline, failures by reason and the
 * recipient list, plus its Excel export. Numbers are read only to MASK them for display (a page at a time, in memory); names only
 * for holders of the customer-details permission. The caller has already found the campaign through SmsAccess::findCampaign, so
 * it is inside the viewer's region.
 *
 * When Arkesel cannot report per-message delivery (gwl.commercial_sms_delivery_reports = false) the report says "accepted only"
 * and has no delivered column, instead of showing figures it does not have.
 */
class SmsReportService
{
    public const STATUS_LABELS = [
        'pending' => 'Not sent yet', 'claimed' => 'Being sent', 'submitted' => 'Accepted by the provider', 'delivered' => 'Delivered',
        'failed' => 'Failed', 'unknown' => 'Unknown (may have been sent)',
    ];

    public const ERROR_LABELS = [
        'not_eligible' => 'The customer left the region or the latest file before this message',
        'no_number' => 'No number on file when the message was sent',
        'duplicate_number' => 'The same number was already in this send',
        'not_accepted' => 'The provider did not accept this number',
        'not_saved' => 'No result was saved (the sending worker stopped)',
        'timeout' => 'The provider did not answer in time',
        'unreachable' => 'The provider could not be reached',
        'unreadable' => 'The provider answered in a way that could not be read',
        'not_configured' => 'No provider key is set on the server',
        'undelivered' => 'Reported as not delivered',
    ];

    public const HEADINGS = ['Account', 'District', 'Status', 'Reason', 'Accepted at', 'Delivered at'];

    public const PII_HEADINGS = ['Name', 'Mobile (masked)'];

    /** @return array<string, mixed> */
    public function summary(CommercialSmsCampaign $campaign): array
    {
        $deliveryReports = (bool) config('gwl.commercial_sms_delivery_reports');
        $purged = $campaign->details_purged_at !== null;
        $counts = $purged ? (array) $campaign->totals : $this->live($campaign->id);
        $pct = fn (int $n) => $campaign->recipients_total > 0 ? round($n / $campaign->recipients_total * 100, 1) : null;

        $get = fn (string $status) => (int) ($counts[$status] ?? 0);
        $accepted = $purged ? $get('submitted') + $get('delivered') : (int) DB::table('commercial_sms_recipients')->where('campaign_id', $campaign->id)->where(fn ($q) => $q->whereIn('status', ['submitted', 'delivered'])->orWhereNotNull('provider_message_id'))->count();

        $rows = [
            ['key' => 'audience', 'label' => 'Audience', 'count' => (int) $campaign->recipients_total, 'pct' => null],
            ['key' => 'accepted', 'label' => 'Accepted by the provider', 'count' => $accepted, 'pct' => $pct($accepted)],
        ];

        if ($deliveryReports) {
            $rows[] = ['key' => 'delivered', 'label' => 'Delivered', 'count' => $get('delivered'), 'pct' => $pct($get('delivered'))];
            $rows[] = ['key' => 'awaiting', 'label' => 'Accepted, no delivery report yet', 'count' => $get('submitted'), 'pct' => $pct($get('submitted'))];
        }

        $rows[] = ['key' => 'failed', 'label' => 'Failed', 'count' => $get('failed'), 'pct' => $pct($get('failed'))];
        $rows[] = ['key' => 'unknown', 'label' => 'Unknown (may have been sent)', 'count' => $get('unknown') + $get('claimed'), 'pct' => $pct($get('unknown') + $get('claimed'))];
        $rows[] = ['key' => 'pending', 'label' => 'Not sent'.($campaign->isOpen() ? ' yet' : ''), 'count' => $get('pending'), 'pct' => $pct($get('pending'))];

        $times = $purged ? null : DB::table('commercial_sms_recipients')->where('campaign_id', $campaign->id)->selectRaw('MIN(submitted_at) AS first_sent, MAX(submitted_at) AS last_sent, MAX(delivered_at) AS last_delivered')->first();

        $timeline = array_values(array_filter([
            ['at' => $campaign->created_at, 'label' => 'Filed'],
            $campaign->scheduled_at ? ['at' => $campaign->scheduled_at, 'label' => 'Scheduled for'] : null,
            $campaign->started_at ? ['at' => $campaign->started_at, 'label' => 'Started'] : null,
            $times?->first_sent ? ['at' => \Illuminate\Support\Carbon::parse($times->first_sent), 'label' => 'First message accepted'] : null,
            $times?->last_sent ? ['at' => \Illuminate\Support\Carbon::parse($times->last_sent), 'label' => 'Last message accepted'] : null,
            $deliveryReports && $times?->last_delivered ? ['at' => \Illuminate\Support\Carbon::parse($times->last_delivered), 'label' => 'Latest delivery report'] : null,
            $campaign->cancelled_at ? ['at' => $campaign->cancelled_at, 'label' => 'Cancelled'] : null,
            $campaign->finished_at && ! $campaign->cancelled_at ? ['at' => $campaign->finished_at, 'label' => $campaign->status === 'completed' ? 'Finished' : 'Stopped'] : null,
        ]));

        usort($timeline, fn (array $a, array $b) => $a['at'] <=> $b['at']);

        return [
            'rows' => $rows,
            'counts' => $counts,
            'delivery_reports' => $deliveryReports,
            'purged' => $purged,
            'credits_estimated' => (int) $campaign->credits_estimated,
            'credits_used' => (int) $campaign->credits_used,
            'timeline' => $timeline,
            'failures' => $purged ? [] : $this->failures($campaign->id),
            'accounts' => (int) $campaign->accounts_total,
            'sharing' => max(0, (int) $campaign->accounts_total - (int) $campaign->recipients_total),
        ];
    }

    /** @return array<string, int> */
    protected function live(int $campaignId): array
    {
        $totals = DB::table('commercial_sms_recipients')->where('campaign_id', $campaignId)->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();
        ksort($totals);

        return $totals;
    }

    /** @return list<array{code: string, label: string, count: int}> failed and unknown messages by reason */
    protected function failures(int $campaignId): array
    {
        return DB::table('commercial_sms_recipients')->where('campaign_id', $campaignId)->whereIn('status', ['failed', 'unknown'])->whereNotNull('error_code')
            ->selectRaw('error_code, COUNT(*) AS n')->groupBy('error_code')->orderByDesc('n')->get()
            ->map(fn ($row) => ['code' => (string) $row->error_code, 'label' => self::label((string) $row->error_code), 'count' => (int) $row->n])->all();
    }

    public static function label(string $code): string
    {
        if (isset(self::ERROR_LABELS[$code])) {
            return self::ERROR_LABELS[$code];
        }

        return str_starts_with($code, 'arkesel_') || str_starts_with($code, 'http_') ? 'The provider refused the request ('.$code.')' : 'Reported as: '.$code;
    }

    /**
     * A page of recipients, by keyset on id, optionally of one status.
     *
     * @return array{rows: list<array<string, mixed>>, next: int|null}
     */
    public function recipients(CommercialSmsCampaign $campaign, ?string $status, ?int $after, bool $details, int $size = 50): array
    {
        $query = DB::table('commercial_sms_recipients')->where('campaign_id', $campaign->id)
            ->when($status && isset(self::STATUS_LABELS[$status]), fn ($q) => $q->where('status', $status))
            ->when($after, fn ($q, $id) => $q->where('id', '>', $id))
            ->orderBy('id')->limit($size + 1);

        $rows = $query->get(['id', 'customer_id', 'slot', 'status', 'error_code', 'submitted_at', 'delivered_at']);
        $more = $rows->count() > $size;
        $rows = $rows->take($size)->values();
        $ids = $rows->pluck('customer_id')->all();

        $customers = DB::table('commercial_customers')->whereIn('id', $ids)->get(['id', 'account_no', 'district_id'])->keyBy('id');
        $districts = DB::table('districts')->whereIn('id', $customers->pluck('district_id')->unique()->all())->pluck('district_name', 'id');
        $contacts = DB::table('commercial_customer_contacts')->whereIn('customer_id', $ids)->get(['customer_id', 'phone_primary', 'phone_secondary', 'account_name'])->keyBy('customer_id');

        return [
            'rows' => $rows->map(function ($row) use ($customers, $districts, $contacts, $details) {
                $customer = $customers[$row->customer_id] ?? null;
                $contact = $contacts[$row->customer_id] ?? null;

                return [
                    'id' => (int) $row->id,
                    'account_no' => $customer->account_no ?? '–',
                    'district' => $customer ? ($districts[$customer->district_id] ?? '') : '',
                    'status' => (string) $row->status,
                    'status_label' => self::STATUS_LABELS[$row->status] ?? (string) $row->status,
                    'reason' => $row->error_code ? self::label((string) $row->error_code) : '',
                    'submitted_at' => $row->submitted_at,
                    'delivered_at' => $row->delivered_at,
                    'name' => $details ? ($contact->account_name ?? null) : null,
                    'mobile' => $details ? CustomerValues::maskPhone((int) $row->slot === 2 ? ($contact->phone_secondary ?? null) : ($contact->phone_primary ?? null)) : null,
                ];
            })->all(),
            'next' => $more ? (int) $rows->last()->id : null,
        ];
    }

    // ---------------------------------------------------------------- export

    /**
     * Streams the recipient list to an Excel file: STRING cells (a name starting with "=" stays text), masked numbers, a row cap.
     *
     * @return array{rows: int, truncated: bool}
     */
    public function write(string $path, CommercialSmsCampaign $campaign, bool $details, int $cap): array
    {
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Recipients');

        $headings = self::HEADINGS;

        if (! config('gwl.commercial_sms_delivery_reports')) {
            $headings = array_values(array_diff($headings, ['Delivered at']));
        }

        $writer->addRow($this->row($details ? [...$headings, ...self::PII_HEADINGS] : $headings));

        $written = 0;
        $after = null;
        $truncated = false;

        do {
            $page = $this->recipients($campaign, null, $after, $details, 2000);

            foreach ($page['rows'] as $row) {
                if ($written >= $cap) {
                    $truncated = true;

                    break 2;
                }

                $cells = [$row['account_no'], $row['district'], $row['status_label'], $row['reason'], $row['submitted_at'] ? (string) $row['submitted_at'] : ''];

                if (config('gwl.commercial_sms_delivery_reports')) {
                    $cells[] = $row['delivered_at'] ? (string) $row['delivered_at'] : '';
                }

                if ($details) {
                    array_push($cells, (string) ($row['name'] ?? ''), (string) ($row['mobile'] ?? ''));
                }

                $writer->addRow($this->row($cells));
                $written++;
            }

            $after = $page['next'];
        } while ($after !== null);

        if ($truncated) {
            $writer->addRow($this->row(['Limited to the first '.number_format($cap).' rows (the export row cap).']));
        }

        $writer->close();

        return ['rows' => $written, 'truncated' => $truncated];
    }

    protected function row(array $values): Row
    {
        return new Row(array_map(fn ($value) => is_string($value) ? new StringCell($value, null) : Cell::fromValue($value), $values));
    }
}
