<?php

declare(strict_types=1);

namespace IamAdty\Empira\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use IamAdty\Empira\Config\Config;
use IamAdty\Empira\Config\DatabaseConfig;

/**
 * Builds the {@see EntityManagerInterface} the framework persists through.
 *
 * A long-lived process is the reason this exists rather than a call to
 * `EntityManager::create()` in the application. Under php-fpm a manager per
 * request costs nothing, because the request is the process. Under FrankenPHP a
 * worker serves thousands of requests in one process, and an EntityManager that
 * is not cleared between them keeps serving **request two out of request one's
 * identity map** — a stale user, a stale permission, a write that never lands.
 *
 * That is the same trap as the session, and it has the same answer: reset per
 * request, never per boot. {@see \IamAdty\Empira\Application} calls `clear()`
 * where it calls `Session::beginRequest()`.
 */
final class EntityManagerFactory
{
    /**
     * An EntityManager over these settings.
     *
     * @param list<string> $entityPaths directories holding `#[Entity]` classes
     *
     * @throws \Doctrine\DBAL\Exception            when the database is not there
     * @throws \Doctrine\ORM\ORMException          when the mapping is invalid
     */
    public static function make(Config $database, array $entityPaths, bool $devMode = true): EntityManagerInterface
    {
        return self::makeFor(self::connection($database), $entityPaths, $devMode);
    }

    /**
     * An EntityManager over a connection that is already open.
     *
     * Taking the connection rather than opening one is what lets an application
     * share a single connection between its migrations and its requests.
     *
     * @param list<string> $entityPaths
     */
    public static function makeFor(Connection $connection, array $entityPaths, bool $devMode = true): EntityManagerInterface
    {
        return new EntityManager($connection, self::configuration($entityPaths, $devMode));
    }

    /**
     * Doctrine's configuration, built by hand.
     *
     * Not `ORMSetup::createAttributeMetadataConfig()`, which is the obvious
     * call and is wrong twice over here. It is deprecated on PHP 8.4, and it
     * insists on a cache implementation — reaching for `symfony/cache` when the
     * framework has no other reason to want Symfony than to configure a cache is
     * a poor trade.
     *
     * A null cache pool tells Doctrine to keep metadata in memory for the life
     * of the process, which is right for a worker: it is parsed once and used
     * by every request that process serves.
     *
     * Native lazy objects are on because PHP 8.4 has them. Without that Doctrine
     * wants `symfony/var-exporter` to generate lazy ghost classes, and it warns
     * that the alternative is going away in 4.0 — so the framework that requires
     * PHP ^8.4 should use the feature that floor was chosen for.
     *
     * @param list<string> $entityPaths
     */
    public static function configuration(array $entityPaths, bool $devMode = true): Configuration
    {
        $configuration = new Configuration();
        $configuration->setMetadataDriverImpl(new AttributeDriver($entityPaths));
        $configuration->setProxyDir(sys_get_temp_dir());
        $configuration->setProxyNamespace('EmpiraProxies');
        $configuration->setAutoGenerateProxyClasses($devMode);
        $configuration->enableNativeLazyObjects(true);

        return $configuration;
    }

    /**
     * The DBAL connection these settings describe.
     */
    public static function connection(Config $database): Connection
    {
        return DatabaseConfig::connection($database);
    }
}