<?php

namespace Cipi\Agent\Anonymizer;

/**
 * Runs mysqldump / pg_dump for a Laravel database connection.
 *
 * The command is started without a shell and the password travels in the
 * environment (MYSQL_PWD / PGPASSWORD), never on the command line where
 * `ps` would show it to every user on the server.
 */
final class Dumper
{
    /**
     * @param  array<string, mixed>  $connection  a config/database.php connection
     * @param  list<string>  $extraArgs
     */
    public function __construct(private readonly array $connection, private readonly array $extraArgs = []) {}

    public static function forConnection(?string $name, array $extraArgs = []): self
    {
        $name = $name ?? (string) config('database.default');
        $connection = config("database.connections.{$name}");
        if (! is_array($connection)) {
            throw new \RuntimeException("Database connection '{$name}' is not configured");
        }

        return new self($connection, $extraArgs);
    }

    public function dialect(): string
    {
        return match ($this->connection['driver'] ?? '') {
            'mysql', 'mariadb' => SqlValues::MYSQL,
            'pgsql' => SqlValues::PGSQL,
            default => throw new \RuntimeException('Unsupported database driver: '.($this->connection['driver'] ?? '(none)').' — the anonymizer supports MySQL/MariaDB and PostgreSQL'),
        };
    }

    public function dump(string $outputPath): void
    {
        [$argv, $env] = $this->dialect() === SqlValues::MYSQL ? $this->mysqlCommand() : $this->postgresCommand();

        $stderr = '';
        $process = proc_open($argv, [
            0 => ['pipe', 'r'],
            1 => ['file', $outputPath, 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, $env + $this->baseEnv());

        if (! is_resource($process)) {
            throw new \RuntimeException('Could not start '.$argv[0]);
        }
        fclose($pipes[0]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        if ($exit !== 0) {
            $stderr = trim(preg_replace('/\s+/', ' ', $stderr) ?? $stderr);
            throw new \RuntimeException(basename($argv[0])." failed with exit code {$exit}".($stderr !== '' ? ": {$stderr}" : ''));
        }
        if (! is_file($outputPath) || filesize($outputPath) === 0) {
            throw new \RuntimeException(basename($argv[0]).' produced an empty dump');
        }
    }

    /** @return array{0: list<string>, 1: array<string, string>} */
    private function mysqlCommand(): array
    {
        $bin = $this->binary(['mysqldump', 'mariadb-dump'], 'CIPI_MYSQLDUMP');
        $c = $this->connection;
        $argv = [$bin, '--single-transaction', '--quick', '--skip-lock-tables', '--routines', '--triggers',
            '--default-character-set='.($c['charset'] ?? 'utf8mb4')];
        if (! empty($c['unix_socket'])) {
            $argv[] = '--socket='.$c['unix_socket'];
        } else {
            $argv[] = '--host='.($c['host'] ?? '127.0.0.1');
            $argv[] = '--port='.($c['port'] ?? 3306);
        }
        $argv[] = '--user='.($c['username'] ?? 'root');
        foreach ($this->extraArgs as $arg) {
            $argv[] = $arg;
        }
        $argv[] = (string) $c['database'];

        return [$argv, ['MYSQL_PWD' => (string) ($c['password'] ?? '')]];
    }

    /** @return array{0: list<string>, 1: array<string, string>} */
    private function postgresCommand(): array
    {
        $bin = $this->binary(['pg_dump'], 'CIPI_PG_DUMP');
        $c = $this->connection;
        $argv = [$bin, '--format=plain', '--no-owner', '--no-privileges', '--clean', '--if-exists', '--encoding=UTF8',
            '--host='.($c['host'] ?? '127.0.0.1'), '--port='.($c['port'] ?? 5432), '--username='.($c['username'] ?? 'postgres')];
        if (! empty($c['search_path']) && is_string($c['search_path']) && ! str_contains($c['search_path'], ',') && $c['search_path'] !== 'public') {
            $argv[] = '--schema='.$c['search_path'];
        }
        foreach ($this->extraArgs as $arg) {
            $argv[] = $arg;
        }
        $argv[] = '--dbname='.$c['database'];

        $env = ['PGPASSWORD' => (string) ($c['password'] ?? '')];
        if (! empty($c['sslmode'])) {
            $env['PGSSLMODE'] = (string) $c['sslmode'];
        }

        return [$argv, $env];
    }

    /** @param list<string> $candidates */
    private function binary(array $candidates, string $envVar): string
    {
        $configured = (string) (config('cipi.anonymizer_binaries.'.$candidates[0]) ?? getenv($envVar) ?: '');
        if ($configured !== '') {
            return $configured;
        }
        $path = getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin';
        foreach ($candidates as $name) {
            foreach (array_merge(explode(PATH_SEPARATOR, $path), ['/usr/local/bin', '/usr/bin', '/opt/homebrew/bin']) as $dir) {
                if ($dir !== '' && is_executable("{$dir}/{$name}")) {
                    return "{$dir}/{$name}";
                }
            }
        }

        return $candidates[0];
    }

    /** @return array<string, string> */
    private function baseEnv(): array
    {
        $env = [];
        foreach (['PATH', 'HOME', 'LANG', 'LC_ALL', 'TMPDIR'] as $k) {
            $v = getenv($k);
            if ($v !== false) {
                $env[$k] = $v;
            }
        }

        return $env;
    }
}
