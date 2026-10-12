<?php

declare(strict_types=1);

namespace Empira\Security;

/**
 * Whoever a request has been attributed to.
 *
 * Deliberately almost nothing: an identifier and nothing else. A layout needs
 * an id to render, a guard needs to hand one back, and everything richer — a
 * name, an email, roles — belongs to whoever decides what a user *is*.
 *
 * That is why this is an interface rather than a base class. The core can ask
 * "who is this" without knowing whether the answer is a Doctrine entity, a row
 * of an array, or something an application defined for a request and threw
 * away.
 */
interface Identity
{
    /**
     * This identity's identifier, or null when it has never been stored.
     *
     * Null is not "user zero" — it is the difference between a user that has
     * not been persisted yet and one that has, and conflating them would let an
     * unsaved identity be treated as a real one.
     */
    public function id(): int|string|null;
}