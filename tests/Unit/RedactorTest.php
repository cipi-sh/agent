<?php

namespace Cipi\Agent\Tests\Unit;

use Cipi\Agent\Support\Redactor;
use PHPUnit\Framework\TestCase;

class RedactorTest extends TestCase
{
    public function test_redacts_common_secrets(): void
    {
        $in = implode("\n", [
            'DB_PASSWORD=supersecret',
            'APP_KEY=base64:'.str_repeat('A', 44),
            'MAIL_PASSWORD="quoted"',
            'Authorization: Bearer abc.def.ghi',
            'password: hunter2',
            'mysql://user:pw@host/db',
            'token cipi_mcp_0123456789abcdef0123456789abcdef',
            'ghp_'.str_repeat('a', 36),
            'plain text stays',
        ]);
        $out = Redactor::redact($in);
        foreach (['supersecret', str_repeat('A', 44), 'quoted', 'abc.def.ghi', 'hunter2', ':pw@', 'cipi_mcp_0123', 'ghp_aaaa'] as $secret) {
            $this->assertStringNotContainsString($secret, $out, "leaked {$secret}");
        }
        $this->assertStringContainsString('plain text stays', $out);
        $this->assertStringContainsString('DB_PASSWORD=[REDACTED]', $out);
        $this->assertStringContainsString('mysql://user:[REDACTED]@host/db', $out);
        $this->assertTrue(Redactor::hasHighRiskPatterns('see mysql://a:b@c/d'));
        $this->assertFalse(Redactor::hasHighRiskPatterns('nothing here'));
    }
}
