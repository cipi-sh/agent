<?php

namespace Cipi\Agent\Yml;

use Cipi\Agent\Support\AppHome;

/**
 * Where a cipi.yml is looked for. Inside the application first (the file
 * travels with the code), then the same places the Cipi CLI checks on the
 * server: current/, htdocs/ and shared/ under the app's home.
 */
final class Locator
{
    /** @return list<string> */
    public static function candidates(): array
    {
        $paths = [
            base_path('cipi.yml'),
            base_path('cipi.yaml'),
        ];

        if (($home = AppHome::base()) !== null) {
            foreach (['current/cipi.yml', 'current/cipi.yaml', 'htdocs/cipi.yml', 'htdocs/cipi.yaml', 'shared/cipi.yml'] as $rel) {
                $paths[] = "{$home}/{$rel}";
            }
        }

        return array_values(array_unique($paths));
    }

    public static function find(): ?string
    {
        foreach (self::candidates() as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /** The path a generated file is written to. */
    public static function target(): string
    {
        return base_path('cipi.yml');
    }
}
