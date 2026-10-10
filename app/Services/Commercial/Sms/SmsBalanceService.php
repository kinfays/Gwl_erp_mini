<?php

namespace App\Services\Commercial\Sms;

use App\Models\CommercialReminderState;
use App\Models\User;
use App\Notifications\GeneralDatabaseNotification;
use Illuminate\Support\Facades\Cache;

/**
 * The SMS credit balance as the screens and the sender see it. Cached (gwl.commercial_sms_balance_cache_seconds, default 5
 * minutes); a Refresh bypasses the cache but is itself throttled (30 s). An unreachable provider is "unavailable", never
 * zero, and is cached only briefly so the page recovers quickly. The API key never appears here.
 */
class SmsBalanceService
{
    public const CACHE_KEY = 'commercial:sms:balance';

    public const REFRESH_LOCK = 'commercial:sms:balance:refresh';

    public function __construct(protected SmsGateway $gateway)
    {
    }

    /** @return array{available: bool, credits: int|null, reason: string|null, at: string|null, low: bool} */
    public function current(bool $refresh = false): array
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached) && ! ($refresh && $this->mayRefresh())) {
            return $this->shape($cached);
        }

        $balance = $this->gateway->balance();
        $stored = ['available' => $balance->available, 'credits' => $balance->credits, 'reason' => $balance->reason, 'at' => now()->toIso8601String()];

        Cache::put(self::CACHE_KEY, $stored, $balance->available ? max(1, (int) config('gwl.commercial_sms_balance_cache_seconds', 300)) : 30);

        return $this->shape($stored);
    }

    /** One refresh per 30 seconds, whoever presses the button. */
    protected function mayRefresh(): bool
    {
        return Cache::add(self::REFRESH_LOCK, 1, 30);
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** @param  array<string, mixed>  $stored */
    protected function shape(array $stored): array
    {
        $credits = $stored['credits'] ?? null;

        return [
            'available' => (bool) ($stored['available'] ?? false),
            'credits' => $credits === null ? null : (int) $credits,
            'reason' => $stored['reason'] ?? null,
            'at' => $stored['at'] ?? null,
            'low' => $credits !== null && (int) $credits < (int) config('gwl.commercial_sms_low_balance', 200),
        ];
    }

    /**
     * May a campaign needing $credits (an ESTIMATE: recipients x segments) go out? An unavailable balance blocks the send: we
     * would be spending blind.
     *
     * @return array{ok: bool, reason: string|null}
     */
    public function covers(int $credits, bool $fresh = false): array
    {
        $balance = $this->current($fresh);

        if (! $balance['available']) {
            return ['ok' => false, 'reason' => 'The SMS balance cannot be read right now, so the cost cannot be checked. Try again in a moment.'];
        }

        if ($credits > (int) $balance['credits']) {
            return ['ok' => false, 'reason' => 'This campaign needs about '.number_format($credits).' credits (an estimate: recipients x message parts) and the balance is '.number_format((int) $balance['credits']).'.'];
        }

        return ['ok' => true, 'reason' => null];
    }

    /**
     * The daily check: below the configured level, tell the officers and super_admins of the locked region(s) in-app, at most
     * once per commercial_reminder_repeat_days. Returns what happened (counts only).
     *
     * @return array{status: string, notified: int}
     */
    public function checkLowBalance(bool $dryRun = false): array
    {
        if (! SmsAccess::enabled()) {
            return ['status' => 'off', 'notified' => 0];
        }

        $balance = $this->current(true);

        if (! $balance['available']) {
            return ['status' => 'unavailable', 'notified' => 0];
        }

        if (! $balance['low']) {
            return ['status' => 'ok', 'notified' => 0];
        }

        $repeat = max(1, (int) config('gwl.commercial_reminder_repeat_days', 7));
        $notified = 0;
        $skipped = true;

        foreach (SmsAccess::regionIds() as $regionId) {
            $state = CommercialReminderState::query()->where(['region_id' => $regionId, 'report_type' => 'sms_balance'])->first();

            if ($state?->last_reminded_at && $state->last_reminded_at->gt(now()->subDays($repeat))) {
                continue;
            }

            $skipped = false;

            if ($dryRun) {
                continue;
            }

            foreach (User::query()->whereIn('id', SmsAccess::balanceRecipientIds($regionId))->get() as $user) {
                $user->notify(new GeneralDatabaseNotification(
                    'SMS credits are running low',
                    'About '.number_format((int) $balance['credits']).' SMS credits are left (the warning level is '.number_format((int) config('gwl.commercial_sms_low_balance')).'). Top up the Arkesel account before the next campaign.',
                    route('commercial.sms'),
                    'commercial',
                    ['kind' => 'commercial_sms_low_balance', 'region_id' => $regionId],
                ));
                $notified++;
            }

            CommercialReminderState::query()->updateOrCreate(['region_id' => $regionId, 'report_type' => 'sms_balance'], ['last_reminded_at' => now(), 'last_batch_id' => null]);
        }

        return ['status' => $skipped ? 'already_told' : 'low', 'notified' => $notified];
    }
}
