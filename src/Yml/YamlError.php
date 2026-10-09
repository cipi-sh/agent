<?php

namespace Cipi\Agent\Yml;

final class YamlError extends \RuntimeException
{
    public function __construct(int $line, string $message)
    {
        parent::__construct("line {$line}: {$message}");
        $this->line = $line;
    }
}
