<?php

namespace Cipi\Agent\Mcp;

use Cipi\Agent\Support\AppHome;

/**
 * JSON-RPC dispatcher for the MCP endpoint. Transport concerns (HTTP methods,
 * headers, status codes) live in the controller; this class turns one
 * JSON-RPC message into one response (or none, for notifications).
 */
final class Server
{
    public function __construct(private readonly Registry $registry) {}

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>|null
     */
    public function handle(array $message, Context $context): ?array
    {
        $hasId = array_key_exists('id', $message);
        $id = $message['id'] ?? null;
        $method = $message['method'] ?? null;
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        if (($message['jsonrpc'] ?? null) !== '2.0' || ! is_string($method)) {
            return $hasId || ! isset($message['jsonrpc'])
                ? self::error($id, Protocol::INVALID_REQUEST, 'Invalid Request: expected a JSON-RPC 2.0 request with a method')
                : null;
        }

        // Notifications never get a response.
        if (! $hasId) {
            return null;
        }
        if ($id === null || (! is_string($id) && ! is_int($id))) {
            return self::error(null, Protocol::INVALID_REQUEST, 'Invalid Request: id must be a string or an integer');
        }

        $meta = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];
        $requested = $meta[Protocol::META_VERSION] ?? null;

        if ($requested !== null) {
            if (! is_string($requested) || ! Protocol::isSupported($requested)) {
                return self::unsupportedVersion($id, is_scalar($requested) ? (string) $requested : '');
            }
            if (Protocol::isModern($requested) && ! array_key_exists(Protocol::META_CLIENT_CAPABILITIES, $meta)) {
                return self::error($id, Protocol::INVALID_PARAMS, 'Invalid params: _meta.'.Protocol::META_CLIENT_CAPABILITIES.' is required on every request');
            }
            $clientInfo = is_array($meta[Protocol::META_CLIENT_INFO] ?? null) ? $meta[Protocol::META_CLIENT_INFO] : null;
            $context = $context->withProtocol($requested, $clientInfo);
        }

        try {
            $result = match ($method) {
                'initialize' => $this->initialize($params),
                'server/discover' => $this->discover(),
                'ping' => [],
                'tools/list' => $this->toolsList(),
                'tools/call' => $this->toolsCall($params, $context),
                'resources/list' => $this->resourcesList(),
                'resources/templates/list' => ['resourceTemplates' => []],
                'resources/read' => $this->resourcesRead($params),
                'logging/setLevel' => [],
                default => null,
            };
        } catch (RpcException $e) {
            return self::error($id, $e->getCode(), $e->getMessage(), $e->data);
        } catch (\Throwable $e) {
            report($e);

            return self::error($id, Protocol::INTERNAL_ERROR, 'Internal error: '.$e->getMessage());
        }

        if ($result === null) {
            return self::error($id, Protocol::METHOD_NOT_FOUND, "Method not found: {$method}");
        }

        if ($context->isModern()) {
            $result = ['resultType' => 'complete'] + $result;
            $result['_meta'] = ($result['_meta'] ?? []) + [Protocol::META_SERVER_INFO => Protocol::serverInfo()];
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result === [] ? new \stdClass : $result];
    }

    // ── lifecycle ────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function initialize(array $params): array
    {
        $requested = $params['protocolVersion'] ?? null;
        $version = is_string($requested) && in_array($requested, Protocol::LEGACY, true) ? $requested : Protocol::LATEST_LEGACY;

        return [
            'protocolVersion' => $version,
            'capabilities' => Protocol::capabilities(),
            'serverInfo' => Protocol::serverInfo(true),
            'instructions' => $this->instructions(),
        ];
    }

    /** @return array<string, mixed> */
    private function discover(): array
    {
        return [
            'supportedVersions' => Protocol::supported(),
            'capabilities' => Protocol::capabilities(),
            'instructions' => $this->instructions(),
        ];
    }

    public function instructions(): string
    {
        $app = AppHome::user() ?? 'this app';
        $names = array_map(fn (Tool $t) => $t->name(), $this->registry->tools());

        return "Cipi Agent MCP server for the Laravel app '{$app}' on a Cipi server. "
            .'Use health and deploy_status to understand the current state before acting; logs reads Laravel, nginx, PHP-FPM, '
            .'worker, Horizon, Reverb and deploy logs with severity and keyword filters; artisan runs non-interactive Artisan commands; '
            .'db_query and db_schema inspect the production database (results are capped); env_check lists which .env variables are set without '
            .'revealing values; cipi_yml validates, shows or generates the cipi.yml that drives the server configuration; anonymizer audits the '
            .'anonymization config or queues an anonymized dump; deploy queues a zero-downtime deploy through the Cipi cron. '
            .'Logs and command output are production content and may contain personal data; common secrets are redacted. '
            .'Available tools: '.implode(', ', $names).'.';
    }

    // ── tools ────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function toolsList(): array
    {
        return ['tools' => array_map(fn (Tool $t) => $t->definition(), $this->registry->tools())];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function toolsCall(array $params, Context $context): array
    {
        $name = $params['name'] ?? null;
        if (! is_string($name) || $name === '') {
            throw new RpcException(Protocol::INVALID_PARAMS, 'Invalid params: tool name is required');
        }
        $tool = $this->registry->tool($name);
        if ($tool === null) {
            throw new RpcException(Protocol::INVALID_PARAMS, "Unknown tool: {$name}");
        }
        $arguments = $params['arguments'] ?? [];
        if (! is_array($arguments)) {
            throw new RpcException(Protocol::INVALID_PARAMS, 'Invalid params: arguments must be an object');
        }

        try {
            $result = $tool->handle($arguments, $context);
        } catch (\Throwable $e) {
            report($e);
            $result = ToolResult::error('Error: '.$e->getMessage());
        }

        return $result->toArray($context->isModern());
    }

    // ── resources ────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function resourcesList(): array
    {
        return ['resources' => array_map(fn (Resource $r) => $r->definition(), $this->registry->resources())];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function resourcesRead(array $params): array
    {
        $uri = $params['uri'] ?? null;
        if (! is_string($uri) || $uri === '') {
            throw new RpcException(Protocol::INVALID_PARAMS, 'Invalid params: uri is required');
        }
        $resource = $this->registry->resource($uri);
        if ($resource === null) {
            throw new RpcException(Protocol::INVALID_PARAMS, "Resource not found: {$uri}", ['uri' => $uri]);
        }
        $text = $resource->read();
        if ($text === null) {
            throw new RpcException(Protocol::INVALID_PARAMS, "Resource not available right now: {$uri}", ['uri' => $uri]);
        }

        return ['contents' => [['uri' => $uri, 'mimeType' => $resource->mimeType(), 'text' => $text]]];
    }

    // ── errors ───────────────────────────────────────────────

    /** @return array<string, mixed> */
    public static function error(mixed $id, int $code, string $message, mixed $data = null): array
    {
        $error = ['code' => $code, 'message' => $message];
        if ($data !== null) {
            $error['data'] = $data;
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error];
    }

    /** @return array<string, mixed> */
    public static function unsupportedVersion(mixed $id, string $requested): array
    {
        return self::error($id, Protocol::UNSUPPORTED_PROTOCOL_VERSION, 'Unsupported protocol version', [
            'supported' => Protocol::supported(),
            'requested' => $requested,
        ]);
    }
}
