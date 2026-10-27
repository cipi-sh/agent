<?php

namespace Cipi\Agent\Tests\Feature;

use Cipi\Agent\Support\Environment;
use Cipi\Agent\Tests\TestCase;
use Cipi\Agent\Yml\Generator;
use Cipi\Agent\Yml\Locator;
use Cipi\Agent\Yml\Validator;
use Illuminate\Support\Facades\Schema;

class CommandsTest extends TestCase
{
    public function test_yml_example_and_generate_validate(): void
    {
        $this->artisan('cipi:yml example')->assertExitCode(0);
        $this->artisan('cipi:yml generate')->assertExitCode(0);
        $this->artisan('cipi:yml schema')->assertExitCode(0);

        $target = base_path('cipi.yml');
        @unlink($target);
        try {
            $this->artisan('cipi:yml generate --write')->assertExitCode(0);
            $this->assertFileExists($target);
            $this->assertTrue(Validator::validateFile($target, 'myapp')->ok);
            $this->artisan('cipi:yml generate --write')->assertExitCode(1); // exists, no --force
            $this->artisan('cipi:yml validate')->assertExitCode(0);
            $this->artisan('cipi:yml show --json')->assertExitCode(0);

            file_put_contents($target, "version: 1\napp:\n  php: \"7.4\"\n");
            $this->artisan('cipi:yml validate')->assertExitCode(1);
            $this->artisan('cipi:yml validate --json')->assertExitCode(1);

            file_put_contents($target, "version: 1\nenv:\n  required: [ DEFINITELY_MISSING_KEY ]\n");
            $this->artisan('cipi:yml validate')->assertExitCode(0);
            $this->artisan('cipi:yml validate --strict')->assertExitCode(1);
        } finally {
            @unlink($target);
        }
    }

    public function test_init_anonymize_scan_and_audit(): void
    {
        Schema::create('users', function ($t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->rememberToken();
        });
        Schema::create('jobs', function ($t) {
            $t->id();
            $t->text('payload');
        });

        $this->artisan('cipi:init-anonymize --scan')->assertExitCode(0);
        $file = "{$this->home}/.db/anonymization.json";
        $this->assertFileExists($file);
        $config = json_decode(file_get_contents($file), true);
        $this->assertSame('fakeEmail', $config['transformations']['users']['email']);
        $this->assertSame('password', $config['transformations']['users']['password']);
        $this->assertSame('null', $config['transformations']['users']['remember_token']);
        $this->assertContains('jobs', $config['truncate']);

        $this->artisan('cipi:init-anonymize --scan')->assertExitCode(1); // exists
        $this->artisan('cipi:anonymize-audit')->assertExitCode(0);
        $this->artisan('cipi:anonymize --check')->assertExitCode(0);
        $this->artisan('cipi:anonymize --transformations')->assertExitCode(0);

        // sqlite is not a supported dump dialect → clean failure
        $this->artisan('cipi:anonymize')->assertExitCode(1);
    }

    public function test_status_and_mcp_commands_run(): void
    {
        // one substring per line: a write satisfies only the first expectation it matches
        $this->artisan('cipi:status')
            ->expectsOutputToContain('| Cipi Env      | staging (reads cipi-staging.yml before cipi.yml)')
            ->assertExitCode(0);
        $this->artisan('cipi:mcp')->assertExitCode(0);
        $this->artisan('cipi:mcp --json')->assertExitCode(0);
    }

    public function test_environment_label_falls_back_to_app_env_then_production(): void
    {
        $this->assertSame('staging', Environment::label());
        $this->assertSame(['cipi-staging.yml', 'cipi-staging.yaml', 'cipi.yml', 'cipi.yaml'], Environment::ymlNames());

        config(['cipi.env' => null]);
        $this->assertSame('testing', Environment::label()); // APP_ENV of the test app

        config(['cipi.env' => 'Not A Label']);
        $this->assertSame('testing', Environment::label()); // an invalid CIPI_ENV is skipped

        config(['cipi.env' => 'qa-2']);
        $this->assertSame('qa-2', Environment::label());
        $this->assertTrue(Environment::valid('production'));
        $this->assertFalse(Environment::valid('Prod'));
        $this->assertFalse(Environment::valid(str_repeat('a', 33)));
        $this->assertFalse(Environment::valid('-staging'));
    }

    public function test_the_environments_own_cipi_yml_is_read_before_cipi_yml(): void
    {
        $plain = base_path('cipi.yml');
        $staging = base_path('cipi-staging.yml');
        @unlink($plain);
        @unlink($staging);

        try {
            $this->assertSame([$staging, base_path('cipi-staging.yaml'), $plain, base_path('cipi.yaml')], array_slice(Locator::candidates(), 0, 4));
            $this->assertContains("{$this->home}/current/cipi-staging.yml", Locator::candidates());
            $this->assertContains("{$this->home}/shared/cipi-staging.yml", Locator::candidates());

            file_put_contents($plain, "version: 1\napp:\n  php: \"8.4\"\n");
            $this->assertSame($plain, Locator::find());

            file_put_contents($staging, "version: 1\ndeploy:\n  branch: develop\n");
            $this->assertSame($staging, Locator::find());
            $this->artisan('cipi:yml validate')->expectsOutputToContain('cipi-staging.yml')->assertExitCode(0);

            // a production app (or an older server without CIPI_ENV and APP_ENV=production) reads cipi.yml
            config(['cipi.env' => 'production']);
            $this->assertSame($plain, Locator::find());
        } finally {
            @unlink($plain);
            @unlink($staging);
        }
    }

    public function test_generate_offers_the_deploy_branch(): void
    {
        $yaml = Generator::generate('myapp')['yaml'];
        $this->assertStringContainsString('  # branch: "main"', $yaml);
        $this->assertStringContainsString('cipi-staging.yml, cipi-production.yml', $yaml);
        $this->assertSame('main', Generator::facts('myapp')['branch']);
        $this->assertSame('staging', Generator::facts('myapp')['env']);

        config(['cipi.deploy_branch' => 'bad..branch']);
        $this->assertNull(Generator::facts('myapp')['branch']);
        $this->assertStringContainsString('  # branch: "main"', Generator::generate('myapp')['yaml']);
    }
}
