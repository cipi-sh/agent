<?php

namespace Cipi\Agent\Mcp\Resources;

use Cipi\Agent\Mcp\Resource;
use Cipi\Agent\Support\AppHome;
use Cipi\Agent\Yml\Example;

final class CipiYmlExampleResource extends Resource
{
    public function uri(): string
    {
        return 'cipi://cipi.yml/example';
    }

    public function name(): string
    {
        return 'cipi.yml example';
    }

    public function title(): string
    {
        return 'cipi.yml commented template';
    }

    public function description(): string
    {
        return 'A complete, commented cipi.yml template in this app\'s namespace (same as `cipi yml example <app>`).';
    }

    public function mimeType(): string
    {
        return 'application/yaml';
    }

    public function read(): ?string
    {
        return Example::render(AppHome::user(), parse_url((string) config('app.url'), PHP_URL_HOST) ?: null);
    }
}
