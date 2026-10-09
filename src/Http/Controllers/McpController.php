<?php

namespace Cipi\Agent\Http\Controllers;

use Cipi\Agent\Mcp\Context;
use Cipi\Agent\Mcp\Protocol;
use Cipi\Agent\Mcp\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Streamable HTTP transport for the MCP server: one POST endpoint, JSON
 * responses, 202 for notifications, 405 for GET/DELETE (no server-initiated
 * streams or sessions), Origin validation, and the MCP-Protocol-Version /
 * Mcp-Method / Mcp-Name header checks of the 2026-07-28 revision. Legacy
 * clients (initialize handshake, JSON-RPC batches) keep working unchanged.
 */
class McpController extends Controller
{
    public function __construct(private readonly Server $server) {}

    public function handle(Request $request): HttpResponse
    {
        if (! $request->isMethod('POST')) {
            return $this->json(Server::error(null, Protocol::INVALID_REQUEST, 'Method Not Allowed: the MCP endpoint accepts POST only (no SSE stream, no sessions).'), 405)
                ->header('Allow', 'POST');
        }

        if (($denied = $this->checkOrigin($request)) !== null) {
            return $denied;
        }

        $raw = $request->getContent();
        $body = $raw === '' ? null : json_decode($raw, true);
        if (! is_array($body) || $body === []) {
            return $this->json(Server::error(null, Protocol::PARSE_ERROR, 'Parse error: empty or invalid JSON'), 400);
        }

        $headerVersion = $request->header('MCP-Protocol-Version');
        if ($headerVersion !== null && ! Protocol::isSupported($headerVersion)) {
            $id = array_is_list($body) ? null : ($body['id'] ?? null);

            return $this->json(Server::unsupportedVersion($id, $headerVersion), 400);
        }

        $context = new Context($request, $headerVersion !== null && Protocol::isSupported($headerVersion) ? $headerVersion : Protocol::LATEST_LEGACY);

        // JSON-RPC batch (allowed up to protocol 2025-03-26; later revisions send one message per POST).
        if (array_is_list($body)) {
            $responses = [];
            foreach ($body as $message) {
                if (! is_array($message)) {
                    $responses[] = Server::error(null, Protocol::INVALID_REQUEST, 'Invalid Request');

                    continue;
                }
                $response = $this->server->handle($message, $context);
                if ($response !== null) {
                    $responses[] = $response;
                }
            }

            return $responses === [] ? $this->accepted() : $this->json($responses, 200);
        }

        if (($mismatch = $this->checkMirroredHeaders($request, $body)) !== null) {
            return $this->json($mismatch, 400);
        }

        $response = $this->server->handle($body, $context);
        if ($response === null) {
            return $this->accepted();
        }

        return $this->json($response, $this->statusFor($response, $body));
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $message
     */
    private function statusFor(array $response, array $message): int
    {
        $code = $response['error']['code'] ?? null;
        if ($code === null) {
            return 200;
        }
        $modern = isset($message['params']['_meta'][Protocol::META_VERSION]);
        if (in_array($code, [Protocol::UNSUPPORTED_PROTOCOL_VERSION, Protocol::HEADER_MISMATCH, Protocol::MISSING_CLIENT_CAPABILITY, Protocol::PARSE_ERROR, Protocol::INVALID_REQUEST], true)) {
            return 400;
        }
        if ($modern && $code === Protocol::INVALID_PARAMS && str_starts_with((string) ($response['error']['message'] ?? ''), 'Invalid params: _meta.')) {
            return 400;
        }
        if ($modern && $code === Protocol::METHOD_NOT_FOUND) {
            return 404;
        }

        return 200;
    }

    /**
     * Origin is only present on browser-initiated requests; a value that is
     * not this app (or an allowed origin) means DNS rebinding or a stray web
     * page talking to the endpoint.
     */
    private function checkOrigin(Request $request): ?JsonResponse
    {
        $origin = $request->header('Origin');
        if ($origin === null || $origin === '' || $origin === 'null') {
            return null;
        }
        $allowed = config('cipi.mcp_allowed_origins');
        $allowed = is_string($allowed) ? array_filter(array_map('trim', explode(',', $allowed))) : (array) $allowed;
        if (in_array('*', $allowed, true)) {
            return null;
        }
        $hosts = array_map(fn (string $o) => strtolower((string) (parse_url($o, PHP_URL_HOST) ?: $o)), $allowed);
        $hosts[] = strtolower($request->getHost());
        if (($appHost = parse_url((string) config('app.url'), PHP_URL_HOST)) !== null && $appHost !== false) {
            $hosts[] = strtolower($appHost);
        }
        $originHost = strtolower((string) parse_url($origin, PHP_URL_HOST));
        if ($originHost !== '' && in_array($originHost, $hosts, true)) {
            return null;
        }

        return $this->json(Server::error(null, Protocol::INVALID_REQUEST, 'Forbidden: Origin not allowed'), 403);
    }

    /**
     * 2026-07-28 mirrors `method` and `params.name`/`params.uri` into HTTP
     * headers so proxies can route on them; when present they must match the
     * body, or the request is rejected with HeaderMismatch.
     *
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>|null
     */
    private function checkMirroredHeaders(Request $request, array $message): ?array
    {
        $id = $message['id'] ?? null;
        $meta = $message['params']['_meta'] ?? null;
        $bodyVersion = is_array($meta) ? ($meta[Protocol::META_VERSION] ?? null) : null;
        $headerVersion = $request->header('MCP-Protocol-Version');

        if (is_string($bodyVersion) && $headerVersion !== null && $headerVersion !== $bodyVersion) {
            return Server::error($id, Protocol::HEADER_MISMATCH, "Header mismatch: MCP-Protocol-Version header '{$headerVersion}' does not match body value '{$bodyVersion}'");
        }

        $method = $request->header('Mcp-Method');
        if ($method !== null && $method !== ($message['method'] ?? null)) {
            return Server::error($id, Protocol::HEADER_MISMATCH, "Header mismatch: Mcp-Method header '{$method}' does not match body method '".($message['method'] ?? '')."'");
        }

        $name = $request->header('Mcp-Name');
        if ($name !== null) {
            $name = self::decodeHeaderValue($name);
            $bodyName = $message['params']['name'] ?? $message['params']['uri'] ?? null;
            if ($name !== $bodyName) {
                return Server::error($id, Protocol::HEADER_MISMATCH, "Header mismatch: Mcp-Name header '{$name}' does not match body value '".($bodyName ?? '')."'");
            }
        }

        return null;
    }

    private static function decodeHeaderValue(string $value): string
    {
        if (preg_match('/^=\?base64\?(.*)\?=$/', $value, $m)) {
            $decoded = base64_decode($m[1], true);

            return $decoded === false ? $value : $decoded;
        }

        return $value;
    }

    private function accepted(): Response
    {
        return response('', 202)->header('Cache-Control', 'no-store');
    }

    /** @param array<mixed> $payload */
    private function json(array $payload, int $status): JsonResponse
    {
        return response()->json($payload, $status, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ->header('Cache-Control', 'no-store');
    }
}
