<?php

namespace Cipi\Agent\Mcp\Tools;

use Cipi\Agent\Mcp\Context;
use Cipi\Agent\Mcp\Tool;
use Cipi\Agent\Mcp\ToolResult;
use Cipi\Agent\Support\AppHome;
use Cipi\Agent\Yml\Example;
use Cipi\Agent\Yml\Generator;
use Cipi\Agent\Yml\Locator;
use Cipi\Agent\Yml\Validator;

final class CipiYmlTool extends Tool
{
    public function name(): string
    {
        return 'cipi_yml';
    }

    public function title(): string
    {
        return 'cipi.yml';
    }

    public function description(): string
    {
        return 'Work with cipi.yml, the declarative file that drives the server configuration of this app (PHP version, aliases, workers, Horizon/Reverb, scheduler, healthcheck, deploy recipe and post-deploy steps, redirects, proxies, backups, env.required). Actions: validate (same rules and messages as `cipi yml validate` on the server), show (normalized data), generate (a cipi.yml inferred from this Laravel application: Horizon, Reverb, Scout, scheduler, /up route, .env.example), example (commented template). Nothing is written to disk.';
    }

    public function inputSchema(): array
    {
        return self::schema([
            'action' => ['type' => 'string', 'enum' => ['validate', 'show', 'generate', 'example'], 'default' => 'validate'],
            'file' => ['type' => 'string', 'description' => 'Path of the file to validate/show (inside the app or its home). Default: the cipi.yml found in the app root or on the server.', 'maxLength' => 512],
            'content' => ['type' => 'string', 'description' => 'YAML text to validate/show instead of a file (e.g. a draft before committing).', 'maxLength' => 65536],
        ]);
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, Context $context): ToolResult
    {
        $action = self::str($arguments, 'action', 'validate');
        $app = AppHome::user();

        switch ($action) {
            case 'example':
                $yaml = Example::render($app, parse_url((string) config('app.url'), PHP_URL_HOST) ?: null);

                return ToolResult::text($yaml, ['yaml' => $yaml]);

            case 'generate':
                $g = Generator::generate($app);
                $text = $g['yaml'];
                if (! $g['result']->ok) {
                    $text .= "\n\n# WARNING: the generated file did not pass validation:\n# ".implode("\n# ", $g['result']->errors);
                }

                return ToolResult::text($text, ['yaml' => $g['yaml'], 'facts' => $g['facts'], 'valid' => $g['result']->ok, 'warnings' => $g['result']->warnings]);

            case 'validate':
            case 'show':
                $content = self::str($arguments, 'content');
                if ($content !== null && $content !== '') {
                    $result = Validator::validate($content, $app, '(content)');
                } else {
                    $file = self::str($arguments, 'file');
                    if ($file !== null && $file !== '') {
                        if (! self::allowedPath($file)) {
                            return ToolResult::error('The file must be inside the application or its home directory.');
                        }
                        if (! is_file($file)) {
                            return ToolResult::error("File not found: {$file}");
                        }
                    } else {
                        $file = Locator::find();
                        if ($file === null) {
                            return ToolResult::error('No cipi.yml found. Looked in: '.implode(', ', Locator::candidates()).'. Use action "generate" for a starting point.');
                        }
                    }
                    $result = Validator::validateFile($file, $app);
                }
                $data = json_decode((string) json_encode($result), true);
                if ($action === 'validate') {
                    unset($data['data']);
                    $text = $result->ok
                        ? "cipi.yml is valid".($result->file ? " ({$result->file})" : '').($result->warnings ? "\n\nWarnings:\n- ".implode("\n- ", $result->warnings) : '')
                        : "cipi.yml is NOT valid".($result->file ? " ({$result->file})" : '')."\n\nErrors:\n- ".implode("\n- ", $result->errors);

                    return $result->ok ? ToolResult::text($text, $data) : ToolResult::error($text, $data);
                }

                return $result->ok ? ToolResult::json($data) : ToolResult::error("cipi.yml is NOT valid:\n- ".implode("\n- ", $result->errors), $data);
        }

        return ToolResult::error("Unknown action '{$action}'. Use validate, show, generate or example.");
    }

    private static function allowedPath(string $file): bool
    {
        $real = realpath($file);
        if ($real === false) {
            return false;
        }
        $roots = array_filter([realpath(base_path()), AppHome::base() !== null ? realpath(AppHome::base()) : null]);
        foreach ($roots as $root) {
            if ($root !== false && str_starts_with($real, rtrim($root, '/').'/')) {
                return true;
            }
        }

        return false;
    }
}
