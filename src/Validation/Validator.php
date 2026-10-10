<?php

declare(strict_types=1);

namespace IamAdty\Empira\Validation;

/**
 * Checks an array of input against a set of rules and collects what failed.
 *
 * Rules are written the way you would say them out loud, and a field's rules
 * can be one string or a list of them:
 *
 *     $errors = (new Validator($input))->validate([
 *         'name'  => 'required|string|max:80',
 *         'email' => ['required', 'email'],
 *         'tags'  => 'array|max:5',
 *     ]);
 *
 * Nested data is addressed with dots — `'user.name' => 'required'` reads
 * `$input['user']['name']` — and the name is what the message says, so the
 * person filling in the form sees the field they filled in.
 *
 * Three decisions are worth knowing about, because they are the ones that
 * usually go wrong:
 *
 * - **A rule that is not recognised throws.** `['age' => 'requried']` is a
 *   programming error, not a validation failure, so it raises an
 *   `\InvalidArgumentException` rather than quietly validating nothing. Same
 *   for a rule missing its parameter: `max` with no number throws too.
 * - **Numbers arriving as strings are numbers.** `'12'` passes `integer` and
 *   `numeric`, because a form input is *always* a string and demanding an
 *   `int` here would mean casting at every call site, in a module whose job is
 *   to report rather than to convert. `integer` accepts an int, an integer
 *   string such as `'12'` or `'-3'`, and a whole float such as `12.0`;
 *   `'12.5'` and `'12px'` do not pass. Nothing is ever written back — the
 *   input array is untouched.
 * - **An absent field is an absent field.** Only `required` reacts to a field
 *   that was never sent; the other rules describe the shape of a value and
 *   have nothing to say about nothing. Add `required` when presence is what
 *   you mean.
 *
 * `nullable` is the escape hatch for the opposite case: a field that may
 * legally be null, in which case its remaining rules are skipped. `required`
 * is checked before `nullable` gets its say, so `'required|nullable'` is a
 * contradiction you hear about rather than one that passes in silence.
 *
 * `min` and `max` measure whatever they are handed: a number — numeric string
 * included, since that is what a form sends — as itself, a list by its count,
 * any other string by its length.
 */
final class Validator
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private readonly array $data)
    {
    }

    /**
     * Validate in one line, for the many call sites that do not need the
     * validator to outlive the check.
     *
     *     $errors = Validator::make($input, ['email' => 'required|email']);
     *
     * @param array<string, mixed>            $data
     * @param array<string, string|list<string>> $rules
     */
    public static function make(array $data, array $rules): ErrorBag
    {
        return (new self($data))->validate($rules);
    }

    /**
     * Run every rule and return the failures.
     *
     * @param array<string, string|list<string>> $rules
     *
     * @throws \InvalidArgumentException on an unknown rule or a rule missing its parameter
     */
    public function validate(array $rules): ErrorBag
    {
        $errors = new ErrorBag();

        foreach ($rules as $field => $spec) {
            $field = (string) $field;
            [$parsed, $nullable, $required] = $this->parse($field, $spec);

            [$present, $value] = $this->value($field);

            if ($required && ! $this->filled($value)) {
                $errors->add($field, sprintf('The %s field is required.', $field));

                continue;
            }

            if (! $present || ($nullable && $value === null)) {
                continue;
            }

            foreach ($parsed as [$rule, $parameter]) {
                $message = $this->check($rule, $field, $value, $parameter);

                if ($message !== null) {
                    $errors->add($field, $message);
                }
            }
        }

        return $errors;
    }

    /**
     * Read the field out of the input, following dots into nested arrays.
     *
     * Presence and value travel together because `null` and "never sent" are
     * different answers: `'nickname' => 'nullable|string'` is happy with the
     * first and has nothing to check for the second.
     *
     * @return array{bool, mixed}
     */
    private function value(string $field): array
    {
        $value = $this->data;

        foreach (explode('.', $field) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return [false, null];
            }

            $value = $value[$segment];
        }

        return [true, $value];
    }

    /**
     * Is there a value here at all, as `required` understands it?
     */
    private function filled(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== [];
    }

    /**
     * Flatten a field's rules and reject anything unusable, before any of them
     * runs — a typo on a rule that happens to be short-circuited is still a
     * typo.
     *
     * @param string|list<string> $spec
     *
     * @return array{list<array{Rule, string}>, bool, bool} the rules, whether `nullable` was among them, whether `required` was
     *
     * @throws \InvalidArgumentException
     */
    private function parse(string $field, string|array $spec): array
    {
        $parsed = [];
        $nullable = false;
        $required = false;

        foreach ((array) $spec as $rule) {
            foreach (explode('|', (string) $rule) as $line) {
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                [$name, $parameter] = explode(':', $line, 2) + [1 => ''];

                $resolved = Rule::of($name)
                    ?? throw new \InvalidArgumentException(sprintf('Unknown validation rule "%s" on field "%s".', trim($name), $field));

                if ($resolved->needsParameter() && trim($parameter) === '') {
                    throw new \InvalidArgumentException(sprintf(
                        'The "%s" rule on field "%s" needs a parameter, as in "%s:...".',
                        $name,
                        $field,
                        trim($name)
                    ));
                }

                if (($resolved === Rule::Min || $resolved === Rule::Max) && ! is_numeric($parameter)) {
                    throw new \InvalidArgumentException(sprintf(
                        'The "%s" rule on field "%s" needs a number, as in "%s:10".',
                        $name,
                        $field,
                        trim($name)
                    ));
                }

                $parsed[] = [$resolved, trim($parameter)];
                $nullable = $nullable || $resolved === Rule::Nullable;
                $required = $required || $resolved === Rule::Required;
            }
        }

        return [$parsed, $nullable, $required];
    }

    /**
     * Apply one rule, returning the message it fails with, or null.
     */
    private function check(Rule $rule, string $field, mixed $value, string $parameter): ?string
    {
        return match ($rule) {
            Rule::Required => $this->filled($value) ? null : sprintf('The %s field is required.', $field),
            Rule::Nullable => null,
            Rule::String => is_string($value) ? null : sprintf('The %s field must be a string.', $field),
            Rule::Integer => $this->isInteger($value) ? null : sprintf('The %s field must be an integer.', $field),
            Rule::Numeric => $this->isNumeric($value) ? null : sprintf('The %s field must be a number.', $field),
            Rule::Boolean => $this->isBoolean($value) ? null : sprintf('The %s field must be true or false.', $field),
            Rule::Array => is_array($value) ? null : sprintf('The %s field must be an array.', $field),
            Rule::Email => $this->isEmail($value) ? null : sprintf('The %s field must be a valid email address.', $field),
            Rule::Min => $this->atLeast($value, (float) $parameter) ? null : sprintf('The %s field must be at least %s.', $field, $parameter),
            Rule::Max => $this->atMost($value, (float) $parameter) ? null : sprintf('The %s field may not be greater than %s.', $field, $parameter),
            Rule::In => $this->withinList($field, $value, $parameter),
            Rule::Regex => preg_match($this->pattern($field, $parameter), $this->stringify($value)) === 1 ? null : sprintf('The %s field format is invalid.', $field),
            Rule::Confirmed => $this->isSame($value, $this->value($field . '_confirmation')[1])
                ? null
                : sprintf('The %s confirmation does not match.', $field),
            Rule::Same => $this->isSame($value, $this->value($parameter)[1])
                ? null
                : sprintf('The %s field must match the %s field.', $field, $parameter),
        };
    }

    /**
     * Is the value one of the choices an `in:` rule lists?
     */
    private function withinList(string $field, mixed $value, string $parameter): ?string
    {
        $choices = array_map('trim', explode(',', $parameter));

        return in_array($this->stringify($value), $choices, true)
            ? null
            : sprintf('The %s field must be one of: %s.', $field, implode(', ', $choices));
    }

    /**
     * An int, an integer string, or a float that is not hiding a fraction.
     */
    private function isInteger(mixed $value): bool
    {
        return match (true) {
            is_int($value) => true,
            is_float($value) => floor($value) === $value,
            is_string($value) => preg_match('/^[+-]?\d+$/', $value) === 1,
            default => false,
        };
    }

    private function isNumeric(mixed $value): bool
    {
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value));
    }

    private function isBoolean(mixed $value): bool
    {
        return match (true) {
            is_bool($value) => true,
            is_int($value) => $value === 0 || $value === 1,
            is_string($value) => in_array(strtolower($value), ['0', '1', 'true', 'false', 'on', 'off', 'yes', 'no'], true),
            default => false,
        };
    }

    private function isEmail(mixed $value): bool
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * How `min` measures: a number — including a numeric string, because form
     * input is text — as itself, a list by its count, any other string by its
     * length. A value it cannot measure fails the rule rather than blowing up
     * mid-validation.
     */
    private function atLeast(mixed $value, float $limit): bool
    {
        $measured = $this->measure($value);

        return $measured !== null && $measured >= $limit;
    }

    private function atMost(mixed $value, float $limit): bool
    {
        $measured = $this->measure($value);

        return $measured !== null && $measured <= $limit;
    }

    private function measure(mixed $value): ?float
    {
        if (is_int($value) || is_float($value) || is_bool($value) || (is_string($value) && is_numeric($value))) {
            return (float) $value;
        }

        return match (true) {
            is_array($value) => count($value),
            is_string($value) => mb_strlen($value),
            default => null,
        };
    }

    /**
     * Two values, compared the way a form would: as text for scalars, and by
     * identity for anything else. `true` is not `1` and `false` is not `''`.
     */
    private function isSame(mixed $value, mixed $other): bool
    {
        if (is_bool($value) || is_bool($other)) {
            return $value === $other;
        }

        if (is_scalar($value) && is_scalar($other)) {
            return $this->stringify($value) === $this->stringify($other);
        }

        return $value === $other;
    }

    private function stringify(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * The regex a `regex:` rule carries, checked once per field.
     *
     * @throws \InvalidArgumentException when the pattern will not compile
     */
    private function pattern(string $field, string $parameter): string
    {
        if (preg_match('#^/.*/[a-zA-Z]*$#s', $parameter) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'The "regex" rule on field "%s" needs a pattern between slashes, as in "regex:/^[a-z]+$/i".',
                $field
            ));
        }

        if (@preg_match($parameter, '') === false) {
            throw new \InvalidArgumentException(sprintf(
                'The "regex" rule on field "%s" has a pattern PHP cannot compile: "%s".',
                $field,
                $parameter
            ));
        }

        return $parameter;
    }
}