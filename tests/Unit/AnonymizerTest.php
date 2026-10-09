<?php

namespace Cipi\Agent\Tests\Unit;

use Cipi\Agent\Anonymizer\Config;
use Cipi\Agent\Anonymizer\ConfigException;
use Cipi\Agent\Anonymizer\CopyText;
use Cipi\Agent\Anonymizer\Dialect\MySqlDumpProcessor;
use Cipi\Agent\Anonymizer\Dialect\PostgresDumpProcessor;
use Cipi\Agent\Anonymizer\Heuristics;
use Cipi\Agent\Anonymizer\SqlValues;
use Cipi\Agent\Anonymizer\Transformer;
use PHPUnit\Framework\TestCase;

class AnonymizerTest extends TestCase
{
    private const CONFIG = [
        'transformations' => [
            'users' => ['name' => 'fakeName', 'email' => ['type' => 'fakeEmail', 'consistent' => true], 'phone' => 'mask:3,2', 'password' => 'password', 'remember_token' => 'null', 'balance' => 'fakeNumber:3'],
            'orders' => ['customer_email' => ['type' => 'fakeEmail', 'consistent' => true, 'unique' => false], 'notes' => 'fakeParagraph'],
        ],
        'truncate' => ['sessions'],
        'options' => ['hash_algorithm' => 'bcrypt', 'password' => 'secret', 'seed' => 'test'],
    ];

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/cipi-anon-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->dir}/*") ?: []);
        rmdir($this->dir);
    }

    public function test_sql_values_tokenizer_round_trips(): void
    {
        $sql = "(1,'O\\'Brien, Pat (CEO)',NULL,12.50,_binary 'x',0x1F),(2,'Zoë \\\\ \\\"Q\\\"','a\\nb',-3,TRUE,'')";
        $p = SqlValues::parseTuples($sql, SqlValues::MYSQL);
        $this->assertCount(2, $p['tuples']);
        $this->assertSame(['string', "O'Brien, Pat (CEO)"], SqlValues::decode($p['tuples'][0][1], SqlValues::MYSQL));
        $this->assertSame(['null', null], SqlValues::decode($p['tuples'][0][2], SqlValues::MYSQL));
        $this->assertSame(['number', 12.5], SqlValues::decode($p['tuples'][0][3], SqlValues::MYSQL));
        $this->assertSame(['string', 'x'], SqlValues::decode($p['tuples'][0][4], SqlValues::MYSQL));
        $this->assertSame(['raw', '0x1F'], SqlValues::decode($p['tuples'][0][5], SqlValues::MYSQL));
        $this->assertSame(['string', 'Zoë \\ "Q"'], SqlValues::decode($p['tuples'][1][1], SqlValues::MYSQL));
        $this->assertSame(['string', "a\nb"], SqlValues::decode($p['tuples'][1][2], SqlValues::MYSQL));
        $this->assertSame(['bool', true], SqlValues::decode($p['tuples'][1][4], SqlValues::MYSQL));
        $this->assertSame($sql, SqlValues::buildTuples($p['tuples'], $p['separators'], $p['rest']));

        $this->assertSame("'it\\'s'", SqlValues::encode("it's", SqlValues::MYSQL));
        $this->assertSame("'it''s'", SqlValues::encode("it's", SqlValues::PGSQL));
        $this->assertSame("E'a\\nb'", SqlValues::encode("a\nb", SqlValues::PGSQL));
        $this->assertSame(['string', "it's"], SqlValues::decode("'it''s'", SqlValues::PGSQL));
        $this->assertSame(['string', "a\nb"], SqlValues::decode("E'a\\nb'", SqlValues::PGSQL));
    }

    public function test_copy_text_round_trips(): void
    {
        $row = CopyText::decodeRow("1\tTab\\tName\\\\Back\t\\N\tline\\nbreak\n");
        $this->assertSame(['1', "Tab\tName\\Back", null, "line\nbreak"], $row);
        $this->assertSame("1\tTab\\tName\\\\Back\t\\N\tline\\nbreak\n", CopyText::encodeRow($row));
    }

    public function test_config_validation(): void
    {
        $config = Config::fromArray(self::CONFIG);
        $this->assertSame('mask', $config->spec('users', 'phone')->type);
        $this->assertSame('3,2', $config->spec('users', 'phone')->arg);
        $this->assertTrue($config->spec('users', 'email')->consistent);
        $this->assertTrue($config->truncates('sessions'));

        try {
            Config::fromArray(['transformations' => ['users' => ['email' => 'fakeNope', 'x' => ['unique' => true]]], 'truncate' => ['users'], 'options' => ['hash_algorithm' => 'md5', 'bogus' => 1]]);
            $this->fail('expected ConfigException');
        } catch (ConfigException $e) {
            $this->assertStringContainsString("unknown transformation 'fakeNope'", implode("\n", $e->errors));
            $this->assertStringContainsString("'type' is required", implode("\n", $e->errors));
            $this->assertStringContainsString('both truncated and transformed', implode("\n", $e->errors));
            $this->assertStringContainsString('options.hash_algorithm', implode("\n", $e->errors));
            $this->assertStringContainsString("unknown option 'bogus'", implode("\n", $e->errors));
        }

        try {
            Config::fromArray(['transformations' => []]);
            $this->fail('expected ConfigException');
        } catch (ConfigException $e) {
            $this->assertStringContainsString('no transformations defined', $e->getMessage());
        }
    }

    public function test_mysql_dump_is_anonymized(): void
    {
        file_put_contents("{$this->dir}/in.sql", <<<'SQL'
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `balance` decimal(10,2) DEFAULT 0.00,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB;
INSERT INTO `users` VALUES (1,'O\'Brien, Pat (CEO)','pat@example.com','+39 333 1234567','$2y$12$old','tok',12.50),(2,'Zoe','zoe@example.com',NULL,'$2y$12$old',NULL,0.00);
CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `payload` longtext NOT NULL
) ENGINE=InnoDB;
INSERT INTO `sessions` VALUES ('abc','secret payload');
CREATE TABLE `orders` (
  `id` int NOT NULL,
  `customer_email` varchar(255) NOT NULL,
  `notes` text
) ENGINE=InnoDB;
INSERT INTO `orders` (`id`, `customer_email`, `notes`) VALUES (10,'pat@example.com','call me at (555) 123, thanks'),(11,'zoe@example.com',NULL);

SQL);
        $config = Config::fromArray(self::CONFIG);
        $stats = (new MySqlDumpProcessor($config, new Transformer($config)))->process("{$this->dir}/in.sql", "{$this->dir}/out.sql");
        $out = file_get_contents("{$this->dir}/out.sql");

        $this->assertSame(['users' => 2, 'orders' => 2], $stats->tables);
        $this->assertSame(['sessions' => 1], $stats->truncated);
        $this->assertSame([], $stats->warnings);
        $this->assertStringNotContainsString('secret payload', $out);
        $this->assertStringContainsString('CREATE TABLE `sessions`', $out);
        $this->assertStringNotContainsString('pat@example.com', $out);
        $this->assertStringNotContainsString("O\\'Brien", $out);
        $this->assertStringContainsString("'+39**********67'", $out);
        $this->assertStringContainsString(',NULL,', $out); // phone NULL preserved

        preg_match('/INSERT INTO `users` VALUES \((.*)\);/', $out, $m);
        $users = SqlValues::parseTuples('('.$m[1].')', SqlValues::MYSQL)['tuples'];
        $hash = SqlValues::decode($users[0][4], SqlValues::MYSQL)[1];
        $this->assertTrue(password_verify('secret', $hash));
        $this->assertSame($hash, SqlValues::decode($users[1][4], SqlValues::MYSQL)[1], 'the hash is computed once');
        $this->assertSame('NULL', $users[0][5]);
        $this->assertMatchesRegularExpression('/^\d+$/', $users[0][6], 'numeric stays unquoted');

        $patFake = SqlValues::decode($users[0][2], SqlValues::MYSQL)[1];
        preg_match('/INSERT INTO `orders` .*VALUES \((.*)\);/', $out, $m);
        $orders = SqlValues::parseTuples('('.$m[1].')', SqlValues::MYSQL)['tuples'];
        $this->assertSame($patFake, SqlValues::decode($orders[0][1], SqlValues::MYSQL)[1], 'consistent: same email → same fake across tables');
        $this->assertSame('NULL', $orders[1][2]);
    }

    public function test_postgres_dump_is_anonymized(): void
    {
        file_put_contents("{$this->dir}/in.sql", <<<'SQL'
COPY public.users (id, name, email, password, remember_token) FROM stdin;
1	Pat O'Brien	pat@example.com	$2y$12$old	tok
2	Tab\tName\\Back	zoe@example.com	$2y$12$old	\N
\.

COPY public.sessions (id, payload) FROM stdin;
abc	secret
\.

INSERT INTO public.orders (id, customer_email, notes) VALUES (10, 'pat@example.com', 'it''s fine');
INSERT INTO public.sessions (id, payload) VALUES ('x', 'y');

SQL);
        $config = Config::fromArray(self::CONFIG);
        $stats = (new PostgresDumpProcessor($config, new Transformer($config)))->process("{$this->dir}/in.sql", "{$this->dir}/out.sql");
        $out = file_get_contents("{$this->dir}/out.sql");

        $this->assertSame(['users' => 2, 'orders' => 1], $stats->tables);
        $this->assertSame(['sessions' => 2], $stats->truncated);
        $this->assertStringNotContainsString('secret', $out);
        $this->assertStringNotContainsString('pat@example.com', $out);
        $this->assertStringContainsString("COPY public.sessions (id, payload) FROM stdin;\n\\.\n", $out);

        $lines = explode("\n", $out);
        $row1 = CopyText::decodeRow($lines[1]);
        $row2 = CopyText::decodeRow($lines[2]);
        $this->assertTrue(password_verify('secret', $row1[3]));
        $this->assertNull($row1[4], 'remember_token → NULL');
        $this->assertNull($row2[4]);
        preg_match("/INSERT INTO public.orders .*VALUES \((.*)\);/", $out, $m);
        $order = SqlValues::parseTuples('('.$m[1].')', SqlValues::PGSQL)['tuples'][0];
        $this->assertSame($row1[2], SqlValues::decode($order[1], SqlValues::PGSQL)[1], 'consistent across COPY and INSERT');
    }

    public function test_heuristics(): void
    {
        $this->assertSame('fakeEmail', Heuristics::suggest('users', 'email'));
        $this->assertSame('fakeEmail', Heuristics::suggest('orders', 'customer_email'));
        $this->assertSame('password', Heuristics::suggest('users', 'password'));
        $this->assertSame('null', Heuristics::suggest('users', 'remember_token'));
        $this->assertSame('fakeName', Heuristics::suggest('users', 'name'));
        $this->assertNull(Heuristics::suggest('products', 'name'));
        $this->assertNull(Heuristics::suggest('orders', 'user_id'));
        $this->assertNull(Heuristics::suggest('users', 'created_at'));
        $this->assertSame('fakeIpv4', Heuristics::suggest('logins', 'ip_address'));
        $this->assertSame('fakeIdentifier', Heuristics::suggest('customers', 'vat_number'));
        $this->assertNull(Heuristics::suggest('users', 'settings', 'json'));
        $this->assertTrue(Heuristics::isTruncateCandidate('telescope_entries'));
        $this->assertTrue(Heuristics::isTruncateCandidate('sessions'));
        $this->assertFalse(Heuristics::isTruncateCandidate('users'));
    }

    public function test_unique_and_structural_transformations(): void
    {
        $config = Config::fromArray(['transformations' => ['t' => ['c' => 'fakeBoolean']], 'options' => ['hash_algorithm' => 'bcrypt', 'seed' => 's']]);
        $t = new Transformer($config);
        $spec = function (string $s) {
            $e = [];

            return \Cipi\Agent\Anonymizer\Spec::parse($s, 'x', $e);
        };

        $this->assertSame('ACME', $t->transform('t', 'c', 'Old Co', $spec('fixed:ACME')));
        $this->assertSame('ACME', $t->transform('t', 'c', null, $spec('fixed:ACME')));
        $this->assertNull($t->transform('t', 'c', 'x', $spec('null')));
        $this->assertNull($t->transform('t', 'c', null, $spec('fakeName')));
        $this->assertSame('', $t->transform('t', 'c', 'x', $spec('empty')));
        $this->assertSame('[REDACTED]', $t->transform('t', 'c', 'x', $spec('redact')));
        $this->assertSame(32, strlen($t->transform('t', 'c', 'x', $spec('hash:32'))));
        $this->assertSame($t->transform('t', 'c', 'x', $spec('hash')), $t->transform('t', 'c', 'x', $spec('hash')));
        $this->assertSame('j*******om', $t->transform('t', 'c', 'john@a.com', $spec('mask:1,2')));
        $this->assertMatchesRegularExpression('/^\d{3}$/', $t->transform('t', 'c', '999', $spec('numerify:###')));
        $this->assertSame('keep me', $t->transform('t', 'c', 'keep me', $spec('keep')));

        // unique: 60 values from a 10-value domain must still be distinct (retries, then the counter fallback)
        $seen = [];
        for ($i = 0; $i < 60; $i++) {
            $seen[] = $t->transform('t', 'u', "v{$i}", new \Cipi\Agent\Anonymizer\Spec('numerify', '#', true));
        }
        $this->assertCount(60, array_unique(array_map('strval', $seen)));
    }
}
