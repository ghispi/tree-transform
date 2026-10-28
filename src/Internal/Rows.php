<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Internal;

/**
 * @internal
 */
final class Rows
{
    /**
     * Normalises any iterable to an Iterator without copying arrays.
     *
     * @template T
     *
     * @param iterable<T> $rows
     *
     * @return \Iterator<T>
     */
    public static function iterator(iterable $rows): \Iterator
    {
        if (\is_array($rows)) {
            return new \ArrayIterator($rows);
        }
        if ($rows instanceof \Iterator) {
            return $rows;
        }

        return new \IteratorIterator($rows);
    }

    /**
     * Normalises an int-like id to an array key ("5" and 5 become the same key).
     */
    public static function key(mixed $id): int|string
    {
        if (\is_int($id) || \is_string($id)) {
            return $id;
        }
        if ($id instanceof \Stringable) {
            return (string) $id;
        }
        if (\is_float($id) && floor($id) === $id) {
            return (int) $id;
        }

        throw new \Ghispi\TreeTransform\Exception\InvalidTreeException(
            \sprintf('Node ids must be int, string or Stringable, got %s.', get_debug_type($id)),
        );
    }

    /**
     * Converts a numeric field value (int, or numeric string as returned by PDO) to int.
     */
    public static function int(mixed $value, string $field, mixed $node): int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        throw new \Ghispi\TreeTransform\Exception\InvalidTreeException(\sprintf(
            'Field "%s" must be an integer, got %s (node of type %s).',
            $field,
            get_debug_type($value),
            get_debug_type($node),
        ));
    }
}
