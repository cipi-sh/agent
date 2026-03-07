<?php

namespace Cipi\Agent\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyHealthToken
{
    public function handle(Request $request, Closure $next): Response
    {
        // Falls back to webhook token for backward compatibility
        $token = config('cipi.health_token') ?: config('cipi.webhook_token', '');

        if (empty($token)) {
            return response()->json(['error' => 'Health check token not configured'], 500);
        }

        // Check Bearer token
        if ($bearer = $request->bearerToken()) {
            if (hash_equals($token, $bearer)) {
                return $next($request);
            }
        }

        // Fallback to query string token (for compatibility)
        if ($request->query('token') && hash_equals($token, $request->query('token'))) {
            return $next($request);
        }

        return response()->json(['error' => 'Invalid health check token'], 403);
    }
}
