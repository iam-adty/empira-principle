<?php

declare(strict_types=1);

namespace Empira\Container;

use Empira\Exception;
use Psr\Container\ContainerExceptionInterface;

/**
 * Something went wrong resolving a name.
 *
 * Implements PSR-11's marker so a PSR-aware consumer can catch this with its own
 * catch block rather than by naming an Empira class it has never heard of.
 *
 * "Went wrong" is deliberately broad — a factory returning a non-object, a
 * circular dependency, an unresolvable parameter. "Nothing is bound under that
 * name" is a narrower and more useful thing to say, and is
 * {@see NotFound} instead.
 *
 * Not `final`, unlike most classes here: PSR-11 draws a distinction between
 * "something went wrong" and "there is nothing here", and expressing the second
 * as a subclass of the first is the only shape that keeps a consumer's
 * `catch (ContainerExceptionInterface)` working.
 */
class ContainerException extends Exception implements ContainerExceptionInterface
{
}