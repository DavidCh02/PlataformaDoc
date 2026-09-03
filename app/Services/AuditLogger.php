<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuditLogger
{
    public function log(
        string $action,
        ?Model $auditable = null,
        array $metadata = [],
        ?Request $request = null,
    ): AuditLog {
        $request ??= request();

        return AuditLog::create([
            'user_id' => Auth::id(),
            'action' => $action,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => $metadata ?: null,
        ]);
    }
}
