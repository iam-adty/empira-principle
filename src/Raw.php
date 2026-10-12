<?php

declare(strict_types=1);

namespace Empira;

/**
 * A string that is emitted verbatim, bypassing escaping.
 *
 * Only ever hand this values you control — never request input.
 *
 * Verbatim means verbatim: nothing about the content is sanitised, so a string
 * containing `</style>` will close the element around it and everything after it
 * is parsed as markup. Wrapping a stylesheet in a `<style>` element does not
 * make that text safe; it only decides where the text lands.
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