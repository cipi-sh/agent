<?php

namespace Cipi\Agent\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyWebhookToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = config('cipi.webhook_token');

        if (empty($token)) {
            return response()->json(['error' => 'Webhook token not configured'], 500);
        }

        // 1. GitHub: X-Hub-Signature-256 (HMAC SHA256)
        if ($signature = $request->header('X-Hub-Signature-256')) {
            $expected = 'sha256=' . hash_hmac('sha256', $request->getContent(), $token);
            if (hash_equals($expected, $signature)) {
                return $next($request);
            }
        }

        // 2. GitHub legacy: X-Hub-Signature (HMAC SHA1)
        if ($signature = $request->header('X-Hub-Signature')) {
            $expected = 'sha1=' . hash_hmac('sha1', $request->getContent(), $token);
            if (hash_equals($expected, $signature)) {
                return $next($request);
            }
        }

        // 3. GitLab: X-Gitlab-Token (plain token)
        if ($gitlabToken = $request->header('X-Gitlab-Token')) {
            if (hash_equals($token, $gitlabToken)) {
                return $next($request);
            }
        }

        // 4. Bitbucket: X-Hub-Signature (same as GitHub legacy)
        // Already covered by case 2

        // 5. Gitea: X-Gitea-Signature (HMAC SHA256)
        if ($giteaSignature = $request->header('X-Gitea-Signature')) {
            $expected = hash_hmac('sha256', $request->getContent(), $token);
            if (hash_equals($expected, $giteaSignature)) {
                return $next($request);
            }
        }

        // 6. Query string token (for simple/custom setups and health check)
        if ($request->query('token') && hash_equals($token, $request->query('token'))) {
            return $next($request);
        }

        // 1. GitHub: X-Hub-Signature-256 (HMAC SHA256)
        if ($signature = $request->header('X-Hub-Signature-256')) {
            $expected = 'sha256=' . hash_hmac('sha256', $request->getContent(), $token);
            if (hash_equals($expected, $signature)) {
                return $next($request);
            }
        }

        // 2. GitHub legacy: X-Hub-Signature (HMAC SHA1)
        if ($signature = $request->header('X-Hub-Signature')) {
            $expected = 'sha1=' . hash_hmac('sha1', $request->getContent(), $token);
            if (hash_equals($expected, $signature)) {
                return $next($request);
            }
        }

        // 3. GitLab: X-Gitlab-Token (plain token)
        if ($gitlabToken = $request->header('X-Gitlab-Token')) {
            if (hash_equals($token, $gitlabToken)) {
                return $next($request);
            }
        }

        // 4. Bitbucket: X-Hub-Signature (same as GitHub legacy)
        // Already covered by case 2

        // 5. Gitea: X-Gitea-Signature (HMAC SHA256)
        if ($giteaSignature = $request->header('X-Gitea-Signature')) {
            $expected = hash_hmac('sha256', $request->getContent(), $token);
            if (hash_equals($expected, $giteaSignature)) {
                return $next($request);
            }
        }

        // 6. Query string token (for simple/custom setups)
        if ($request->query('token') && hash_equals($token, $request->query('token'))) {
            return $next($request);
        }

        // 7. Bearer token (for health check via curl)
        if ($bearer = $request->bearerToken()) {
            if (hash_equals($token, $bearer)) {
                return $next($request);
            }
        }

        return response()->json(['error' => 'Invalid webhook signature or token'], 403);
    }
}
