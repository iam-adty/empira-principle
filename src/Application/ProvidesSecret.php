<?php

declare(strict_types=1);

namespace Empira\Application;

/**
 * Where an application gets its signing secret.
 *
 * One method, so replacing it is one method.
 *
 * @see EnvSecret the framework's own, which refuses rather than inventing one
 */
interface ProvidesSecret
{
    /** @throws \RuntimeException when there is no usable secret */
    public function secret(): string;
}