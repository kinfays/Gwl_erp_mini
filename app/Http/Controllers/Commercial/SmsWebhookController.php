<?php

namespace App\Http\Controllers\Commercial;

use App\Http\Controllers\Controller;
use App\Jobs\Commercial\ApplySmsDeliveryReport;
use App\Services\Commercial\Sms\SmsAccess;
use App\Services\Commercial\Sms\SmsGateway;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /webhooks/sms-arkesel?token=SECRET: the provider's delivery reports. Same shape as the MDM webhook: no session, no CSRF, rate
 * limited, authenticated by a shared secret compared in constant time. It never calls the provider and never touches a customer: each
 * report is reduced to (message id, state, code), the rest is thrown away unread and unstored (it can hold a phone number), and a
 * job applies it. A wrong token is 403; with no token configured, or SMS off, the route answers 404.
 */
class SmsWebhookController extends Controller
{
    private const MAX_REPORTS = 500;

    public function __invoke(Request $request, SmsGateway $gateway): Response
    {
        abort_unless(SmsAccess::enabled() && config('gwl.commercial_sms_delivery_reports'), 404);

        $secret = (string) config('gwl.arkesel.webhook_token');
        abort_if($secret === '', 404);
        abort_unless(hash_equals($secret, (string) $request->query('token', '')), 403);

        $payload = $request->json()->all() ?: $request->all();
        $items = $payload !== [] && array_is_list($payload) ? $payload : [$payload];

        foreach (array_slice($items, 0, self::MAX_REPORTS) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $report = $gateway->deliveryReport($item);

            if ($report && in_array($report->state, ['delivered', 'failed'], true)) {
                ApplySmsDeliveryReport::dispatch($report->messageId, $report->state, $report->code);
            }
        }

        return response()->noContent();
    }
}
