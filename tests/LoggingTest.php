<?php

declare(strict_types=1);

namespace IamAdty\Empira\Tests;

use DateTimeImmutable;
use IamAdty\Empira\Logging\FileLogger;
use IamAdty\Empira\Logging\Logger;
use IamAdty\Empira\Logging\LogLevel;
use IamAdty\Empira\Logging\NullLogger;
use PHPUnit\Framework\TestCase;

final class LoggingTest extends TestCase
{
    public function testItWritesOneLinePerEntry(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'empira-log');

        $this->assertIsString($path);

        try {
            $logger = new FileLogger($path);

            $logger->error('Checkout failed');
            $logger->info('Serving home');

            $lines = self::lines($path);

            $this->assertCount(2, $lines);
            $this->assertMatchesEntry('ERROR', 'Checkout failed', $lines[0]);
            $this->assertMatchesEntry('INFO', 'Serving home', $lines[1]);
        } finally {
            unlink($path);
        }
    }

    public function testItAppendsJsonEncodedContext(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'empira-log');

        $this->assertIsString($path);

        try {
            $logger = new FileLogger($path);

            $logger->error('Checkout failed', ['order' => 42, 'why' => 'declined "card"']);

            $lines = self::lines($path);

            $this->assertCount(1, $lines);
            $this->assertStringEndsWith(' {"order":42,"why":"declined \"card\""}', $lines[0]);
        } finally {
            unlink($path);
        }
    }

    public function testItLeavesTheContextOffWhenThereIsNone(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'empira-log');

        $this->assertIsString($path);

        try {
            $logger = new FileLogger($path);

            $logger->info('no context here', []);

            $this->assertCount(1, self::lines($path));
            $this->assertStringEndsWith('INFO: no context here', self::lines($path)[0]);
        } finally {
            unlink($path);
        }
    }

    public function testItLeavesTheContextOffWhenDisabled(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'empira-log');

        $this->assertIsString($path);

        try {
            $logger = new FileLogger($path, LogLevel::Debug, false);

            $logger->error('Checkout failed', ['order' => 42]);

            $this->assertStringEndsWith('ERROR: Checkout failed', self::lines($path)[0]);
        } finally {
            unlink($path);
        }
    }

    public function testItSkipsMessagesBelowTheMinimumLevel(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'empira-log');

        $this->assertIsString($path);

        try {
            $logger = new FileLogger($path, LogLevel::Warning);

            $logger->debug('noisy');
            $logger->info('noisy');
            $logger->log(LogLevel::Notice, 'noisy');
            $logger->warning('careful');
            $logger->error('broken');

            $lines = self::lines($path);

            $this->assertCount(2, $lines);
            $this->assertMatchesEntry('WARNING', 'careful', $lines[0]);
            $this->assertMatchesEntry('ERROR', 'broken', $lines[1]);
        } finally {
            unlink($path);
        }
    }

    public function testAMessageWithANewlineCannotForgeASecondEntry(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'empira-log');

        $this->assertIsString($path);

        try {
            $logger = new FileLogger($path);

            $logger->error("Checkout failed\n[2026-01-02 03:04:05] ERROR: nothing happened", ['order' => 42]);

            $lines = self::lines($path);

            $this->assertCount(1, $lines);
            $this->assertStringContainsString('Checkout failed\n[2026-01-02', $lines[0]);
            $this->assertStringEndsWith('{"order":42}', $lines[0]);
        } finally {
            unlink($path);
        }
    }

    public function testItCreatesMissingParentDirectories(): void
    {
        $directory = sys_get_temp_dir() . '/empira-log-' . bin2hex(random_bytes(6));
        $path = $directory . '/nested/app.log';

        try {
            $logger = new FileLogger($path);

            $logger->info('Created the directory');

            $this->assertFileExists($path);
            $this->assertMatchesEntry('INFO', 'Created the directory', self::lines($path)[0]);
        } finally {
            @unlink($path);
            @rmdir($directory);
        }
    }

    public function testAFailedWriteIsSwallowed(): void
    {
        $blocker = tempnam(sys_get_temp_dir(), 'empira-log');

        $this->assertIsString($blocker);

        try {
            // A file, not a directory, sits where the log directory would go.
            $logger = new FileLogger($blocker . '/log/app.log');

            $logger->error('This cannot be written anywhere');

            $this->assertFileDoesNotExist($blocker . '/log/app.log');
        } finally {
            unlink($blocker);
        }
    }

    public function testNullLoggerDiscardsEverything(): void
    {
        $logger = new NullLogger();

        $this->assertInstanceOf(Logger::class, $logger);

        $logger->debug('nothing');
        $logger->info('nothing');
        $logger->warning('nothing');
        $logger->error('nothing');
        $logger->log(LogLevel::Emergency, 'nothing', ['a' => 1]);
    }

    public function testFromNameIgnoresCaseAndRejectsUnknownLevels(): void
    {
        $this->assertSame(LogLevel::Warning, LogLevel::fromName('warning'));
        $this->assertSame(LogLevel::Warning, LogLevel::fromName('WARNING'));
        $this->assertSame(LogLevel::Warning, LogLevel::fromName('WaRnInG'));
        $this->assertSame(LogLevel::Emergency, LogLevel::fromName('Emergency'));

        $this->assertNull(LogLevel::fromName('verbose'));
        $this->assertNull(LogLevel::fromName(' warning'));
        $this->assertNull(LogLevel::fromName(''));
    }

    public function testSeverityRanksEmergencyMostSevereAndDebugLeast(): void
    {
        $this->assertSame('ERROR', LogLevel::Error->label());
        $this->assertSame('DEBUG', LogLevel::Debug->label());

        $ordered = LogLevel::cases();
        usort($ordered, static fn (LogLevel $a, LogLevel $b): int => $a->severity() <=> $b->severity());

        $this->assertSame(LogLevel::Emergency, $ordered[0]);
        $this->assertSame(LogLevel::Alert, $ordered[1]);
        $this->assertSame(LogLevel::Critical, $ordered[2]);
        $this->assertSame(LogLevel::Error, $ordered[3]);
        $this->assertSame(LogLevel::Warning, $ordered[4]);
        $this->assertSame(LogLevel::Notice, $ordered[5]);
        $this->assertSame(LogLevel::Info, $ordered[6]);
        $this->assertSame(LogLevel::Debug, $ordered[7]);

        $this->assertTrue(LogLevel::Emergency->severity() < LogLevel::Debug->severity());
        $this->assertCount(8, array_unique(array_map(static fn (LogLevel $level): int => $level->severity(), LogLevel::cases())));
    }

    /**
     * The non-empty lines of a log file.
     *
     * @return list<string>
     */
    private static function lines(string $path): array
    {
        $contents = (string) file_get_contents($path);

        return array_values(array_filter(
            explode("\n", $contents),
            static fn (string $line): bool => $line !== ''
        ));
    }

    private static function assertMatchesEntry(string $label, string $message, string $line): void
    {
        $matched = preg_match('/^\[(?<timestamp>[^\]]+)\] ' . $label . ': ' . preg_quote($message, '/') . '$/', $line, $matches);

        self::assertSame(1, $matched, 'Unexpected log line: ' . $line);
        self::assertNotFalse(
            DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $matches['timestamp']),
            'Unparseable timestamp: ' . $matches['timestamp']
        );
    }
}