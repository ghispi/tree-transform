<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Accessor;

/**
 * Reads properties of any visibility declared in $class (or its parents) without calling getters.
 *
 * Useful for Doctrine entities that keep tree fields private. Uses a closure bound to the class scope, which is
 * much faster than reflection.
 */
final readonly class PrivatePropertyAccessor implements Accessor
{
    /**
     * @param class-string $class
     */
    public function __construct(private string $class) {}

    public function reader(string $field): \Closure
    {
        $class = $this->class;
        // Bind to the class that declares the property so private parent properties are visible too.
        $scope = $class;
        if (property_exists($class, $field)) {
            $scope = (new \ReflectionProperty($class, $field))->getDeclaringClass()->getName();
        }

        $reader = static fn(mixed $node): mixed => $node->{$field} ?? null;

        return \Closure::bind($reader, null, $scope);
    }
}
