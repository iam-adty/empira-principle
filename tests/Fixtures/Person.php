<?php

declare(strict_types=1);

namespace Empira\Tests\Fixtures;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Empira\Security\Identity;

/**
 * A small entity for the framework's own ORM tests.
 *
 * These tests used `Empira\Auth\User`, which made the framework test its
 * authentication package — a dependency running the other way, and one that
 * cannot survive auth becoming a module. A local fixture is the fix, and it is
 * *smaller* than the user it replaces: an id, a name, and an email, which is all
 * the identity-map and provider tests actually read.
 *
 * The mapping mirrors a real entity deliberately — a generated integer id, a
 * unique email, and a JSON column — because `PostgresTest` uses it to assert
 * that the *framework's* EntityManager configuration produces a schema a real
 * server accepts. A fixture that was simpler than production would make those
 * tests pass for reasons that have nothing to do with Doctrine.
 */
#[ORM\Entity]
#[ORM\Table(name: 'people')]
#[ORM\UniqueConstraint(name: 'people_email_unique', columns: ['email'])]
class Person implements Identity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $email = '';

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $name = '';

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private array $attributes = [];

    public function __construct(string $email = '', string $name = '', array $attributes = [])
    {
        $this->email = $email;
        $this->name = $name;
        $this->attributes = $attributes;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function attributes(): array
    {
        return $this->attributes;
    }
}