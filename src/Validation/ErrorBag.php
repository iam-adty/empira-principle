<?php

declare(strict_types=1);

namespace Empira\Validation;

/**
 * Everything that went wrong, keyed by the field that caused it.
 *
 * One field can fail several rules, and a form wants to show them all rather
 * than one at a time, so messages accumulate in a list. The bag is also
 * `JsonSerializable`, which is what makes it useful at the edge of the
 * framework: hand it straight to a response or an htmx event and the client
 * gets `{ "email": ["The email field is required."] }`.
 *
 *     $errors = Validator::make($input, ['email' => 'required|email']);
 *
 *     if ($errors->any()) {
 *         return Response::json($errors, 422);
 *     }
 *
 * It is `Countable` too, so a component can size an error region with
 * `count($errors)`.
 */
final class ErrorBag implements \Countable, \JsonSerializable
{
    /** @var array<string, list<string>> */
    private array $messages = [];

    /**
     * Record a failure against a field, keeping any earlier ones.
     */
    public function add(string $field, string $message): void
    {
        $this->messages[$field][] = $message;
    }

    /**
     * Does this field have at least one message?
     */
    public function has(string $field): bool
    {
        return ($this->messages[$field] ?? []) !== [];
    }

    /**
     * Did anything fail at all?
     */
    public function any(): bool
    {
        return $this->messages !== [];
    }

    /**
     * Every message for a field, or an empty list when it is innocent.
     *
     * @return list<string>
     */
    public function messages(string $field): array
    {
        return $this->messages[$field] ?? [];
    }

    /**
     * The whole bag.
     *
     * @return array<string, list<string>>
     */
    public function all(): array
    {
        return $this->messages;
    }

    /**
     * The first message for a field — the one worth showing in a summary.
     */
    public function first(string $field): ?string
    {
        return $this->messages[$field][0] ?? null;
    }

    /**
     * How many fields failed.
     */
    public function count(): int
    {
        return count($this->messages);
    }

    /**
     * @return array<string, list<string>>
     */
    public function jsonSerialize(): array
    {
        return $this->messages;
    }
}