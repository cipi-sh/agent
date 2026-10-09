<?php

namespace Cipi\Agent\Anonymizer;

/**
 * One column's transformation, normalized from the short string form
 * ("fakeEmail", "fixed:acme", "numerify:###") or the object form
 * ({"type": "fakeEmail", "unique": true, "consistent": true}).
 */
final class Spec
{
    public function __construct(
        public readonly string $type,
        public readonly ?string $arg = null,
        public readonly ?bool $unique = null,
        public readonly ?bool $consistent = null,
    ) {}

    /**
     * @param  mixed  $raw
     * @param  list<string>  $errors
     */
    public static function parse(mixed $raw, string $path, array &$errors): ?self
    {
        if (is_string($raw)) {
            $raw = trim($raw);
            if ($raw === '') {
                $errors[] = "{$path}: empty transformation";

                return null;
            }
            [$type, $arg] = array_pad(explode(':', $raw, 2), 2, null);

            return new self(trim($type), $arg);
        }
        if (is_array($raw) && ! array_is_list($raw)) {
            $type = $raw['type'] ?? $raw['transformation'] ?? null;
            if (! is_string($type) || trim($type) === '') {
                $errors[] = "{$path}: 'type' is required";

                return null;
            }
            $arg = $raw['arg'] ?? $raw['value'] ?? $raw['pattern'] ?? $raw['length'] ?? null;
            if ($arg !== null && ! is_scalar($arg)) {
                $errors[] = "{$path}: 'value' must be a scalar";

                return null;
            }
            foreach (['unique', 'consistent'] as $flag) {
                if (array_key_exists($flag, $raw) && ! is_bool($raw[$flag])) {
                    $errors[] = "{$path}: '{$flag}' must be true or false";

                    return null;
                }
            }
            foreach (array_keys($raw) as $k) {
                if (! in_array($k, ['type', 'transformation', 'arg', 'value', 'pattern', 'length', 'unique', 'consistent'], true)) {
                    $errors[] = "{$path}: unknown key '{$k}'";
                }
            }

            return new self(trim($type), $arg === null ? null : (string) $arg, $raw['unique'] ?? null, $raw['consistent'] ?? null);
        }
        $errors[] = "{$path}: expected a transformation name or an object with 'type'";

        return null;
    }

    /** @return string|array<string, mixed> */
    public function toConfig(): string|array
    {
        if ($this->unique === null && $this->consistent === null) {
            return $this->arg === null ? $this->type : "{$this->type}:{$this->arg}";
        }
        $out = ['type' => $this->type];
        if ($this->arg !== null) {
            $out['value'] = $this->arg;
        }
        if ($this->unique !== null) {
            $out['unique'] = $this->unique;
        }
        if ($this->consistent !== null) {
            $out['consistent'] = $this->consistent;
        }

        return $out;
    }
}
