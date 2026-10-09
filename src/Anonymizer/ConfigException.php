<?php

namespace Cipi\Agent\Anonymizer;

final class ConfigException extends \InvalidArgumentException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors, ?string $file = null)
    {
        parent::__construct(($file !== null ? "{$file}: " : '').'invalid anonymization config — '.implode('; ', $errors));
    }
}
