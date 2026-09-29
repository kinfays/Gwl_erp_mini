<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MdmEvent;
use App\Services\Assets\Mdm\EventIntakeService;
use App\Services\Assets\Mdm\Exceptions\MalformedNotificationException;
use App\Services\Assets\Mdm\Exceptions\PushAuthenticationException;
use App\Services\Assets\Mdm\PubSubPushVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * POST /webhooks/android-management — Google Pub/Sub push delivery of Android Management notifications.
 *
 * Authenticated by Google's OIDC token plus a secret ?token= (not the api_token check the agent endpoints use).
 * The request only verifies, stores and queues: it never calls Google and does no device work, so it can answer 204
 * in a few milliseconds. Duplicates also answer 204 so Pub/Sub stops retrying; only a genuinely malformed body is a 400.
 */
class AndroidManagementWebhookController extends Controller
{
    public function __invoke(Request $request, PubSubPushVerifier $verifier, EventIntakeService $intake): Response|JsonResponse
    {
        try {
            $verifier->verify($request);
        } catch (PushAuthenticationException $e) {
            // Reason slug + caller IP only: never the payload, the JWT or the query token.
            Log::warning('MDM push notification rejected.', ['reason' => $e->reason, 'ip' => $request->ip()]);

            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $message = $request->json('message');

        $messageId = is_array($message) ? ($message['messageId'] ?? $message['message_id'] ?? null) : null;
        $data = is_array($message) ? ($message['data'] ?? null) : null;
        $attributes = is_array($message) && is_array($message['attributes'] ?? null) ? $message['attributes'] : [];

        if ((! is_string($messageId) && ! is_int($messageId)) || ! is_string($data)) {
            return response()->json(['message' => 'Malformed Pub/Sub message.'], 400);
        }

        try {
            $intake->ingest(
                (string) $messageId,
                MdmEvent::MODE_PUSH,
                $data,
                array_map('strval', array_filter($attributes, 'is_scalar')),
                is_string($message['publishTime'] ?? null) ? $message['publishTime'] : null,
            );
        } catch (MalformedNotificationException) {
            return response()->json(['message' => 'Malformed Pub/Sub message.'], 400);
        }

        return response()->noContent();
    }
}
