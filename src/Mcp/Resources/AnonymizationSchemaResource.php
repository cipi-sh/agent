<?php

namespace Cipi\Agent\Mcp\Resources;

use Cipi\Agent\Mcp\Resource;

final class AnonymizationSchemaResource extends Resource
{
    public static function path(): string
    {
        return dirname(__DIR__, 3).'/resources/schema/anonymization.schema.json';
    }

    public function uri(): string
    {
        return 'cipi://anonymization.json/schema';
    }

    public function name(): string
    {
        return 'anonymization.json JSON Schema';
    }

    public function title(): string
    {
        return 'anonymization.json JSON Schema';
    }

    public function description(): string
    {
        return 'JSON Schema (2020-12) for anonymization.json: transformations, truncate list and options, including every transformation type.';
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
