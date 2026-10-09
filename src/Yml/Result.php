<?php

namespace Cipi\Agent\Yml;

final class Result implements \JsonSerializable
{
    /**
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     * @param  array<string, mixed>|null  $data
     */
    public function __construct(
        public readonly bool $ok,
        public readonly array $errors = [],
        public readonly array $warnings = [],
        public readonly ?array $data = null,
        public readonly ?string $file = null,
    ) {}

    /** @param list<string> $errors */
    public static function failure(array $errors, array $warnings = [], ?string $file = null): self
    {
        return new self(false, $errors, $warnings, null, $file);
    }

    /** @param array<string, mixed> $data */
    public static function success(array $data, array $warnings = [], ?string $file = null): self
    {
        return new self(true, [], $warnings, $data, $file);
    }

    public function withWarnings(array $warnings): self
    {
        return new self($this->ok, $this->errors, [...$this->warnings, ...$warnings], $this->data, $this->file);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $out = ['ok' => $this->ok];
        if ($this->file !== null) {
            $out['file'] = $this->file;
        }
        if ($this->ok) {
            $out['data'] = $this->data;
        } else {
            $out['errors'] = $this->errors;
        }
        $out['warnings'] = $this->warnings;

        return $out;
    }
}
