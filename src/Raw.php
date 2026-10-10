<?php

declare(strict_types=1);

namespace IamAdty\Empira;

/**
 * A string that is emitted verbatim, bypassing escaping.
 *
 * Only ever hand this values you control — never request input.
 */
final class Raw implements \Stringable
{
    public function __construct(private readonly string $html)
    {
    }

    public static function of(string $html): self
    {
        return new self($html);
    }

    public function get(): string
    {
        return $this->html;
    }

    public function __toString(): string
    {
        return $this->html;
    }
}