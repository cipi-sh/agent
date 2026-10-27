<?php

namespace Cipi\Agent\Tests\Unit;

use Cipi\Agent\Yml\Example;
use Cipi\Agent\Yml\Validator;
use Cipi\Agent\Yml\YamlError;
use Cipi\Agent\Yml\YamlParser;
use PHPUnit\Framework\TestCase;

class YamlValidatorTest extends TestCase
{
    public function test_example_template_is_valid_in_its_namespace(): void
    {
        $r = Validator::validate(Example::render('shop', 'shop.example.com'), 'shop');
        $this->assertTrue($r->ok, implode("\n", $r->errors));
        $this->assertSame('8.5', $r->data['app']['php']);
        $this->assertSame(['www.shop.example.com', '*.shop.example.com'], $r->data['app']['aliases']);
        $this->assertSame('warn', $r->data['deploy']['post_on_failure']);
        $this->assertSame(['run' => 'npm', 'argv' => ['run', 'build']], $r->data['deploy']['post'][2]);
        $this->assertSame(200, $r->data['health']['expect']);
        $this->assertSame(['keep' => 48, 'days' => 0, 'weeks' => 0], $r->data['backup']['profiles'][0]['retention']);
        $this->assertSame(3600, $r->data['workers']['queues'][0]['timeout']);
    }

    public function test_parser_refuses_unsupported_yaml(): void
    {
        foreach (["version: 1\napp: &a\n  php: \"8.4\"\n", "version: 1\napp:\n\tphp: 1\n", "version: 1\nhealth: {enabled: false}\n", "version: 1\nx: |\n  text\n", "version: 1\n<<: *base\n"] as $yaml) {
            try {
                YamlParser::parse($yaml);
                $this->fail('expected YamlError for: '.$yaml);
            } catch (YamlError $e) {
                $this->assertStringStartsWith('line ', $e->getMessage());
            }
        }
    }

    public function test_schema_errors_match_the_server_messages(): void
    {
        $r = Validator::validate("version: 1\nfoo: bar\napp:\n  php: \"8.2\"\n  www: sideways\ndatabases:\n  - name: otherapp\nworkers:\n  horizon: true\n  queues: [default]\n", 'myapp');
        $this->assertFalse($r->ok);
        $this->assertSame([
            'cipi.yml.foo: unknown key (allowed: app, backup, crons, databases, deploy, env, health, node, proxies, redirect, redirects, schedule, search, ssl, version, workers)',
            'app.www: must be one of from-root, none, to-root',
            'app.php: must be one of 8.3, 8.4, 8.5',
            "databases[0].name: must be 'myapp' or start with 'myapp_' — a project file cannot claim databases outside its own namespace",
            'workers: horizon and queues are mutually exclusive — Horizon replaces queue:work workers',
        ], $r->errors);
    }

    public function test_version_is_required_and_scalars_are_coerced(): void
    {
        $this->assertSame(["version: required — add 'version: 1'"], Validator::validate("app:\n  php: \"8.4\"\n")->errors);
        $this->assertSame(["version: unsupported version '1' (this Cipi understands version 1)"], Validator::validate("version: \"1\"\n")->errors);

        $r = Validator::validate("version: 1\nschedule: yes\nsearch: off\napp:\n  php: 8.4\n  ini:\n    opcache.enable: on\n    max_input_vars: 5000\n", 'myapp');
        $this->assertTrue($r->ok);
        $this->assertTrue($r->data['schedule']);
        $this->assertFalse($r->data['search']);
        $this->assertSame('8.4', $r->data['app']['php']);
        $this->assertSame(['opcache.enable' => 'On', 'max_input_vars' => '5000'], $r->data['app']['ini']);
    }

    public function test_deploy_branch_is_validated_like_cipi_app_edit(): void
    {
        $r = Validator::validate("version: 1\ndeploy:\n  branch: release/2026.10\n", 'myapp');
        $this->assertTrue($r->ok, implode("\n", $r->errors));
        $this->assertSame('release/2026.10', $r->data['deploy']['branch']);

        $this->assertArrayNotHasKey('branch', Validator::validate("version: 1\ndeploy:\n  keep_releases: 3\n", 'myapp')->data['deploy']);

        foreach (['../main' => '../main', 'feature..x' => 'feature..x', '-lead' => '-lead', 'has space' => 'has space', 'a;b' => 'a;b'] as $branch) {
            $r = Validator::validate("version: 1\ndeploy:\n  branch: \"{$branch}\"\n", 'myapp');
            $this->assertSame(["deploy.branch: invalid branch name '{$branch}' (letters, digits, . _ / -, no \"..\", up to 191 characters)"], $r->errors, $branch);
        }
        $this->assertSame(['deploy.branch: invalid branch name \''.str_repeat('b', 192).'\' (letters, digits, . _ / -, no "..", up to 191 characters)'], Validator::validate("version: 1\ndeploy:\n  branch: ".str_repeat('b', 192)."\n", 'myapp')->errors);
        $this->assertSame(['deploy.branch: expected a string'], Validator::validate("version: 1\ndeploy:\n  branch: true\n", 'myapp')->errors);
        $this->assertStringContainsString('# branch: main', Example::render('shop', 'shop.example.com'));
    }

    public function test_deploy_post_forms(): void
    {
        $r = Validator::validate("version: 1\ndeploy:\n  post:\n    - artisan cache:clear\n    - run: artisan\n      command: migrate\n      force: true\n    - composer: [dump-autoload, -o]\n    - bash -c ls\n    - php /abs.php\n", 'myapp');
        $this->assertSame([
            "deploy.post[3]: unknown runner 'bash' — allowed: artisan, composer, node, npm, npx, php, pnpm, yarn",
            'deploy.post[4]: script must be a relative path under the release (e.g. scripts/warm.mjs)',
        ], $r->errors);

        $r = Validator::validate("version: 1\ndeploy:\n  post:\n    - run: artisan\n      command: migrate\n      force: true\n    - composer: [dump-autoload, -o]\n", 'myapp');
        $this->assertTrue($r->ok);
        $this->assertSame(['run' => 'artisan', 'argv' => ['migrate', '--force']], $r->data['deploy']['post'][0]);
        $this->assertSame(['run' => 'composer', 'argv' => ['dump-autoload', '-o']], $r->data['deploy']['post'][1]);
    }

    public function test_without_app_namespaces_are_not_checked_and_a_warning_is_added(): void
    {
        $r = Validator::validate("version: 1\ndatabases:\n  - name: anything\n");
        $this->assertTrue($r->ok);
        $this->assertCount(1, $r->warnings);
    }
}
