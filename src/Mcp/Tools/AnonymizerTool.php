<?php

namespace Cipi\Agent\Mcp\Tools;

use Cipi\Agent\Anonymizer\Auditor;
use Cipi\Agent\Anonymizer\Config;
use Cipi\Agent\Anonymizer\ConfigException;
use Cipi\Agent\Anonymizer\SchemaInspector;
use Cipi\Agent\Anonymizer\Transformer;
use Cipi\Agent\Jobs\AnonymizeDatabaseJob;
use Cipi\Agent\Mcp\Context;
use Cipi\Agent\Mcp\Tool;
use Cipi\Agent\Mcp\ToolResult;
use Cipi\Agent\Support\AppHome;

final class AnonymizerTool extends Tool
{
    public function name(): string
    {
        return 'anonymizer';
    }

    public function title(): string
    {
        return 'Database anonymizer';
    }

    public function description(): string
    {
        return 'Database anonymizer (MySQL/MariaDB and PostgreSQL). Actions: audit — validate anonymization.json against the live schema and list columns that look like personal data but are not covered; config — show the current config; scan — propose a complete config from the schema; transformations — list the available transformations; start — queue an anonymized dump (requires the anonymizer to be enabled and an e-mail address that receives the signed download link).';
    }

    public function inputSchema(): array
    {
        return self::schema([
            'action' => ['type' => 'string', 'enum' => ['audit', 'config', 'scan', 'transformations', 'start'], 'default' => 'audit'],
            'email' => ['type' => 'string', 'format' => 'email', 'description' => 'For start: who receives the download link.'],
            'gzip' => ['type' => 'boolean', 'description' => 'For start: compress the dump.'],
        ]);
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false];
    }

    public function handle(array $arguments, Context $context): ToolResult
    {
        $action = self::str($arguments, 'action', 'audit');

        if ($action === 'transformations') {
            return ToolResult::json(['transformations' => Transformer::TYPES]);
        }
        if ($action === 'scan') {
            return ToolResult::json((new Auditor(new SchemaInspector))->scan());
        }

        $path = AppHome::anonymizationConfig();
        if ($path === null) {
            return ToolResult::error('No anonymization config found. Looked in: '.implode(', ', AppHome::anonymizationConfigCandidates()).'. Create one with `php artisan cipi:init-anonymize --scan` (or action "scan" here to see a proposal).');
        }
        try {
            $config = Config::load($path);
        } catch (ConfigException $e) {
            return ToolResult::error("Invalid anonymization config ({$path}):\n- ".implode("\n- ", $e->errors), ['errors' => $e->errors]);
        }

        if ($action === 'config') {
            return ToolResult::json(['file' => $path] + $config->toArray());
        }
        if ($action === 'audit') {
            $report = (new Auditor(new SchemaInspector))->audit($config);
            $report['file'] = $path;

            return $report['ok'] ? ToolResult::json($report) : ToolResult::error(ToolResult::pretty($report), $report);
        }
        if ($action === 'start') {
            if (! config('cipi.anonymizer_enabled')) {
                return ToolResult::error('The anonymizer is disabled (CIPI_ANONYMIZER=false). Enable it with `php artisan cipi:service anonymize --enable`.');
            }
            $email = self::str($arguments, 'email');
            if ($email === null || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return ToolResult::error('A valid "email" is required: the download link is sent there.');
            }
            AnonymizeDatabaseJob::dispatch($email, $path, null, isset($arguments['gzip']) ? self::bool($arguments, 'gzip', false) : null);

            return ToolResult::json([
                'status' => 'queued',
                'email' => $email,
                'message' => 'Anonymization queued. A signed download link (valid '.(int) config('cipi.anonymizer_ttl', 15).' minutes) will be e-mailed when the dump is ready. A queue worker must be running.',
            ]);
        }

        return ToolResult::error("Unknown action '{$action}'.");
    }
}
