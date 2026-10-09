<?php

namespace Cipi\Agent\Mcp\Tools;

use Cipi\Agent\Mcp\Context;
use Cipi\Agent\Mcp\Tool;
use Cipi\Agent\Mcp\ToolResult;
use Cipi\Agent\Support\AppHome;
use Cipi\Agent\Yml\Locator;
use Cipi\Agent\Yml\Validator;

final class EnvCheckTool extends Tool
{
    public function name(): string
    {
        return 'env_check';
    }

    public function title(): string
    {
        return 'Check .env variables';
    }

    public function description(): string
    {
        return 'Which environment variables are set in the application .env — names only, never values. Compares with .env.example (missing or extra keys), lists keys that are present but empty, checks the `env.required` names of cipi.yml the server will block a deploy on, and flags risky settings (APP_DEBUG in production).';
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, Context $context): ToolResult
    {
        $envPath = app()->environmentFilePath();
        $env = self::parse($envPath);
        $example = self::parse(base_path('.env.example'));

        $required = [];
        $requiredMissing = [];
        if (($yml = Locator::find()) !== null) {
            $result = Validator::validateFile($yml, AppHome::user());
            $required = $result->ok ? ($result->data['env']['required'] ?? []) : [];
            foreach ($required as $key) {
                if (! isset($env[$key]) || $env[$key] === '') {
                    $requiredMissing[] = $key;
                }
            }
        }

        $flags = [];
        if (app()->environment('production') && filter_var($env['APP_DEBUG'] ?? config('app.debug'), FILTER_VALIDATE_BOOL)) {
            $flags[] = 'APP_DEBUG is true in production';
        }
        if (($env['APP_ENV'] ?? null) !== null && $env['APP_ENV'] !== app()->environment()) {
            $flags[] = "APP_ENV in .env ({$env['APP_ENV']}) differs from the running environment (".app()->environment().') — configuration cache may be stale';
        }
        if (! isset($env['APP_KEY']) || $env['APP_KEY'] === '') {
            $flags[] = 'APP_KEY is empty';
        }

        return ToolResult::json([
            'env_file' => $envPath,
            'exists' => is_file($envPath),
            'keys' => count($env),
            'empty' => array_values(array_keys(array_filter($env, fn ($v) => $v === ''))),
            'missing_from_env' => array_values(array_diff(array_keys($example), array_keys($env))),
            'not_in_example' => $example === [] ? [] : array_values(array_diff(array_keys($env), array_keys($example))),
            'cipi_yml_required' => $required,
            'cipi_yml_required_missing' => $requiredMissing,
            'flags' => $flags,
            'config_cached' => app()->configurationIsCached(),
            'note' => 'Values are never exposed through this tool.',
        ]);
    }

    /** @return array<string, string> key => value */
    private static function parse(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }
        $out = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim(preg_replace('/^export\s+/', '', $key) ?? $key);
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                continue;
            }
            $value = trim($value);
            if ($value !== '' && ($value[0] === '"' || $value[0] === "'") && strlen($value) >= 2 && $value[strlen($value) - 1] === $value[0]) {
                $value = substr($value, 1, -1);
            }
            $out[$key] = $value;
        }

        return $out;
    }
}
