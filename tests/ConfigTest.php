<?php

declare(strict_types=1);

namespace IamAdty\Empira\Tests;

use IamAdty\Empira\Config\Config;
use IamAdty\Empira\Config\Env;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testReadsTopLevelValues(): void
    {
        $config = new Config(['app' => 'empira', 'debug' => true]);

        $this->assertSame('empira', $config->get('app'));
        $this->assertTrue($config->get('debug'));
    }

    public function testTraversesNestedPathsWithDots(): void
    {
        $config = new Config(['db' => ['host' => 'localhost', 'port' => 5432]]);

        $this->assertSame('localhost', $config->get('db.host'));
        $this->assertSame(5432, $config->get('db.port'));
    }

    public function testMissingKeysFallBackToTheDefault(): void
    {
        $config = new Config(['db' => ['host' => 'localhost']]);

        $this->assertNull($config->get('db.missing'));
        $this->assertSame('fallback', $config->get('nope.deeper', 'fallback'));
    }

    public function testHasDistinguishesPresentFromAbsent(): void
    {
        $config = new Config(['db' => ['host' => 'localhost']]);

        $this->assertTrue($config->has('db.host'));
        $this->assertFalse($config->has('db.port'));
        $this->assertFalse($config->has('nothing'));
    }

    public function testTypedGettersCoerceValues(): void
    {
        $config = new Config([
            'db' => ['port' => '5432', 'ratio' => '1.5', 'debug' => 'true', 'name' => 42],
        ]);

        $this->assertSame(5432, $config->int('db.port'));
        $this->assertSame(1.5, $config->float('db.ratio'));
        $this->assertTrue($config->bool('db.debug'));
        $this->assertSame('42', $config->string('db.name'));
    }

    public function testTypedGettersUseDefaultsWhenValuesAreUnusable(): void
    {
        $config = new Config(['db' => ['port' => 'not-a-number', 'debug' => 'maybe']]);

        $this->assertSame(3306, $config->int('db.port', 3306));
        $this->assertFalse($config->bool('db.debug', false));
        $this->assertSame('x', $config->string('db.name', 'x'));
    }

    public function testArrayGetterReturnsArraysAndDefaultsOtherwise(): void
    {
        $config = new Config(['db' => ['hosts' => ['a', 'b']], 'scalar' => 'nope']);

        $this->assertSame(['a', 'b'], $config->array('db.hosts'));
        $this->assertSame([], $config->array('scalar'));
        $this->assertSame(['fallback'], $config->array('missing', ['fallback']));
    }

    public function testWithReturnsANewInstanceAndLeavesTheOriginalAlone(): void
    {
        $config = new Config(['app' => ['debug' => false]]);

        $debug = $config->with('app.debug', true);

        $this->assertTrue($debug->bool('app.debug'));
        $this->assertFalse($config->bool('app.debug'));
        $this->assertNotSame($config, $debug);
    }

    public function testWithCreatesMissingBranches(): void
    {
        $config = (new Config([]))->with('a.b.c', 'deep');

        $this->assertSame('deep', $config->get('a.b.c'));
    }

    public function testAllReturnsTheWholeArray(): void
    {
        $config = new Config(['a' => 1, 'b' => ['c' => 2]]);

        $this->assertSame(['a' => 1, 'b' => ['c' => 2]], $config->all());
    }

    public function testBuildsFromEnvKeysExpandingDots(): void
    {
        $config = Config::fromEnv(['db.host' => 'db.internal', 'db.port' => '5432', 'APP_DEBUG' => '1']);

        $this->assertSame('db.internal', $config->string('db.host'));
        $this->assertSame(5432, $config->int('db.port'));
        $this->assertTrue($config->bool('APP_DEBUG'));
    }

    public function testFromEnvFileReadsAndParsesAFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'empira-env');

        $this->assertIsString($path);
        file_put_contents($path, "APP_ENV=local\nDB_HOST=localhost\n");

        try {
            $config = Config::fromEnvFile($path);

            $this->assertSame('local', $config->string('APP_ENV'));
            $this->assertSame('localhost', $config->string('DB_HOST'));
        } finally {
            unlink($path);
        }
    }
}