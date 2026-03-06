<?php

namespace Cipi\Agent\Console\Commands;

use Faker\Factory;
use Faker\Generator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CipiAnonymizeCommand extends Command
{
    protected $signature = 'cipi:anonymize
                           {config : Path to anonymization config JSON file}
                           {output : Output file path for anonymized SQL}
                           {--temp-dir= : Temporary directory for processing}';

    protected $description = 'Create an anonymized database dump based on configuration';

    protected Generator $faker;
    protected array $transformations = [];
    protected string $hashAlgorithm = 'auto';

    public function handle(): int
    {
        $configPath = $this->argument('config');
        $outputPath = $this->argument('output');
        $tempDir = $this->option('temp-dir') ?: sys_get_temp_dir() . '/cipi-anonymize';

        // Validate inputs
        if (!file_exists($configPath)) {
            $this->error("Config file not found: {$configPath}");
            return self::FAILURE;
        }

        // Load configuration
        $config = json_decode(file_get_contents($configPath), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error('Invalid JSON in config file: ' . json_last_error_msg());
            return self::FAILURE;
        }

        $this->transformations = $config['transformations'] ?? [];
        $this->hashAlgorithm = $config['options']['hash_algorithm'] ?? 'auto';
        $fakerLocale = $config['options']['faker_locale'] ?? 'en_US';

        // Initialize Faker
        $this->faker = Factory::create($fakerLocale);

        $this->info('Starting database anonymization...');
        $this->newLine();

        // Create temp directory
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        try {
            // Step 1: Create database dump
            $this->info('Step 1: Creating database dump...');
            $dumpPath = $this->createDatabaseDump($tempDir);

            // Step 2: Apply transformations
            $this->info('Step 2: Applying anonymization transformations...');
            $anonymizedPath = $this->applyTransformations($dumpPath, $tempDir);

            // Step 3: Move to final output
            $this->info('Step 3: Saving anonymized dump...');
            rename($anonymizedPath, $outputPath);

            $this->info('✓ Database anonymization completed successfully!');
            $this->line("  Output: <fg=green>{$outputPath}</>");

            // Cleanup
            $this->cleanupTempFiles($tempDir);

        } catch (\Exception $e) {
            $this->error('Anonymization failed: ' . $e->getMessage());
            $this->cleanupTempFiles($tempDir);
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    protected function createDatabaseDump(string $tempDir): string
    {
        $connection = config('database.default');
        $config = config("database.connections.{$connection}");

        $dumpPath = $tempDir . '/original_dump.sql';

        if ($config['driver'] === 'mysql') {
            return $this->createMySqlDump($config, $dumpPath);
        } elseif ($config['driver'] === 'pgsql') {
            return $this->createPostgresDump($config, $dumpPath);
        } else {
            throw new \Exception("Unsupported database driver: {$config['driver']}");
        }
    }

    protected function createMySqlDump(array $config, string $dumpPath): string
    {
        $host = $config['host'] ?? 'localhost';
        $port = $config['port'] ?? 3306;
        $database = $config['database'];
        $username = $config['username'];
        $password = $config['password'];

        $command = sprintf(
            'mysqldump --host=%s --port=%s --user=%s --password=%s --single-transaction --routines --triggers %s > %s',
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($username),
            escapeshellarg($password),
            escapeshellarg($database),
            escapeshellarg($dumpPath)
        );

        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            throw new \Exception('MySQL dump failed with exit code ' . $exitCode);
        }

        return $dumpPath;
    }

    protected function createPostgresDump(array $config, string $dumpPath): string
    {
        $host = $config['host'] ?? 'localhost';
        $port = $config['port'] ?? 5432;
        $database = $config['database'];
        $username = $config['username'];
        $password = $config['password'];

        $env = [
            'PGPASSWORD' => $password,
            'PGHOST' => $host,
            'PGPORT' => $port,
            'PGUSER' => $username,
        ];

        $command = sprintf(
            'pg_dump --no-owner --no-privileges --clean --if-exists --format=custom %s > %s',
            escapeshellarg($database),
            escapeshellarg($dumpPath)
        );

        $this->executeCommand($command, $env);

        return $dumpPath;
    }

    protected function applyTransformations(string $dumpPath, string $tempDir): string
    {
        $anonymizedPath = $tempDir . '/anonymized_dump.sql';

        $input = fopen($dumpPath, 'r');
        $output = fopen($anonymizedPath, 'w');

        if (!$input || !$output) {
            throw new \Exception('Failed to open dump files for processing');
        }

        $tableName = null;
        $columns = [];
        $columnMap = [];

        while (($line = fgets($input)) !== false) {
            // Detect table creation
            if (preg_match('/^CREATE TABLE `?(\w+)`?/i', $line, $matches)) {
                $tableName = $matches[1];
                $columns = [];
                $columnMap = [];

                if (isset($this->transformations[$tableName])) {
                    $this->info("  Processing table: {$tableName}");
                }
            }

            // Detect column definitions in CREATE TABLE
            elseif ($tableName && preg_match('/^\s*`?(\w+)`?\s+([^,]+)/i', $line, $matches)) {
                $columnName = $matches[1];
                $columns[] = $columnName;

                if (isset($this->transformations[$tableName][$columnName])) {
                    $columnMap[$columnName] = $this->transformations[$tableName][$columnName];
                }
            }

            // Detect INSERT statements
            elseif ($tableName && str_starts_with(trim($line), 'INSERT INTO') && !empty($columnMap)) {
                $line = $this->anonymizeInsertStatement($line, $tableName, $columns, $columnMap);
            }

            // Detect individual INSERT VALUES
            elseif ($tableName && str_starts_with(trim($line), '(') && !empty($columnMap)) {
                $line = $this->anonymizeInsertValues($line, $tableName, $columns, $columnMap);
            }

            fwrite($output, $line);
        }

        fclose($input);
        fclose($output);

        return $anonymizedPath;
    }

    protected function anonymizeInsertStatement(string $line, string $tableName, array $columns, array $columnMap): string
    {
        // Handle INSERT INTO table (col1, col2) VALUES (val1, val2), (val3, val4)
        return preg_replace_callback(
            '/VALUES\s*\(([^)]+)\)/i',
            function ($matches) use ($tableName, $columns, $columnMap) {
                $values = $this->parseInsertValues($matches[1]);
                $anonymizedValues = $this->anonymizeValues($values, $tableName, $columns, $columnMap);
                return 'VALUES (' . implode(', ', $anonymizedValues) . ')';
            },
            $line
        );
    }

    protected function anonymizeInsertValues(string $line, string $tableName, array $columns, array $columnMap): string
    {
        // Handle individual value lines like (val1, val2),
        $line = preg_replace_callback(
            '/^\s*\(([^)]+)\)/',
            function ($matches) use ($tableName, $columns, $columnMap) {
                $values = $this->parseInsertValues($matches[1]);
                $anonymizedValues = $this->anonymizeValues($values, $tableName, $columns, $columnMap);
                return '(' . implode(', ', $anonymizedValues) . ')';
            },
            $line
        );

        return $line;
    }

    protected function parseInsertValues(string $valuesStr): array
    {
        // Simple CSV parser for SQL VALUES
        $values = [];
        $current = '';
        $inQuotes = false;
        $quoteChar = '';

        $chars = str_split($valuesStr);
        foreach ($chars as $char) {
            if (!$inQuotes && ($char === ',')) {
                $values[] = trim($current);
                $current = '';
            } elseif (!$inQuotes && ($char === "'" || $char === '"')) {
                $inQuotes = true;
                $quoteChar = $char;
                $current .= $char;
            } elseif ($inQuotes && $char === $quoteChar) {
                $inQuotes = false;
                $current .= $char;
            } else {
                $current .= $char;
            }
        }

        if (!empty($current)) {
            $values[] = trim($current);
        }

        return $values;
    }

    protected function anonymizeValues(array $values, string $tableName, array $columns, array $columnMap): array
    {
        $anonymized = [];

        foreach ($values as $index => $value) {
            $columnName = $columns[$index] ?? null;

            if ($columnName && isset($columnMap[$columnName])) {
                $transformation = $columnMap[$columnName];
                $anonymized[] = $this->applyTransformation($value, $transformation);
            } else {
                $anonymized[] = $value;
            }
        }

        return $anonymized;
    }

    protected function applyTransformation(string $value, string $transformation): string
    {
        // Handle NULL values
        if (strtoupper($value) === 'NULL') {
            return $value;
        }

        // Remove quotes for processing
        $originalValue = $value;
        $isQuoted = (str_starts_with($value, "'") && str_ends_with($value, "'")) ||
                    (str_starts_with($value, '"') && str_ends_with($value, '"'));

        if ($isQuoted) {
            $value = substr($value, 1, -1);
        }

        // Apply transformation
        $transformedValue = match ($transformation) {
            'fakeName' => $this->faker->name(),
            'fakeFirstName' => $this->faker->firstName(),
            'fakeLastName' => $this->faker->lastName(),
            'fakeEmail' => $this->faker->email(),
            'fakeCompany' => $this->faker->company(),
            'fakeAddress' => $this->faker->address(),
            'fakeCity' => $this->faker->city(),
            'fakePostcode' => $this->faker->postcode(),
            'fakePhoneNumber' => $this->faker->phoneNumber(),
            'fakeDate' => $this->faker->date(),
            'fakeUrl' => $this->faker->url(),
            'fakeParagraph' => $this->faker->paragraph(),
            'password' => $this->hashPassword($value),
            default => $value, // Unknown transformation, keep original
        };

        // Re-quote if necessary
        if ($isQuoted) {
            return "'" . addslashes($transformedValue) . "'";
        }

        return $transformedValue;
    }

    protected function hashPassword(string $plainPassword): string
    {
        if ($this->hashAlgorithm === 'auto') {
            // Use Laravel's configured hash driver
            return Hash::make($plainPassword);
        }

        return match ($this->hashAlgorithm) {
            'bcrypt' => password_hash($plainPassword, PASSWORD_BCRYPT),
            'argon' => password_hash($plainPassword, PASSWORD_ARGON2ID),
            'argon2i' => password_hash($plainPassword, PASSWORD_ARGON2I),
            'argon2d' => password_hash($plainPassword, PASSWORD_ARGON2D),
            default => Hash::make($plainPassword),
        };
    }

    protected function executeCommand(string $command, array $env = []): void
    {
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ], $pipes, null, $env);

        if (!is_resource($process)) {
            throw new \Exception('Failed to execute command: ' . $command);
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new \Exception('Command failed: ' . $stderr);
        }
    }

    protected function cleanupTempFiles(string $tempDir): void
    {
        if (is_dir($tempDir)) {
            $files = glob($tempDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($tempDir);
        }
    }
}