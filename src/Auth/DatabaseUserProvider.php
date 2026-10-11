<?php

declare(strict_types=1);

namespace IamAdty\Empira\Auth;

use IamAdty\Empira\Data\Db;

/**
 * Users in a table.
 *
 *     $users = new DatabaseUserProvider($db, table: 'users', credential: 'email');
 *     $user = $users->byCredential('ada@example.com');
 *     $users->verifyPassword($user, 'correct horse');
 *
 * The provider holds the hashing and the comparison, so a controller never
 * touches a password and the hash never leaves the layer that stored it.
 *
 * Passwords are hashed with PHP's own `password_hash`, which defaults to
 * bcrypt. There is no separate hasher interface: swapping the algorithm means
 * writing a different {@see UserProvider}, which is the same seam an ORM would
 * arrive through. A `Hasher` would have been a second way to say that, chosen
 * by nobody.
 */
final class DatabaseUserProvider implements UserProvider
{
    /** Attributes not worth carrying into a {@see User}. */
    private const HIDDEN = ['password', 'password_hash', 'remember_token'];

    /**
     * @param string $table      the users table
     * @param string $credential the column a user signs in with
     * @param string $hashColumn the column holding the password hash
     */
    public function __construct(
        private readonly Db $db,
        private readonly string $table = 'users',
        private readonly string $credential = 'email',
        private readonly string $hashColumn = 'password_hash',
    ) {
    }

    public function byId(int|string $id): ?User
    {
        $row = $this->db->first($this->table, fn($query) => $query->where('id', $id));

        return $row === null ? null : $this->toUser($row);
    }

    public function byCredential(string $credential): ?User
    {
        $row = $this->db->first($this->table, fn($query) => $query->where($this->credential, $credential));

        return $row === null ? null : $this->toUser($row);
    }

    public function verifyPassword(User $user, string $password): bool
    {
        $hash = $this->db->first($this->table, fn($query) => $query->where('id', $user->id))[$this->hashColumn] ?? null;

        if (!is_string($hash) || $hash === '') {
            // A user with no stored hash cannot be authenticated, and saying so
            // with false is what stops a missing column reading as "any
            // password works".
            return false;
        }

        return password_verify($password, $hash);
    }

    /**
     * Hash a password for storage.
     *
     * A method on the provider rather than a free function so the hashing
     * travels with the thing that verifies it.
     */
    public function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toUser(array $row): User
    {
        $attributes = $row;

        foreach (self::HIDDEN as $hidden) {
            unset($attributes[$hidden]);
        }

        return new User(
            id: is_scalar($row['id'] ?? null) ? (string) $row['id'] : '',
            attributes: $attributes,
        );
    }
}
