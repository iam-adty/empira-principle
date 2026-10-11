<?php

declare(strict_types=1);

namespace IamAdty\Empira\Tests;

/**
 * Scratch files, inside the repository rather than the system temp directory.
 *
 * `sys_get_temp_dir()` works, but a failing run leaves its fixtures somewhere
 * nobody thinks to look, and two runs can collide. Here, `principle/tmp/` is
 * visible in both places the suite runs from — on the host, and inside the test
 * container where the repository is mounted at `/app` — so what the suite wrote
 * is where you would go to look for it.
 *
 * The directory is committed as an empty folder so `tempnam()` has somewhere to
 * write on a fresh clone; {@see Temp::root()} creates it regardless.
 *
 *     $directory = Temp::dir('empira-console-');   // unique, created
 *     $file = Temp::file('empira-env');            // unique file
 */
final class Temp
{
    /**
     * A unique directory, created and returned.
     */
    public static function dir(string $prefix = 'empira-'): string
    {
        $path = self::root() . '/' . $prefix . bin2hex(random_bytes(6));

        if (!mkdir($path, 0o777, true) && ! is_dir($path)) {
            throw new \RuntimeException(sprintf('Could not create a temporary directory at %s.', $path));
        }

        return $path;
    }

    /**
     * A unique file name, created and returned.
     *
     * The caller owns it; several tests remove theirs in a `finally`, which is
     * the only reason `tmp/` stays empty between runs.
     */
    public static function file(string $prefix = 'empira-'): string
    {
        $path = tempnam(self::root(), $prefix);

        if ($path === false) {
            throw new \RuntimeException('Could not create a temporary file in ' . self::root() . '.');
        }

        return $path;
    }

    /**
     * Delete a file, or a directory and everything inside it.
     *
     * Recursive because several tests build a tree — a logger creating its own
     * parent directories, say — and a plain `rmdir()` on the directory the
     * helper returned fails silently when a test forgets to silence it, leaving
     * `tmp/` to fill up one fixture per run.
     *
     * Nothing here throws: cleanup runs in a `finally`, and an exception from
     * deleting a temporary file would mask whatever the test was actually
     * reporting.
     */
    public static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::remove($path . '/' . $entry);
            }
        }

        @rmdir($path);
    }

    /**
     * This package's `tmp/`, created if it is not there.
     *
     * `dirname(__DIR__)` rather than an environment variable, because the same
     * test files run from two places — the host, and the container where the
     * repository sits at `/app` — and both must resolve to the same folder.
     */
    public static function root(): string
    {
        $root = dirname(__DIR__) . '/tmp';

        if (! is_dir($root) && ! mkdir($root, 0o777, true) && ! is_dir($root)) {
            throw new \RuntimeException(sprintf('Could not create the temporary directory at %s.', $root));
        }

        return $root;
    }
}