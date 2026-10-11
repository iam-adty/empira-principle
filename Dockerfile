# The test runner.
#
# The suite runs in Docker so that a contributor and CI get the same PHP, the
# same extensions and the same database server. PHP 8.4 is the lowest version
# composer.json allows, so this is the build most likely to fail first — which
# is the one worth failing first.
FROM php:8.4-cli

# pdo_sqlite is built in; pdo_pgsql is not, and compiling it needs the libpq
# headers, which the base image does not carry. `unzip` is here for Composer,
# which cannot extract a downloaded package without it or `7z`.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev unzip \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql \
    && apt-get purge -y libpq-dev \
    && rm -rf /var/lib/apt/lists/*

# libpq5 is the runtime half of libpq-dev and is what pdo_pgsql.so links
# against. It must be installed in its own right: purging libpq-dev takes the
# headers away, and `--auto-remove` would take libpq5 with them, leaving an
# extension that compiles and then fails to load.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq5 \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# WORKDIR is the package's place *inside the workspace*, not the root of it,
# because the suite shares a scratch folder with the app: `Temp::root()` is two
# levels up from `tests/`, so the container must lay the workspace out exactly
# as the host does. `/workspace/tmp` here is `../tmp` next to this file. Every
# COPY below is relative to WORKDIR and needs no change.
WORKDIR /workspace/principle

# Dependencies first: this layer is only rebuilt when composer.json changes, so
# editing a test does not re-download PHPUnit.
#
# The empty `tests/Fixtures` directory exists only so the dev autoload classmap
# has something to scan; it is regenerated properly once the source is copied.
COPY composer.json ./
RUN mkdir -p tests/Fixtures \
    && composer install --no-interaction --no-progress --no-scripts

COPY . .

# Now that the real fixtures are present, rebuild the classmap over them.
RUN composer dump-autoload --no-interaction

CMD ["vendor/bin/phpunit"]
