<?php

declare(strict_types=1);

namespace Empira\Tests;

use Empira\Config\Env;
use PHPUnit\Framework\TestCase;

final class EnvTest extends TestCase
{
    public function testReadsKeyValueLines(): void
    {
        $env = Env::parse("APP_ENV=local\nAPP_DEBUG=true\n");

        $this->assertSame(['APP_ENV' => 'local', 'APP_DEBUG' => 'true'], $env);
    }

    public function testIgnoresBlankLinesAndComments(): void
    {
        $env = Env::parse("# a comment\n\n   \nAPP_ENV=local\n# trailing comment\n");

        $this->assertSame(['APP_ENV' => 'local'], $env);
    }

    public function testStripsExportPrefix(): void
    {
        $env = Env::parse("export APP_ENV=local\n");

        $this->assertSame(['APP_ENV' => 'local'], $env);
    }

    public function testTrimsWhitespaceAroundValues(): void
    {
        $env = Env::parse("APP_NAME =   empira   \n");

        $this->assertSame(['APP_NAME' => 'empira'], $env);
    }

    public function testHonoursDoubleAndSingleQuotes(): void
    {
        $env = Env::parse("DOUBLE=\"hello world\"\nSINGLE='raw \$value'\n");

        $this->assertSame('hello world', $env['DOUBLE']);
        $this->assertSame('raw $value', $env['SINGLE']);
    }

    public function testInterpolatesEarlierValues(): void
    {
        $env = Env::parse("BASE=/app\nURL=\${BASE}/public\n");

        $this->assertSame('/app/public', $env['URL']);
    }

    public function testLeavesUnknownInterpolationIntact(): void
    {
        $env = Env::parse("URL=\${MISSING}/x\n");

        $this->assertSame('${MISSING}/x', $env['URL']);
    }

    public function testIgnoresMalformedLines(): void
    {
        $env = Env::parse("not a key value line\n9INVALID=x\nGOOD=yes\n");

        $this->assertSame(['GOOD' => 'yes'], $env);
    }

    public function testHandlesCarriageReturnLineEndings(): void
    {
        $env = Env::parse("A=1\r\nB=2\r\n");

        $this->assertSame(['A' => '1', 'B' => '2'], $env);
    }

    public function testFromFileReturnsEmptyArrayWhenMissing(): void
    {
        $this->assertSame([], Env::fromFile('/definitely/not/here/.env'));
    }

    public function testFromFileParsesRealFile(): void
    {
        $path = Temp::file('empira-env');

        $this->assertIsString($path);
        file_put_contents($path, "APP_ENV=local\n");

        try {
            $this->assertSame(['APP_ENV' => 'local'], Env::fromFile($path));
        } finally {
            unlink($path);
        }
    }
}