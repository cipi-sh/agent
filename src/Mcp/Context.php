<?php

namespace Cipi\Agent\Mcp;

use Illuminate\Http\Request;

/**
 * What a tool may want to know about the call: the HTTP request (client IP
 * for deploy claims), the protocol version in use and, on modern requests,
 * the client's self-reported identity.
 */
final class Context
{
    /**
     * @param  array<string, mixed>|null  $clientInfo
     */
    public function __construct(
        public readonly ?Request $request = null,
        public readonly string $protocolVersion = Protocol::LATEST_LEGACY,
        public readonly ?array $clientInfo = null,
    ) {}

    public function isModern(): bool
    {
        return Protocol::isModern($this->protocolVersion);
    }

    public function ip(): ?string
    {
        return $this->request?->ip();
    }

    public function clientName(): ?string
    {
        $name = $this->clientInfo['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    public function withProtocol(string $version, ?array $clientInfo): self
    {
        return new self($this->request, $version, $clientInfo);
    }
}
