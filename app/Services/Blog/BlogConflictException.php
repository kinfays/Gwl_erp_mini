<?php

namespace App\Services\Blog;

use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Raised when an article was saved by someone else (or from another tab) after the editor opened it. The editor shows who
 * and when and lets the author reload or deliberately save over it; nothing is overwritten silently.
 */
class BlogConflictException extends RuntimeException
{
    public function __construct(
        public readonly int $currentVersion,
        public readonly ?string $changedBy,
        public readonly ?Carbon $changedAt,
    ) {
        parent::__construct('This article was changed after you opened it.');
    }
}
