<?php

namespace Cipi\Agent\Mcp;

/**
 * One MCP tool. Subclasses declare name, title, description, JSON Schema
 * input (and optionally output) and behaviour annotations, and implement
 * handle(). Tools are listed in registry order so clients can cache the list.
 */
abstract class Tool
{
    abstract public function name(): string;

    abstract public function title(): string;

    abstract public function description(): string;

    /** @param array<string, mixed> $arguments */
    abstract public function handle(array $arguments, Context $context): ToolResult;

    /** @return array<string, mixed> JSON Schema (2020-12) */
    public function inputSchema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false];
    }

    /** @return array<string, mixed>|null */
    public function outputSchema(): ?array
    {
        return null;
    }

    /**
     * MCP tool annotations. Defaults are the spec's: a tool is assumed to be
     * able to change things until it says otherwise.
     *
     * @return array<string, bool>
     */
    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => false];
    }

    /** Whether the tool is exposed at all in this installation. */
    public function available(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $def = [
            'name' => $this->name(),
            'title' => $this->title(),
            'description' => $this->description(),
            'inputSchema' => $this->inputSchema(),
        ];
        if (($out = $this->outputSchema()) !== null) {
            $def['outputSchema'] = $out;
        }
        $def['annotations'] = ['title' => $this->title()] + $this->annotations();

        return $def;
    }

    // ── helpers for subclasses ───────────────────────────────

    /** @return array<string, mixed> */
    protected static function readOnly(bool $idempotent = true): array
    {
        return ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => $idempotent, 'openWorldHint' => false];
    }

    /** @return array<string, mixed> */
    protected static function schema(array $properties, array $required = []): array
    {
        return [
            'type' => 'object',
            'properties' => $properties === [] ? new \stdClass : $properties,
            'required' => $required,
            'additionalProperties' => false,
        ];
    }

    /** @param array<string, mixed> $arguments */
    protected static function str(array $arguments, string $key, ?string $default = null): ?string
    {
        $v = $arguments[$key] ?? null;
        if ($v === null) {
            return $default;
        }

        return is_scalar($v) ? trim((string) $v) : $default;
    }

    /** @param array<string, mixed> $arguments */
    protected static function int(array $arguments, string $key, int $default, int $min, int $max): int
    {
        $v = $arguments[$key] ?? null;
        if (! is_numeric($v)) {
            return $default;
        }

        return max($min, min($max, (int) $v));
    }

    /** @param array<string, mixed> $arguments */
    protected static function bool(array $arguments, string $key, bool $default): bool
    {
        $v = $arguments[$key] ?? null;
        if (is_bool($v)) {
            return $v;
        }
        if (is_string($v)) {
            return filter_var($v, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
        }

        return $default;
    }
}
