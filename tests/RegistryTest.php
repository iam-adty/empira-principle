<?php

declare(strict_types=1);

namespace Empira\Tests;

use Empira\Registry;
use Empira\State\StateStore;
use Empira\Tests\Fixtures\CounterFixture;
use Empira\Tests\Fixtures\MemoryStore;
use Empira\Tests\Fixtures\UnregisteredFixture;
use Empira\UnknownComponentException;
use PHPUnit\Framework\TestCase;

/**
 * What a token is allowed to name.
 *
 * Two ways in: an explicit alias, or a namespace that admits a whole tree. The
 * second exists so that composing components does not cost a registration each
 * — see `testANestedComponentNeedsNoRegistrationOfItsOwn`.
 */
final class RegistryTest extends TestCase
{
    private const FIXTURES = 'Empira\\Tests\\Fixtures';

    // ------------------------------------------------------------- aliases

    public function testAnAliasResolvesToItsClass(): void
    {
        $registry = (new Registry())->alias('counter', CounterFixture::class);

        self::assertSame(CounterFixture::class, $registry->resolve('counter'));
    }

    public function testAnAliasStillWinsOverTheNamespaceRule(): void
    {
        $registry = (new Registry())
            ->alias('counter', CounterFixture::class)
            ->allowNamespace(self::FIXTURES);

        // The short name must not have been displaced by the fully-qualified one
        // the namespace rule would otherwise admit.
        self::assertSame(CounterFixture::class, $registry->resolve('counter'));
    }

    public function testAnAliasProducesAShortTokenName(): void
    {
        $registry = (new Registry())->alias('counter', CounterFixture::class);

        self::assertSame('counter', $registry->nameFor(CounterFixture::class));
    }

    public function testAnUnaliasedComponentIsNamedByItsClass(): void
    {
        $registry = (new Registry())->allowNamespace(self::FIXTURES);

        // Longer token, and that is the trade for not registering anything.
        self::assertSame(CounterFixture::class, $registry->nameFor(CounterFixture::class));
    }

    // ---------------------------------------------------------- namespaces

    public function testAComponentUnderAnAllowedNamespaceResolves(): void
    {
        $registry = (new Registry())->allowNamespace(self::FIXTURES);

        self::assertSame(CounterFixture::class, $registry->resolve(CounterFixture::class));
    }

    public function testATrailingSeparatorIsOptional(): void
    {
        $with = (new Registry())->allowNamespace(self::FIXTURES)->resolve(CounterFixture::class);
        $without = (new Registry())->allowNamespace(self::FIXTURES . '\\')->resolve(CounterFixture::class);

        self::assertSame($with, $without);
    }

    public function testASubNamespaceOfAnAllowedOneIsIncluded(): void
    {
        $registry = (new Registry())->allowNamespace('Empira');

        self::assertSame(CounterFixture::class, $registry->resolve(CounterFixture::class));
    }

    public function testAStringPrefixCollisionIsNotAPrefixMatch(): void
    {
        // `Fixture` and `Fixtures` differ by one character, and a naive prefix
        // test would admit the whole second directory. The trailing separator
        // `allowNamespace()` appends is what stops it — and this is the mistake
        // that would quietly widen the boundary to a directory nobody named.
        $registry = (new Registry())->allowNamespace('Empira\\Tests\\Fixture');

        self::expectException(UnknownComponentException::class);

        $registry->resolve(CounterFixture::class);
    }

    public function testAClassOutsideEveryAllowedNamespaceIsRefused(): void
    {
        $registry = (new Registry())->allowNamespace('Some\\Other\\Package');

        self::expectException(UnknownComponentException::class);

        $registry->resolve(CounterFixture::class);
    }

    public function testANonComponentInAnAllowedNamespaceIsStillRefused(): void
    {
        $registry = (new Registry())->allowNamespace(self::FIXTURES);

        // The namespace says which code is in scope. It says nothing about
        // whether the class is one that can be built and have actions invoked —
        // and `MemoryStore` lives in the very same directory as the components.
        self::expectException(UnknownComponentException::class);

        $registry->resolve(MemoryStore::class);
    }

    public function testAClassThatDoesNotExistIsRefused(): void
    {
        $registry = (new Registry())->allowNamespace(self::FIXTURES);

        self::expectException(UnknownComponentException::class);

        $registry->resolve(self::FIXTURES . '\\NoSuchComponent');
    }

    public function testAnEmptyRegistryStillRefusesEverything(): void
    {
        $registry = new Registry();

        self::expectException(UnknownComponentException::class);

        $registry->resolve(CounterFixture::class);
    }

    public function testTheFailureMessageSaysWhy(): void
    {
        $registry = (new Registry())->allowNamespace('Some\\Other\\Package');

        $this->expectException(UnknownComponentException::class);
        $this->expectExceptionMessage('not reachable');

        $registry->resolve(UnregisteredFixture::class);
    }

    // ------------------------------------------------------- guard() still bites

    public function testRegisteringANonComponentIsStillRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Registry())->add(MemoryStore::class);
    }

    public function testRegisteringAClassThatIsNotAComponentAtAllIsStillRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Registry())->allowNamespace(self::FIXTURES);
        (new Registry())->alias('store', MemoryStore::class);
    }

    public function testTheStateStoreInterfaceItselfIsNotAComponent(): void
    {
        // Guards the test above against quietly testing the wrong thing: if
        // `MemoryStore` ever stopped being a `StateStore`, `testANonComponentIn
        // AnAllowedNamespaceIsStillRefused` would stop proving what its name says.
        self::assertTrue(is_a(MemoryStore::class, StateStore::class, true));
        self::assertFalse(is_a(MemoryStore::class, \Empira\Component::class, true));
    }
}