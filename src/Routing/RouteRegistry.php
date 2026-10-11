<?php

declare(strict_types=1);

namespace IamAdty\Empira\Routing;

use IamAdty\Empira\Attributes\Route;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;

/**
 * Finds the routes declared with {@see Route} in a directory.
 *
 *     $app->discover(__DIR__, __NAMESPACE__);
 *
 * A **class-level** `#[Route]` means "this URL renders this class" — normally a
 * Layout, which the framework renders for you. A **method-level** one names the
 * method to call, and becomes `[Class::class, 'method']`.
 *
 * ## Ordering
 *
 * Routes are collected then sorted by specificity, because declaration order
 * from a filesystem scan is not something anyone can reason about: `/todos/new`
 * has to beat `/todos/{id}` or the dynamic route swallows it, and if that
 * depended on which file happened to sort first, renaming a file would
 * silently produce a 404.
 *
 * The comparator is on {@see RouteCollection::compareSpecificity()} and is
 * stable, so routes of equal specificity keep the order they were declared in.
 *
 * ## What it skips
 *
 * Abstract classes and interfaces, anything not a Layout/Controller the
 * container can build, and files whose class does not match their path — that
 * last one is logged rather than fataled, because a PSR-4 mismatch is a
 * configuration mistake and should be visible.
 */
final class RouteRegistry
{
    /**
     * Register every `#[Route]` found under `$directory`.
     *
     * @param class-string $namespace the namespace `$directory` maps to
     */
    public static function discover(RouteCollection $routes, string $directory, string $namespace): void
    {
        foreach (self::classesIn($directory, $namespace) as $class) {
            self::registerClass($routes, $class);
        }
    }

    /**
     * Turn one class's attributes into routes.
     *
     * @param class-string $class
     */
    public static function registerClass(RouteCollection $routes, string $class): void
    {
        $reflection = new ReflectionClass($class);

        // An abstract class, an interface or an enum cannot be a page, and neither
        // can a class whose constructor is private — registering one would
        // register a URL that cannot be built when the request arrives.
        if ($reflection->isAbstract() || $reflection->isInterface() || $reflection->isEnum() || !$reflection->isInstantiable()) {
            return;
        }

        $derived = self::deriveName($reflection);

        // Class-level attributes render the class itself — the page/URL case.
        foreach (self::attributesOf($reflection) as $attribute) {
            $routes->addDiscovered($attribute, $class, null, $derived);
        }

        // Method-level attributes name the method to call.
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            $methodDerived = self::kebab($method->getName());

            foreach (self::attributesOf($method) as $attribute) {
                $routes->addDiscovered($attribute, $class, $method->getName(), $derived . '.' . $methodDerived);
            }
        }
    }


    /**
     * @return list<string>
     */
    private static function classesIn(string $directory, string $namespace): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $classes = [];
        $prefix = trim($namespace, '\\') . '\\';

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen(rtrim($directory, '/')) + 1);
            $class = $prefix . str_replace(['/', '.php'], ['\\', ''], $relative);

            if (!class_exists($class)) {
                // PSR-4 mismatch: the autoloader cannot find what the path implies.
                error_log(sprintf('empira: no autoloadable class %s at %s', $class, $file->getPathname()));

                continue;
            }

            $classes[] = $class;
        }

        sort($classes);

        return $classes;
    }

    /**
     * @param ReflectionClass<object>|ReflectionMethod $reflector
     *
     * @return list<Route>
     */
    private static function attributesOf(ReflectionClass|ReflectionMethod $reflector): array
    {
        return array_map(
            static fn(ReflectionAttribute $attribute): Route => $attribute->newInstance(),
            $reflector->getAttributes(Route::class)
        );
    }

    /**
     * `App\Pages\HomeLayout` becomes `pages.home`, dropping a leading `App`.
     *
     * @param ReflectionClass<object> $reflection
     */
    /**
     * Derive a route name from the class that declares it.
     *
     * `App\Pages\HomeLayout` becomes `pages.home`: the package root is not
     * part of the name, and the class-name suffix describes *what the class
     * is*, not what the route is called.
     */
    private static function deriveName(ReflectionClass $reflection): string
    {
        $parts = explode('\\', $reflection->getName());

        // `App\` and `Src\` are package roots — every application has them, so
        // they carry no information about the route.
        if (count($parts) > 1 && in_array($parts[0], ['App', 'Src'], true)) {
            array_shift($parts);
        }

        $short = (string) preg_replace('/(?:Layout|Page|Controller)$/', '', (string) array_pop($parts));

        $segments = array_map(self::kebab(...), $parts);
        $segments[] = self::kebab($short);

        return implode('.', array_filter($segments, static fn(string $s): bool => $s !== ''));
    }

    /** `TodoNew` becomes `todo-new`. */
    private static function kebab(string $part): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $part));
    }
}