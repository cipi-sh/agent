<?php

namespace Cipi\Agent\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyAnonymizerToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = config('cipi.anonymizer_token');

        if (empty($token)) {
            return response()->json(['error' => 'Anonymizer token not configured'], 500);
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

        return response()->json(['error' => 'Invalid anonymizer token'], 403);
    }
}
