<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Accessor;

/**
 * Default accessor: works with arrays, ArrayAccess and objects without configuration.
 *
 * - arrays / ArrayAccess: $node[$field]
 * - objects: a public property $field, else a getter (getField(), isField(), hasField(), field()),
 *   else a property of any visibility. The choice is made once per class and cached.
 */
final class AutoAccessor implements Accessor
{
    /** @var array<string, array<class-string, \Closure(object): mixed>> */
    private array $objectReaders = [];

    public function reader(string $field): \Closure
    {
        return function (mixed $node) use ($field): mixed {
            if (\is_array($node)) {
                return $node[$field] ?? null;
            }
            if ($node instanceof \ArrayAccess) {
                return $node[$field] ?? null;
            }
            if (!\is_object($node)) {
                return null;
            }
            $read = $this->objectReaders[$field][$node::class] ??= self::resolve($node::class, $field);

            return $read($node);
        };
    }

    /**
     * @param class-string $class
     *
     * @return \Closure(object): mixed
     */
    private static function resolve(string $class, string $field): \Closure
    {
        if (property_exists($class, $field) && (new \ReflectionProperty($class, $field))->isPublic()) {
            return static fn(object $node): mixed => $node->{$field} ?? null;
        }
        $ucField = ucfirst($field);
        foreach (['get' . $ucField, 'is' . $ucField, 'has' . $ucField, $field] as $method) {
            if (method_exists($class, $method) && (new \ReflectionMethod($class, $method))->isPublic()) {
                return static fn(object $node): mixed => $node->{$method}();
            }
        }
        if (property_exists($class, $field)) {
            $reader = (new PrivatePropertyAccessor($class))->reader($field);

            return static fn(object $node): mixed => $reader($node);
        }

        // Dynamic/magic properties (stdClass, __get).
        return static fn(object $node): mixed => $node->{$field} ?? null;
    }
}
