<?php

namespace Cipi\Agent\Mcp\Resources;

use Cipi\Agent\Mcp\Resource;
use Cipi\Agent\Support\AppHome;

final class AnonymizationConfigResource extends Resource
{
    public function uri(): string
    {
        return 'cipi://anonymization.json';
    }

    public function name(): string
    {
        return 'anonymization.json';
    }

    public function title(): string
    {
        return 'Database anonymization config';
    }

    public function description(): string
    {
        return 'The anonymization.json kept outside the repository (/home/<app>/.db/): which columns are transformed and how, which tables are emptied in the dump.';
    }

    public function mimeType(): string
    {
        return 'application/json';
    }

    public function available(): bool
    {
        return (bool) config('cipi.anonymizer_enabled', false);
    }

    public function read(): ?string
    {
        $file = AppHome::anonymizationConfig();

        return $file === null ? null : (string) file_get_contents($file);
    }
}
