<?php

declare(strict_types=1);

namespace Empira\Application;

/**
 * The default secret: `APP_SECRET`, and nothing to fall back to.
 *
 * A component token is `base64url(payload).base64url(hmac)` and the payload
 * carries the component class and its props — so with the secret in the open,
 * anyone can mint a signed URL for any component with any props they like.
 * Nothing 500s, nothing is logged, and the forged URL simply works.
 *
 * There was a fallback once, a constant in the application class. It was public
 * in three places, so "change it before deploying" was a step every deployment
 * had to remember. A missing secret now stops the application everywhere, which
 * makes a first run one step longer and every deploy one safer.
 *
 * **It must not change between deploys** — that invalidates every component link
 * already in a user's history.
 *
 * ## The message names `.env` without saying where it is
 *
 * It used to interpolate its own directory, which was true while this class
 * lived in the application and became actively wrong the moment it moved into a
 * package: it would have pointed at `vendor/…/principle/.env`, a file that does
 * not exist and never will. A refusal that names a file in the wrong place is
 * worse than one that names only the file's name.
 */
final class EnvSecret implements ProvidesSecret
{
    public function secret(): string
    {
        $secret = (string) ($_ENV['APP_SECRET'] ?? '');

        if (strlen($secret) >= 16) {
            return $secret;
        }

        throw new \RuntimeException(sprintf(
            'APP_SECRET is %s. Generate one with: php -r \'echo bin2hex(random_bytes(32)), PHP_EOL;\' '
            . 'and put it in your application\'s .env beside the rest of .env.example. It must be at '
            . 'least 16 bytes, and it must not change between deploys — changing it invalidates every '
            . 'component link already in a user\'s history.',
            $secret === '' ? 'not set' : sprintf('too short (%d bytes, 16 needed)', strlen($secret)),
        ));
    }
}