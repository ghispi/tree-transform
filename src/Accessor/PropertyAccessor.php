<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Accessor;

/**
 * Reads public properties: $node->$field.
 */
final readonly class PropertyAccessor implements Accessor
{
    public function reader(string $field): \Closure
    {
        return static fn(mixed $node): mixed => $node->{$field} ?? null;
    }
}
