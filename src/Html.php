<?php

declare(strict_types=1);

namespace Empira;

final class Html
{
    private function __construct()
    {
    }

    /**
     * Escape a value for use inside an HTML text node or attribute value.
     */
    public static function escape(mixed $value): string
    {
        return htmlspecialchars(self::stringify($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Turn a PHP value into the string that should appear in the document.
     */
    public static function stringify(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            $value === true => 'true',
            $value === false => 'false',
            is_scalar($value) => (string) $value,
            is_array($value) => self::json($value),
            $value instanceof \Stringable => (string) $value,
            default => throw new \InvalidArgumentException('Cannot convert value of type ' . get_debug_type($value) . ' to a string.'),
        };
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function json(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    /**
     * Attribute names are interpolated straight into the document, so they are
     * validated rather than escaped. Anything that is not a legal HTML
     * attribute name is rejected outright.
     */
    public static function attrName(string $name): string
    {
        if (preg_match('/^[A-Za-z_:][A-Za-z0-9_.:-]*$/', $name) !== 1) {
            throw new \InvalidArgumentException(sprintf('Illegal HTML attribute name "%s".', $name));
        }

        return $name;
    }

    /**
     * Render an attribute map. `true` renders a bare attribute, `false`/`null`
     * drops it entirely.
     */
    public static function attrs(array $attrs): string
    {
        $out = '';

        foreach ($attrs as $name => $value) {
            if ($value === false || $value === null) {
                continue;
            }

            $name = self::attrName((string) $name);

            $out .= $value === true
                ? ' ' . $name
                : ' ' . $name . '="' . self::escape($value) . '"';
        }

        return $out;
    }
}