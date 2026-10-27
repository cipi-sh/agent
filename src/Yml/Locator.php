<?php

namespace Cipi\Agent\Yml;

use Cipi\Agent\Support\AppHome;
use Cipi\Agent\Support\Environment;

/**
 * Where a cipi.yml is looked for. Inside the application first (the file
 * travels with the code), then the same places the Cipi CLI checks on the
 * server: current/, htdocs/ and shared/ under the app's home. In each place
 * the environment's own file (cipi-<env>.yml, cipi-<env>.yaml) comes before
 * cipi.yml / cipi.yaml, as Cipi 5.8 reads them: a repository deployed to a
 * staging app and a production app carries cipi-staging.yml next to
 * cipi.yml (or cipi-production.yml).
 */
final class Locator
{
    /** @return list<string> */
    public static function candidates(): array
    {
        $names = Environment::ymlNames();
        $paths = array_map(fn (string $name) => base_path($name), $names);

        if (($home = AppHome::base()) !== null) {
            foreach (['current', 'htdocs'] as $dir) {
                foreach ($names as $name) {
                    $paths[] = "{$home}/{$dir}/{$name}";
                }
            }
            // shared/ holds .yml files only, as on the server
            $paths[] = "{$home}/shared/{$names[0]}";
            $paths[] = "{$home}/shared/cipi.yml";
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
