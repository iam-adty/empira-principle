<?php

declare(strict_types=1);

namespace IamAdty\Empira\Console;

/**
 * Writes the four kinds of line a CLI needs, coloured only when a human is
 * watching.
 *
 * Colour is decided by asking the stream whether it is a terminal. Piped into a
 * file or captured by a test, the same call emits the same text without escape
 * codes, which is what keeps `empira list > out.txt` readable and lets tests
 * assert on plain strings.
 */
final class Output
{
    private const RESET = "\033[0m";

    private const COLOURS = [
        'success' => '32',
        'error' => '31',
        'warn' => '33',
        'info' => '36',
    ];

    /** @var resource */
    private $stream;

    private bool $colours;

    /**
     * @param resource|null $stream  where lines go; STDOUT when null
     * @param bool|null     $colours force colour on or off; detected when null
     */
    public function __construct(mixed $stream = null, ?bool $colours = null)
    {
        $this->stream = $stream ?? STDOUT;
        $this->colours = $colours ?? stream_isatty($this->stream);
    }

    public function line(string $message = ''): void
    {
        fwrite($this->stream, $message . PHP_EOL);
    }

    public function success(string $message): void
    {
        $this->write($message, 'success');
    }

    public function error(string $message): void
    {
        $this->write($message, 'error');
    }

    public function info(string $message): void
    {
        $this->write($message, 'info');
    }

    public function warn(string $message): void
    {
        $this->write($message, 'warn');
    }

    /**
     * Are escape codes being written?
     */
    public function isColoured(): bool
    {
        return $this->colours;
    }

    private function write(string $message, string $kind): void
    {
        if ($this->colours) {
            $message = "\033[" . self::COLOURS[$kind] . 'm' . $message . self::RESET;
        }

        $this->line($message);
    }
}
