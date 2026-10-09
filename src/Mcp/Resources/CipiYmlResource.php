<?php

namespace Cipi\Agent\Mcp\Resources;

use Cipi\Agent\Mcp\Resource;
use Cipi\Agent\Yml\Locator;

final class CipiYmlResource extends Resource
{
    public function uri(): string
    {
        return 'cipi://cipi.yml';
    }

    public function name(): string
    {
        return 'cipi.yml';
    }

    public function title(): string
    {
        return 'cipi.yml of this app';
    }

    public function description(): string
    {
        return 'The declarative server configuration committed with the app (first match of: app root, then current/, htdocs/ and shared/ in the app home).';
    }

    public function mimeType(): string
    {
        return 'application/yaml';
    }

    public function read(): ?string
    {
        $file = Locator::find();

        return $file === null ? null : (string) file_get_contents($file);
    }
}
