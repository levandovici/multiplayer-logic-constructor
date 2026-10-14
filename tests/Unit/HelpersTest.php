<?php

namespace Mlc\Tests\Unit;

use PHPUnit\Framework\TestCase;

class HelpersTest extends TestCase
{
    public function testGenerateUuidFormat(): void
    {
        $uuid = generate_uuid();
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid
        );
    }

    public function testTokenDigestDeterministicAndPeppered(): void
    {
        // No pepper configured in test env → digest disabled (plaintext path).
        $this->assertNull(token_digest('secret'));

        $_ENV['TOKEN_PEPPER'] = 'test-pepper';
        $a = token_digest('secret');
        $b = token_digest('secret');
        $this->assertSame($a, $b);
        $this->assertSame(64, strlen((string)$a));
        $this->assertNotSame($a, token_digest('other'));
        unset($_ENV['TOKEN_PEPPER']);
    }

    public function testLoginCodeNormalize(): void
    {
        $this->assertSame('XK4B9P2R', login_code_normalize('xk4b-9p2r'));
        $this->assertSame('XK4B9P2R', login_code_normalize(' XK4B 9P2R '));
    }

    public function testSplitSqlStatements(): void
    {
        $sql = "CREATE TABLE a (x INT); -- comment\nCREATE TABLE b (y VARCHAR(9) DEFAULT 'a;b');";
        $stmts = split_sql_statements($sql);
        $this->assertCount(2, $stmts);
        $this->assertStringContainsString("'a;b'", $stmts[1]);
    }

    public function testGetParamRejectsArrays(): void
    {
        $_GET['arr'] = ['x' => 'y'];
        $_GET['ok'] = 'value';
        $this->assertNull(get_param('arr'));
        $this->assertSame('value', get_param('ok'));
        unset($_GET['arr'], $_GET['ok']);
    }
}
