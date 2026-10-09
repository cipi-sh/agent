<?php

namespace Cipi\Agent\Mcp\Tools;

use Cipi\Agent\Mcp\Context;
use Cipi\Agent\Mcp\Tool;
use Cipi\Agent\Mcp\ToolResult;
use Cipi\Agent\Support\Redactor;
use Illuminate\Support\Facades\Artisan;

final class ArtisanTool extends Tool
{
    /** Interactive or long-running commands that would hang a web request, and the ones that destroy data outright. */
    public const DEFAULT_BLOCKED = [
        'serve', 'tinker', 'pail', 'queue:work', 'queue:listen', 'schedule:work', 'horizon', 'horizon:work',
        'horizon:supervisor', 'octane:start', 'reverb:start', 'db', 'db:wipe', 'migrate:fresh', 'migrate:reset',
    ];

    public function name(): string
    {
        return 'artisan';
    }

    public function title(): string
    {
        return 'Run Artisan command';
    }

    public function description(): string
    {
        return 'Run an Artisan command in this application (e.g. "migrate:status", "queue:failed", "cache:clear", "about"). Runs synchronously inside the web request: interactive and long-running commands (serve, tinker, queue:work, horizon, …) and data-destroying ones (db:wipe, migrate:fresh, migrate:reset) are blocked. Output is production content; common secrets are redacted.';
    }

    public function inputSchema(): array
    {
        return self::schema([
            'command' => ['type' => 'string', 'description' => 'The command and its arguments, without "php artisan" (e.g. "migrate:status", "queue:retry all").', 'maxLength' => 500],
        ], ['command']);
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => false];
    }

    /** @return list<string> */
    public static function blocked(): array
    {
        $extra = config('cipi.mcp_blocked_artisan', []);

        return array_values(array_unique([...self::DEFAULT_BLOCKED, ...(is_array($extra) ? $extra : [])]));
    }

    public function handle(array $arguments, Context $context): ToolResult
    {
        $command = self::str($arguments, 'command', '') ?? '';
        if ($command === '') {
            return ToolResult::error('The "command" argument is required.');
        }
        if (preg_match('/[;&|`$><]/', $command)) {
            return ToolResult::error('Shell operators are not allowed: pass a single Artisan command and its options.');
        }
        $name = strtok($command, ' ') ?: $command;
        if (in_array($name, self::blocked(), true)) {
            return ToolResult::error("'{$name}' is blocked: interactive, long-running and data-destroying commands cannot run via MCP. Blocked: ".implode(', ', self::blocked()));
        }
        if (preg_match('/(^|\s)(--no-interaction|-n)(\s|$)/', $command) !== 1) {
            $command .= ' --no-interaction';
        }

        try {
            $exit = Artisan::call($command);
            $output = Artisan::output();
        } catch (\Throwable $e) {
            return ToolResult::error('Error: '.$e->getMessage(), ['command' => $name, 'exit_code' => null]);
        }

        $text = "Exit code: {$exit}\n\n".($output !== '' ? $output : '(no output)');
        $text = Redactor::sensitive($text);
        $structured = ['command' => $name, 'exit_code' => $exit];

        return $exit === 0 ? ToolResult::text($text, $structured) : ToolResult::error($text, $structured);
    }
}
