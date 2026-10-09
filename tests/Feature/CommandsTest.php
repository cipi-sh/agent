<?php

namespace Cipi\Agent\Tests\Feature;

use Cipi\Agent\Tests\TestCase;
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
        $this->artisan('cipi:status')->assertExitCode(0);
        $this->artisan('cipi:mcp')->assertExitCode(0);
        $this->artisan('cipi:mcp --json')->assertExitCode(0);
    }
}
