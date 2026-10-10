<?php

declare(strict_types=1);

namespace IamAdty\Empira\Validation;

/**
 * The rules a validator knows, and nothing else.
 *
 * The enum exists so the vocabulary of the rules DSL has exactly one
 * definition. {@see Validator} asks it what a rule name means, which means a
 * typo is an error at validation time rather than a rule that silently does
 * nothing — a misspelt `maxmum` that validates nothing is a bug that ships.
 *
 *     Rule::of('int');      // Rule::Integer
 *     Rule::of('email');    // Rule::Email
 *     Rule::of('maxmum');   // null
 *
 *     Rule::Max->needsParameter();   // true — `max` alone is a mistake
 */
enum Rule: string
{
    /** The field must be present and not empty. */
    case Required = 'required';

    /** A null value is allowed here, and skips the rest of the field's rules. */
    case Nullable = 'nullable';

    case String = 'string';

    /** Ints, integer strings such as `'12'`, and whole floats such as `12.0`. */
    case Integer = 'integer';

    case Numeric = 'numeric';

    case Boolean = 'boolean';

    case Array = 'array';

    case Email = 'email';

    /** `min:18` — the smallest number, shortest string, or shortest list. */
    case Min = 'min';

    /** `max:200` — the largest of the same three. */
    case Max = 'max';

    /** `in:red,green,blue` — one of a fixed set of values. */
    case In = 'in';

    /** `regex:/^[a-z]+$/i` — a pattern delimited by slashes, with optional flags. */
    case Regex = 'regex';

    /** The field must equal `{field}_confirmation` in the same data. */
    case Confirmed = 'confirmed';

    /** `same:password` — the field must equal another field. */
    case Same = 'same';

    /**
     * The rule a name refers to, including the aliases people reach for.
     *
     * Returns null for anything this validator does not implement, which is
     * the validator's cue to throw rather than ignore.
     */
    public static function of(string $name): ?self
    {
        return self::tryFrom(strtolower(trim($name)))
            ?? self::tryFrom(self::ALIASES[strtolower(trim($name))] ?? '');
    }

    /**
     * Does this rule take a parameter, as in `max:200`?
     */
    public function needsParameter(): bool
    {
        return match ($this) {
            self::Min, self::Max, self::In, self::Regex, self::Same => true,
            default => false,
        };
    }

    private const ALIASES = [
        'int' => 'integer',
        'bool' => 'boolean',
    ];
}