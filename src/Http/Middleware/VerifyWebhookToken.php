<?php

namespace Cipi\Agent\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies a Git provider's push webhook with CIPI_WEBHOOK_TOKEN:
 *   GitHub     X-Hub-Signature-256: sha256=HMAC(body)   (X-Hub-Signature: sha1= legacy)
 *   Bitbucket  X-Hub-Signature:     sha256=HMAC(body)
 *   GitLab     X-Gitlab-Token:      token               (Azure DevOps registers the hook the same way)
 *   Gitea      X-Gitea-Signature:   HMAC(body) hex
 * plus `?token=` and a Bearer token for custom setups.
 */
class VerifyWebhookToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) config('cipi.webhook_token', '');

        if ($token === '') {
            return response()->json(['error' => 'Webhook token not configured'], 500);
        }

        $body = $request->getContent();

        if (($sig = $request->header('X-Hub-Signature-256')) !== null) {
            if (hash_equals('sha256='.hash_hmac('sha256', $body, $token), $sig)) {
                return $next($request);
            }
        }

        if (($sig = $request->header('X-Hub-Signature')) !== null) {
            $expected = str_starts_with($sig, 'sha256=')
                ? 'sha256='.hash_hmac('sha256', $body, $token)
                : 'sha1='.hash_hmac('sha1', $body, $token);
            if (hash_equals($expected, $sig)) {
                return $next($request);
            }
        }

        if (($plain = $request->header('X-Gitlab-Token')) !== null && hash_equals($token, $plain)) {
            return $next($request);
        }

        if (($sig = $request->header('X-Gitea-Signature')) !== null && hash_equals(hash_hmac('sha256', $body, $token), $sig)) {
            return $next($request);
        }

        if (($query = $request->query('token')) && is_string($query) && hash_equals($token, $query)) {
            return $next($request);
        }

        if (($bearer = $request->bearerToken()) && hash_equals($token, $bearer)) {
            return $next($request);
        }

        return response()->json(['error' => 'Invalid webhook signature or token'], 403);
    }
}
