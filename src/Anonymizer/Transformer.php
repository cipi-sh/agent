<?php

namespace Cipi\Agent\Anonymizer;

use Faker\Factory;
use Faker\Generator;
use Illuminate\Support\Facades\Hash;

/**
 * Turns one real value into a fake one.
 *
 * Besides the Faker formatters there are a few structural transformations:
 * `password` hashes one known plaintext (options.password) so developers can
 * actually log in to the anonymized copy, `null`/`empty`/`fixed` replace the
 * value, `hash`/`mask`/`redact` derive an unreadable form from the original.
 *
 * `consistent` makes a transformation a function of the original value: the
 * same e-mail becomes the same fake e-mail in every table and every run with
 * the same seed, so joins across denormalized columns keep working. `unique`
 * guarantees no two rows of a column receive the same value, which matters
 * for columns with a unique index (e-mails, user names).
 */
final class Transformer
{
    /** Formatters that default to unique because the column usually carries a unique index. */
    private const DEFAULT_UNIQUE = ['fakeEmail', 'fakeSafeEmail', 'fakeUserName', 'fakeUuid', 'fakeToken', 'fakeIban'];

    /** type => [description, faker formatter or null] */
    public const TYPES = [
        'fakeName' => 'Full name', 'fakeFirstName' => 'First name', 'fakeLastName' => 'Last name',
        'fakeUserName' => 'User name (unique)', 'fakeEmail' => 'E-mail address (unique)',
        'fakeSafeEmail' => 'E-mail on example.com/net/org (unique)', 'fakeCompany' => 'Company name',
        'fakeJobTitle' => 'Job title', 'fakeAddress' => 'Full postal address', 'fakeStreetAddress' => 'Street and number',
        'fakeCity' => 'City', 'fakeState' => 'State / region', 'fakePostcode' => 'Postal code',
        'fakeCountry' => 'Country name', 'fakeCountryCode' => 'ISO country code', 'fakePhoneNumber' => 'Phone number',
        'fakeDate' => 'Date (Y-m-d)', 'fakeDateTime' => 'Date and time (Y-m-d H:i:s)', 'fakeUrl' => 'URL',
        'fakeDomain' => 'Domain name', 'fakeParagraph' => 'Paragraph of text', 'fakeSentence' => 'One sentence',
        'fakeText' => 'Text (length as argument, default 200)', 'fakeWord' => 'One word', 'fakeIban' => 'IBAN (unique)',
        'fakeCreditCardNumber' => 'Credit card number (test range)', 'fakeIpv4' => 'IPv4 address', 'fakeIpv6' => 'IPv6 address',
        'fakeMacAddress' => 'MAC address', 'fakeUserAgent' => 'Browser user agent', 'fakeUuid' => 'UUID v4 (unique)',
        'fakeLatitude' => 'Latitude', 'fakeLongitude' => 'Longitude', 'fakeNumber' => 'Random integer (digits as argument)',
        'fakeBoolean' => 'Random true/false', 'fakeToken' => 'Random hex token (length as argument, default 40)',
        'fakeIdentifier' => 'Random alphanumeric identifier (tax id, passport, …)',
        'numerify' => 'Pattern with # replaced by digits, e.g. numerify:###-###', 'lexify' => 'Pattern with ? replaced by letters',
        'bothify' => 'Pattern with # and ? replaced', 'password' => 'Hash of options.password (default "password")',
        'null' => 'Set to NULL', 'empty' => 'Set to an empty string', 'fixed' => 'Constant value, e.g. fixed:ACME',
        'hash' => 'SHA-256 of the original (hex length as argument, e.g. hash:32)',
        'mask' => 'Keep the first/last characters, e.g. mask:1,2 → j*******om', 'redact' => 'The string [REDACTED]',
        'keep' => 'Leave the value as it is',
    ];

    private Generator $faker;

    private ?string $passwordHash = null;

    /** @var array<string, array<string, true>> */
    private array $seen = [];

    private string $seed;

    public function __construct(private readonly Config $config)
    {
        $this->faker = Factory::create((string) $config->options['faker_locale']);
        $this->seed = (string) ($config->options['seed'] ?? '');
    }

    public static function supports(string $type): bool
    {
        return array_key_exists($type, self::TYPES);
    }

    /** @return list<string> */
    public static function types(): array
    {
        return array_keys(self::TYPES);
    }

    /**
     * @param  mixed  $value  decoded value (string|int|float|bool|null)
     * @return mixed the replacement, same family of type where possible
     */
    public function transform(string $table, string $column, mixed $value, Spec $spec): mixed
    {
        $type = $spec->type;

        if ($type === 'keep') {
            return $value;
        }
        if ($type === 'fixed') {
            return $spec->arg ?? '';
        }
        if ($value === null) {
            return null;
        }
        if ($type === 'null') {
            return null;
        }
        if ($type === 'empty') {
            return '';
        }
        if ($type === 'password') {
            return $this->passwordHash();
        }
        if ($type === 'redact') {
            return '[REDACTED]';
        }
        if ($type === 'hash') {
            $hex = hash('sha256', $this->seed."\0".(string) $value);
            $len = (int) ($spec->arg ?? 64);

            return $len > 0 && $len < 64 ? substr($hex, 0, $len) : $hex;
        }
        if ($type === 'mask') {
            return $this->mask((string) $value, $spec->arg);
        }

        $consistent = $spec->consistent ?? (bool) $this->config->options['consistent'];
        $unique = $spec->unique ?? in_array($type, self::DEFAULT_UNIQUE, true);
        $key = "{$table}.{$column}";

        for ($attempt = 0; $attempt < 25; $attempt++) {
            if ($consistent) {
                $this->faker->seed(self::seedFor($this->seed, $type, (string) $value, $attempt));
            }
            $fake = $this->generate($type, $spec->arg, $value);
            if ($consistent) {
                mt_srand(random_int(0, PHP_INT_MAX));
            }
            if (! $unique) {
                return $fake;
            }
            $k = is_scalar($fake) ? (string) $fake : serialize($fake);
            if (! isset($this->seen[$key][$k])) {
                $this->seen[$key][$k] = true;

                return $fake;
            }
        }

        // Collisions on a tiny domain: make it unique by construction.
        $counter = count($this->seen[$key]) + 1;
        $fake = $this->generate($type, $spec->arg, $value);
        $fake = is_string($fake) && str_contains($fake, '@')
            ? preg_replace('/@/', "+{$counter}@", $fake, 1)
            : (is_string($fake) ? "{$fake}-{$counter}" : $fake);
        $this->seen[$key][(string) $fake] = true;

        return $fake;
    }

    private function generate(string $type, ?string $arg, mixed $original): mixed
    {
        $f = $this->faker;

        return match ($type) {
            'fakeName' => $f->name(),
            'fakeFirstName' => $f->firstName(),
            'fakeLastName' => $f->lastName(),
            'fakeUserName' => $f->userName(),
            'fakeEmail' => $f->email(),
            'fakeSafeEmail' => $f->safeEmail(),
            'fakeCompany' => $f->company(),
            'fakeJobTitle' => $f->jobTitle(),
            'fakeAddress' => str_replace("\n", ', ', $f->address()),
            'fakeStreetAddress' => $f->streetAddress(),
            'fakeCity' => $f->city(),
            'fakeState' => $this->tryFormatter('state', fn () => $f->city()),
            'fakePostcode' => $f->postcode(),
            'fakeCountry' => $f->country(),
            'fakeCountryCode' => $f->countryCode(),
            'fakePhoneNumber' => $f->phoneNumber(),
            'fakeDate' => $f->date('Y-m-d'),
            'fakeDateTime' => $f->dateTime()->format('Y-m-d H:i:s'),
            'fakeUrl' => $f->url(),
            'fakeDomain' => $f->domainName(),
            'fakeParagraph' => $f->paragraph(),
            'fakeSentence' => $f->sentence(),
            'fakeText' => $f->text(max(5, (int) ($arg ?? 200))),
            'fakeWord' => $f->word(),
            'fakeIban' => $f->iban($arg !== null && preg_match('/^[A-Z]{2}$/', $arg) ? $arg : null),
            'fakeCreditCardNumber' => $f->creditCardNumber(),
            'fakeIpv4' => $f->ipv4(),
            'fakeIpv6' => $f->ipv6(),
            'fakeMacAddress' => $f->macAddress(),
            'fakeUserAgent' => $f->userAgent(),
            'fakeUuid' => $f->uuid(),
            'fakeLatitude' => $this->numericLike($original, (float) $f->latitude()),
            'fakeLongitude' => $this->numericLike($original, (float) $f->longitude()),
            'fakeNumber' => $this->numericLike($original, $f->randomNumber(max(1, min(9, (int) ($arg ?? 5))), true)),
            'fakeBoolean' => is_bool($original) ? $f->boolean() : ($f->boolean() ? 1 : 0),
            'fakeToken' => bin2hex(random_bytes(max(1, (int) ceil(((int) ($arg ?? 40)) / 2)))),
            'fakeIdentifier' => strtoupper($f->bothify($arg ?? '??########')),
            'numerify' => $f->numerify($arg ?? '##########'),
            'lexify' => $f->lexify($arg ?? '??????????'),
            'bothify' => $f->bothify($arg ?? '??########'),
            default => $original,
        };
    }

    private function tryFormatter(string $formatter, callable $fallback): string
    {
        try {
            return (string) $this->faker->format($formatter);
        } catch (\Throwable) {
            return (string) $fallback();
        }
    }

    private function numericLike(mixed $original, int|float $fake): int|float|string
    {
        if (is_int($original) || is_float($original)) {
            return $fake;
        }

        return (string) $fake;
    }

    private function mask(string $value, ?string $arg): string
    {
        [$keepStart, $keepEnd] = array_map('intval', array_pad(explode(',', $arg ?? '1,2', 2), 2, 0));
        $len = mb_strlen($value);
        if ($len <= $keepStart + $keepEnd) {
            return str_repeat('*', $len);
        }

        return mb_substr($value, 0, $keepStart).str_repeat('*', $len - $keepStart - $keepEnd).($keepEnd > 0 ? mb_substr($value, -$keepEnd) : '');
    }

    public function passwordHash(): string
    {
        if ($this->passwordHash !== null) {
            return $this->passwordHash;
        }
        $plain = (string) $this->config->options['password'];

        $this->passwordHash = match ((string) $this->config->options['hash_algorithm']) {
            'bcrypt' => password_hash($plain, PASSWORD_BCRYPT),
            'argon', 'argon2id' => password_hash($plain, PASSWORD_ARGON2ID),
            'argon2i' => password_hash($plain, PASSWORD_ARGON2I),
            'argon2d' => defined('PASSWORD_ARGON2D') ? password_hash($plain, constant('PASSWORD_ARGON2D')) : password_hash($plain, PASSWORD_ARGON2ID),
            default => Hash::make($plain),
        };

        return $this->passwordHash;
    }

    private static function seedFor(string $seed, string $type, string $value, int $attempt): int
    {
        $hash = hash('xxh3', "{$seed}\0{$type}\0{$value}\0{$attempt}");

        return (int) (hexdec(substr($hash, 0, 8)) & 0x7FFFFFFF);
    }
}
