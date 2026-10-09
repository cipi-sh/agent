<?php

namespace Cipi\Agent\Mcp\Resources;

use Cipi\Agent\Mcp\Resource;

final class CipiYmlSchemaResource extends Resource
{
    public static function path(): string
    {
        return dirname(__DIR__, 3).'/resources/schema/cipi.schema.json';
    }

    public function uri(): string
    {
        return 'cipi://cipi.yml/schema';
    }

    public function name(): string
    {
        return 'cipi.yml JSON Schema';
    }

    public function title(): string
    {
        return 'cipi.yml JSON Schema';
    }

    public function description(): string
    {
        return 'JSON Schema (2020-12) describing every key cipi.yml accepts, with allowed values and bounds. Namespace rules (database and backup profile names) are validated by the cipi_yml tool, not by the schema.';
    }

    public function mimeType(): string
    {
        return 'application/schema+json';
    }

    public function read(): ?string
    {
        return is_file(self::path()) ? (string) file_get_contents(self::path()) : null;
    }
}
