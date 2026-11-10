<?php

namespace App\Services\Finance;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * A short-lived, one-use hand-over between a list screen (where vouchers are ticked for printing) and the print route: the ids go into
 * the cache under a random token tied to the user and the door they were ticked in, so a long id list never rides in a URL and a token is
 * useless to anyone else or a second time. The print route re-checks permission and reach itself; this only carries the selection.
 */
class PrintSelection
{
    private const MINUTES = 10;

    /** @param  list<int>  $ids */
    public function stash(User $user, string $as, array $ids): string
    {
        $token = Str::random(32);

        Cache::put($this->key($user, $as, $token), array_values($ids), now()->addMinutes(self::MINUTES));

        return $token;
    }

    /** @return list<int>|null */
    public function take(User $user, string $as, string $token): ?array
    {
        return Cache::pull($this->key($user, $as, $token));
    }

    private function key(User $user, string $as, string $token): string
    {
        return 'fin-print-selection:'.$as.':'.$user->id.':'.$token;
    }
}
