<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Accessor;

/**
 * Reads array keys (and ArrayAccess offsets): $node[$field]. The fastest accessor for array rows.
 */
final readonly class ArrayKeyAccessor implements Accessor
{
    public function reader(string $field): \Closure
    {
        return static fn(mixed $node): mixed => $node[$field] ?? null;
    }
}
