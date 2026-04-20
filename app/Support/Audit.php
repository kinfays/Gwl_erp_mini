<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class Audit
{
    public static function log(
        string $action,
        string $module,
        string $targetType = null,
        int $targetId = null,
        array $metadata = []
    ): void {
        AuditLog::create([
            'action'      => $action,
            'module'      => $module,
            'target_type' => $targetType,
            'target_id'   => $targetId,
            'user_id'     => Auth::id(),
            'ip_address'  => Request::ip(),
            
        ]);
    }
}
