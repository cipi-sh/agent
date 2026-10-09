<?php

namespace Cipi\Agent\Anonymizer;

/**
 * The anonymization.json file: which columns get which transformation, which
 * tables are emptied in the dump, and the run options.
 *
 *   {
 *     "transformations": { "users": { "email": "fakeEmail", "password": "password" } },
 *     "truncate": ["sessions", "jobs", "failed_jobs", "password_reset_tokens"],
 *     "options": { "hash_algorithm": "auto", "password": "password", "faker_locale": "en_US",
 *                  "consistent": false, "seed": null, "gzip": false }
 *   }
 */
final class Config
{
    public const OPTION_DEFAULTS = [
        'hash_algorithm' => 'auto',
        'password' => 'password',
        'faker_locale' => 'en_US',
        'consistent' => false,
        'seed' => null,
        'gzip' => false,
        'preserve_ids' => true,
        'connection' => null,
        'dump_args' => [],
    ];

    public const HASH_ALGORITHMS = ['auto', 'bcrypt', 'argon', 'argon2id', 'argon2i', 'argon2d'];

    /**
     * @param  array<string, array<string, Spec>>  $transformations
     * @param  list<string>  $truncate
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public readonly array $transformations,
        public readonly array $truncate,
        public readonly array $options,
        public readonly ?string $file = null,
    ) {}

    public static function load(string $path): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new ConfigException(["cannot read {$path}"]);
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ConfigException(['invalid JSON: '.json_last_error_msg()], $path);
        }
        if (! is_array($data)) {
            throw new ConfigException(['the file must contain a JSON object'], $path);
        }

        return self::fromArray($data, $path);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, ?string $file = null): self
    {
        $errors = [];

        foreach (array_keys($data) as $k) {
            if (! in_array($k, ['transformations', 'truncate', 'options', '$schema', '_comment'], true)) {
                $errors[] = "unknown top-level key '{$k}' (allowed: transformations, truncate, options)";
            }
        }

        $transformations = [];
        $rawT = $data['transformations'] ?? null;
        if (! is_array($rawT) || array_is_list($rawT) && $rawT !== []) {
            $errors[] = "'transformations' must be an object of tables";
        } else {
            foreach ($rawT as $table => $columns) {
                $table = (string) $table;
                if (! preg_match('/^[A-Za-z_][A-Za-z0-9_.$-]{0,127}$/', $table)) {
                    $errors[] = "transformations: invalid table name '{$table}'";

                    continue;
                }
                if (! is_array($columns) || (array_is_list($columns) && $columns !== [])) {
                    $errors[] = "transformations.{$table}: must be an object of columns";

                    continue;
                }
                foreach ($columns as $column => $raw) {
                    $column = (string) $column;
                    if (! preg_match('/^[A-Za-z_][A-Za-z0-9_$-]{0,127}$/', $column)) {
                        $errors[] = "transformations.{$table}: invalid column name '{$column}'";

                        continue;
                    }
                    $spec = Spec::parse($raw, "transformations.{$table}.{$column}", $errors);
                    if ($spec === null) {
                        continue;
                    }
                    if (! Transformer::supports($spec->type)) {
                        $errors[] = "transformations.{$table}.{$column}: unknown transformation '{$spec->type}' (see: php artisan cipi:anonymize --transformations)";

                        continue;
                    }
                    $transformations[$table][$column] = $spec;
                }
            }
        }

        $truncate = [];
        if (array_key_exists('truncate', $data)) {
            if (! is_array($data['truncate']) || ! array_is_list($data['truncate'])) {
                $errors[] = "'truncate' must be a list of table names";
            } else {
                foreach ($data['truncate'] as $i => $t) {
                    if (! is_string($t) || ! preg_match('/^[A-Za-z_][A-Za-z0-9_.$-]{0,127}$/', $t)) {
                        $errors[] = "truncate[{$i}]: invalid table name";

                        continue;
                    }
                    if (is_array($rawT) && array_key_exists($t, $rawT)) {
                        $errors[] = "truncate[{$i}]: '{$t}' is both truncated and transformed — pick one";

                        continue;
                    }
                    if (! in_array($t, $truncate, true)) {
                        $truncate[] = $t;
                    }
                }
            }
        }

        $options = self::OPTION_DEFAULTS;
        $rawO = $data['options'] ?? [];
        if (! is_array($rawO) || (array_is_list($rawO) && $rawO !== [])) {
            $errors[] = "'options' must be an object";
        } else {
            foreach ($rawO as $k => $v) {
                if (! array_key_exists($k, self::OPTION_DEFAULTS)) {
                    $errors[] = "options: unknown option '{$k}' (allowed: ".implode(', ', array_keys(self::OPTION_DEFAULTS)).')';

                    continue;
                }
                $options[$k] = $v;
            }
            if (! in_array($options['hash_algorithm'], self::HASH_ALGORITHMS, true)) {
                $errors[] = 'options.hash_algorithm: must be one of '.implode(', ', self::HASH_ALGORITHMS);
            }
            if (! is_string($options['password']) || $options['password'] === '') {
                $errors[] = 'options.password: must be a non-empty string (the plaintext every password is set to)';
            }
            if (! is_string($options['faker_locale']) || ! preg_match('/^[a-z]{2,3}(_[A-Za-z]{2,4})?$/', $options['faker_locale'])) {
                $errors[] = 'options.faker_locale: expected a Faker locale such as en_US or it_IT';
            }
            foreach (['consistent', 'gzip', 'preserve_ids'] as $flag) {
                if (! is_bool($options[$flag])) {
                    $errors[] = "options.{$flag}: must be true or false";
                }
            }
            if ($options['seed'] !== null && ! is_string($options['seed']) && ! is_int($options['seed'])) {
                $errors[] = 'options.seed: must be a string or an integer';
            }
            if ($options['connection'] !== null && ! is_string($options['connection'])) {
                $errors[] = 'options.connection: must be a connection name from config/database.php';
            }
            if (! is_array($options['dump_args']) || ! array_is_list($options['dump_args'])) {
                $errors[] = 'options.dump_args: must be a list of extra arguments for mysqldump / pg_dump';
            } else {
                foreach ($options['dump_args'] as $arg) {
                    if (! is_string($arg) || ! preg_match('/^--?[A-Za-z0-9][A-Za-z0-9_.=,:\/-]*$/', $arg)) {
                        $errors[] = 'options.dump_args: each entry must be a single flag such as --skip-triggers';

                        break;
                    }
                }
            }
        }

        if ($transformations === [] && $truncate === []) {
            $errors[] = 'no transformations defined — the dump would not be anonymized at all';
        }

        if ($errors !== []) {
            throw new ConfigException($errors, $file);
        }

        return new self($transformations, $truncate, $options, $file);
    }

    public function spec(string $table, string $column): ?Spec
    {
        return $this->transformations[$table][$column] ?? null;
    }

    /** @return array<string, Spec> */
    public function specsFor(string $table): array
    {
        return $this->transformations[$table] ?? [];
    }

    public function truncates(string $table): bool
    {
        return in_array($table, $this->truncate, true);
    }

    /** @return list<string> */
    public function tables(): array
    {
        return array_keys($this->transformations);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $t = [];
        foreach ($this->transformations as $table => $columns) {
            foreach ($columns as $column => $spec) {
                $t[$table][$column] = $spec->toConfig();
            }
        }
        $options = array_filter($this->options, fn ($v, $k) => $v !== self::OPTION_DEFAULTS[$k], ARRAY_FILTER_USE_BOTH);

        return array_filter([
            'transformations' => $t,
            'truncate' => $this->truncate,
            'options' => $options === [] ? ['hash_algorithm' => 'auto', 'password' => 'password', 'faker_locale' => 'en_US'] : $options,
        ], fn ($v) => $v !== []);
    }
}
