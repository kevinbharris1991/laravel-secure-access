<?php

namespace SecureAccess\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use SecureAccess\Models\CampAudit;
use SecureAccess\SecureAccess;

class AuditAccessChanges
{
    public function handle(Request $request, Closure $next)
    {
        return DB::transaction(function () use ($request, $next) {
            $response = $next($request);
            if ($response->getStatusCode() < 400) {
                $user = SecureAccess::guard()->user();
                $audit = new CampAudit;
                $audit->actor_type = $user->getMorphClass();
                $audit->actor_id = (string) $user->getKey();
                $audit->module = 'access_settings';
                $audit->action = $request->isMethod('GET') ? 'read' : 'update';
                $audit->save();
            }

            return $response;
        });
    }
}
