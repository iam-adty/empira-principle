<?php

declare(strict_types=1);

namespace Empira\Container;

use Psr\Container\NotFoundExceptionInterface;

/**
 * Nothing is bound under that name, or nothing bound to it could be built.
 *
 * Separate from {@see ContainerException} because the two mean different things
 * to a caller. PSR-11 draws the same line: `ContainerExceptionInterface` is
 * "something went wrong", `NotFoundExceptionInterface` is "and specifically,
 * there is nothing here" — which is what a consumer checks `has()` to avoid and
 * what a fallback branch catches.
 *
 * It extends `ContainerException`, so existing `catch (ContainerException)` in
 * an application keeps catching everything it caught before. PSR-11 requires
 * the same inheritance: `NotFoundExceptionInterface` extends
 * `ContainerExceptionInterface`.
 */
final class NotFound extends ContainerException implements NotFoundExceptionInterface
{
}