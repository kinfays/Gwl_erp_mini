<?php

namespace App\Support;

use App\Models\AuditLog;

class Audit
{
    public static function log(
        string $action,
        string $module,
        ?string $targetType = null,
        ?int $targetId = null,
        array $metadata = []
    ): void {
        AuditLog::record(
            action: $action,
            module: $module,
            targetType: $targetType,
            targetId: $targetId,
            metadata: $metadata ?: null
        );
    }
}
