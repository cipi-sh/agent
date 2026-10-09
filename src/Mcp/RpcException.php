<?php

namespace Cipi\Agent\Mcp;

final class RpcException extends \RuntimeException
{
    public function __construct(int $code, string $message, public readonly mixed $data = null)
    {
        parent::__construct($message, $code);
    }
}
