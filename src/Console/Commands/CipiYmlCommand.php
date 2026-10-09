<?php

namespace Cipi\Agent\Console\Commands;

use Cipi\Agent\Mcp\Resources\CipiYmlSchemaResource;
use Cipi\Agent\Support\AppHome;
use Cipi\Agent\Yml\Example;
use Cipi\Agent\Yml\Generator;
use Cipi\Agent\Yml\Locator;
use Cipi\Agent\Yml\Result;
use Cipi\Agent\Yml\Validator;
use Illuminate\Console\Command;

class CipiYmlCommand extends Command
{
    protected $signature = 'cipi:yml
        {action=validate : validate | show | generate | example | schema}
        {--file= : cipi.yml to read (default: ./cipi.yml, then the server locations)}
        {--app= : App name for the namespace checks (default: CIPI_APP_USER)}
        {--write : generate / example / schema: write the file instead of printing it}
        {--force : Overwrite an existing file with --write}
        {--json : Machine-readable output}
        {--strict : validate: treat warnings as errors (exit 1)}';

    protected $description = 'Validate, inspect or generate the cipi.yml that drives the server configuration of this app (same rules as `cipi yml validate` on the server)';

    public function handle(): int
    {
        $action = strtolower((string) $this->argument('action'));
        $app = $this->option('app') ?: AppHome::user();

        return match ($action) {
            'validate', 'check' => $this->validate($app, false),
            'show', 'dump' => $this->validate($app, true),
            'generate' => $this->generate($app),
            'example', 'sample' => $this->example($app),
            'schema' => $this->schema(),
            default => $this->failWith("Unknown action '{$action}'. Use: validate, show, generate, example, schema"),
        };
    }

    private function validate(?string $app, bool $show): int
    {
        $file = $this->option('file');
        if ($file) {
            if (! is_file($file)) {
                return $this->failWith("File not found: {$file}");
            }
        } else {
            $file = Locator::find();
            if ($file === null) {
                $this->components->error('No cipi.yml found. Looked in:');
                foreach (Locator::candidates() as $c) {
                    $this->line("    {$c}");
                }
                $this->newLine();
                $this->line('  Start from this application:   <fg=cyan>php artisan cipi:yml generate --write</>');
                $this->line('  Or from a commented template:  <fg=cyan>php artisan cipi:yml example --write</>');

                return self::FAILURE;
            }
        }

        $result = Validator::validateFile($file, $app);
        if ($result->ok) {
            $result = $result->withWarnings($this->envRequiredWarnings($result));
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $result->ok && ! ($this->option('strict') && $result->warnings !== []) ? self::SUCCESS : self::FAILURE;
        }

        if (! $result->ok) {
            $this->components->error("cipi.yml is not valid ({$file}):");
            foreach ($result->errors as $e) {
                $this->line("    <fg=red>✗</> {$e}");
            }
            $this->newLine();

            return self::FAILURE;
        }

        if ($show) {
            $this->line((string) json_encode($result->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->components->info("cipi.yml is valid ({$file})");
        }

        foreach ($result->warnings as $w) {
            $this->components->warn($w);
        }

        if ($app !== null && ! $show) {
            $this->line("  <fg=gray>See what applying it would change on the server: cipi yml plan {$app}</>");
        }

        return $this->option('strict') && $result->warnings !== [] ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The server blocks a plan while an env.required variable is missing or
     * empty in the app's .env; say so before the deploy does.
     *
     * @return list<string>
     */
    private function envRequiredWarnings(Result $result): array
    {
        $required = $result->data['env']['required'] ?? [];
        if ($required === []) {
            return [];
        }
        $envPath = app()->environmentFilePath();
        if (! is_file($envPath)) {
            return ["env.required: no .env found at {$envPath} to check the required variables against"];
        }
        $env = (string) file_get_contents($envPath);
        $warnings = [];
        foreach ($required as $key) {
            if (! preg_match('/^'.preg_quote($key, '/').'=..*/m', $env)) {
                $warnings[] = "env.required: {$key} is not set in .env — the server blocks the plan while it is missing";
            }
        }

        return $warnings;
    }

    private function generate(?string $app): int
    {
        $g = Generator::generate($app);

        if (! $g['result']->ok) {
            $this->components->warn('The generated file did not pass validation — please report this:');
            foreach ($g['result']->errors as $e) {
                $this->line("    {$e}");
            }
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(['yaml' => $g['yaml'], 'facts' => $g['facts'], 'valid' => $g['result']->ok], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if ($app === null) {
            $this->components->warn('CIPI_APP_USER is not set: database and backup examples use "myapp" — pass --app=<name> to use the real app name.');
        }

        return $this->output($g['yaml'], Locator::target(), 'cipi.yml generated from this application', "Review it, then validate with: php artisan cipi:yml validate");
    }

    private function example(?string $app): int
    {
        $domain = parse_url((string) config('app.url'), PHP_URL_HOST) ?: null;

        return $this->output(Example::render($app, $domain), Locator::target(), 'cipi.yml template', 'Edit it to match your app, then: php artisan cipi:yml validate');
    }

    private function schema(): int
    {
        $path = CipiYmlSchemaResource::path();
        if (! is_file($path)) {
            return $this->failWith("Schema file missing from the package: {$path}");
        }
        $json = (string) file_get_contents($path);

        return $this->output($json, base_path('cipi.schema.json'), 'cipi.yml JSON Schema', 'Point your editor at it: add "# yaml-language-server: $schema=./cipi.schema.json" as the first line of cipi.yml');
    }

    private function output(string $content, string $target, string $what, string $hint): int
    {
        if (! $this->option('write')) {
            $this->line($content);

            return self::SUCCESS;
        }
        if (is_file($target) && ! $this->option('force')) {
            $this->components->warn("File already exists: {$target}");
            $this->line('  Use <fg=yellow>--force</> to overwrite it.');

            return self::FAILURE;
        }
        if (file_put_contents($target, rtrim($content)."\n") === false) {
            return $this->failWith("Could not write {$target}");
        }
        $this->components->info("{$what} written to {$target}");
        $this->line("  <fg=gray>{$hint}</>");

        return self::SUCCESS;
    }

    private function failWith(string $message): int
    {
        $this->components->error($message);

        return self::FAILURE;
    }
}
