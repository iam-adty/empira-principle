<?php

declare(strict_types=1);

namespace Empira;

/**
 * The class names a directory implies, for the things that are found by
 * existing rather than by being registered.
 *
 * Two things scan a directory instead of being listed: routes, via
 * `#[Route]`, and console commands, via `Console\Command`. They are the same
 * question asked twice — *which class does this path hold?* — so the answer
 * lives here once. A second copy would be free until the day one of them gained
 * a fix the other did not, and the difference would surface as a command that
 * silently was not found.
 *
 * ## What it refuses to do
 *
 * It does not guess past a PSR-4 mismatch. If the path says
 * `src/Console/Seed.php` but the autoloader cannot produce
 * `App\Console\Seed`, that is a composer `autoload` mapping which does not cover
 * the directory — a real error, and one that produces an empty list rather than
 * a class that happens to load. An empty list is the dangerous failure: the
 * routes simply are not there, or the command does not appear in `empira list`,
 * and neither says why. So it goes to `error_log` by name.
 *
 * A directory that does not exist is not a mismatch and not an error. An
 * application with no commands has no `src/Console/`, and asking for the classes
 * under a path nobody created should answer with none rather than throw.
 *
 * @internal
 */
final class ClassScanner
{
    /**
     * Every autoloadable type under `$directory`, as `$namespace` describes it.
     *
     * Interfaces included, because they are autoloadable and callers filter
     * what they receive — a scanner that dropped them would make an interface
     * look like a PSR-4 mistake and log about a file that was exactly where it
     * belonged.
     *
     * @return list<class-string>
     */
    public static function in(string $directory, string $namespace): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $classes = [];
        $prefix = trim($namespace, '\\') . '\\';

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen(rtrim($directory, '/')) + 1);
            $class = $prefix . str_replace(['/', '.php'], ['\\', ''], $relative);

            if (!class_exists($class) && !interface_exists($class)) {
                // PSR-4 mismatch: the autoloader cannot find what the path implies.
                error_log(sprintf('empira: no autoloadable class %s at %s', $class, $file->getPathname()));

                continue;
            }

            $classes[] = $class;
        }

        sort($classes);

        return $classes;
    }
}