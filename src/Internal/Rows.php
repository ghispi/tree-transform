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
        if ($rows instanceof \Generator) {
            try {
                $rows->rewind();
            } catch (\Exception $e) {
                // Only PHP's own "already run/closed" errors mean reuse; anything else comes from the generator.
                if ($e::class !== \Exception::class || preg_match('/already (run|closed)/', $e->getMessage()) !== 1) {
                    throw $e;
                }

                throw new \Ghispi\TreeTransform\Exception\LogicException(
                    'This source reads a generator, which can only be traversed once. Use Tree::lazy() or LazySource to read it again.',
                    0,
                    $e,
                );
            }

            return $rows;
        }
        if ($rows instanceof \Iterator) {
            return $rows;
        }

        return new \IteratorIterator($rows);
    }

    /**
     * Rows ready for foreach in a stream: arrays as they are (no copy), anything else as an Iterator. Called
     * before the stream starts, so a reused generator fails when the source is opened.
     *
     * @template TKey
     * @template T
     *
     * @param iterable<TKey, T> $rows
     *
     * @return iterable<TKey, T>
     */
    public static function each(iterable $rows): iterable
    {
        if (\is_array($rows)) {
            return $rows;
        }
        $iterator = self::iterator($rows);

        // An empty generator is finished by its rewind, and foreach refuses finished generators.
        return $iterator instanceof \Generator && !$iterator->valid() ? [] : $iterator;
    }

    /**
     * Normalises an int-like id to an array key ("5" and 5 become the same key).
     */
    public static function key(mixed $id): int|string
    {
        if (\is_int($id)) {
            return $id;
        }
        if (\is_string($id)) {
            // Same rule as PHP array keys: canonical integer strings become integers.
            return (string) (int) $id === $id ? (int) $id : $id;
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
     * The class in $class's hierarchy that declares $property (private parent properties included), or null.
     *
     * @param class-string $class
     *
     * @return class-string|null
     */
    public static function declaringClass(string $class, string $property): ?string
    {
        for ($current = $class; $current !== false; $current = get_parent_class($current)) {
            if (property_exists($current, $property)) {
                return (new \ReflectionProperty($current, $property))->getDeclaringClass()->getName();
            }
        }

        return null;
    }

    /**
     * Converts a numeric field value (int, or numeric string as returned by PDO) to int.
     */
    public static function int(mixed $value, string $field, mixed $node): int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && preg_match('/^-?[0-9]+$/', $value) === 1) {
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
