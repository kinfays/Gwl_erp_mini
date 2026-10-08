<?php

namespace App\Services\HealthSafety;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * A short-lived, one-use hand-over between a list screen (where items are ticked) and the PDF download route: the ids go
 * into the cache under a random token tied to the user, so a long id list never rides in a URL and a token is useless to
 * anyone else or a second time. The download re-checks permission and scope itself; this only carries the selection.
 */
class LabelBatch
{
    private const MINUTES = 10;

    /** @param  list<int>  $ids */
    public function stash(User $user, array $ids, string $layout): string
    {
        $token = Str::random(32);

        Cache::put($this->key($user, $token), ['ids' => array_values($ids), 'layout' => $layout], now()->addMinutes(self::MINUTES));

        return $token;
    }

    /** @return array{ids: list<int>, layout: string}|null */
    public function take(User $user, string $token): ?array
    {
        return Cache::pull($this->key($user, $token));
    }

    private function key(User $user, string $token): string
    {
        return 'hs-label-batch:'.$user->id.':'.$token;
    }
}
