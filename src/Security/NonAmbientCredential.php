<?php

declare(strict_types=1);

namespace Empira\Security;

/**
 * Marker for a credential a browser will not send by itself.
 *
 * CSRF protection exists because a session cookie is **ambient**: the browser
 * attaches it to any request to your host, including one a third-party page
 * caused. That is a feature for navigation and a hole for writes, so a request
 * carrying only a session cookie is checked.
 *
 * A bearer token is the opposite. It lives in JavaScript, is attached by code
 * the attacker does not control, and arrives with `Authorization` rather than
 * being swept up by cookie rules — so a cross-site form post cannot produce
 * one, and CSRF has nothing left to protect against.
 *
 * Core asks whether the guard that answered is *marked*, never whether it is
 * some particular class. That is what lets a module add a mechanism — a signed
 * header, a client certificate — without the core learning its name, and what
 * stops CSRF from growing a list of `instanceof` checks as those accumulate.
 */
interface NonAmbientCredential
{
}