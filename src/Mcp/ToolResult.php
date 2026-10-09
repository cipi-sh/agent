<?php

namespace Cipi\Agent\Mcp;

/**
 * A tools/call result: human-readable text, optional structured data and the
 * isError flag that tells the model the tool ran but could not do what was
 * asked (as opposed to a protocol error).
 */
final class ToolResult
{
    private function __construct(
        public readonly string $text,
        public readonly mixed $structured,
        public readonly bool $isError,
    ) {}

    public static function text(string $text, mixed $structured = null): self
    {
        return new self($text, $structured, false);
    }

    /** @param array<string, mixed>|list<mixed> $data */
    public static function json(array $data): self
    {
        return new self(self::pretty($data), $data, false);
    }

    public static function error(string $message, mixed $structured = null): self
    {
        return new self($message, $structured, true);
    }

    /** @return array<string, mixed> */
    public function toArray(bool $modern): array
    {
        $result = [];
        if ($modern) {
            $result['resultType'] = 'complete';
        }
        $result['content'] = [['type' => 'text', 'text' => $this->text]];
        if ($this->structured !== null) {
            $result['structuredContent'] = $this->structured;
        }
        if ($this->isError) {
            $result['isError'] = true;
        }

        return $result;
    }

    public static function pretty(mixed $data): string
    {
        return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
