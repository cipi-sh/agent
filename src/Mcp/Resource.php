<?php

namespace Cipi\Agent\Mcp;

/**
 * A readable MCP resource (files the model benefits from seeing whole:
 * cipi.yml, its schema, the anonymization config).
 */
abstract class Resource
{
    abstract public function uri(): string;

    abstract public function name(): string;

    abstract public function title(): string;

    abstract public function description(): string;

    abstract public function mimeType(): string;

    /** The resource text, or null when it does not exist right now. */
    abstract public function read(): ?string;

    public function available(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'uri' => $this->uri(),
            'name' => $this->name(),
            'title' => $this->title(),
            'description' => $this->description(),
            'mimeType' => $this->mimeType(),
        ];
    }
}
