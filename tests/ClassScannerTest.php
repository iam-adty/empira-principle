<?php

declare(strict_types=1);

namespace Empira\Tests;

use Empira\ClassScanner;
use PHPUnit\Framework\TestCase;

/**
 * The scanner underneath discovery, tested on its own.
 *
 * Discovery is normally proved through `discoverCommands()` or `discover()`,
 * which is right — the path *is* the mechanism, so it has to be a real path.
 * But both of those then filter what they receive, and so cannot say anything
 * about a type that was dropped before the filter ran. That gap is where the
 * interface bug lived: `discoverCommands()` was green the whole time, while
 * every console command printed a warning about an autoloadable interface.
 *
 * So this asks the scanner directly, and asks it about the types the filters
 * would have hidden.
 */
final class ClassScannerTest extends TestCase
{
    private string $directory;

    /** @var array<class-string, string> */
    private static array $fixturePaths = [];

    private static bool $loaderRegistered = false;

    private const NAMESPACE = 'Empira\Fixtures\Scanned';

    public static function setUpBeforeClass(): void
    {
        if (self::$loaderRegistered) {
            return;
        }

        self::$loaderRegistered = true;

        spl_autoload_register(static function (string $class): void {
            $path = self::$fixturePaths[$class] ?? null;

            if ($path !== null && is_file($path)) {
                require_once $path;
            }
        });
    }

    protected function setUp(): void
    {
        $this->directory = Temp::dir('empira-scan-');
    }

    protected function tearDown(): void
    {
        Temp::remove($this->directory);
    }

    /**
     * Write a fixture whose class name does not have to be valid PHP on its
     * own, because the point of some of these tests is a file whose contents and
     * whose path disagree.
     */
    private function write(string $relative, string $class, string $body): void
    {
        $path = $this->directory . '/' . $relative;

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o777, true);
        }

        file_put_contents($path, $body);

        self::$fixturePaths[$class] = $path;
    }

    /**
     * An interface is autoloadable, and the directory holds exactly what the
     * namespace implies — so reporting it as a PSR-4 mismatch is simply wrong.
     *
     * It is also the shape an application's own seams take: one-method contracts
     * an override implements. Every application using them got a warning per
     * console command, naming a file that was exactly where it belonged.
     */
    public function testAnInterfaceInTheDirectoryIsReturnedRatherThanReportedAsMissing(): void
    {
        $class = self::NAMESPACE . '\ProvidesSecret';

        $this->write('ProvidesSecret.php', $class, <<<PHP
            <?php

            declare(strict_types=1);

            namespace Empira\Fixtures\Scanned;

            interface ProvidesSecret
            {
                public function secret(): string;
            }
            PHP);

        $found = $this->scanQuietly();

        self::assertContains($class, $found, 'an autoloadable interface is not a PSR-4 mistake');
    }

    public function testAnInterfaceProducesNoWarning(): void
    {
        $class = self::NAMESPACE . '\ProvidesSecret';

        $this->write('ProvidesSecret.php', $class, <<<PHP
            <?php

            declare(strict_types=1);

            namespace Empira\Fixtures\Scanned;

            interface ProvidesSecret
            {
                public function secret(): string;
            }
            PHP);

        $this->assertNothingWasLogged();
    }

    public function testClassesAndTraitsAreBothReturned(): void
    {
        $this->write('Service.php', self::NAMESPACE . '\Service', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Empira\Fixtures\Scanned;

            final class Service
            {
            }
            PHP);

        $this->write('Concern.php', self::NAMESPACE . '\Concern', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Empira\Fixtures\Scanned;

            trait Concern
            {
            }
            PHP);

        $found = $this->scanQuietly();

        self::assertContains(self::NAMESPACE . '\Service', $found);
        self::assertContains(self::NAMESPACE . '\Concern', $found, 'a trait is a class_exists() success, and is autoloadable');
    }

    /**
     * The warning is for a file whose contents disagree with its path — a
     * genuine PSR-4 mistake, and the only thing it should ever fire about.
     */
    public function testAFileWhoseClassDoesNotMatchItsPathIsReportedAndSkipped(): void
    {
        $directory = $this->directory;

        $this->write('Wrong.php', self::NAMESPACE . '\Wrong', <<<PHP
            <?php

            declare(strict_types=1);

            namespace Empira\Fixtures\Scanned;

            final class SomewhereElse
            {
            }
            PHP);

        $found = [];
        $logged = $this->captureLog(static function () use ($directory, &$found): void {
            $found = ClassScanner::in($directory, self::NAMESPACE);
        });

        self::assertSame([], $found);
        self::assertStringContainsString('no autoloadable class', $logged);
        self::assertStringContainsString('Wrong.php', $logged);
    }

    public function testNestedDirectoriesAreNamespacedByPath(): void
    {
        $class = self::NAMESPACE . '\Support\Thing';

        $this->write('Support/Thing.php', $class, <<<PHP
            <?php

            declare(strict_types=1);

            namespace Empira\Fixtures\Scanned\Support;

            final class Thing
            {
            }
            PHP);

        self::assertContains($class, $this->scanQuietly());
    }

    public function testADirectoryThatDoesNotExistAnswersWithNone(): void
    {
        self::assertSame([], ClassScanner::in($this->directory . '/nowhere', self::NAMESPACE));
    }

    // ---------------------------------------------------------------- helpers

    /**
 * @return list<class-string>
 */
    private function scanQuietly(): array
    {
        $directory = $this->directory;
        $result = [];

        $this->captureLog(static function () use ($directory, &$result): void {
            $result = ClassScanner::in($directory, self::NAMESPACE);
        });

        return $result;
    }

    private function assertNothingWasLogged(): void
    {
        $directory = $this->directory;

        self::assertSame('', $this->captureLog(static fn(): array => ClassScanner::in($directory, self::NAMESPACE)));
    }

    /**
     * Run a scan with `error_log` pointed at a file, and return what it wrote.
     *
     * The ini setting rather than `set_error_handler`, because `error_log()` is
     * a direct write to the SAPI logger and never raises anything an error
     * handler could see. A handler installed here would capture nothing at all
     * and this test would pass for the wrong reason.
     */
    private function captureLog(callable $scan): string
    {
        $log = Temp::file('empira-scan-log-');
        $previous = (string) ini_get('error_log');

        ini_set('error_log', $log);

        try {
            $scan();
        } finally {
            ini_set('error_log', $previous);
        }

        return is_file($log) ? (string) file_get_contents($log) : '';
    }
}