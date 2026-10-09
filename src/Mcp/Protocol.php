<?php

namespace Cipi\Agent\Mcp;

/**
 * Protocol revisions this server speaks.
 *
 * Two eras coexist. "Legacy" revisions (2024-11-05 … 2025-11-25) open with an
 * `initialize` handshake and, over HTTP, may carry a session. The "modern"
 * revision (2026-07-28) is stateless: every request declares its protocol
 * version and client capabilities in `params._meta`, discovery is a plain
 * `server/discover` call, and results carry `resultType`. The server answers
 * each request according to the era the client used for it, so Cursor,
 * Claude Desktop, VS Code and a brand-new client all work on one endpoint.
 */
final class Protocol
{
    public const LEGACY = ['2024-11-05', '2025-03-26', '2025-06-18', '2025-11-25'];

    public const MODERN = ['2026-07-28'];

    public const LATEST_LEGACY = '2025-11-25';

    public const LATEST = '2026-07-28';

    public const SERVER_NAME = 'cipi-agent';

    public const SERVER_TITLE = 'Cipi Agent';

    public const SERVER_VERSION = '2.0.0';

    public const WEBSITE = 'https://cipi.sh/docs/agent';

    public const META_VERSION = 'io.modelcontextprotocol/protocolVersion';

    public const META_CLIENT_INFO = 'io.modelcontextprotocol/clientInfo';

    public const META_CLIENT_CAPABILITIES = 'io.modelcontextprotocol/clientCapabilities';

    public const META_SERVER_INFO = 'io.modelcontextprotocol/serverInfo';

    // JSON-RPC / MCP error codes
    public const PARSE_ERROR = -32700;

    public const INVALID_REQUEST = -32600;

    public const METHOD_NOT_FOUND = -32601;

    public const INVALID_PARAMS = -32602;

    public const INTERNAL_ERROR = -32603;

    public const HEADER_MISMATCH = -32020;

    public const MISSING_CLIENT_CAPABILITY = -32021;

    public const UNSUPPORTED_PROTOCOL_VERSION = -32022;

    /** @return list<string> */
    public static function supported(): array
    {
        return [...self::MODERN, ...array_reverse(self::LEGACY)];
    }

    public static function isSupported(string $version): bool
    {
        return in_array($version, self::LEGACY, true) || in_array($version, self::MODERN, true);
    }

    public static function isModern(string $version): bool
    {
        return in_array($version, self::MODERN, true);
    }

    /** @return array<string, mixed> */
    public static function serverInfo(bool $extended = false): array
    {
        $info = ['name' => self::SERVER_NAME, 'version' => self::SERVER_VERSION];
        if ($extended) {
            $info['title'] = self::SERVER_TITLE;
            $info['description'] = 'Cipi Agent: health, deploys, logs, Artisan, database, cipi.yml and anonymizer tools for one Laravel app on a Cipi server.';
            $info['websiteUrl'] = self::WEBSITE;
        }

        return $info;
    }

    /** @return array<string, mixed> */
    public static function capabilities(): array
    {
        return [
            'tools' => ['listChanged' => false],
            'resources' => ['subscribe' => false, 'listChanged' => false],
        ];
    }
}
